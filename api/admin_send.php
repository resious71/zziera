<?php
/**
 * 수동 발송 실행 (POST: csrf_token, range=new|today) — 관리자 전용
 * 정기 발송·자동 발송 설정과 무관하게 동작하고 내용이 겹쳐도 허용한다. 동시에 두 발송은 하지 않는다(잠금).
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$problems = MailConfig::check();
if ($problems !== []) {
    Json::error('메일 설정이 비어 있어 발송할 수 없습니다: ' . implode(', ', $problems), 400);
}
$range = Input::choice($_POST, 'range', '범위', ['new', 'today'], 'new');
[$from, $to] = ManualSend::range($range);
session_write_close(); // 발송이 오래 걸려도 같은 세션의 다른 요청(남은 시간 조회 등)이 기다리지 않게
$r = SendService::run('manual', $from, $to);
// 실패한 묶음에는 원인별 안내를 붙인다(5c)
$auth = MailSettings::get()['smtp_auth'];
$r['batches'] = array_map(static fn (array $b) => $b + ['hint' => $b['error'] ? MailErrorHint::for((string) $b['error'], $auth) : null], $r['batches']);
$r['hint'] = $r['status'] === 'failed' ? MailErrorHint::for((string) $r['message'], $auth) : null;
Auth::ok($r + ['from' => $from, 'to' => $to]);
