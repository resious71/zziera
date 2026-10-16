<?php
/**
 * 관리자: 댓글·답글 삭제 (POST: csrf_token, id, 헤더 X-Admin-Token) — 5d. 복구 없음.
 * 답글이 달린 일반 댓글은 "삭제된 댓글입니다"로 표시만 남는다(Comments::adminDelete). 감사 로그 comment_delete.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$r = Comments::adminDelete(Input::int($_POST, 'id', '댓글 번호', null, 1));
Auth::ok([
    'message' => $r['mode'] === 'marked' ? '댓글을 삭제했습니다(답글이 있어 "삭제된 댓글입니다"로 표시).' : '댓글을 삭제했습니다.',
    'count'   => Comments::activeCount($r['zzira_id']),
]);
