<?php
/**
 * 찌라시 댓글 (5d)
 *
 *  - 한 글에 최대 COMMENT_MAX_PER_POST(20)개(답글 포함, 관리자가 삭제한 것 제외). BEGIN IMMEDIATE 안에서 세고 넣는다.
 *  - 답글은 1단계만: 부모는 같은 글의 일반 댓글(parent_id NULL)이어야 하고, 삭제 표시된 댓글·자기 댓글에는 달 수 없다.
 *  - 작성자는 저장하지 않는다. author_key = HMAC(사용자ID|c|글ID|댓글ID, 앱 키) 만 저장해
 *    "이 댓글이 지금 사용자 것인가"만 다시 계산해 비교한다. 댓글 ID 가 섞여 같은 사람의 댓글끼리도 값이 다르다.
 *    author_key 는 어떤 응답·로그에도 내보내지 않는다. 관리자 세션(사용자 없음)으로 단 댓글은 NULL.
 *  - 본문은 글자만(원문 저장, 출력할 때 이스케이프). 수정 없음, 삭제는 관리자만.
 *  - 댓글 등록·조회는 감사 로그에 남기지 않는다(관리자 삭제만 comment_delete).
 */
final class Comments
{
    public static function maxPerPost(): int
    {
        return max(1, Config::int('COMMENT_MAX_PER_POST', 20));
    }

    public static function maxLen(): int
    {
        return max(1, Config::int('COMMENT_MAX_LEN', 500));
    }

    public static function authorKey(int $userId, int $postId, int $commentId): string
    {
        return hash_hmac('sha256', $userId . '|c|' . $postId . '|' . $commentId, AppKey::get());
    }

    /** 본문 정리: 줄바꿈 통일, 제어 문자 제거(탭은 공백), 연속 빈 줄 2개 이하, 앞뒤 공백 제거. 길이 검사 */
    public static function cleanBody(mixed $raw): string
    {
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
            throw new ValidationException('댓글 내용이 올바르지 않습니다.');
        }
        $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $raw);
        $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $s) ?? '';
        $s = preg_replace('/\n[ \x{00A0}]+\n/u', "\n\n", $s) ?? $s;      // 공백만 있는 줄은 빈 줄로
        $s = preg_replace('/\n{4,}/', "\n\n\n", $s) ?? $s;                // 빈 줄은 최대 2개 연속
        $s = trim($s);
        if ($s === '') {
            throw new ValidationException('댓글 내용을 입력하세요.');
        }
        if (mb_strlen($s) > self::maxLen()) {
            throw new ValidationException('댓글은 ' . self::maxLen() . '자 이하로 입력하세요.');
        }
        return $s;
    }

    /** 삭제 표시되지 않은 댓글·답글 수(20개 제한·comment_count 기준) */
    public static function activeCount(int $postId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM zzira_comments WHERE zzira_id = ? AND deleted_at IS NULL', [$postId]);
    }

    /**
     * 댓글·답글 등록.
     * @param ?int $userId 사용자 세션의 사용자 번호(관리자 세션만 있으면 null). 저장하지 않고 해시 계산에만 쓴다
     * @return array 새 댓글(목록 항목 형식)
     */
    public static function create(int $postId, string $body, ?int $parentId, ?int $userId): array
    {
        $body = self::cleanBody($body);
        $pdo = Db::pdo();
        $pdo->exec('BEGIN IMMEDIATE'); // 다른 등록과 겹쳐도 20개를 넘지 않게 쓰기 잠금을 먼저 잡는다
        try {
            $post = Db::row('SELECT id, status FROM zzira WHERE id = ?', [$postId]);
            if ($post === null || $post['status'] !== 'active') {
                throw new NotFoundException('찌라시를 찾을 수 없습니다. 삭제되었을 수 있습니다.');
            }
            if ($parentId !== null) {
                $parent = Db::row('SELECT id, zzira_id, parent_id, author_key, deleted_at FROM zzira_comments WHERE id = ?', [$parentId]);
                if ($parent === null || (int) $parent['zzira_id'] !== $postId) {
                    throw new ValidationException('답글을 달 댓글을 찾을 수 없습니다.');
                }
                if ($parent['parent_id'] !== null) {
                    throw new ValidationException('답글에는 답글을 달 수 없습니다.');
                }
                if ($parent['deleted_at'] !== null) {
                    throw new ValidationException('삭제된 댓글에는 답글을 달 수 없습니다.');
                }
                if ($userId !== null && $parent['author_key'] !== null
                    && hash_equals((string) $parent['author_key'], self::authorKey($userId, $postId, (int) $parent['id']))) {
                    throw new ValidationException('자신의 댓글에는 답글을 달 수 없습니다.');
                }
            }
            if (self::activeCount($postId) >= self::maxPerPost()) {
                throw new ValidationException('댓글은 글당 최대 ' . self::maxPerPost() . '개까지 달 수 있습니다.');
            }
            $now = Time::now();
            Db::exec('INSERT INTO zzira_comments (zzira_id, parent_id, body, author_key, deleted_at, created_at) VALUES (?, ?, ?, NULL, NULL, ?)',
                [$postId, $parentId, $body, $now]);
            $id = Db::lastId();
            if ($userId !== null) {
                Db::exec('UPDATE zzira_comments SET author_key = ? WHERE id = ?', [self::authorKey($userId, $postId, $id), $id]);
            }
            Db::exec('UPDATE zzira SET comment_count = comment_count + 1 WHERE id = ?', [$postId]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return ['id' => $id, 'parent_id' => $parentId, 'body' => $body, 'created_at' => $now, 'mine' => $userId !== null, 'deleted' => false,
            'reactions' => [], 'my_reaction' => null, 'replies' => []];
    }

    /**
     * 목록: 일반 댓글 등록순, 각 댓글의 replies 에 답글 등록순.
     * 항목: id, body, created_at, mine(이 요청 사용자가 쓴 것인지), deleted(삭제 표시면 true, 본문 없음),
     *       reactions[{emoji, count}](많은 순), my_reaction(내 공감 이모티콘 또는 null, 관리자는 항상 null) — 삭제 표시 댓글에는 없음
     */
    public static function listFor(int $postId, ?int $userId, bool $isAdmin): array
    {
        $rows = Db::all('SELECT id, parent_id, body, author_key, deleted_at, created_at FROM zzira_comments WHERE zzira_id = ? ORDER BY created_at, id', [$postId]);
        $mine = static function (array $r) use ($userId, $isAdmin, $postId): bool {
            if ($isAdmin || $userId === null || $r['author_key'] === null) {
                return false;
            }
            return hash_equals((string) $r['author_key'], self::authorKey($userId, $postId, (int) $r['id']));
        };
        $reactions = CommentReactions::forPost($postId, $isAdmin ? null : $userId);
        $top = [];
        $replies = [];
        foreach ($rows as $r) {
            $item = $r['deleted_at'] !== null
                ? ['id' => (int) $r['id'], 'created_at' => $r['created_at'], 'mine' => false, 'deleted' => true]
                : ['id' => (int) $r['id'], 'body' => $r['body'], 'created_at' => $r['created_at'], 'mine' => $mine($r), 'deleted' => false]
                    + ($reactions[(int) $r['id']] ?? ['reactions' => [], 'my_reaction' => null]);
            if ($r['parent_id'] === null) {
                $top[(int) $r['id']] = $item + ['replies' => []];
            } else {
                $replies[(int) $r['parent_id']][] = $item;
            }
        }
        foreach ($replies as $pid => $list) {
            if (isset($top[$pid])) {
                $top[$pid]['replies'] = $list;
            }
        }
        return array_values($top);
    }

    /**
     * 관리자 삭제(복구 없음).
     *  - 답글·답글 없는 댓글: 영구 삭제, comment_count 감소.
     *  - 답글이 달린 일반 댓글: 행은 남기고 본문을 비우고 deleted_at 표시("삭제된 댓글입니다"), comment_count 감소.
     *  - 삭제 표시 댓글의 마지막 답글을 지우면 그 표시 행도 영구 삭제.
     * @return array{zzira_id:int, mode:string}
     */
    public static function adminDelete(int $commentId): array
    {
        $pdo = Db::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $c = Db::row('SELECT id, zzira_id, parent_id, body, deleted_at FROM zzira_comments WHERE id = ?', [$commentId]);
            if ($c === null) {
                throw new ValidationException('댓글을 찾을 수 없습니다. 이미 삭제되었을 수 있습니다.');
            }
            if ($c['deleted_at'] !== null) {
                throw new ValidationException('이미 삭제된 댓글입니다.');
            }
            $postId = (int) $c['zzira_id'];
            $preview = mb_substr((string) $c['body'], 0, 30);
            Db::exec('DELETE FROM zzira_comment_reactions WHERE comment_id = ?', [$commentId]); // 삭제·삭제 표시 모두 공감은 지운다
            if ($c['parent_id'] === null) {
                $hasReplies = (int) Db::value('SELECT COUNT(*) FROM zzira_comments WHERE parent_id = ?', [$commentId]) > 0;
                if ($hasReplies) {
                    Db::exec("UPDATE zzira_comments SET body = '', author_key = NULL, deleted_at = ? WHERE id = ?", [Time::now(), $commentId]);
                    $mode = 'marked';
                } else {
                    Db::exec('DELETE FROM zzira_comments WHERE id = ?', [$commentId]);
                    $mode = 'deleted';
                }
            } else {
                Db::exec('DELETE FROM zzira_comments WHERE id = ?', [$commentId]);
                $mode = 'deleted';
                $parentId = (int) $c['parent_id'];
                $parentMarked = Db::value('SELECT deleted_at FROM zzira_comments WHERE id = ?', [$parentId]) !== null;
                if ($parentMarked && (int) Db::value('SELECT COUNT(*) FROM zzira_comments WHERE parent_id = ?', [$parentId]) === 0) {
                    Db::exec('DELETE FROM zzira_comments WHERE id = ?', [$parentId]); // 이미 개수에서 빠진 표시 행
                }
            }
            Db::exec('UPDATE zzira SET comment_count = MAX(0, comment_count - 1) WHERE id = ?', [$postId]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        Audit::log('comment_delete', 'zzira', $postId, '댓글 삭제(' . ($mode === 'marked' ? '답글이 있어 "삭제된 댓글"로 표시' : '영구 삭제') . '): ' . $preview);
        return ['zzira_id' => $postId, 'mode' => $mode];
    }
}
