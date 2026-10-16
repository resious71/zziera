<?php
/**
 * 5c 단위 테스트: 메일 오류 → 원인별 안내(MailErrorHint), 비밀번호 암호화(SecretBox)
 *
 *   C:\xampp\php\php.exe cli\test_mail_settings.php
 *
 * 실패가 하나라도 있으면 종료 코드 1. SecretBox 시험은 이 폴더의 data/app.key 를 쓴다(없으면 만든다).
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}

$pass = 0;
$fail = 0;
function t(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? "  → {$detail}" : '') . PHP_EOL;
}

echo "── MailErrorHint\n";
$cases = [
    // [오류 문자열, 인증 사용 여부, 기대 kind, 기대 focus]
    ['다음 From 주소에서 오류가 발생했습니다: a@naver.com : MAIL FROM command failed,Authentication Required ,530,5.5.1 SMTP 서버 오류: MAIL FROM command failed Detail: Authentication Required SMTP code: 530', false, 'auth_required', 'smtp_auth'],
    ['SMTP 서버 오류: MAIL FROM command failed Detail: authentication required SMTP code: 530 Additional SMTP info: 5.7.0', true, 'auth_required', null],
    ['SMTP 오류: 인증할 수 없습니다. SMTP 서버 오류: 535 5.7.8 Username and Password not accepted. Learn more at https://support.google.com/mail', true, 'auth_failed', null],
    ['SMTP Error: Could not authenticate. 535 5.7.8 bad credentials', true, 'auth_failed', null],
    ['SMTP 오류: 인증할 수 없습니다.', true, 'auth_failed', null],
    ['SMTP 오류: 인증할 수 없습니다.', false, 'auth_required', 'smtp_auth'],
    ['SMTP 오류: SMTP 호스트에 접속할 수 없습니다. Failed to connect to server SMTP code: 10061 대상 컴퓨터에서 연결을 거부했으므로 연결하지 못했습니다', false, 'connect', null],
    ['SMTP Error: Could not connect to SMTP host. Connection timed out', true, 'connect', null],
    ['stream_socket_enable_crypto(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed', true, 'cert', null],
    ['SMTP 오류: 데이터가 받아들여지지 않았습니다. 554 5.7.1 rejected', true, null, null],
];
foreach ($cases as [$err, $auth, $kind, $focus]) {
    $h = MailErrorHint::for($err, $auth);
    $ok = $kind === null ? $h === null : ($h !== null && $h['kind'] === $kind && $h['focus'] === $focus);
    t($ok, ($kind ?? '안내 없음') . ($auth ? ' (인증 사용)' : ' (인증 안 씀)') . ': ' . mb_substr($err, 0, 50), $h === null ? 'null' : $h['kind'] . '/' . var_export($h['focus'], true));
}
$h = MailErrorHint::for('Authentication Required 530', false);
t(str_contains($h['text'], "'SMTP 인증 사용'을 켜고") && str_contains($h['text'], '앱 비밀번호'), '530 안내 문구');
$h = MailErrorHint::for('535 Username and Password not accepted', true);
t(str_contains($h['text'], '앱 비밀번호') && str_contains($h['text'], 'ID 형식'), '535 안내 문구');

echo "── SecretBox\n";
if (SecretBox::unavailableReason() !== null) {
    t(false, '암호화 사용 가능', (string) SecretBox::unavailableReason());
} else {
    $secret = 'p@ss 한글 ' . bin2hex(random_bytes(4));
    $c1 = SecretBox::encrypt($secret);
    $c2 = SecretBox::encrypt($secret);
    t(str_starts_with($c1, 'v1:') && !str_contains($c1, $secret), '암호문 형식(v1:…), 평문 없음');
    t($c1 !== $c2, '같은 값도 저장마다 다른 암호문(새 IV)');
    t(SecretBox::decrypt($c1) === $secret && SecretBox::decrypt($c2) === $secret, '복호화 왕복');
    $raw = base64_decode(substr($c1, 3));
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    t(SecretBox::decrypt('v1:' . base64_encode($raw)) === null, '암호문 변조 → null(인증 태그 검사)');
    t(SecretBox::decrypt('plain-text') === null && SecretBox::decrypt('v1:!!!') === null, '형식이 다른 값 → null');
    t(is_file(SecretBox::keyPath()) && filesize(SecretBox::keyPath()) === 32, 'data/app.key 32바이트');
}

echo "\n결과: 통과 {$pass} / 실패 {$fail}\n";
exit($fail > 0 ? 1 : 0);
