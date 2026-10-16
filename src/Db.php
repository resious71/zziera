<?php
/**
 * SQLite 연결과 스키마 초기화 (PRD 7장)
 *
 * 첫 연결 시 data/app.sqlite 를 만들고 스키마를 생성한다.
 * 스키마 버전은 PRAGMA user_version 으로 관리한다(이후 변경은 migrate()에 단계 추가).
 *   v1: 2단계 초기 스키마(table_data, zzira_links)
 *   v2: WYSIWYG 본문(2b단계) — body 는 정화된 HTML, has_* 추가, 임시 이미지(zzira_id NULL)
 *   v3: 멤버 자가 해지 폐지(4단계) — unsub_requests 삭제. 테이블 생성은 IF NOT EXISTS 라 v2 DB 에서도 안전
 *   v4: 상단 고정(zzira.is_pinned, pinned_at), 설정 테이블 app_settings(5단계)
 *       — 기존 DB 는 PRAGMA table_info 로 컬럼 유무를 확인해 ALTER TABLE ADD COLUMN(데이터 유지)
 *   v5: 사이트 로그인 계정 users(5b단계). 찌라시·멤버와 연결하지 않는다(익명성)
 *   v6: 댓글 zzira_comments·추천 zzira_votes, zzira.comment_count/vote_count(5d). 사용자 ID 는 저장하지 않고 HMAC 만
 *   v7: 스크랩(5f) — zzira.source/source_name/source_url/scrap_topic, scrap_seen, scrap_runs
 *   v8: 댓글 공감(이모티콘) zzira_comment_reactions. 사용자 ID 대신 댓글별 HMAC(reactor_key)만
 */
final class Db
{
    private const SCHEMA_VERSION = 8;

    private static ?PDO $pdo = null;

    public static function path(): string
    {
        return DATA_DIR . DIRECTORY_SEPARATOR . 'app.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0775, true);
        }

        $pdo = new PDO('sqlite:' . self::path(), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    /** 한 행 조회 */
    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** 여러 행 조회 */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** 단일 값 조회 */
    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT/UPDATE/DELETE 실행 후 영향받은 행 수 반환 */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** 콜백을 트랜잭션으로 감싼다. 예외 시 롤백 후 다시 던진다. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        if ($version === 1) {
            // v1 → v2: 본문 구조가 완전히 달라져 자동 변환하지 않는다.
            // 개발 중 빈 DB 만 재생성하고, 데이터가 있으면 멈춘다(데이터 보호).
            $posts = (int) $pdo->query('SELECT COUNT(*) FROM zzira')->fetchColumn();
            if ($posts > 0) {
                throw new RuntimeException(
                    'DB 스키마 v1(구 본문 구조)에 찌라시 ' . $posts . '건이 있어 자동 변환할 수 없습니다. '
                    . 'data/app.sqlite 를 백업한 뒤 처리 방법을 결정하세요.'
                );
            }
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        try {
            if ($version === 1) {
                foreach (['send_log_items', 'send_log_batches', 'send_log', 'unsub_requests', 'members',
                             'zzira_links', 'zzira_attachments', 'zzira_images', 'audit_log', 'zzira'] as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS {$table}");
                }
            }
            // 5e: 답글 기능 이전의 댓글 테이블이 이미 있으면, 아래 인덱스(zzira_id, parent_id, created_at)를 만들기 전에
            //     빠진 컬럼부터 추가한다. 기존 댓글은 parent_id·author_key·deleted_at 이 모두 NULL(일반 댓글, 작성자 해시 없음)
            if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'zzira_comments'")->fetchColumn()) {
                self::ensureColumns($pdo, 'zzira_comments', [
                    'parent_id'  => 'INTEGER REFERENCES zzira_comments(id)',
                    'author_key' => 'TEXT',
                    'deleted_at' => 'TEXT',
                ]);
            }
            foreach (self::schema() as $sql) {
                $pdo->exec($sql);
            }
            // v2 → v3: 멤버 자가 해지 폐지(관리자만 해지 처리) → 해지 확인 토큰 테이블 삭제
            $pdo->exec('DROP TABLE IF EXISTS unsub_requests');
            // v3 → v4: 기존 zzira 테이블에 고정 컬럼 추가(이미 있으면 건너뜀)
            self::ensureColumns($pdo, 'zzira', [
                'is_pinned' => 'INTEGER NOT NULL DEFAULT 0',
                'pinned_at' => 'TEXT NULL',
            ]);
            // v6 → v7: 스크랩 구분 컬럼(기존 글은 모두 source='user')
            self::ensureColumns($pdo, 'zzira', [
                'source'      => "TEXT NOT NULL DEFAULT 'user'",
                'source_name' => 'TEXT',
                'source_url'  => 'TEXT',
                'scrap_topic' => 'TEXT',
            ]);
            // v5 → v6: 댓글·추천 집계 컬럼(테이블은 위 schema() 의 IF NOT EXISTS 로 생성)
            self::ensureColumns($pdo, 'zzira', [
                'comment_count' => 'INTEGER NOT NULL DEFAULT 0',
                'vote_count'    => 'INTEGER NOT NULL DEFAULT 0',
            ]);
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** 테이블에 없는 컬럼만 ALTER TABLE ADD COLUMN 으로 추가한다 */
    private static function ensureColumns(PDO $pdo, string $table, array $columns): void
    {
        $existing = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
    }

    /** PRD 7장 스키마(v7). 시각은 모두 KST 'YYYY-MM-DD HH:MM:SS' 문자열 */
    private static function schema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS zzira (
                id          INTEGER PRIMARY KEY,
                title       TEXT NOT NULL,
                body        TEXT NOT NULL,
                summary     TEXT,
                has_image   INTEGER NOT NULL DEFAULT 0,
                has_table   INTEGER NOT NULL DEFAULT 0,
                has_link    INTEGER NOT NULL DEFAULT 0,
                status      TEXT CHECK(status IN ('active','deleted')) DEFAULT 'active',
                created_at  DATETIME NOT NULL,
                deleted_at  DATETIME,
                is_pinned   INTEGER NOT NULL DEFAULT 0,
                pinned_at   TEXT NULL,
                comment_count INTEGER NOT NULL DEFAULT 0,
                vote_count  INTEGER NOT NULL DEFAULT 0,
                source      TEXT NOT NULL DEFAULT 'user',
                source_name TEXT,
                source_url  TEXT,
                scrap_topic TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_zzira_created ON zzira(created_at, status)",

            // 본문 속 이미지. zzira_id NULL = 등록 전 임시 이미지
            "CREATE TABLE IF NOT EXISTS zzira_images (
                id              INTEGER PRIMARY KEY,
                zzira_id        INTEGER REFERENCES zzira(id),
                file_path       TEXT NOT NULL,
                uploaded_at     DATETIME NOT NULL,
                file_deleted_at DATETIME
            )",
            "CREATE INDEX IF NOT EXISTS idx_zzira_images_zzira ON zzira_images(zzira_id)",

            "CREATE TABLE IF NOT EXISTS zzira_attachments (
                id              INTEGER PRIMARY KEY,
                zzira_id        INTEGER NOT NULL REFERENCES zzira(id),
                file_path       TEXT NOT NULL,
                original_name   TEXT NOT NULL,
                file_size       INTEGER,
                mime_type       TEXT,
                uploaded_at     DATETIME NOT NULL,
                file_deleted_at DATETIME
            )",
            "CREATE INDEX IF NOT EXISTS idx_zzira_attachments_zzira ON zzira_attachments(zzira_id)",

            "CREATE TABLE IF NOT EXISTS members (
                id         INTEGER PRIMARY KEY,
                email      TEXT UNIQUE NOT NULL,
                status     TEXT CHECK(status IN ('active','suspended','unsubscribed')) DEFAULT 'active',
                created_at DATETIME NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_members_status ON members(status)",

            "CREATE TABLE IF NOT EXISTS send_log (
                id              INTEGER PRIMARY KEY,
                send_date       DATE NOT NULL,
                trigger_type    TEXT CHECK(trigger_type IN ('scheduled','manual')) NOT NULL,
                range_from      DATETIME,
                range_to        DATETIME,
                recipient_count INTEGER,
                status          TEXT CHECK(status IN ('running','success','failed','skipped')),
                error_message   TEXT,
                sent_at         DATETIME NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_send_log_date ON send_log(send_date, trigger_type)",

            "CREATE TABLE IF NOT EXISTS send_log_batches (
                id              INTEGER PRIMARY KEY,
                send_log_id     INTEGER NOT NULL REFERENCES send_log(id),
                batch_no        INTEGER NOT NULL,
                recipient_count INTEGER,
                attempts        INTEGER DEFAULT 0,
                status          TEXT CHECK(status IN ('success','failed')),
                error_message   TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_send_log_batches_log ON send_log_batches(send_log_id)",

            "CREATE TABLE IF NOT EXISTS send_log_items (
                send_log_id INTEGER NOT NULL REFERENCES send_log(id),
                zzira_id    INTEGER NOT NULL REFERENCES zzira(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_send_log_items_log ON send_log_items(send_log_id)",
            "CREATE INDEX IF NOT EXISTS idx_send_log_items_zzira ON send_log_items(zzira_id)",

            "CREATE TABLE IF NOT EXISTS audit_log (
                id          INTEGER PRIMARY KEY,
                event       TEXT NOT NULL,
                target_type TEXT,
                target_id   INTEGER,
                detail      TEXT,
                created_at  DATETIME NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at)",

            // 관리 화면에서 바꾸는 설정(메일 서버·발신 주소, 자동 발송 켜기/끄기 등)
            "CREATE TABLE IF NOT EXISTS app_settings (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",

            // 댓글(5d): parent_id NULL = 일반 댓글, 값 = 답글(1단계만). author_key 는 댓글 ID 가 섞인 HMAC(사용자 ID 저장 안 함)
            "CREATE TABLE IF NOT EXISTS zzira_comments (
                id         INTEGER PRIMARY KEY,
                zzira_id   INTEGER NOT NULL REFERENCES zzira(id),
                parent_id  INTEGER REFERENCES zzira_comments(id),
                body       TEXT NOT NULL,
                author_key TEXT,
                deleted_at TEXT,
                created_at TEXT NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_zzira_comments_post ON zzira_comments(zzira_id, parent_id, created_at)",

            // 추천(5d): vote_key = HMAC(사용자 ID|글 ID). 중복 추천 방지용으로만 쓴다
            "CREATE TABLE IF NOT EXISTS zzira_votes (
                zzira_id     INTEGER NOT NULL REFERENCES zzira(id),
                vote_key     TEXT NOT NULL,
                created_date TEXT NOT NULL,
                PRIMARY KEY (zzira_id, vote_key)
            )",

            // 댓글 공감(v8): 사용자당 댓글 하나에 이모티콘 하나. reactor_key = HMAC(사용자 ID|r|댓글 ID)
            "CREATE TABLE IF NOT EXISTS zzira_comment_reactions (
                comment_id   INTEGER NOT NULL REFERENCES zzira_comments(id),
                zzira_id     INTEGER NOT NULL REFERENCES zzira(id),
                reactor_key  TEXT NOT NULL,
                emoji        TEXT NOT NULL,
                created_date TEXT NOT NULL,
                PRIMARY KEY (comment_id, reactor_key)
            )",
            "CREATE INDEX IF NOT EXISTS idx_comment_reactions_post ON zzira_comment_reactions(zzira_id)",

            // 스크랩(5f): 중복 방지(URL 해시·정규화 제목, 30일 보관)와 실행 이력(90일 보관)
            "CREATE TABLE IF NOT EXISTS scrap_seen (
                url_hash   TEXT PRIMARY KEY,
                title_norm TEXT NOT NULL,
                zzira_id   INTEGER,
                created_at DATETIME NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_scrap_seen_created ON scrap_seen(created_at)",
            "CREATE TABLE IF NOT EXISTS scrap_runs (
                id            INTEGER PRIMARY KEY,
                trigger_type  TEXT CHECK(trigger_type IN ('auto','manual')) NOT NULL,
                started_at    DATETIME NOT NULL,
                finished_at   DATETIME,
                status        TEXT CHECK(status IN ('running','success','partial','failed')) NOT NULL,
                topic_count   INTEGER NOT NULL DEFAULT 0,
                created_count INTEGER NOT NULL DEFAULT 0,
                skipped_count INTEGER NOT NULL DEFAULT 0,
                message       TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_scrap_runs_started ON scrap_runs(started_at)",

            // 사이트 로그인 계정(ID·비밀번호만). members·찌라시·로그와 연결하지 않는다
            "CREATE TABLE IF NOT EXISTS users (
                id              INTEGER PRIMARY KEY,
                login_id        TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash   TEXT NOT NULL,
                status          TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
                token_version   INTEGER NOT NULL DEFAULT 1,
                created_at      TEXT NOT NULL,
                last_login_date TEXT NULL
            )",
        ];
    }
}
