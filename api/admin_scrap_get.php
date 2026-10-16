<?php
/**
 * 관리자: 스크랩 설정·상태 조회 (GET) — 5f
 * 제안 목록, 현재 선택, 주제당 건수, 자동 실행 상태, 최근 실행 이력 20건, 프록시 설정 여부(값은 보내지 않음), 오늘 자동 실행 여부.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

$s = ScrapTopics::settings();
$runs = array_map(static fn (array $r) => [
    'id' => (int) $r['id'], 'trigger_type' => $r['trigger_type'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at'],
    'status' => $r['status'], 'topic_count' => (int) $r['topic_count'], 'created_count' => (int) $r['created_count'],
    'skipped_count' => (int) $r['skipped_count'], 'topics' => json_decode((string) $r['message'], true) ?: [],
], Db::all('SELECT * FROM scrap_runs ORDER BY id DESC LIMIT 20'));
$today = Scraper::todayAutoRun();
Auth::ok([
    'suggestions'   => ScrapTopics::suggestions(),
    'keys'          => $s['keys'],
    'keywords'      => $s['keywords'],
    'per_topic'     => $s['per_topic'],
    'topic_count'   => ScrapTopics::count(),
    'max_topics'    => ScrapTopics::maxTopics(),
    'enabled'       => ScrapTopics::enabled(),
    'proxy_set'     => trim((string) Config::get('SCRAP_PROXY', '')) !== '',
    'today_auto'    => $today,
    'running'       => Db::value("SELECT COUNT(*) FROM scrap_runs WHERE status = 'running'") > 0,
    'runs'          => $runs,
]);
