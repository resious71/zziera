<?php
/** 관리자 찌라시 상세 (GET id) — 삭제된 글도 볼 수 있다. 본문은 일반 조회와 같은 ContentRenderer 결과 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

$id = Input::int($_GET, 'id', '글 번호', null, 1);
$post = PostRepository::find($id, true);
if ($post === null) {
    Json::error('찌라시를 찾을 수 없습니다.', 404);
}
$post['restore_remaining'] = $post['status'] === 'deleted' ? AdminPostService::restoreRemaining($post['deleted_at']) : null;
Auth::ok(['post' => $post]);
