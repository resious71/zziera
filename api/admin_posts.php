<?php
/**
 * 관리자 찌라시 목록 (GET)
 *  view=all(기본): from, to(YYYY-MM-DD), q(제목), status(active|deleted|빈값), page
 *  view=deleted : 삭제 후 복구 가능 기간 안의 글(남은 시간 포함)
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

if (($_GET['view'] ?? '') === 'deleted') {
    Auth::ok(['items' => AdminPostService::deletedList(), 'retention_days' => Config::int('DELETED_RETENTION_DAYS', 3)]);
}
$filters = [
    'from'   => ($_GET['from'] ?? '') === '' ? '' : Input::date($_GET, 'from', '시작일'),
    'to'     => ($_GET['to'] ?? '') === '' ? '' : Input::date($_GET, 'to', '종료일'),
    'q'      => Input::string($_GET, 'q', '검색어', 100, false),
    'status' => in_array($_GET['status'] ?? '', ['active', 'deleted'], true) ? $_GET['status'] : '',
];
Auth::ok(AdminPostService::list($filters, Input::int($_GET, 'page', '페이지', 1, 1, 100000)));
