<?php
/**
 * 본문 미리보기 API (POST) — 저장하지 않는다
 *
 * 필드: csrf_token, body_html
 * 응답: {ok:true, html(서버 정화·렌더링 결과), errors[](등록 시 거부될 사유), text_length, image_count, link_count}
 * 이미지는 이 세션이 올린 임시 이미지만 보여 준다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();
Csrf::check();

$raw = $_POST['body_html'] ?? '';
if (!is_string($raw)) {
    Json::error('본문 값이 올바르지 않습니다.');
}

$pending = PendingImages::all();
session_write_close(); // 이후로는 세션을 쓰지 않는다

$result = HtmlSanitizer::sanitize($raw, HtmlSanitizer::existingImagesFilter());
$errors = $result['errors'];
if ($result['rejected_image_ids'] !== [] || PendingImages::invalid($result['image_ids']) !== []) {
    $errors[] = '다른 사람이 올렸거나 이미 다른 글에 쓰인 이미지가 본문에 있습니다. 해당 이미지는 등록되지 않습니다.';
}

Json::ok([
    'html'        => ContentRenderer::renderPreview($result['html'], $pending),
    'errors'      => array_values(array_unique($errors)),
    'text_length' => mb_strlen($result['text']),
    'image_count' => count($result['image_ids']),
]);
