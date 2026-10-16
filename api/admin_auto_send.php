<?php
/**
 * 메일 발송 화면 상태 + 자동 발송(매일 07:00) 켜기/끄기 (관리자 전용)
 *  GET : 자동 발송 상태, 마지막 변경 일시, 메일 설정 점검 결과, 수신자 수, 직전 발송
 *  POST: enabled=1|0, resume=include|from_now(켤 때)
 * 메일 설정이 비어 있으면 켤 수 없다. 수동 발송·테스트 메일은 이 설정과 무관하다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

function autoStatus(): array
{
    $row = AppSettings::row(ScheduledSend::AUTO_KEY);
    return [
        'enabled'      => ScheduledSend::autoEnabled(),
        'changed_at'   => $row['updated_at'] ?? null,
        'resume_from'  => AppSettings::get(ScheduledSend::AUTO_FROM_KEY),
        'send_time'    => (string) Config::get('SEND_TIME', '07:00'),
        'deadline'     => (string) Config::get('SEND_DEADLINE', '12:00'),
        'problems'     => MailConfig::check(),
        'recipients'   => count(MemberRepository::activeEmails()),
        'last_send'    => Db::row('SELECT id, trigger_type, status, sent_at FROM send_log ORDER BY id DESC LIMIT 1'),
        'base_url_set' => trim((string) Config::get('BASE_URL', '')) !== '',
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $enable = Input::choice($_POST, 'enabled', '켜기/끄기', ['1', '0']) === '1';
    if ($enable) {
        $problems = MailConfig::check();
        if ($problems !== []) {
            Json::error('메일 설정이 비어 있어 자동 발송을 켤 수 없습니다: ' . implode(', ', $problems), 400);
        }
        ScheduledSend::setEnabled(true, Input::choice($_POST, 'resume', '다시 켤 때 범위', ['include', 'from_now'], 'include'));
        Auth::ok(['message' => '자동 발송을 켰습니다.'] + autoStatus());
    }
    ScheduledSend::setEnabled(false);
    Auth::ok(['message' => '자동 발송을 껐습니다. 다시 켤 때까지 정기 메일이 발송되지 않습니다.'] + autoStatus());
}
Auth::ok(autoStatus());
