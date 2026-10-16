<?php
/**
 * 찌라시 상단 고정/해제 (POST: csrf_token, id, pinned=1|0) — 관리자 전용
 * 삭제된 글은 고정할 수 없고, 동시에 PIN_MAX_COUNT 건까지. 이미 같은 상태면 오류 없이 현재 상태를 돌려준다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$id = Input::int($_POST, 'id', '글 번호', null, 1);
$pinned = Input::choice($_POST, 'pinned', '고정 여부', ['1', '0']) === '1';
$r = AdminPostService::pin($id, $pinned);
Auth::ok($r + [
    'pinned_count' => AdminPostService::pinnedCount(),
    'pin_max'      => AdminPostService::pinMax(),
    'message'      => $r['is_pinned'] ? '상단에 고정했습니다.' : '고정을 해제했습니다.',
]);
