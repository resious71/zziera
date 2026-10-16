<?php
/**
 * lib/ 외부 소스 오토로더 (Composer 대신, PSR-4 형태)
 *
 * 네임스페이스 접두어 → lib/ 아래 폴더. 새 라이브러리를 넣으면 아래 표에 한 줄 추가한다.
 * src/bootstrap.php 가 이 파일을 불러온다(웹·CLI 공통).
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'PhpScience\\TextRank\\' => __DIR__ . '/PhpScience/TextRank/',
        // PHPMailer 의 src/ 세 파일(PHPMailer.php, SMTP.php, Exception.php)을 lib/PHPMailer/ 에 그대로 둔다
        'PHPMailer\\PHPMailer\\' => __DIR__ . '/PHPMailer/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});
