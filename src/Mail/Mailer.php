<?php

namespace Mail;

/**
 * 메일 한 통 보내기. 실패하면 예외(메시지에 원인).
 */
interface Mailer
{
    /**
     * @param string       $to   받는 사람(BCC 묶음 메일에서는 MAIL_TO_PLACEHOLDER)
     * @param list<string> $bcc  숨은 참조 수신자
     */
    public function send(string $to, array $bcc, string $subject, string $html, string $text): void;
}
