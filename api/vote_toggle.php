<?php
/**
 * 추천/취소 (POST: csrf_token, post_id) — 5d. 사용자 로그인 필요(관리자 세션만으로는 불가). 감사 로그에 남기지 않는다.
 * 응답: {voted, vote_count}. 사용자 번호·vote_key 는 보내지 않는다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Csrf::check();

$userId = UserAuth::currentUserId();
if ($userId === null) {
    Json::error('추천은 사용자 로그인이 필요합니다.', 403);
}
$postId = Input::int($_POST, 'post_id', '찌라시 번호', null, 1);
Json::ok(Votes::toggle($postId, $userId));
