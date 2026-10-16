<?php

namespace Mail;

use MailConfig;
use MailSettings;
use RuntimeException;

/**
 * 메일러 생성. MailSettings(화면 설정 + config.php 의 SMTP_USER/PASS)로 PhpMailerMailer 를 만든다.
 * MAIL_DRIVER 설정은 없다(PHPMailer SMTP 만 사용).
 */
final class MailerFactory
{
    /** 테스트용으로 다른 메일러를 끼워 넣을 수 있다(null 이면 PHPMailer) */
    private static ?Mailer $override = null;

    public static function create(): Mailer
    {
        if (self::$override !== null) {
            return self::$override;
        }
        if (!MailConfig::phpMailerAvailable()) {
            throw new RuntimeException('lib/PHPMailer 에 PHPMailer 소스(PHPMailer.php, SMTP.php, Exception.php)를 넣어 주세요.');
        }
        return new PhpMailerMailer(MailSettings::get());
    }

    public static function setOverride(?Mailer $mailer): void
    {
        self::$override = $mailer;
    }
}
