<?php
/**
 * assets/thought-balloon.png 로 favicon 파일을 만든다(PRD 2장 화면 스타일). GD 확장이 필요하다.
 *
 *   C:\xampp\php\php.exe cli\make_favicon.php
 *   (php.ini 에서 gd 가 꺼져 있으면) C:\xampp\php\php.exe -d extension=gd cli\make_favicon.php
 *
 * 만드는 파일(assets/): favicon.ico(16·32·48px, PNG 내장), favicon-32.png, apple-touch-icon.png(180px)
 * 아이콘 원본을 바꿨을 때만 다시 실행하면 된다. 웹 서버에는 GD 가 필요 없다.
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD 확장이 없습니다. php -d extension=gd cli\\make_favicon.php 로 실행하세요.\n");
    exit(2);
}

$assets = APP_ROOT . DIRECTORY_SEPARATOR . 'assets';
$src = @imagecreatefrompng($assets . DIRECTORY_SEPARATOR . 'thought-balloon.png');
if ($src === false) {
    fwrite(STDERR, "assets/thought-balloon.png 를 읽을 수 없습니다.\n");
    exit(2);
}

/** 투명도를 유지한 채 정사각형 PNG 바이트로 줄이거나 늘린다 */
function resizedPng(GdImage $src, int $size): string
{
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    ob_start();
    imagepng($dst, null, 9);
    imagedestroy($dst);
    return (string) ob_get_clean();
}

// favicon.ico: PNG 를 그대로 담는 ICO(Windows Vista 이후·모든 최신 브라우저 지원)
$sizes = [16, 32, 48];
$images = array_map(static fn (int $s) => resizedPng($src, $s), $sizes);
$ico = pack('vvv', 0, 1, count($sizes));
$offset = 6 + 16 * count($sizes);
foreach ($sizes as $i => $s) {
    $ico .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, strlen($images[$i]), $offset);
    $offset += strlen($images[$i]);
}
$ico .= implode('', $images);

$out = [
    'favicon.ico'          => $ico,
    'favicon-32.png'       => resizedPng($src, 32),
    'apple-touch-icon.png' => resizedPng($src, 180),
];
foreach ($out as $name => $bytes) {
    file_put_contents($assets . DIRECTORY_SEPARATOR . $name, $bytes);
    echo "assets/{$name} (" . strlen($bytes) . " bytes)" . PHP_EOL;
}
imagedestroy($src);
