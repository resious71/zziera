<?php
/**
 * 관리자 멤버 작업 (POST: csrf_token, action, ...)
 *  add        : emails(줄바꿈·쉼표·세미콜론 구분)
 *  update     : id, email
 *  suspend | resume | unsubscribe | reactivate | delete : id
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

$action = Input::choice($_POST, 'action', '작업', ['add', 'update', 'suspend', 'resume', 'unsubscribe', 'reactivate', 'delete']);
if ($action === 'add') {
    $raw = Input::string($_POST, 'emails', '이메일 주소', 100000, true, true);
    $r = MemberRepository::addBulk($raw);
    Auth::ok($r + ['message' => sprintf('추가 %d건 · 중복 %d건 · 형식 오류 %d건', count($r['added']), count($r['duplicate']), count($r['invalid']))]);
}
$id = Input::int($_POST, 'id', '멤버 번호', null, 1);
switch ($action) {
    case 'update':
        MemberRepository::updateEmail($id, Input::string($_POST, 'email', '이메일 주소', 254));
        Auth::ok(['message' => '주소를 수정했습니다.']);
    case 'delete':
        MemberRepository::delete($id);
        Auth::ok(['message' => '멤버를 삭제했습니다.']);
    default:
        $r = MemberRepository::changeStatus($id, $action);
        Auth::ok($r + ['message' => '상태를 "' . MemberRepository::STATUS_LABELS[$r['status']] . '"(으)로 바꿨습니다.']);
}
