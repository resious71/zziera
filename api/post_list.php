<?php
/**
 * 날짜별 찌라시 목록 API (GET)
 *
 * 파라미터: date(YYYY-MM-DD, 기본 오늘), q(검색어, 제목·본문 일반 텍스트 기준),
 *           image/table/link/attachment(=1 이면 해당 항목이 있는 글만),
 *           all(=1 이면 날짜와 무관하게 전체 기간 검색, 검색어가 있을 때만 적용)
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();

$date = Input::date($_GET, 'date', '날짜', Time::today());
$q = Input::string($_GET, 'q', '검색어', 100, false);
$filters = [
    'q'          => $q,
    'image'      => ($_GET['image'] ?? '') === '1',
    'table'      => ($_GET['table'] ?? '') === '1',
    'link'       => ($_GET['link'] ?? '') === '1',
    'attachment' => ($_GET['attachment'] ?? '') === '1',
    'all_dates'  => $q !== '' && ($_GET['all'] ?? '') === '1',
];

Json::ok([
    'date'   => $date,
    'today'  => Time::today(),
    'items'  => $items = PostRepository::listByDate($date, $filters),
    'total'  => count($items),
    // 상단 고정된 글(날짜·검색·필터와 무관하게 맨 위 "고정된 찌라시" 영역). 기존 필드는 그대로 두고 추가만 한다
    'pinned' => PostRepository::listPinned(),
]);
