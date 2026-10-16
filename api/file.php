<?php
/**
 * 이미지·첨부파일 제공 (GET ?type=image|attachment&id=)
 *
 * 업로드 폴더는 웹에서 직접 열 수 없으므로 모든 파일은 이 스크립트를 거친다.
 * DB의 file_path 만 사용하고 storage/uploads 밖의 경로는 거부한다.
 * 삭제된 찌라시의 파일은 일반 사용자에게 제공하지 않는다(로그인한 관리자에게는 제공).
 * 등록 전 임시 이미지(zzira_id NULL)는 그 이미지를 올린 세션에서만 제공한다.
 */
require __DIR__ . '/../src/bootstrap.php';

function fileError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$type = $_GET['type'] ?? '';
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!in_array($type, ['image', 'attachment'], true) || $id === false) {
    fileError(400, '잘못된 요청입니다.');
}

$row = $type === 'image'
    ? Db::row('SELECT f.zzira_id, f.file_path, f.file_deleted_at, z.status
               FROM zzira_images f LEFT JOIN zzira z ON z.id = f.zzira_id WHERE f.id = ?', [$id])
    : Db::row('SELECT f.zzira_id, f.file_path, f.file_deleted_at, f.original_name, f.mime_type, z.status
               FROM zzira_attachments f JOIN zzira z ON z.id = f.zzira_id WHERE f.id = ?', [$id]);

if ($row === null) {
    fileError(404, '파일을 찾을 수 없습니다.');
}
if ($type === 'image' && $row['zzira_id'] === null) {
    // 등록 전 임시 이미지: 올린 세션에서만 볼 수 있다(세션은 읽기만 하고 바로 닫음)
    if (!PendingImages::has((int) $id, true)) {
        fileError(404, '파일을 찾을 수 없습니다.');
    }
} elseif ($row['status'] !== 'active' && !Auth::sessionHasAdmin()) {
    // 삭제된 글의 파일은 관리자에게만. <img src> 는 헤더를 못 보내므로 "이 브라우저 세션에 유효한 관리자 토큰이 있는지"로 판단
    // (세션은 읽기만 하고 연장하지 않음)
    fileError(404, '파일을 찾을 수 없습니다.');
}
$isTemporary = $type === 'image' && $row['zzira_id'] === null;
$path = $row['file_deleted_at'] === null ? Upload::absolutePath($row['file_path']) : null;
if ($path === null) {
    fileError(410, '첨부가 만료되었습니다.');
}

if ($type === 'image') {
    $contentType = Upload::imageMime($row['file_path']);
    $disposition = 'inline; filename="image.' . strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION)) . '"';
} else {
    $contentType = (string) ($row['mime_type'] ?: 'application/octet-stream');
    $name = (string) $row['original_name'];
    // ASCII 대체 이름 + RFC 5987 UTF-8 이름(한글 파일명)
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
    if (!preg_match('/[A-Za-z0-9]/', pathinfo($fallback, PATHINFO_FILENAME))) {
        $fallback = 'attachment.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
    $disposition = 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

// 캐시: 주소는 id 로만 정해지는데, SQLite 는 마지막 행이 지워지면 같은 id 를 다시 쓸 수 있다.
// 시간 기반 캐시(max-age)를 쓰면 같은 id 의 "예전 파일"이 계속 보이므로,
// 매번 서버에 확인(no-cache)하되 파일이 같으면 304 로 본문 없이 응답한다.
// ETag 는 저장 파일명(랜덤)·크기·수정 시각으로 만들어 id 가 같아도 파일이 다르면 달라진다.
$etag = '"' . sha1($row['file_path'] . '|' . filesize($path) . '|' . filemtime($path)) . '"';
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
if ($isTemporary) {
    header('Cache-Control: no-store'); // 임시 이미지는 세션 전용이므로 저장하지 않는다
} else {
    header('Cache-Control: private, no-cache');
    header('ETag: ' . $etag);
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch !== '' && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)) {
        http_response_code(304);
        exit;
    }
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition);
readfile($path);
