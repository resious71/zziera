<?php

namespace Mail;

use MailSettings;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use RuntimeException;

/**
 * PHPMailer(SMTP) 구현 — 이 앱의 유일한 메일 발송 방식(PHP mail() 은 쓰지 않는다).
 *
 *  - UTF-8, multipart/alternative(text/plain + text/html), base64
 *  - 보안: none(암호화 없음, SMTPAutoTLS 끔) / tls(STARTTLS) / ssl(SMTPS)
 *  - 인증: 설정(MailSettings::get)의 smtp_auth 가 켜져 있으면 ID·비밀번호로 로그인, 꺼져 있으면 인증 없이 접속
 *  - SMTPDebug 는 항상 0(비밀번호가 출력·로그에 남지 않게)
 *  - 이메일·제목·이름의 개행은 지운다(헤더 인젝션 방지)
 *  - 실패하면 PHPMailer::ErrorInfo 를 예외 메시지로 전달한다
 */
final class PhpMailerMailer implements Mailer
{
    public function __construct(private array $settings)
    {
    }

    public function send(string $to, array $bcc, string $subject, string $html, string $text): void
    {
        $s = $this->settings;
        $mail = new PHPMailer(true);
        $smtpClient = new RecordingSmtp();
        $mail->setSMTPInstance($smtpClient);
        $mail->setLanguage('ko', APP_ROOT . '/lib/PHPMailer/language/');
        try {
            $mail->isSMTP();
            $mail->Host = self::oneLine($s['smtp_host']);
            $mail->Port = (int) $s['smtp_port'];
            $mail->Timeout = (int) $s['timeout'];
            $mail->SMTPDebug = 0;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            if ($s['smtp_secure'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($s['smtp_secure'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false; // "없음"을 골랐으면 서버가 STARTTLS 를 알려도 쓰지 않는다
            }
            if ($s['smtp_auth']) {
                $mail->SMTPAuth = true;
                $mail->Username = $s['smtp_user'];
                $mail->Password = $s['smtp_pass'];
            } else {
                $mail->SMTPAuth = false;
            }
            if ($s['allow_self_signed']) {
                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            }

            $mail->setFrom(self::oneLine($s['mail_from']), self::oneLine(MailSettings::fromName($s)), false);
            $mail->addAddress(self::oneLine($to));
            foreach ($bcc as $address) {
                $mail->addBCC(self::oneLine($address));
            }
            $mail->Subject = self::oneLine($subject);
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
        } catch (PHPMailerException $e) {
            $msg = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            // 인증 실패는 ErrorInfo 에 서버 응답 코드가 빠지므로 그 순간의 서버 응답을 덧붙인다(비밀번호는 들어 있지 않다)
            if ($smtpClient->authFailureReply !== '' && !str_contains($msg, $smtpClient->authFailureReply)) {
                $msg .= ' (서버 응답: ' . mb_substr($smtpClient->authFailureReply, 0, 300) . ')';
            }
            throw new RuntimeException($msg, 0, $e);
        } finally {
            $mail->smtpClose();
        }
    }

    /** 헤더에 들어가는 값의 개행·제어 문자 제거 */
    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    }
}
