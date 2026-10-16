<?php
/**
 * 관리자: 지금 정리 (POST: csrf_token) — 6b. 스케줄러(cli/cron_cleanup.php)와 같은 Cleanup::run(false)·같은 잠금(cron_cleanup).
 * 이미 실행 중이면 409. 감사 로그 cleanup_manual(스케줄러의 cleanup 과 구분). 예외 내용은 화면에 내보내지 않는다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();
session_write_close();
@set_time_limit(120);

$lock = FileLock::acquire(CleanupInfo::LOCK);
if ($lock === null) {
    Json::error('정리가 이미 실행 중입니다. 끝난 뒤 다시 시도하세요.', 409);
}
try {
    $r = Cleanup::run(false);
    Audit::log('cleanup_manual', 'system', null, '수동 정리: ' . Cleanup::summary($r));
} catch (Throwable $e) {
    error_log('[ZZieRa cleanup manual] ' . $e);
    Audit::log('cleanup_manual', 'system', null, '수동 정리 오류: ' . mb_substr($e->getMessage(), 0, 300));
    Json::error('정리 중 오류가 발생했습니다. 서버 로그를 확인하세요.', 500);
} finally {
    $lock->release();
}
Auth::ok(['items' => CleanupInfo::items($r), 'total' => CleanupInfo::total($r), 'summary' => Cleanup::summary($r),
    'file_errors' => (int) $r['file_errors'], 'last' => CleanupInfo::last(),
    'message' => '정리를 마쳤습니다.' . ((int) $r['file_errors'] > 0 ? ' 지우지 못한 파일은 다음 정리 때 다시 시도합니다.' : '')]);
