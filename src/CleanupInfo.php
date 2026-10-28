<?php
/**
 * 관리 탭 "정리" 메뉴(6b)용 보조 정보 — 정리 로직은 Cleanup::run() 한 곳에만 있다.
 *  - items(): 결과 배열의 키 → 화면 이름(표시 순서)
 *  - total(): 영구 삭제·정리되는 항목 수(파일 오류는 제외)
 *  - last(): 마지막 정리 기록(audit_log 의 cleanup = 스케줄러, cleanup_manual = 수동)
 *  - running(): 스케줄러·수동 정리가 같은 잠금(cron_cleanup)을 잡고 있는지
 */
final class CleanupInfo
{
    public const LOCK = 'cron_cleanup';

    public const ITEMS = [
        'expired_images'      => '만료 이미지',
        'expired_attachments' => '만료 첨부',
        'temp_images'         => '임시 이미지',
        'expired_posts'       => '보관 기간 지난 찌라시',
        'purged_posts'        => '완전 삭제된 찌라시',
        'purged_files'        => '삭제된 파일',
        'temp_store'          => '임시 파일',
        'stale_sends'         => '중단된 발송',
        'scrap_records'       => '스크랩 기록',
        'file_errors'         => '파일 오류',
    ];

    /** @return list<array{key:string, label:string, count:int}> */
    public static function items(array $r): array
    {
        $out = [];
        foreach (self::ITEMS as $k => $label) {
            $out[] = ['key' => $k, 'label' => $label, 'count' => (int) ($r[$k] ?? 0)];
        }
        return $out;
    }

    public static function total(array $r): int
    {
        $n = 0;
        foreach (array_keys(self::ITEMS) as $k) {
            if ($k !== 'file_errors') {
                $n += (int) ($r[$k] ?? 0);
            }
        }
        return $n;
    }

    /** @return array{trigger:string, at:string, summary:string}|null */
    public static function last(): ?array
    {
        $row = Db::row("SELECT event, detail, created_at FROM audit_log WHERE event IN ('cleanup', 'cleanup_manual') ORDER BY id DESC LIMIT 1");
        if ($row === null) {
            return null;
        }
        $summary = preg_replace('/^(정리 배치|수동 정리)(\s*오류)?:\s*/u', '', (string) $row['detail']) ?? '';
        return ['trigger' => $row['event'] === 'cleanup_manual' ? 'manual' : 'scheduled', 'at' => $row['created_at'], 'summary' => $summary,
            'error' => str_contains((string) $row['detail'], '오류:')];
    }

    public static function running(): bool
    {
        $lock = FileLock::acquire(self::LOCK);
        if ($lock === null) {
            return true;
        }
        $lock->release();
        return false;
    }

    /** 화면 설명용 보관 기간 */
    public static function retention(): array
    {
        return [
            'post_days'    => Retention::postRetentionDays(),
            'post_text'    => Retention::postRetentionText(),
            'file_days'    => Retention::fileRetentionDays(),
            'file_text'    => Retention::fileRetentionText(),
            'deleted_days' => max(1, Config::int('DELETED_RETENTION_DAYS', 3)),
            'temp_hours'   => Cleanup::TEMP_IMAGE_HOURS,
            'max_days'     => Retention::MAX_DAYS,
        ];
    }
}
