<?php
/**
 * 에디터 임시 이미지(등록 전, zzira_id NULL)의 세션 소유 목록
 *
 * 누가 올렸는지는 DB에 남기지 않고 PHP 세션에만 둔다(익명성).
 * 이 목록에 있는 id 만 이 세션에서 미리보기·본문 삽입·등록 연결을 할 수 있다.
 */
final class PendingImages
{
    private const KEY = 'pending_images';
    private const MAX_KEEP = 50; // 세션에 보관하는 최대 개수(오래된 것부터 버림)

    /** @return list<int> */
    public static function all(bool $readOnly = false): array
    {
        if (!Session::hasCookie() && session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        Session::start($readOnly);
        $ids = $_SESSION[self::KEY] ?? [];
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public static function has(int $id, bool $readOnly = false): bool
    {
        return in_array($id, self::all($readOnly), true);
    }

    public static function add(int $id): void
    {
        $ids = self::all();
        $ids[] = $id;
        $_SESSION[self::KEY] = array_slice(array_values(array_unique($ids)), -self::MAX_KEEP);
    }

    /** @param list<int> $used 글에 연결된 id 들을 목록에서 뺀다 */
    public static function remove(array $used): void
    {
        $_SESSION[self::KEY] = array_values(array_diff(self::all(), $used));
    }

    /**
     * 본문에 쓰인 이미지 id 들이 "이 세션이 올렸고 아직 어느 글에도 연결되지 않은 임시 이미지"인지 확인.
     * @param list<int> $ids
     * @return list<int> 조건을 만족하지 않는 id
     */
    public static function invalid(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $mine = array_fill_keys(self::all(), true);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $unlinked = array_fill_keys(array_map('intval', array_column(
            Db::all("SELECT id FROM zzira_images WHERE zzira_id IS NULL AND id IN ({$marks})", array_values($ids)),
            'id'
        )), true);
        return array_values(array_filter($ids, static fn (int $id) => !isset($mine[$id]) || !isset($unlinked[$id])));
    }
}
