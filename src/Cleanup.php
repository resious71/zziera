<?php
/**
 * 정리 배치 (PRD 9.2) — cli/cron_cleanup.php 가 매일 한 번 실행한다.
 *
 *  1. 등록 후 FILE_RETENTION_DAYS(기본 30일, Retention::fileRetentionDays) 지난 본문 이미지·첨부: 디스크 파일만 지우고 레코드는 남긴다(file_deleted_at 기록).
 *     본문 HTML 은 그대로 두며 출력할 때 "이미지가 만료되었습니다"/"첨부가 만료되었습니다"로 바뀐다.
 *  2. 등록되지 않은 임시 이미지(zzira_id NULL): 업로드 1일 후 파일과 레코드를 함께 삭제.
 *  3. 관리자 삭제 후 DELETED_RETENTION_DAYS(3일) 지난 찌라시: Retention::purgePostChildren(발송 이력 항목·댓글·추천·이미지·첨부) → 본 레코드 순으로
 *     한 트랜잭션에서 완전 삭제하고, 커밋한 뒤 남은 파일을 지운다(트랜잭션이 실패하면 파일은 남는다).
 *  4. 만료된 TempStore 파일(레이트 리미팅·로그인 잠금), running 으로 남은 발송 기록.
 *
 * 기준 시각은 Time::now()(--force-now 로 바꿀 수 있음). TempStore 만료는 실제 시각 기준이다.
 * 파일이 이미 없거나 지울 수 없어도 멈추지 않고 건수만 따로 센다.
 */
final class Cleanup
{
    public const TEMP_IMAGE_HOURS = 24;

    /**
     * @return array<string,int> 처리 건수
     */
    public static function run(bool $dryRun = false): array
    {
        $now = Time::now();
        $fileCut = self::ago(Retention::fileRetentionDays() . ' days');
        $tempCut = self::ago(self::TEMP_IMAGE_HOURS . ' hours');
        $deletedCut = self::ago(max(1, Config::int('DELETED_RETENTION_DAYS', 3)) . ' days');
        $r = ['expired_images' => 0, 'expired_attachments' => 0, 'temp_images' => 0, 'purged_posts' => 0,
              'purged_files' => 0, 'temp_store' => 0, 'stale_sends' => 0, 'file_errors' => 0, 'scrap_records' => 0];

        // 1. 보관 기간이 지난 파일(레코드 유지)
        foreach (['zzira_images' => 'expired_images', 'zzira_attachments' => 'expired_attachments'] as $table => $key) {
            $rows = Db::all(
                "SELECT id, file_path FROM {$table} WHERE file_deleted_at IS NULL AND zzira_id IS NOT NULL AND uploaded_at <= ?",
                [$fileCut]
            );
            foreach ($rows as $row) {
                $r[$key]++;
                if ($dryRun) {
                    continue;
                }
                if (!Upload::remove($row['file_path'])) {
                    $r['file_errors']++;
                    continue; // 다음 실행에서 다시 시도
                }
                Db::exec("UPDATE {$table} SET file_deleted_at = ? WHERE id = ?", [$now, $row['id']]);
            }
        }

        // 2. 등록되지 않은 임시 이미지(파일과 레코드 삭제)
        foreach (Db::all('SELECT id, file_path FROM zzira_images WHERE zzira_id IS NULL AND uploaded_at <= ?', [$tempCut]) as $row) {
            $r['temp_images']++;
            if ($dryRun) {
                continue;
            }
            if (!Upload::remove($row['file_path'])) {
                $r['file_errors']++;
            }
            Db::exec('DELETE FROM zzira_images WHERE id = ? AND zzira_id IS NULL', [$row['id']]);
        }

        // 3. 삭제 후 보관 기간이 지난 찌라시 완전 삭제
        $posts = Db::all("SELECT id, title FROM zzira WHERE status = 'deleted' AND deleted_at IS NOT NULL AND deleted_at <= ?", [$deletedCut]);
        foreach ($posts as $post) {
            $id = (int) $post['id'];
            $r['purged_posts']++;
            if ($dryRun) {
                $r['purged_files'] += (int) Db::value('SELECT (SELECT COUNT(*) FROM zzira_images WHERE zzira_id = ? AND file_deleted_at IS NULL) + (SELECT COUNT(*) FROM zzira_attachments WHERE zzira_id = ? AND file_deleted_at IS NULL)', [$id, $id]);
                continue;
            }
            // 하위 레코드(발송 이력 항목·댓글·추천·이미지·첨부)는 Retention::purgePostChildren 이 외래 키 순서로 지운다(5d)
            $files = Db::transaction(static function (PDO $pdo) use ($id): array {
                $files = Retention::purgePostChildren($pdo, $id);
                Db::exec("DELETE FROM zzira WHERE id = ? AND status = 'deleted'", [$id]);
                return $files;
            });
            foreach ($files as $path) {
                if (Upload::remove($path)) {
                    $r['purged_files']++;
                } else {
                    $r['file_errors']++;
                }
            }
            Audit::log('post_purge', 'zzira', $id, '삭제 후 보관 기간이 지나 완전 삭제: ' . mb_substr((string) $post['title'], 0, 80));
        }

        // 4. 임시 파일·중단된 발송(dry-run 이면 건수만 센다)
        $r['temp_store'] = TempStore::purgeExpired($dryRun);
        $r['stale_sends'] = SendService::cleanupStale($dryRun);
        // 5. 스크랩 기록(5f): 중복 방지 기록 30일, 실행 이력 90일
        $sc = Scraper::cleanup($dryRun);
        $r['scrap_records'] = $sc['seen'] + $sc['runs'];
        return $r;
    }

    /** 한 줄 요약(표준 출력·감사 로그 공용) */
    public static function summary(array $r): string
    {
        return sprintf(
            '만료 이미지 %d · 만료 첨부 %d · 임시 이미지 %d · 완전 삭제 글 %d(파일 %d) · 임시 저장 %d · 중단 발송 %d · 파일 오류 %d · 스크랩 기록 %d',
            $r['expired_images'], $r['expired_attachments'], $r['temp_images'], $r['purged_posts'],
            $r['purged_files'], $r['temp_store'], $r['stale_sends'], $r['file_errors'], $r['scrap_records'] ?? 0
        );
    }

    private static function ago(string $interval): string
    {
        return Time::nowObj()->modify('-' . $interval)->format('Y-m-d H:i:s');
    }
}
