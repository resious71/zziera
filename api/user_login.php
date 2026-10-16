<?php
/**
 * 사용자 로그인 (POST: csrf_token, login_id, password, next) — 공개 허용
 * 성공: {ok, redirect}(검증된 next 또는 index.php). 실패 400, 잠금 423(lock_remaining).
 * 비밀번호는 어디에도 기록·응답하지 않는다. 성공·실패는 감사 로그에 남기지 않는다(잠금 발생만).
 */
define('ZZ_PUBLIC', true);
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Csrf::check();

$id = $_POST['login_id'] ?? '';
$pw = $_POST['password'] ?? '';
if (!is_string($id) || !is_string($pw) || trim($id) === '' || $pw === '') {
    Json::error('ID와 비밀번호를 입력하세요.');
}
$r = UserAuth::attempt(mb_substr($id, 0, 100), mb_substr($pw, 0, 200));
if (!$r['ok']) {
    Json::error($r['message'], $r['lock_remaining'] > 0 ? 423 : 400, ['lock_remaining' => $r['lock_remaining']]);
}
Json::ok(['message' => $r['message'], 'redirect' => AccessGate::safeNext($_POST['next'] ?? '') ?? 'index.php']);
