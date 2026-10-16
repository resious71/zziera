<?php
/**
 * 로그아웃 (POST: csrf_token) — 공개 허용
 * 사용자 세션과 이 브라우저의 관리자 인증을 모두 끝낸다. 헤더의 로그아웃 폼(redirect=1)은 로그인 화면으로 303 이동한다.
 * 보안 토큰이 맞지 않으면(이미 세션이 끝났거나 다른 사이트의 요청) 아무것도 바꾸지 않는다.
 */
define('ZZ_PUBLIC', true);
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();

$token = $_POST[Csrf::FIELD] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
$valid = (Session::hasCookie() && Csrf::verify(is_string($token) ? $token : null));
if ($valid) {
    UserAuth::logout();
}
if (($_POST['redirect'] ?? '') === '1') {
    header('Cache-Control: no-store');
    header('Location: ' . Url::basePath() . '/login.php' . ($valid ? '?logout=1' : ''), true, 303);
    exit;
}
$valid ? Json::ok(['message' => '로그아웃했습니다.']) : Json::error('보안 토큰이 유효하지 않습니다.', 403);
