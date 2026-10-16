<?php
/** 관리자 찌라시 삭제·복구 (POST: csrf_token, action=delete|restore, id). 수정 기능은 없다 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$id = Input::int($_POST, 'id', '글 번호', null, 1);
$action = Input::choice($_POST, 'action', '작업', ['delete', 'restore']);
if ($action === 'delete') {
    AdminPostService::delete($id);
    Auth::ok(['message' => '찌라시를 삭제했습니다. ' . Config::int('DELETED_RETENTION_DAYS', 3) . '일 안에는 "삭제된 찌라시"에서 복구할 수 있습니다.']);
}
AdminPostService::restore($id);
Auth::ok(['message' => '찌라시를 복구했습니다.']);
