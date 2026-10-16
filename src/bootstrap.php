<?php
/**
 * 공통 부트스트랩: 모든 웹 진입 파일과 cli 스크립트가 가장 먼저 불러온다.
 *   require __DIR__ . '/src/bootstrap.php';
 */

// 웹 서버나 CLI의 php.ini 설정과 무관하게 항상 KST로 동작한다.
date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'data');
define('UPLOAD_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads');
define('IS_CLI', PHP_SAPI === 'cli');

// src/ 오토로더: Foo → src/Foo.php, Mail\Foo → src/Mail/Foo.php
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// lib/ 외부 소스 오토로더(3단계에서 추가)
if (is_file(APP_ROOT . '/lib/autoload.php')) {
    require APP_ROOT . '/lib/autoload.php';
}

Config::load(require APP_ROOT . '/config.php');

// 오류 표시: 운영에서는 화면에 노출하지 않고 로그로만 남긴다.
error_reporting(E_ALL);
ini_set('display_errors', (IS_CLI || Config::get('DEBUG')) ? '1' : '0');
ini_set('log_errors', '1');

// 사이트 접근 게이트(5b단계): 웹 요청은 기본 거부. 공개 허용 파일만 require 전에 ZZ_PUBLIC 을 정의한다.
// (로그인 화면·로그인/로그아웃 API·관리자 로그인/로그아웃/상태 API. CLI 는 확인하지 않는다)
AccessGate::enforce();
