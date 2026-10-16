<?php
/**
 * 에디터 본문 이미지 업로드 API (POST, multipart/form-data)
 *
 * 필드: csrf_token, image(파일 1개)
 * 응답: {ok:true, id, url:"api/file.php?type=image&id=N"}
 *
 * 등록 전 임시 이미지로 저장한다(zzira_images.zzira_id = NULL).
 * 누가 올렸는지는 DB에 남기지 않고, 이 세션의 소유 목록(PendingImages)에만 넣는다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Input::requirePost();

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_POST === [] && $_FILES === [] && $contentLength > 0) {
    Json::error('이미지 용량이 서버 허용치(' . ini_get('post_max_size') . ')를 넘었습니다.', 413);
}

Csrf::check();

$perMinute = Config::int('IMAGE_UPLOAD_PER_MIN', 10);
$limitMessage = SessionRateLimit::check('image_upload', [
    [60, $perMinute, "이미지는 1분에 {$perMinute}장까지 올릴 수 있습니다. 잠시 후 다시 시도하세요."],
]);
if ($limitMessage !== null) {
    Json::error($limitMessage, 429);
}

$files = Upload::collect('image');
if ($files === []) {
    Json::error('이미지 파일을 선택하세요.');
}
if (count($files) > 1) {
    Json::error('이미지는 한 번에 한 장씩 올려 주세요.');
}
$validated = Upload::validate($files, Upload::KIND_IMAGE)[0];

$path = Upload::store($validated);
try {
    Db::exec('INSERT INTO zzira_images (zzira_id, file_path, uploaded_at) VALUES (NULL, ?, ?)', [$path, Time::now()]);
    $id = Db::lastId();
} catch (Throwable $e) {
    Upload::remove($path);
    throw $e;
}

PendingImages::add($id);
SessionRateLimit::hit('image_upload', 60);

Json::ok([
    'id'  => $id,
    'url' => HtmlSanitizer::imageSrc($id),
]);
