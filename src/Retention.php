<?php
/**
 * 보관·완전 삭제 공통 규칙 (5d)
 *
 *  - fileRetentionDays(): 본문 이미지·첨부 보관 일수(FILE_RETENTION_DAYS, 기본 30). 삭제 글 복구 기간(3일)과는 별개.
 *  - fileRetentionText(): 화면 문구용("한 달(30일)" / "N일"). 설정을 바꾸면 문구도 따라간다.
 *  - purgePostChildren(): 글을 완전 삭제하기 전에 하위 레코드를 외래 키 순서대로 지운다(호출하는 쪽 트랜잭션 안에서).
 */
final class Retention
{
    public static function fileRetentionDays(): int
    {
        return max(1, Config::int('FILE_RETENTION_DAYS', 30));
    }

    public static function fileRetentionText(): string
    {
        $d = self::fileRetentionDays();
        return $d === 30 ? '한 달(30일)' : $d . '일';
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
