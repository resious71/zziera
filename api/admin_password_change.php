<?php
/**
 * 관리자 비밀번호 변경 (6c, POST: csrf_token, current, new_password, new_password_confirm, 헤더 X-Admin-Token)
 * 현재 비밀번호를 틀리면 로그인 실패와 같은 전역 카운터에 합산(5회 → 5분 잠금, 잠금 중 423).
 * 성공하면 다른 관리자 세션은 끊기고 이 화면만 유지된다. 비밀번호는 응답·로그에 넣지 않는다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$result = Auth::changePassword($str('current'), $str('new_password'), $str('new_password_confirm'));
if (!$result['ok']) {
    Json::error($result['message'], $result['lock_remaining'] > 0 ? 423 : 400, ['lock_remaining' => $result['lock_remaining']]);
}
Auth::ok(['message' => $result['message'], 'password_default' => false]);
