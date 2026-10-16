<?php
/**
 * 메일 발송 준비 상태 점검
 *
 * 발송에는 발신 주소·메일 서버·포트·보안이 모두 설정되어 있어야 하고 lib/PHPMailer 소스가 있어야 한다.
 * 설정이 비어 있어도 앱의 다른 기능은 정상 동작하며, 메일 화면에만 부족한 항목을 안내한다.
 */
final class MailConfig
{
    /** lib/PHPMailer 의 필수 소스 3개가 있는지 */
    public static function phpMailerAvailable(): bool
    {
        foreach (['PHPMailer.php', 'SMTP.php', 'Exception.php'] as $file) {
            if (!is_file(APP_ROOT . '/lib/PHPMailer/' . $file)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 부족하거나 잘못된 항목 목록(비어 있으면 발송 가능)
     * @return list<string>
     */
    public static function check(?array $s = null): array
    {
        $s ??= MailSettings::get();
        $problems = [];
        if (!self::phpMailerAvailable()) {
            $problems[] = 'PHPMailer 소스 없음(lib/PHPMailer 에 PHPMailer.php, SMTP.php, Exception.php 를 넣어 주세요)';
        }
        if ($s['mail_from'] === '') {
            $problems[] = '발신 주소';
        } elseif (Input::email($s['mail_from']) === null) {
            $problems[] = '발신 주소 형식 오류';
        }
        if ($s['smtp_host'] === '') {
            $problems[] = '메일 서버 주소';
        } elseif (!MailSettings::validHost($s['smtp_host'])) {
            $problems[] = '메일 서버 주소 형식 오류';
        }
        if ($s['smtp_port'] === '') {
            $problems[] = '포트';
        } elseif (!ctype_digit($s['smtp_port']) || (int) $s['smtp_port'] < 1 || (int) $s['smtp_port'] > 65535) {
            $problems[] = '포트 범위 오류(1~65535)';
        }
        if ($s['smtp_secure'] === '') {
            $problems[] = '보안 방식';
        } elseif (!in_array($s['smtp_secure'], MailSettings::SECURE_VALUES, true)) {
            $problems[] = '보안 방식 값 오류(none/tls/ssl)';
        }
        // 5c: 인증을 켰으면 ID·비밀번호가 모두 있어야 한다
        if ($s['smtp_auth']) {
            if ($s['smtp_user'] === '') {
                $problems[] = 'SMTP 인증 ID';
            }
            if ($s['pass_unreadable']) {
                $problems[] = 'SMTP 인증 비밀번호(저장된 비밀번호를 읽을 수 없습니다. 다시 입력하세요)';
            } elseif ($s['smtp_pass'] === '') {
                $problems[] = 'SMTP 인증 비밀번호';
            }
        }
        return $problems;
    }

    public static function ready(): bool
    {
        return self::check() === [];
    }
}
