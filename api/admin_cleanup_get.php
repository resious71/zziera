<?php
/** 관리자: 정리 메뉴 정보 (GET) — 6b. 마지막 정리 기록, 실행 중 여부, 보관 기간 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

Auth::ok(['last' => CleanupInfo::last(), 'running' => CleanupInfo::running(), 'retention' => CleanupInfo::retention(), 'labels' => CleanupInfo::ITEMS]);
