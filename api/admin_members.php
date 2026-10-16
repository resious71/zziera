<?php
/** 관리자 멤버 목록 (GET: status=active|suspended|unsubscribed|빈값, q, page) */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

$status = $_GET['status'] ?? '';
Auth::ok(MemberRepository::list(
    is_string($status) && in_array($status, MemberRepository::STATUSES, true) ? $status : null,
    Input::string($_GET, 'q', '검색어', 100, false),
    Input::int($_GET, 'page', '페이지', 1, 1, 100000)
));
