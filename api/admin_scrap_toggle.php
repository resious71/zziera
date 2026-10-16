<?php
/** 관리자: 스크랩 자동 실행 켜기/끄기 (POST: csrf_token, enabled=1|0) — 5f. 주제가 없으면 켤 수 없다. 감사 로그 scrap_on/off */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$on = Input::choice($_POST, 'enabled', '자동 실행', ['0', '1']) === '1';
ScrapTopics::setEnabled($on);
Auth::ok(['message' => $on ? '스크랩 자동 실행을 켰습니다. 매일 06:30에 실행합니다(12:00까지 보충).' : '스크랩 자동 실행을 껐습니다.', 'enabled' => ScrapTopics::enabled()]);
