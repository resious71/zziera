<?php
/** 관리자: 스크랩 주제·건수 저장 (POST: csrf_token, keys[], keywords(줄 단위), per_topic) — 5f. 감사 로그 scrap_settings_update */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$summary = ScrapTopics::save($_POST['keys'] ?? [], is_string($_POST['keywords'] ?? null) ? $_POST['keywords'] : '', $_POST['per_topic'] ?? '');
Audit::log('scrap_settings_update', 'settings', null, '스크랩 설정: ' . mb_substr($summary, 0, 400));
$s = ScrapTopics::settings();
Auth::ok(['message' => '스크랩 주제를 저장했습니다.', 'keys' => $s['keys'], 'keywords' => $s['keywords'], 'per_topic' => $s['per_topic'],
    'topic_count' => ScrapTopics::count(), 'enabled' => ScrapTopics::enabled()]);
