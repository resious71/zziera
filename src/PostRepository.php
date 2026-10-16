<?php
/**
 * 찌라시 저장·조회 (본문 = 정화된 HTML)
 */
final class PostRepository
{
    /** 목록 검색 시 PHP 에서 본문 텍스트를 다시 확인할 최대 후보 수 */
    private const SEARCH_CANDIDATE_MAX = 1000;

    /**
     * 찌라시 저장(하나의 트랜잭션).
     *  - zzira 행 저장(has_*, summary 포함)
     *  - 본문 임시 이미지의 zzira_id 연결(아직 연결되지 않은 것만, 개수가 맞지 않으면 롤백)
     *  - 첨부 저장. 실패하면 이미 옮긴 첨부 파일을 지운다.
     *
     * @param array $body        PostForm::body() 결과(HtmlSanitizer 결과)
     * @param array $attachments Upload::validate(..., attachment) 결과
     * @param array{source?:string, source_name?:?string, source_url?:?string, scrap_topic?:?string, summary?:string} $meta
     *        스크랩(5f) 글의 출처 구분과 요약(RSS 요약). 일반 글은 비워 둔다(source=user, TextRank 요약)
     */
    public static function create(string $title, array $body, array $attachments, array $meta = []): int
    {
        $now = Time::now();
        $summary = array_key_exists('summary', $meta) ? (string) $meta['summary'] : Summarizer::summarize($body['text']);
        $source = ($meta['source'] ?? 'user') === 'scrap' ? 'scrap' : 'user';
        $stored = [];

        try {
            return Db::transaction(static function () use ($title, $body, $attachments, $now, $summary, $source, $meta, &$stored): int {
                Db::exec(
                    'INSERT INTO zzira (title, body, summary, has_image, has_table, has_link, status, created_at, source, source_name, source_url, scrap_topic)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $title, $body['html'], $summary,
                        (int) $body['has_image'], (int) $body['has_table'], (int) $body['has_link'],
                        'active', $now, $source,
                        $meta['source_name'] ?? null, $meta['source_url'] ?? null, $meta['scrap_topic'] ?? null,
                    ]
                );
                $id = Db::lastId();

                $imageIds = $body['image_ids'];
                if ($imageIds !== []) {
                    $marks = implode(',', array_fill(0, count($imageIds), '?'));
                    // uploaded_at 을 등록 시각으로 맞춘다(파일 보관 기간은 "등록일" 기준)
                    $linked = Db::exec(
                        "UPDATE zzira_images SET zzira_id = ?, uploaded_at = ? WHERE zzira_id IS NULL AND id IN ({$marks})",
                        array_merge([$id, $now], $imageIds)
                    );
                    if ($linked !== count($imageIds)) {
                        throw new ValidationException('본문 이미지가 이미 다른 글에 쓰였습니다. 이미지를 다시 올려 주세요.');
                    }
                }

                foreach ($attachments as $att) {
                    $path = Upload::store($att);
                    $stored[] = $path;
                    Db::exec(
                        'INSERT INTO zzira_attachments (zzira_id, file_path, original_name, file_size, mime_type, uploaded_at)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$id, $path, $att['original_name'], $att['size'], $att['mime'], $now]
                    );
                }
                return $id;
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Upload::remove($path);
            }
            throw $e;
        }
    }

    /**
     * 날짜별 목록(삭제되지 않은 건만, 최신순). 상단 고정된 글은 빼고 listPinned() 로 따로 보여 준다.
     *
     * @param array{q?:string, image?:bool, table?:bool, link?:bool, attachment?:bool, all_dates?:bool} $filters
     */
    public static function listByDate(string $date, array $filters = [], int $limit = 200): array
    {
        $where = ["z.status = 'active'", 'z.is_pinned = 0'];
        $params = [];

        if (empty($filters['all_dates'])) {
            [$from, $to] = Time::dayRange($date);
            $where[] = 'z.created_at >= ? AND z.created_at < ?';
            $params[] = $from;
            $params[] = $to;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // 1차 후보: 제목 또는 본문 HTML 에 검색어(또는 HTML 이스케이프된 검색어)가 들어 있는 글
            $esc = static fn (string $s) => '%' . strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $where[] = "(z.title LIKE ? ESCAPE '\\' OR z.body LIKE ? ESCAPE '\\' OR z.body LIKE ? ESCAPE '\\')";
            $params[] = $esc($q);
            $params[] = $esc($q);
            $params[] = $esc(htmlspecialchars($q, ENT_NOQUOTES, 'UTF-8'));
        }
        foreach (['image' => 'z.has_image = 1', 'table' => 'z.has_table = 1', 'link' => 'z.has_link = 1'] as $key => $cond) {
            if (!empty($filters[$key])) {
                $where[] = $cond;
            }
        }
        if (!empty($filters['attachment'])) {
            $where[] = 'EXISTS (SELECT 1 FROM zzira_attachments a WHERE a.zzira_id = z.id)';
        }

        $rows = self::selectItems($where, $params, 'z.created_at DESC, z.id DESC', $q !== '' ? self::SEARCH_CANDIDATE_MAX : max(1, $limit), $q !== '');

        // 2차: 검색어는 본문 "일반 텍스트" 기준으로 다시 확인(태그·속성 안의 글자는 제외)
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r) =>
                mb_stripos($r['title'], $q) !== false || mb_stripos(HtmlText::toPlain($r['body'], true), $q) !== false));
            $rows = array_slice($rows, 0, max(1, $limit));
        }

        return self::toItems($rows);
    }

    /**
     * 상세 화면의 이전/다음 글(post.php). 삭제되지 않은 전체 글을 목록과 같은 최신순(created_at, id)으로 놓고
     * prev = 목록에서 바로 위(더 최신), next = 바로 아래(더 오래된) 글. 없으면 null.
     * @return array{prev: ?array{id:int, title:string}, next: ?array{id:int, title:string}}
     */
    public static function neighbors(int $id): array
    {
        $cur = Db::row("SELECT created_at FROM zzira WHERE id = ? AND status = 'active'", [$id]);
        if ($cur === null) {
            return ['prev' => null, 'next' => null];
        }
        $pick = static function (?array $r): ?array {
            return $r === null ? null : ['id' => (int) $r['id'], 'title' => (string) $r['title']];
        };
        $t = $cur['created_at'];
        return [
            'prev' => $pick(Db::row(
                "SELECT id, title FROM zzira WHERE status = 'active' AND (created_at > ? OR (created_at = ? AND id > ?))
                 ORDER BY created_at ASC, id ASC LIMIT 1", [$t, $t, $id])),
            'next' => $pick(Db::row(
                "SELECT id, title FROM zzira WHERE status = 'active' AND (created_at < ? OR (created_at = ? AND id < ?))
                 ORDER BY created_at DESC, id DESC LIMIT 1", [$t, $t, $id])),
        ];
    }

    /** 상단 고정된 글(삭제되지 않은 것, 고정 일시 최신순). 날짜·검색 필터와 무관 */
    public static function listPinned(): array
    {
        return self::toItems(self::selectItems(["z.status = 'active'", 'z.is_pinned = 1'], [], 'z.pinned_at DESC, z.id DESC', 100, false));
    }

    /** 목록 항목 조회(썸네일은 본문 HTML 의 첫 이미지 주소 부분만 잘라 와 본문 전체를 읽지 않는다) */
    private static function selectItems(array $where, array $params, string $order, int $limit, bool $withBody): array
    {
        $needle = 'api/file.php?type=image&amp;id=';
        $sql = "SELECT z.id, z.title, z.summary, z.created_at, z.has_image, z.has_table, z.has_link, z.is_pinned, z.pinned_at, z.comment_count, z.vote_count, z.source, z.scrap_topic,
                    CASE WHEN instr(z.body, ?) > 0 THEN substr(z.body, instr(z.body, ?) + ?, 12) END AS first_image,
                    (SELECT COUNT(*) FROM zzira_attachments a WHERE a.zzira_id = z.id) AS attachment_count
                    " . ($withBody ? ', z.body' : '') . "
                FROM zzira z
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$order}
                LIMIT " . max(1, $limit);
        return Db::all($sql, array_merge([$needle, $needle, strlen($needle)], $params));
    }

    private static function toItems(array $rows): array
    {
        $thumbs = self::availableThumbs($rows);
        return array_map(static fn (array $r) => [
            'id'               => (int) $r['id'],
            'title'            => $r['title'],
            'summary'          => (string) $r['summary'],
            'created_at'       => $r['created_at'],
            'has_image'        => (bool) $r['has_image'],
            'has_table'        => (bool) $r['has_table'],
            'has_link'         => (bool) $r['has_link'],
            'attachment_count' => (int) $r['attachment_count'],
            'thumb_id'         => $thumbs[(int) $r['id']] ?? null,
            'is_pinned'        => (int) $r['is_pinned'] === 1,
            'pinned_at'        => $r['pinned_at'],
            'comment_count'    => (int) $r['comment_count'],
            'vote_count'       => (int) $r['vote_count'],
            'source'           => (string) ($r['source'] ?? 'user'),
        ], $rows);
    }

    /**
     * 목록 각 글의 첫 본문 이미지 중 실제로 볼 수 있는 것만 썸네일로 쓴다(만료·파일 없음이면 생략).
     * @return array<int,int> 글 id => 이미지 id
     */
    private static function availableThumbs(array $rows): array
    {
        $want = [];
        foreach ($rows as $r) {
            if ($r['first_image'] !== null && preg_match('/^(\d+)/', $r['first_image'], $m)) {
                $want[(int) $m[1]] = (int) $r['id'];
            }
        }
        if ($want === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($want), '?'));
        $thumbs = [];
        foreach (Db::all("SELECT id, zzira_id, file_path, file_deleted_at FROM zzira_images WHERE id IN ({$marks})", array_keys($want)) as $img) {
            $postId = $want[(int) $img['id']];
            if ((int) $img['zzira_id'] === $postId && $img['file_deleted_at'] === null && Upload::absolutePath($img['file_path']) !== null) {
                $thumbs[$postId] = (int) $img['id'];
            }
        }
        return $thumbs;
    }

    /**
     * 상세 조회. 없거나(삭제 포함 여부에 따라) 볼 수 없으면 null.
     * html 은 ContentRenderer 로 출력 직전 재정화·만료 이미지 대체를 거친 결과다.
     */
    public static function find(int $id, bool $includeDeleted = false): ?array
    {
        $post = Db::row('SELECT * FROM zzira WHERE id = ?', [$id]);
        if ($post === null || (!$includeDeleted && $post['status'] !== 'active')) {
            return null;
        }

        $attachments = array_map(static fn (array $r) => [
            'id'            => (int) $r['id'],
            'original_name' => $r['original_name'],
            'file_size'     => (int) $r['file_size'],
            'available'     => $r['file_deleted_at'] === null && Upload::absolutePath($r['file_path']) !== null,
        ], Db::all('SELECT id, file_path, original_name, file_size, file_deleted_at FROM zzira_attachments WHERE zzira_id = ? ORDER BY id', [$id]));

        return [
            'id'          => (int) $post['id'],
            'title'       => $post['title'],
            'html'        => ContentRenderer::render($post['body'], (int) $post['id']),
            'summary'     => (string) $post['summary'],
            'has_image'   => (bool) $post['has_image'],
            'has_table'   => (bool) $post['has_table'],
            'has_link'    => (bool) $post['has_link'],
            'status'      => $post['status'],
            'created_at'  => $post['created_at'],
            'deleted_at'  => $post['deleted_at'],
            'source'      => (string) ($post['source'] ?? 'user'),
            'source_name' => $post['source_name'] ?? null,
            'attachments' => $attachments,
        ];
    }
}
