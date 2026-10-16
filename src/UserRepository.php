<?php
/**
 * 사이트 로그인 계정 관리 (PRD H-3b, 5b단계) — 모든 작업은 관리자만 한다.
 *
 *  - 계정은 ID·비밀번호만 가진다. 메일링 멤버(members)·찌라시와 연결하지 않는다.
 *  - 비밀번호는 password_hash 로만 저장하고 응답·로그에 남기지 않는다.
 *    (관리자가 "임의 생성"한 값만 생성 직후 화면에 한 번 보여 준다)
 *  - 비밀번호 재설정·정지 시 token_version 을 올려 그 사용자의 기존 세션을 모두 무효화한다.
 *  - 감사 로그에는 대상 ID 만 남긴다(비밀번호·해시는 절대 남기지 않음).
 */
final class UserRepository
{
    public const STATUSES = ['active', 'suspended'];
    public const STATUS_LABELS = ['active' => '사용', 'suspended' => '정지'];
    public const BULK_MAX = 300;
    public const ID_MIN = 4;
    public const ID_MAX = 20;
    public const PW_MIN = 8;
    public const PW_MAX = 64;
    private const GEN_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function validLoginId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._\-]{' . self::ID_MIN . ',' . self::ID_MAX . '}$/', $id);
    }

    /** ID 형식 확인. 문제가 있으면 안내 문구, 없으면 null */
    public static function loginIdError(string $id): ?string
    {
        return self::validLoginId($id) ? null : 'ID는 ' . self::ID_MIN . '~' . self::ID_MAX . '자의 영문·숫자·. _ - 만 쓸 수 있습니다.';
    }

    /** 비밀번호 확인(앞뒤 공백도 비밀번호의 일부로 본다). 문제가 있으면 안내 문구 */
    public static function passwordError(string $pw): ?string
    {
        if (!mb_check_encoding($pw, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $pw)) {
            return '비밀번호에 쓸 수 없는 문자가 있습니다.';
        }
        $len = mb_strlen($pw);
        if ($len < self::PW_MIN || $len > self::PW_MAX) {
            return '비밀번호는 ' . self::PW_MIN . '~' . self::PW_MAX . '자로 입력하세요.';
        }
        return null;
    }

    /** 임의 비밀번호(영문·숫자 12자, 헷갈리는 0/O/1/l/I 제외) */
    public static function generatePassword(int $length = 12): string
    {
        $chars = self::GEN_CHARS;
        do {
            $pw = '';
            for ($i = 0; $i < $length; $i++) {
                $pw .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)); // 영문·숫자가 모두 들어가게
        return $pw;
    }

    public static function exists(string $loginId): bool
    {
        return Db::value('SELECT 1 FROM users WHERE login_id = ?', [$loginId]) !== null;
    }

    private static function insert(string $loginId, string $password): void
    {
        Db::exec(
            'INSERT INTO users (login_id, password_hash, status, token_version, created_at) VALUES (?, ?, ?, 1, ?)',
            [$loginId, password_hash($password, PASSWORD_DEFAULT), 'active', Time::now()]
        );
    }

    /** 한 명 추가(즉시 사용 가능) */
    public static function create(string $loginId, string $password): void
    {
        $loginId = trim($loginId);
        if (($e = self::loginIdError($loginId)) !== null || ($e = self::passwordError($password)) !== null) {
            throw new ValidationException($e);
        }
        if (self::exists($loginId)) {
            throw new ValidationException('이미 있는 ID입니다(대소문자 구분 없음).');
        }
        self::insert($loginId, $password);
        Audit::log('user_create', 'user', null, 'ID "' . $loginId . '" 추가');
    }

    /**
     * 일괄 추가: 한 줄에 "ID,비밀번호"(쉼표·탭·공백 구분, 첫 구분자 뒤 전부가 비밀번호).
     * 빈 줄은 건너뛴다. 응답에 비밀번호를 되돌려 보내지 않는다.
     * @return array{added:list<string>, duplicate:list<array{line:int,id:string}>, invalid:list<array{line:int,reason:string}>}
     */
    public static function createBulk(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') {
                $rows[] = [$i + 1, $line];
            }
        }
        if (count($rows) > self::BULK_MAX) {
            throw new ValidationException('한 번에 ' . self::BULK_MAX . '줄까지 추가할 수 있습니다.');
        }
        $result = ['added' => [], 'duplicate' => [], 'invalid' => []];
        $seen = [];
        Db::transaction(static function () use ($rows, &$result, &$seen): void {
            foreach ($rows as [$no, $line]) {
                if (!preg_match('/^\s*([^,\t ]+)[,\t ]+(.*)$/u', $line, $m)) {
                    $result['invalid'][] = ['line' => $no, 'reason' => '"ID,비밀번호" 형식이 아닙니다'];
                    continue;
                }
                $id = $m[1];
                $pw = $m[2];
                if (($e = self::loginIdError($id)) !== null || ($e = self::passwordError($pw)) !== null) {
                    $result['invalid'][] = ['line' => $no, 'reason' => $e];
                    continue;
                }
                $k = strtolower($id);
                if (isset($seen[$k]) || self::exists($id)) {
                    $result['duplicate'][] = ['line' => $no, 'id' => $id];
                    continue;
                }
                self::insert($id, $pw);
                $seen[$k] = true;
                $result['added'][] = $id;
            }
        });
        if ($result['added'] !== []) {
            Audit::log('user_bulk_create', 'user', null, count($result['added']) . '명 추가: ' . implode(', ', array_slice($result['added'], 0, 20)) . (count($result['added']) > 20 ? ' 외' : ''));
        }
        return $result;
    }

    /**
     * 목록(상태 필터·ID 검색·페이지 20건)
     * @return array{items:list<array>, total:int, page:int, pages:int, counts:array<string,int>}
     */
    public static function list(?string $status, string $q, int $page, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = "login_id LIKE ? ESCAPE '\\'";
            $params[] = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM users {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $items = Db::all(
            "SELECT id, login_id, status, created_at, last_login_date FROM users {$whereSql} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM users GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return [
            'items'  => array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $items),
            'total'  => $total,
            'page'   => $page,
            'pages'  => $pages,
            'counts' => $counts,
        ];
    }

    private static function findOrFail(int $id): array
    {
        $u = Db::row('SELECT id, login_id, status FROM users WHERE id = ?', [$id]);
        if ($u === null) {
            throw new ValidationException('사용자를 찾을 수 없습니다. 목록을 새로고침하세요.');
        }
        return $u;
    }

    /** 비밀번호 재설정(기존 세션 모두 무효화) */
    public static function resetPassword(int $id, string $password): string
    {
        $u = self::findOrFail($id);
        if (($e = self::passwordError($password)) !== null) {
            throw new ValidationException($e);
        }
        Db::exec('UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        Audit::log('user_password_reset', 'user', $id, 'ID "' . $u['login_id'] . '" 비밀번호 재설정(기존 로그인 해제)');
        return $u['login_id'];
    }

    /** 정지(즉시 로그아웃)·재개 */
    public static function setStatus(int $id, string $status): string
    {
        $u = self::findOrFail($id);
        if ($status === 'suspended') {
            Db::exec("UPDATE users SET status = 'suspended', token_version = token_version + 1 WHERE id = ?", [$id]);
            Audit::log('user_suspend', 'user', $id, 'ID "' . $u['login_id'] . '" 정지(기존 로그인 해제)');
        } else {
            Db::exec("UPDATE users SET status = 'active' WHERE id = ?", [$id]);
            Audit::log('user_resume', 'user', $id, 'ID "' . $u['login_id'] . '" 재개');
        }
        return $u['login_id'];
    }

    /** 영구 삭제(복구 없음). 세션은 다음 요청에서 무효가 된다 */
    public static function delete(int $id): string
    {
        $u = self::findOrFail($id);
        Db::exec('DELETE FROM users WHERE id = ?', [$id]);
        Audit::log('user_delete', 'user', $id, 'ID "' . $u['login_id'] . '" 삭제');
        return $u['login_id'];
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM users');
    }
}
