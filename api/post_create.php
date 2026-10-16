<?php
/**
 * 찌라시 등록 API (POST, multipart/form-data)
 *
 * 필드: csrf_token, title, body_html(에디터 HTML), attachments[], website(허니팟: 비어 있어야 함)
 *
 * 처리 순서: CSRF → 레이트 리미팅 → 허니팟 → 제목 → 본문 정화 → 본문 이미지 소유 검증 → 첨부 검증
 *           → 트랜잭션 저장(이미지 연결·첨부) → 감사 로그
 * 등록자 식별 정보(IP, 세션 ID, User-Agent 등)는 DB에도 로그에도 남기지 않는다.
 * 본문에 쓰이지 않은 임시 이미지는 그대로 두고 정리 배치가 나중에 지운다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();

// post_max_size 를 넘으면 PHP가 $_POST/$_FILES 를 모두 비운다 → 원인을 알려 준다
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_POST === [] && $_FILES === [] && $contentLength > 0) {
    Json::error('첨부한 파일 전체 용량이 서버 허용치(' . ini_get('post_max_size') . ')를 넘었습니다. 파일 수나 크기를 줄여 주세요.', 413);
}

Csrf::check();

$limitMessage = PostRateLimit::check();
if ($limitMessage !== null) {
    Json::error($limitMessage, 429);
}

$successMessage = '찌라시가 등록되었습니다';

// 허니팟: 사람이 볼 수 없는 칸이 채워져 있으면 봇으로 보고 저장하지 않은 채 성공처럼 응답
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    Json::ok(['message' => $successMessage]);
}

$title = PostForm::title($_POST);
$body = PostForm::body($_POST);
$attachments = Upload::validate(Upload::collect('attachments'), Upload::KIND_ATTACHMENT);

$id = PostRepository::create($title, $body, $attachments);
PendingImages::remove($body['image_ids']);
PostRateLimit::hit();
Audit::log('post_create', 'zzira', $id, $title);

Json::ok([
    'message' => $successMessage,
    'id'      => $id,
    'date'    => Time::today(),
]);
