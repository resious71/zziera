<?php
/**
 * 찌라시 상세 API (GET ?id=)
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();

$id = Input::int($_GET, 'id', '글 번호', null, 1);
$post = PostRepository::find($id);
if ($post === null) {
    Json::error('찌라시를 찾을 수 없습니다. 삭제되었거나 없는 글입니다.', 404);
}
Json::ok(['post' => $post]);
