<?php
/**
 * 관리자: 정리 미리보기 (POST: csrf_token) — 6b. Cleanup::run(true)(dry-run): 아무것도 지우지 않고 감사 로그도 남기지 않는다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();
session_write_close();
@set_time_limit(120);

try {
    $r = Cleanup::run(true);
} catch (Throwable $e) {
    error_log('[ZZieRa cleanup preview] ' . $e);
    Json::error('삭제 대상을 확인하지 못했습니다. 잠시 후 다시 시도하세요.', 500);
}
$total = CleanupInfo::total($r);
Auth::ok(['items' => CleanupInfo::items($r), 'total' => $total, 'summary' => Cleanup::summary($r),
    'message' => $total === 0 ? '정리할 항목이 없습니다.' : "정리 대상 {$total}건을 찾았습니다."]);
