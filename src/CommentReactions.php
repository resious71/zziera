<?php
/**
 * 댓글 공감(이모티콘)
 *
 *  - 한 사용자가 댓글 하나에 이모티콘 하나만 남긴다. 다른 이모티콘을 고르면 바뀌고, 같은 것을 다시 고르면 취소된다.
 *  - 쓸 수 있는 이모티콘은 Emoji::sets() 목록뿐이다.
 *  - reactor_key = HMAC(사용자ID|r|댓글ID, 앱 키). 댓글 ID 가 섞여 같은 사람의 공감끼리도 이어 볼 수 없다.
 *    사용자 ID·reactor_key 는 저장·응답·로그 어디에도 내보내지 않는다(응답에는 이모티콘별 개수와 내 공감만).
 *  - 사용자 로그인이 필요하다(관리자 세션만으로는 불가). 삭제 표시 댓글에는 공감할 수 없다. 감사 로그에 남기지 않는다.
 */
final class CommentReactions
{
    public static function reactorKey(int $userId, int $commentId): string
    {
        return hash_hmac('sha256', $userId . '|r|' . $commentId, AppKey::get());
    }

    /**
     * 글의 댓글별 공감 요약.
     * @return array<int, array{reactions: list<array{emoji:string, count:int}>, my_reaction: ?string}>
     */
    public static function forPost(int $postId, ?int $userId): array
    {
        $out = [];
        $rows = Db::all(
            'SELECT comment_id, emoji, COUNT(*) AS n, MIN(rowid) AS first FROM zzira_comment_reactions
             WHERE zzira_id = ? GROUP BY comment_id, emoji ORDER BY comment_id, n DESC, first', [$postId]);
        foreach ($rows as $r) {
            $out[(int) $r['comment_id']]['reactions'][] = ['emoji' => $r['emoji'], 'count' => (int) $r['n']];
        }
        if ($userId !== null && $out !== []) {
            foreach (Db::all('SELECT comment_id, reactor_key, emoji FROM zzira_comment_reactions WHERE zzira_id = ?', [$postId]) as $r) {
                if (hash_equals((string) $r['reactor_key'], self::reactorKey($userId, (int) $r['comment_id']))) {
                    $out[(int) $r['comment_id']]['my_reaction'] = $r['emoji'];
                }
            }
        }
        foreach ($out as $id => $v) {
            $out[$id] += ['reactions' => [], 'my_reaction' => null];
        }
        return $out;
    }

    /**
     * 공감 남기기·바꾸기·취소.
     * @return array{comment_id:int, reactions: list<array{emoji:string, count:int}>, my_reaction: ?string}
     */
    public static function react(int $commentId, string $emoji, int $userId): array
    {
        if (!Emoji::isAllowed($emoji)) {
            throw new ValidationException('목록에 있는 이모티콘만 고를 수 있습니다.');
        }
        $key = self::reactorKey($userId, $commentId);
        $pdo = Db::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $c = Db::row('SELECT c.id, c.zzira_id, c.deleted_at, z.status FROM zzira_comments c JOIN zzira z ON z.id = c.zzira_id WHERE c.id = ?', [$commentId]);
            if ($c === null || $c['status'] !== 'active') {
                throw new NotFoundException('댓글을 찾을 수 없습니다. 삭제되었을 수 있습니다.');
            }
            if ($c['deleted_at'] !== null) {
                throw new ValidationException('삭제된 댓글에는 공감할 수 없습니다.');
            }
            $current = Db::value('SELECT emoji FROM zzira_comment_reactions WHERE comment_id = ? AND reactor_key = ?', [$commentId, $key]);
            if ($current === $emoji) {
                Db::exec('DELETE FROM zzira_comment_reactions WHERE comment_id = ? AND reactor_key = ?', [$commentId, $key]);
                $mine = null;
            } elseif ($current !== null) {
                Db::exec('UPDATE zzira_comment_reactions SET emoji = ?, created_date = ? WHERE comment_id = ? AND reactor_key = ?', [$emoji, Time::today(), $commentId, $key]);
                $mine = $emoji;
            } else {
                Db::exec('INSERT INTO zzira_comment_reactions (comment_id, zzira_id, reactor_key, emoji, created_date) VALUES (?, ?, ?, ?, ?)',
                    [$commentId, (int) $c['zzira_id'], $key, $emoji, Time::today()]);
                $mine = $emoji;
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        $summary = self::forPost((int) $c['zzira_id'], null)[$commentId] ?? ['reactions' => []];
        return ['comment_id' => $commentId, 'reactions' => $summary['reactions'], 'my_reaction' => $mine];
    }
}
