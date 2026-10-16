<?php
/**
 * 관리자 인증 (POST: csrf_token, password=관리자 비밀번호)
 * 초기 상태면 오늘 날짜 yyyymmdd, 관리자가 바꾼 뒤에는 그 비밀번호만 유효(6c, AdminPassword). 어느 쪽인지는 실패 응답에 드러내지 않는다.
 * 성공: {ok, token, password_default} — 화면은 token 을 메모리에만 들고 X-Admin-Token 헤더로 보낸다(새로고침하면 다시 인증).
 * 실패 400, 잠금 423(lock_remaining 포함).
 */
define('ZZ_PUBLIC', true); // 공개 허용(로그인 전에도 관리자 인증 가능)
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Csrf::check();

$password = $_POST['password'] ?? '';
$result = Auth::login(is_string($password) ? $password : '');
if (!$result['ok']) {
    Json::error($result['message'], $result['lock_remaining'] > 0 ? 423 : 400, ['lock_remaining' => $result['lock_remaining']]);
}
Json::ok(['message' => $result['message'], 'token' => $result['token'], 'password_default' => $result['password_default'],
    'session_remaining' => Auth::ttl(), 'session_ttl' => Auth::ttl()]);
