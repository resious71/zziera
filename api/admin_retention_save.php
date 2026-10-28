<?php
/**
 * 관리자: 보관 기간 저장 (POST: csrf_token, post_days, file_days) — 찌라시·이미지·첨부 보관 일수(1일~3년).
 * 다음 정리(매일 03:00 또는 "지금 정리")부터 적용된다. 감사 로그 retention_update
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$before = '찌라시 ' . Retention::postRetentionText() . ', 이미지·첨부 ' . Retention::fileRetentionText();
$post = Input::int($_POST, 'post_days', '찌라시 보관 기간', null, 1, Retention::MAX_DAYS);
$file = Input::int($_POST, 'file_days', '이미지·첨부 보관 기간', null, 1, Retention::MAX_DAYS);
Retention::save($post, $file);
$after = '찌라시 ' . Retention::postRetentionText() . ', 이미지·첨부 ' . Retention::fileRetentionText();
Audit::log('retention_update', 'settings', null, "보관 기간: {$before} → {$after}");
Auth::ok(['message' => "보관 기간을 저장했습니다({$after}). 다음 정리부터 적용됩니다.", 'retention' => CleanupInfo::retention()]);
