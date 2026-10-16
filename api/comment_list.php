<?php
/**
 * 댓글 목록 + 추천 상태 (GET: post_id) — 5d
 * 응답: comments[{id, body, created_at, mine, deleted, reactions[{emoji,count}], my_reaction, replies[]}], count(삭제 제외), max, max_len, is_admin,
 *       emoji_sets(이모티콘 고르기 목록, Emoji::sets), can_react(사용자 로그인 + 삭제되지 않은 글),
 *       vote{my_vote, vote_count, can_vote}. author_key·사용자 관련 값은 보내지 않는다.
 * is_admin: 이 화면이 유효한 관리자 토큰(X-Admin-Token)을 보냈는지(연장하지 않음). 관리자면 mine 은 항상 false.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();

$postId = Input::int($_GET, 'post_id', '찌라시 번호', null, 1);
$isAdmin = Auth::isAdmin(false);
$status = Db::value('SELECT status FROM zzira WHERE id = ?', [$postId]);
if ($status === null || ($status !== 'active' && !$isAdmin)) {
    throw new NotFoundException('찌라시를 찾을 수 없습니다. 삭제되었을 수 있습니다.');
}
$userId = UserAuth::currentUserId();
$vote = Votes::status($postId, $isAdmin ? null : $userId);
Json::ok([
    'comments' => Comments::listFor($postId, $userId, $isAdmin),
    'count'    => Comments::activeCount($postId),
    'max'      => Comments::maxPerPost(),
    'max_len'  => Comments::maxLen(),
    'is_admin' => $isAdmin,
    'active'   => $status === 'active',
    'vote'     => $vote + ['can_vote' => $userId !== null && $status === 'active'],
    'can_react' => $userId !== null && !$isAdmin && $status === 'active',
    'emoji_sets' => Emoji::sets(),
]);
