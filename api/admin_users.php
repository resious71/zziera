<?php
/** 관리자: 사용자 계정 목록 (GET: status=active|suspended|빈값, q=ID 검색, page) — 20건씩 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

$status = $_GET['status'] ?? '';
Auth::ok(UserRepository::list(
    is_string($status) && in_array($status, UserRepository::STATUSES, true) ? $status : null,
    Input::string($_GET, 'q', '검색어', 20, false),
    Input::int($_GET, 'page', '페이지', 1, 1, 100000)
));
