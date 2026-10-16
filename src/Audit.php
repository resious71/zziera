<?php
/**
 * 감사 로그(audit_log)
 *
 * 등록자 식별 정보(IP, 세션 ID, User-Agent, 이메일 원문 등)는 절대 넣지 않는다.
 * 감사 로그 기록 실패가 본 작업을 막아서는 안 되므로 예외는 서버 로그로만 남긴다.
 */
final class Audit
{
    private const DETAIL_MAX = 500;

    public static function log(string $event, ?string $targetType = null, ?int $targetId = null, ?string $detail = null): void
    {
        try {
            if ($detail !== null && mb_strlen($detail) > self::DETAIL_MAX) {
                $detail = mb_substr($detail, 0, self::DETAIL_MAX) . '…';
            }
            Db::exec(
                'INSERT INTO audit_log (event, target_type, target_id, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$event, $targetType, $targetId, $detail, Time::now()]
            );
        } catch (Throwable $e) {
            error_log('[ZZieRa] audit log 실패: ' . $e->getMessage());
        }
    }

    /** 이메일을 감사 로그용으로 마스킹: abcdef@corp.com → ab****@corp.com */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return '****';
        }
        $local = substr($email, 0, $at);
        return substr($local, 0, min(2, strlen($local))) . '****' . substr($email, $at);
    }
}
