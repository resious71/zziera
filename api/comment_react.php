<?php
/**
 * 댓글 공감 (POST: csrf_token, comment_id, emoji) — 이모티콘 하나를 남기거나 바꾸고, 같은 것을 다시 보내면 취소.
 * 사용자 로그인 필요(관리자 세션만으로는 불가). 세션당 분당 30회 제한. 감사 로그에 남기지 않는다.
 * 응답: {comment_id, reactions[{emoji, count}], my_reaction}. 사용자 번호·reactor_key 는 보내지 않는다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Csrf::check();

$userId = UserAuth::currentUserId();
if ($userId === null) {
    Json::error('공감은 사용자 로그인이 필요합니다.', 403);
}
$limit = SessionRateLimit::check('reaction', [[60, 30, '공감을 너무 자주 바꾸고 있습니다. 잠시 후 다시 시도하세요.']]);
if ($limit !== null) {
    Json::error($limit, 429);
}
$commentId = Input::int($_POST, 'comment_id', '댓글 번호', null, 1);
$emoji = is_string($_POST['emoji'] ?? null) ? $_POST['emoji'] : '';
$result = CommentReactions::react($commentId, $emoji, $userId);
SessionRateLimit::hit('reaction', 60);
Json::ok($result);
