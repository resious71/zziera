<?php
/**
 * 관리자 비밀번호 분실 복구(6c): 초기 상태(오늘 날짜 yyyymmdd)로 되돌린다. 서버 PC에서만 실행한다.
 *   C:\xampp\php\php.exe cli\reset_admin_password.php
 *  - admin_pass_hash 삭제, admin_pass_version +1(열려 있던 관리자 세션 종료), 관리자 로그인 잠금 초기화
 *  - 비밀번호를 인자로 받아 설정하는 기능은 두지 않는다(셸 기록에 남음). 로그인한 뒤 화면에서 바로 새로 설정한다.
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}
AdminPassword::resetToDefault();
Auth::clearLock();
Audit::log('admin_password_reset_cli', 'admin', null, '관리자 비밀번호 초기화(CLI)');
echo '초기 상태(날짜 비밀번호)로 되돌렸습니다. 로그인 후 즉시 새 비밀번호를 설정하세요' . PHP_EOL;
