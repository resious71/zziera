<?php
/**
 * 관리자: 스크랩 지금 실행 (POST: csrf_token) — 5f. 자동 실행이 꺼져 있어도 된다. 실행 중이면 409.
 * 최대 120초. 세션 잠금을 먼저 풀어 다른 화면 요청이 기다리지 않게 한다. 감사 로그 scrap_run_manual(제목은 남기지 않음).
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

if (ScrapTopics::count() === 0) {
    Json::error('주제를 먼저 선택하고 저장하세요.', 400);
}
session_write_close();
@set_time_limit(Scraper::RUN_LIMIT_SEC + 15);
$r = Scraper::run('manual');
if ($r['busy']) {
    Json::error('스크랩이 이미 실행 중입니다. 끝난 뒤 다시 시도하세요.', 409);
}
Auth::ok([
    'message' => "스크랩 {$r['status']}: 등록 {$r['created']}건, 건너뜀 {$r['skipped']}건",
    'status'  => $r['status'], 'created' => $r['created'], 'skipped' => $r['skipped'], 'topics' => $r['topics'], 'run_id' => $r['run_id'],
]);
