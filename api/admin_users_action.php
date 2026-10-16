<?php
/**
 * 관리자: 사용자 계정 작업 (POST: csrf_token, action, ...)
 *  generate : 임의 비밀번호(영문·숫자 12자) 만들기 — 저장하지 않고 화면에만 보여 준다
 *  create   : login_id, password
 *  bulk     : lines ("ID,비밀번호" 한 줄에 하나, 최대 300줄) — 응답에 비밀번호를 담지 않는다
 *  reset    : id, password (기존 로그인 모두 해제)
 *  suspend | resume | delete : id
 * 비밀번호는 앞뒤 공백을 지우지 않는다(입력 그대로가 비밀번호).
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Auth::requireAdmin();

/** 비밀번호 원문(공백 유지). 문자열이 아니면 오류 */
function rawPassword(): string
{
    $pw = $_POST['password'] ?? '';
    if (!is_string($pw) || $pw === '') {
        throw new ValidationException('비밀번호를 입력하세요.');
    }
    return $pw;
}

$action = Input::choice($_POST, 'action', '작업', ['generate', 'create', 'bulk', 'reset', 'suspend', 'resume', 'delete']);
switch ($action) {
    case 'generate':
        Auth::ok(['password' => UserRepository::generatePassword()]);
    case 'create':
        $loginId = Input::string($_POST, 'login_id', 'ID', 100);
        UserRepository::create($loginId, rawPassword());
        Auth::ok(['message' => '사용자 "' . $loginId . '"을(를) 추가했습니다. 비밀번호를 사용자에게 전달하세요(다시 볼 수 없습니다).']);
    case 'bulk':
        $raw = $_POST['lines'] ?? '';
        if (!is_string($raw) || trim($raw) === '' || !mb_check_encoding($raw, 'UTF-8')) {
            throw new ValidationException('추가할 "ID,비밀번호" 줄을 입력하세요.');
        }
        $r = UserRepository::createBulk($raw);
        Auth::ok($r + ['message' => sprintf('추가 %d건 · 중복 %d건 · 형식 오류 %d건', count($r['added']), count($r['duplicate']), count($r['invalid']))]);
}
$id = Input::int($_POST, 'id', '사용자 번호', null, 1);
switch ($action) {
    case 'reset':
        $loginId = UserRepository::resetPassword($id, rawPassword());
        Auth::ok(['message' => '"' . $loginId . '"의 비밀번호를 재설정했습니다. 이 사용자의 기존 로그인은 모두 해제됩니다.']);
    case 'delete':
        $loginId = UserRepository::delete($id);
        Auth::ok(['message' => '"' . $loginId . '"을(를) 삭제했습니다.']);
    default:
        $loginId = UserRepository::setStatus($id, $action === 'suspend' ? 'suspended' : 'active');
        Auth::ok(['message' => '"' . $loginId . '"을(를) ' . ($action === 'suspend' ? '정지했습니다. 즉시 로그아웃됩니다.' : '다시 사용하게 했습니다.')]);
}
