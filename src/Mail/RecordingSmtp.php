<?php

namespace Mail;

use PHPMailer\PHPMailer\SMTP;

/**
 * PHPMailer SMTP 어댑터(5c): 인증이 실패한 순간의 서버 응답(예: "535 5.7.8 Username and Password not accepted")을 기억한다.
 *
 * PHPMailer 는 인증 실패 뒤 연결을 닫으면서 마지막 응답을 QUIT 응답으로 덮어써, 오류 메시지에 서버 응답 코드가 남지 않는다.
 * lib/ 를 고치지 않고 하위 클래스로 응답만 남긴다. 서버 응답에는 비밀번호가 들어 있지 않다(SMTPDebug 는 계속 0).
 */
final class RecordingSmtp extends SMTP
{
    public string $authFailureReply = '';

    public function authenticate($username, $password, $authtype = null, $OAuth = null)
    {
        $ok = parent::authenticate($username, $password, $authtype, $OAuth);
        if (!$ok) {
            $this->authFailureReply = trim(preg_replace('/\s+/', ' ', (string) $this->getLastReply()) ?? '');
        }
        return $ok;
    }
}
