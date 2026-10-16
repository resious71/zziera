<?php
/**
 * 메일 발송 오류 → 원인별 안내 (5c)
 *
 * 테스트 메일·발송 결과·발송 이력 상세에서 PHPMailer 오류 원문 옆에 덧붙인다.
 * 영어 원문(서버 응답)과 PHPMailer 한국어 메시지를 모두 본다. 순서가 중요하다(535 를 530 보다 먼저).
 */
final class MailErrorHint
{
    public const AUTH_REQUIRED = '서버가 로그인을 요구합니다. \'메일 설정\'에서 \'SMTP 인증 사용\'을 켜고 ID·비밀번호를 입력하세요(Gmail·네이버는 앱 비밀번호 또는 SMTP 사용 설정 필요).';
    public const AUTH_FAILED = 'ID 또는 비밀번호가 올바르지 않습니다. 2단계 인증을 쓰면 일반 비밀번호가 아니라 앱 비밀번호를 사용하세요. ID 형식(아이디만 / 전체 주소)도 확인하세요.';
    public const CONNECT = '서버 주소·포트·보안 방식과 방화벽을 확인하세요.';
    public const CERT = '서버 인증서를 확인하세요. 사내 서버라면 \'자체 서명 인증서 허용\'을 검토하세요.';

    /**
     * @param bool $authEnabled 지금 SMTP 인증을 쓰고 있는지(530 일 때 "인증 사용을 켜라" 링크를 보일지)
     * @return array{kind:string, text:string, focus:?string}|null
     */
    public static function for(string $error, bool $authEnabled = true): ?array
    {
        $e = $error;
        $has = static fn (string $re) => (bool) preg_match($re, $e);

        if ($has('/\b535\b|Username and Password not accepted|authentication failed|Invalid credentials|bad credentials|인증할 수 없습니다/i')) {
            // 인증을 쓰지 않았는데 "인증할 수 없습니다"가 나오면 실제로는 로그인 요구(530)인 경우다
            if (!$authEnabled && !$has('/\b535\b|Username and Password not accepted|bad credentials/i')) {
                return ['kind' => 'auth_required', 'text' => self::AUTH_REQUIRED, 'focus' => 'smtp_auth'];
            }
            return ['kind' => 'auth_failed', 'text' => self::AUTH_FAILED, 'focus' => null];
        }
        if ($has('/\b530\b|Authentication Required|authenticat/i')) {
            return ['kind' => 'auth_required', 'text' => self::AUTH_REQUIRED, 'focus' => $authEnabled ? null : 'smtp_auth'];
        }
        if ($has('/certificate|SSL routines|ssl3_|tls_process|verify failed|peer name|self[- ]signed|인증서/i')) {
            return ['kind' => 'cert', 'text' => self::CERT, 'focus' => null];
        }
        if ($has('/timed out|time-out|timeout|Connection refused|Failed to connect|could not connect|connect\(\)|getaddrinfo|php_network|\b1006[01]\b|호스트에 접속할 수 없습니다|연결을 거부|응답하지 않/i')) {
            return ['kind' => 'connect', 'text' => self::CONNECT, 'focus' => null];
        }
        return null;
    }
}
