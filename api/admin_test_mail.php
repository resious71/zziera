<?php
/**
 * 테스트 메일 (POST: csrf_token, email) — 관리자 전용
 * 입력한 주소 1건으로 현재 메일 설정을 점검한다(한글 제목·본문 샘플). 실패하면 PHPMailer 오류 원문과
 * 원인별 안내(hint, MailErrorHint)를 보여 준다. 오류 원문에는 비밀번호가 들어가지 않는다(SMTPDebug 0).
 * send_log 에는 남기지 않고 audit_log 에만 남긴다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$email = Input::email(Input::string($_POST, 'email', '받는 주소', 254));
if ($email === null) {
    Json::error('받는 주소의 이메일 형식이 올바르지 않습니다.');
}
if (!MailConfig::phpMailerAvailable()) {
    Json::error('lib/PHPMailer 에 PHPMailer 소스(PHPMailer.php, SMTP.php, Exception.php)를 넣어 주세요.');
}
$problems = MailConfig::check();
if (MailSettings::get()['pass_unreadable']) {
    Json::error('저장된 비밀번호를 읽을 수 없습니다. 다시 입력하세요(메일 설정 → SMTP 인증).', 400, ['problems' => $problems]);
}
session_write_close();

$appName = (string) Config::get('APP_NAME', 'ZZieRa');
$subject = '[' . $appName . '] 테스트 메일 — 한글 제목 확인 ✓';
$sample = [[
    'id'         => 0,
    'title'      => '테스트 찌라시 제목(한글·특수문자 <>&"\' 확인)',
    'summary'    => '메일 설정 점검용 샘플 요약입니다. 이 메일이 깨지지 않고 보이면 설정이 정상입니다.',
    'created_at' => Time::now(),
]];
try {
    $digest = DigestBuilder::build($sample, Time::now(), Time::now());
    $digest['subject'] = $subject;
    Mail\MailerFactory::create()->send($email, [], $subject, $digest['html'], $digest['text']);
} catch (Throwable $e) {
    Audit::log('mail_test', 'settings', null, '테스트 메일 실패(' . Audit::maskEmail($email) . '): ' . mb_substr($e->getMessage(), 0, 300));
    Json::error(
        '테스트 메일을 보내지 못했습니다: ' . $e->getMessage() . ($problems !== [] ? "\n(설정 점검: " . implode(', ', $problems) . ')' : ''),
        502,
        ['problems' => $problems, 'hint' => MailErrorHint::for($e->getMessage(), MailSettings::get()['smtp_auth'])]
    );
}
Audit::log('mail_test', 'settings', null, '테스트 메일 보냄(' . Audit::maskEmail($email) . ')');
Auth::ok(['message' => $email . ' 주소로 테스트 메일을 보냈습니다. 받은편지함(스팸함 포함)에서 한글이 깨지지 않았는지 확인하세요.']);
