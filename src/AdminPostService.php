<?php
/**
 * 관리자 찌라시 관리 (PRD 4장 H-2, H-2a, H-2b)
 *
 * 등록된 찌라시는 수정할 수 없다(관리자 포함). 여기에는 목록·삭제·복구만 둔다.
 *  - 삭제: status='deleted', deleted_at 기록 → 즉시 일반 조회·발송 대상에서 제외
 *  - 복구: 삭제 후 DELETED_RETENTION_DAYS(3일) 이내만. status='active', deleted_at=NULL.
 *          이미 만료된 이미지·첨부 파일은 되돌리지 않는다(출력 시 "이미지가 만료되었습니다").
 *  - 상단 고정(5단계): 관리자가 지정한 글을 "찌라시 보기" 맨 위에 고정. 최대 PIN_MAX_COUNT 건.
 *          삭제하면 고정도 풀리고, 복구해도 고정은 되살리지 않는다.
 */
final class AdminPostService
{
    public const PER_PAGE = 20;

    public static function retentionSeconds(): int
    {
        return max(1, Config::int('DELETED_RETENTION_DAYS', 3)) * 86400;
    }

    /**
     * 전체 목록(날짜 범위·제목 검색·상태 필터, 20건씩)
     * @param array{from?:string, to?:string, q?:string, status?:string} $f
     */
    public static function list(array $f, int $page): array
    {
        $where = [];
        $params = [];
        if (!empty($f['from'])) {
            $where[] = 'z.created_at >= ?';
            $params[] = Time::dayRange($f['from'])[0];
        }
        if (!empty($f['to'])) {
            $where[] = 'z.created_at < ?';
            $params[] = Time::dayRange($f['to'])[1];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = "z.title LIKE ? ESCAPE '\\'";
            $params[] = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
        if (in_array($f['status'] ?? '', ['active', 'deleted'], true)) {
            $where[] = 'z.status = ?';
            $params[] = $f['status'];
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM zzira z {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = Db::all(
            "SELECT z.id, z.title, z.status, z.created_at, z.deleted_at, z.has_image, z.has_table, z.has_link, z.is_pinned, z.pinned_at, z.comment_count, z.vote_count, z.source,
                    (SELECT COUNT(*) FROM zzira_attachments a WHERE a.zzira_id = z.id) AS attachment_count
             FROM zzira z {$whereSql}
             ORDER BY z.created_at DESC, z.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        );
        return [
            'items' => array_map([self::class, 'row'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages,
            'pinned_count' => self::pinnedCount(), 'pin_max' => self::pinMax(),
        ];
    }

    /** 삭제 후 복구 가능 기간(3일) 안의 글만, 남은 시간 포함 */
    public static function deletedList(): array
    {
        $since = Time::shift(Time::now(), -self::retentionSeconds());
        $rows = Db::all(
            "SELECT z.id, z.title, z.status, z.created_at, z.deleted_at, z.has_image, z.has_table, z.has_link, z.is_pinned, z.pinned_at, z.comment_count, z.vote_count, z.source,
                    (SELECT COUNT(*) FROM zzira_attachments a WHERE a.zzira_id = z.id) AS attachment_count
             FROM zzira z WHERE z.status = 'deleted' AND z.deleted_at > ? ORDER BY z.deleted_at DESC",
            [$since]
        );
        return array_map([self::class, 'row'], $rows);
    }

    public static function delete(int $id): void
    {
        $post = self::mustFind($id);
        if ($post['status'] === 'deleted') {
            throw new ValidationException('이미 삭제된 찌라시입니다.');
        }
        // 삭제하면 고정도 해제한다(복구해도 고정은 되살리지 않음)
        Db::exec("UPDATE zzira SET status = 'deleted', deleted_at = ?, is_pinned = 0, pinned_at = NULL WHERE id = ? AND status = 'active'", [Time::now(), $id]);
        Audit::log('post_delete', 'zzira', $id, $post['title']);
    }

    public static function restore(int $id): void
    {
        $post = self::mustFind($id);
        if ($post['status'] !== 'deleted') {
            throw new ValidationException('삭제된 찌라시가 아닙니다.');
        }
        if (self::restoreRemaining($post['deleted_at']) <= 0) {
            throw new ValidationException('삭제 후 ' . Config::int('DELETED_RETENTION_DAYS', 3) . '일이 지나 복구할 수 없습니다.');
        }
        Db::exec("UPDATE zzira SET status = 'active', deleted_at = NULL WHERE id = ? AND status = 'deleted'", [$id]);
        Audit::log('post_restore', 'zzira', $id, $post['title']);
    }

    public static function pinMax(): int
    {
        return max(1, Config::int('PIN_MAX_COUNT', 5));
    }

    public static function pinnedCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM zzira WHERE is_pinned = 1 AND status = 'active'");
    }

    /**
     * 상단 고정/해제. 이미 같은 상태면 오류 없이 현재 상태를 돌려준다.
     * @return array{id:int, is_pinned:bool, pinned_at:?string, changed:bool}
     */
    public static function pin(int $id, bool $pinned): array
    {
        return Db::transaction(static function () use ($id, $pinned): array {
            $post = Db::row('SELECT id, title, status, is_pinned, pinned_at FROM zzira WHERE id = ?', [$id]);
            if ($post === null) {
                throw new ValidationException('찌라시를 찾을 수 없습니다.');
            }
            $current = (int) $post['is_pinned'] === 1;
            if ($current === $pinned) {
                return ['id' => $id, 'is_pinned' => $current, 'pinned_at' => $post['pinned_at'], 'changed' => false];
            }
            if ($pinned) {
                if ($post['status'] !== 'active') {
                    throw new ValidationException('삭제된 찌라시는 고정할 수 없습니다.');
                }
                $max = self::pinMax();
                if (self::pinnedCount() >= $max) {
                    throw new ValidationException("고정은 최대 {$max}건까지 가능합니다. 다른 글의 고정을 해제해 주세요.");
                }
                $at = Time::now();
                Db::exec('UPDATE zzira SET is_pinned = 1, pinned_at = ? WHERE id = ?', [$at, $id]);
                Audit::log('post_pin', 'zzira', $id, $post['title']);
                return ['id' => $id, 'is_pinned' => true, 'pinned_at' => $at, 'changed' => true];
            }
            Db::exec('UPDATE zzira SET is_pinned = 0, pinned_at = NULL WHERE id = ?', [$id]);
            Audit::log('post_unpin', 'zzira', $id, $post['title']);
            return ['id' => $id, 'is_pinned' => false, 'pinned_at' => null, 'changed' => true];
        });
    }

    /** 복구 가능한 남은 시간(초) */
    public static function restoreRemaining(?string $deletedAt): int
    {
        if ($deletedAt === null) {
            return 0;
        }
        return max(0, self::retentionSeconds() - Time::diffSeconds($deletedAt, Time::now()));
    }

    private static function row(array $r): array
    {
        return [
            'id'                => (int) $r['id'],
            'title'             => $r['title'],
            'status'            => $r['status'],
            'created_at'        => $r['created_at'],
            'deleted_at'        => $r['deleted_at'],
            'restore_remaining' => $r['status'] === 'deleted' ? self::restoreRemaining($r['deleted_at']) : null,
            'has_image'         => (bool) $r['has_image'],
            'has_table'         => (bool) $r['has_table'],
            'has_link'          => (bool) $r['has_link'],
            'attachment_count'  => (int) $r['attachment_count'],
            'is_pinned'         => (int) ($r['is_pinned'] ?? 0) === 1,
            'pinned_at'         => $r['pinned_at'] ?? null,
            'comment_count'     => (int) ($r['comment_count'] ?? 0),
            'vote_count'        => (int) ($r['vote_count'] ?? 0),
            'source'            => (string) ($r['source'] ?? 'user'),
        ];
    }

    private static function mustFind(int $id): array
    {
        $post = Db::row('SELECT id, title, status, deleted_at FROM zzira WHERE id = ?', [$id]);
        if ($post === null) {
            throw new ValidationException('찌라시를 찾을 수 없습니다.');
        }
        return $post;
    }
}
