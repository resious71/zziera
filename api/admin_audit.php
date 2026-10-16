<?php
/** 관리자 감사 로그 조회 (GET) — 최근 100건, 읽기 전용 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

$rows = Db::all('SELECT id, event, target_type, target_id, detail, created_at FROM audit_log ORDER BY id DESC LIMIT 100');
Auth::ok(['items' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'target_id' => $r['target_id'] === null ? null : (int) $r['target_id']] + $r, $rows)]);
