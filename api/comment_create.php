<?php
/**
 * 댓글·답글 등록 (POST: csrf_token, post_id, body, parent_id(선택), website(허니팟: 비어 있어야 함)) — 5d
 * 로그인(사용자 또는 관리자 세션) 필요. 사용자 번호는 저장하지 않고 댓글 해시(author_key) 계산에만 쓴다.
 * 등록 제한(분당 3·시간당 20)은 세션에만 둔다. 댓글 등록은 감사 로그에 남기지 않는다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Csrf::check();

if (trim((string) ($_POST['website'] ?? '')) !== '') {
    Json::error('등록할 수 없습니다.', 400); // 자동 등록 프로그램
}
$limit = SessionRateLimit::check('comment', [
    [60, max(1, Config::int('COMMENT_RATE_PER_MINUTE', 3)), '댓글을 너무 빨리 달고 있습니다. 1분 뒤에 다시 시도하세요.'],
    [3600, max(1, Config::int('COMMENT_RATE_PER_HOUR', 20)), '1시간에 달 수 있는 댓글 수를 넘었습니다. 잠시 후 다시 시도하세요.'],
]);
if ($limit !== null) {
    Json::error($limit, 429);
}
$postId = Input::int($_POST, 'post_id', '찌라시 번호', null, 1);
$parentRaw = $_POST['parent_id'] ?? '';
$parentId = ($parentRaw === '' || $parentRaw === null) ? null : Input::int($_POST, 'parent_id', '댓글 번호', null, 1);

$comment = Comments::create($postId, (string) ($_POST['body'] ?? ''), $parentId, UserAuth::currentUserId());
SessionRateLimit::hit('comment');
Json::ok([
    'message' => $parentId === null ? '댓글을 등록했습니다.' : '답글을 등록했습니다.',
    'comment' => $comment,
    'count'   => Comments::activeCount($postId),
    'max'     => Comments::maxPerPost(),
]);
