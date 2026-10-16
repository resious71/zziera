<?php
/** 관리 잠그기 (POST: csrf_token, 헤더 X-Admin-Token) — 이 화면의 토큰만 버린다 */
define('ZZ_PUBLIC', true); // 공개 허용(로그인 전에도 관리자 인증 가능)
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Csrf::check();
Auth::logout();
Json::ok(['message' => '관리 화면을 잠갔습니다.']);
