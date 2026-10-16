<?php
/**
 * 목록 표시용 집계값(zzira.comment_count, vote_count) 점검·복구 (5d)
 *
 * 평소에는 댓글·추천 변경과 같은 트랜잭션에서 갱신한다. 어긋났을 때만 rebuild() 로 실제 행 수에 맞춘다.
 *  - comment_count: 삭제 표시되지 않은 댓글+답글 수
 *  - vote_count: 추천 수
 * 호출: install_check.php(어긋난 글 수 표시), cli/rebuild_counters.php(복구)
 */
final class Counters
{
    private const ACTUAL = "SELECT z.id, z.comment_count, z.vote_count,
            (SELECT COUNT(*) FROM zzira_comments c WHERE c.zzira_id = z.id AND c.deleted_at IS NULL) AS real_comments,
            (SELECT COUNT(*) FROM zzira_votes v WHERE v.zzira_id = z.id) AS real_votes
        FROM zzira z";

    /** 집계값이 실제와 다른 글 수 */
    public static function mismatches(): int
    {
        $n = 0;
        foreach (Db::all(self::ACTUAL) as $r) {
            if ((int) $r['comment_count'] !== (int) $r['real_comments'] || (int) $r['vote_count'] !== (int) $r['real_votes']) {
                $n++;
            }
        }
        return $n;
    }

    /** 실제 행 수로 다시 계산한다. @return int 고친 글 수 */
    public static function rebuild(): int
    {
        return (int) Db::transaction(static function (): int {
            $fixed = 0;
            foreach (Db::all(self::ACTUAL) as $r) {
                if ((int) $r['comment_count'] !== (int) $r['real_comments'] || (int) $r['vote_count'] !== (int) $r['real_votes']) {
                    Db::exec('UPDATE zzira SET comment_count = ?, vote_count = ? WHERE id = ?', [(int) $r['real_comments'], (int) $r['real_votes'], (int) $r['id']]);
                    $fixed++;
                }
            }
            return $fixed;
        });
    }
}
