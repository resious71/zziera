<?php
/**
 * 찌라시 추천 (5d) — 추천만 있고 비추천은 없다. 한 사용자가 한 글에 한 번, 다시 누르면 취소.
 *
 *  - vote_key = HMAC(사용자ID|글ID, 앱 키). 사용자 ID 는 저장하지 않고 중복 추천 방지에만 쓴다.
 *    댓글의 author_key(…|c|…)와 다른 문자열이라 둘을 이어 볼 수 없다.
 *  - 누가 추천했는지 목록은 없다. 응답에는 my_vote 와 vote_count 만 내보낸다.
 *  - 추천은 사용자 로그인이 필요하다(관리자 세션만으로는 불가). 감사 로그에 남기지 않는다.
 */
final class Votes
{
    public static function voteKey(int $userId, int $postId): string
    {
        return hash_hmac('sha256', $userId . '|' . $postId, AppKey::get());
    }

    /** @return array{my_vote:bool, vote_count:int} */
    public static function status(int $postId, ?int $userId): array
    {
        $count = (int) Db::value('SELECT vote_count FROM zzira WHERE id = ?', [$postId]);
        $mine = $userId !== null
            && Db::value('SELECT 1 FROM zzira_votes WHERE zzira_id = ? AND vote_key = ?', [$postId, self::voteKey($userId, $postId)]) !== null;
        return ['my_vote' => $mine, 'vote_count' => $count];
    }

    /** 추천/취소 전환. @return array{voted:bool, vote_count:int} */
    public static function toggle(int $postId, int $userId): array
    {
        $key = self::voteKey($userId, $postId);
        $pdo = Db::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $post = Db::row('SELECT status FROM zzira WHERE id = ?', [$postId]);
            if ($post === null || $post['status'] !== 'active') {
                throw new NotFoundException('찌라시를 찾을 수 없습니다. 삭제되었을 수 있습니다.');
            }
            $exists = Db::value('SELECT 1 FROM zzira_votes WHERE zzira_id = ? AND vote_key = ?', [$postId, $key]) !== null;
            if ($exists) {
                Db::exec('DELETE FROM zzira_votes WHERE zzira_id = ? AND vote_key = ?', [$postId, $key]);
                Db::exec('UPDATE zzira SET vote_count = MAX(0, vote_count - 1) WHERE id = ?', [$postId]);
            } else {
                Db::exec('INSERT INTO zzira_votes (zzira_id, vote_key, created_date) VALUES (?, ?, ?)', [$postId, $key, Time::today()]);
                Db::exec('UPDATE zzira SET vote_count = vote_count + 1 WHERE id = ?', [$postId]);
            }
            $count = (int) Db::value('SELECT vote_count FROM zzira WHERE id = ?', [$postId]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return ['voted' => !$exists, 'vote_count' => $count];
    }
}
