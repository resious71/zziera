<?php
/**
 * 보관·완전 삭제 공통 규칙 (5d)
 *
 *  - postRetentionDays(): 찌라시 보관 일수. 등록 후 이 기간이 지난 글은 정리 배치가 완전 삭제한다(상단 고정 글 제외).
 *  - fileRetentionDays(): 본문 이미지·첨부 보관 일수. 삭제 글 복구 기간(3일)과는 별개.
 *    두 값 모두 관리 탭 "정리"에서 바꾸며(app_settings), 저장한 적이 없으면 config.php 의
 *    POST_RETENTION_DAYS·FILE_RETENTION_DAYS(기본 180일 = 6개월)를 쓴다. 범위 1일~MAX_DAYS(3년).
 *  - daysText(): 화면 문구용("6개월(180일)" / "1년(365일)" / "N일"). 설정을 바꾸면 문구도 따라간다.
 *  - purgePostChildren(): 글을 완전 삭제하기 전에 하위 레코드를 외래 키 순서대로 지운다(호출하는 쪽 트랜잭션 안에서).
 */
final class Retention
{
    public const MAX_DAYS = 1095; // 3년
    public const DEFAULT_DAYS = 180; // 6개월
    public const POST_KEY = 'post_retention_days';
    public const FILE_KEY = 'file_retention_days';

    public static function postRetentionDays(): int
    {
        return self::days(self::POST_KEY, 'POST_RETENTION_DAYS');
    }

    public static function fileRetentionDays(): int
    {
        return self::days(self::FILE_KEY, 'FILE_RETENTION_DAYS');
    }

    public static function postRetentionText(): string
    {
        return self::daysText(self::postRetentionDays());
    }

    public static function fileRetentionText(): string
    {
        return self::daysText(self::fileRetentionDays());
    }

    /** 관리 화면에서 저장(범위 검사는 호출하는 쪽에서 Input::int 로 한다) */
    public static function save(int $postDays, int $fileDays): void
    {
        AppSettings::set(self::POST_KEY, (string) self::clamp($postDays));
        AppSettings::set(self::FILE_KEY, (string) self::clamp($fileDays));
    }

    /** 365일 단위면 "N년(D일)", 30일 단위면 "N개월(D일)", 그 밖에는 "D일" */
    public static function daysText(int $d): string
    {
        if ($d % 365 === 0) {
            return intdiv($d, 365) . "년({$d}일)";
        }
        if ($d % 30 === 0) {
            return intdiv($d, 30) . "개월({$d}일)";
        }
        return $d . '일';
    }

    private static function days(string $key, string $configKey): int
    {
        $v = AppSettings::get($key);
        $d = $v !== null && ctype_digit($v) ? (int) $v : Config::int($configKey, self::DEFAULT_DAYS);
        return self::clamp($d);
    }

    private static function clamp(int $d): int
    {
        return min(self::MAX_DAYS, max(1, $d));
    }

    /** 이 업로드 시각의 파일이 지금 만료되었는지(보관 일수 기준) */
    public static function isFileExpired(string $uploadedAt): bool
    {
        $cut = Time::nowObj()->modify('-' . self::fileRetentionDays() . ' days')->format('Y-m-d H:i:s');
        return $uploadedAt <= $cut;
    }

    /**
     * 글의 하위 레코드 삭제: 발송 이력 항목 → 댓글 공감 → 답글 → 댓글 → 추천 → 이미지·첨부 레코드.
     * 파일은 지우지 않는다(호출하는 쪽이 커밋 후 지운다). 반드시 트랜잭션 안에서 호출한다.
     * @return list<string> 커밋 후 지울 파일 경로(storage/uploads 기준, 아직 지워지지 않은 것)
     */
    public static function purgePostChildren(PDO $pdo, int $postId): array
    {
        $files = [];
        foreach (['zzira_images', 'zzira_attachments'] as $t) {
            $st = $pdo->prepare("SELECT file_path FROM {$t} WHERE zzira_id = ? AND file_deleted_at IS NULL");
            $st->execute([$postId]);
            $files = array_merge($files, $st->fetchAll(PDO::FETCH_COLUMN));
        }
        foreach ([
            'DELETE FROM send_log_items WHERE zzira_id = ?',
            'DELETE FROM zzira_comment_reactions WHERE zzira_id = ?',
            'DELETE FROM zzira_comments WHERE zzira_id = ? AND parent_id IS NOT NULL',
            'DELETE FROM zzira_comments WHERE zzira_id = ?',
            'DELETE FROM zzira_votes WHERE zzira_id = ?',
            'DELETE FROM zzira_images WHERE zzira_id = ?',
            'DELETE FROM zzira_attachments WHERE zzira_id = ?',
        ] as $sql) {
            $pdo->prepare($sql)->execute([$postId]);
        }
        return $files;
    }
}
