<?php
/**
 * 발송 이력 (GET) — 관리자 전용
 *  page : 목록(20건씩) — 일시, 자동/수동, 포함 기간, 수신자 수, 상태, 오류 메시지
 *  id   : 상세 — 묶음별 결과, 포함된 찌라시 목록
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

if (isset($_GET['id'])) {
    $id = Input::int($_GET, 'id', '이력 번호', null, 1);
    $log = Db::row('SELECT * FROM send_log WHERE id = ?', [$id]);
    if ($log === null) {
        Json::error('발송 이력을 찾을 수 없습니다.', 404);
    }
    Auth::ok([
        'log'     => $log,
        'batches' => array_map(
            static fn (array $b) => $b + ['hint' => $b['error_message'] ? MailErrorHint::for((string) $b['error_message'], MailSettings::get()['smtp_auth']) : null],
            Db::all('SELECT batch_no, recipient_count, attempts, status, error_message FROM send_log_batches WHERE send_log_id = ? ORDER BY batch_no', [$id])
        ),
        'items'   => Db::all(
            'SELECT z.id, z.title, z.created_at, z.status FROM send_log_items i JOIN zzira z ON z.id = i.zzira_id
             WHERE i.send_log_id = ? ORDER BY z.created_at, z.id',
            [$id]
        ),
    ]);
}

$perPage = 20;
$total = (int) Db::value('SELECT COUNT(*) FROM send_log');
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, Input::int($_GET, 'page', '페이지', 1, 1, 100000)), $pages);
$rows = Db::all(
    "SELECT l.*,
            (SELECT COUNT(*) FROM send_log_items i WHERE i.send_log_id = l.id) AS item_count,
            (SELECT COUNT(*) FROM send_log_batches b WHERE b.send_log_id = l.id) AS batch_count,
            (SELECT COUNT(*) FROM send_log_batches b WHERE b.send_log_id = l.id AND b.status = 'failed') AS batch_failed
     FROM send_log l ORDER BY l.id DESC LIMIT ? OFFSET ?",
    [$perPage, ($page - 1) * $perPage]
);
Auth::ok(['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
