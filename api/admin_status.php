<?php
/**
 * 관리자 상태 조회 (GET, 헤더 X-Admin-Token) — 연장하지 않는다(화면이 주기적으로 불러도 만료가 미뤄지면 안 됨).
 * 응답: logged_in(이 화면의 토큰이 유효한지), session_remaining(초), session_ttl, lock_remaining(초), expired(방금 만료됐는지),
 *       revoked(관리자 비밀번호가 바뀌어 끝났는지, 6c), message(끝난 이유),
 *       password_default(관리자 비밀번호가 초기 상태인지 — 인증된 화면에만 보낸다, 6c)
 */
define('ZZ_PUBLIC', true); // 공개 허용(로그인 전에도 관리자 인증 가능)
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();

$state = Auth::tokenState(false);
$valid = $state === Auth::STATE_VALID;
$out = [
    'logged_in'         => $valid,
    'session_remaining' => $valid ? Auth::remaining() : 0,
    'session_ttl'       => Auth::ttl(),
    'lock_remaining'    => Auth::lockRemaining(),
    'expired'           => $state === Auth::STATE_EXPIRED || $state === Auth::STATE_REVOKED,
    'revoked'           => $state === Auth::STATE_REVOKED,
];
if ($valid) {
    $out['password_default'] = AdminPassword::isDefault();
} elseif ($state !== Auth::STATE_NONE) {
    $out['message'] = Auth::stateMessage($state);
}
Json::ok($out);
