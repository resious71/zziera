<?php
/**
 * 작업 스케줄러 래퍼(run_send.bat / run_cleanup.bat)가 부르는 실행기.
 *
 *   php.exe -c <php.ini> cli\run_logged.php send|cleanup|scrap
 *
 * cron_send.php / cron_cleanup.php 를 같은 PHP·php.ini 로 실행해 출력(표준 출력·오류)을
 * data/logs/<send|cleanup|scrap>_YYYYMMDD.log(KST)에 덧붙이고, 그 종료 코드를 그대로 돌려준다.
 * 30일 지난 *.log 는 지운다. data/ 는 웹 접근이 차단된 폴더다.
 * (배치 파일의 for /f·%date% 는 지역 설정과 괄호 처리에 약해 날짜 계산과 로그는 PHP 가 맡는다)
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}

const LOG_KEEP_DAYS = 30;
$jobs = ['send' => 'cron_send.php', 'cleanup' => 'cron_cleanup.php', 'scrap' => 'cron_scrap.php'];
$job = $argv[1] ?? '';
if (!isset($jobs[$job])) {
    fwrite(STDERR, "사용법: php run_logged.php send|cleanup|scrap\n");
    exit(2);
}

$logDir = DATA_DIR . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . $job . '_' . Time::nowObj()->format('Ymd') . '.log';

// 같은 PHP 실행 파일과 php.ini 로 실제 작업을 실행
$cmd = [PHP_BINARY];
$ini = php_ini_loaded_file();
if ($ini !== false) {
    array_push($cmd, '-c', $ini);
}
$cmd[] = __DIR__ . DIRECTORY_SEPARATOR . $jobs[$job];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
if (!is_resource($proc)) {
    file_put_contents($logFile, '[' . Time::now() . "] 실행 실패: {$jobs[$job]}\n", FILE_APPEND | LOCK_EX);
    exit(2);
}
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$code = proc_close($proc);

$output = rtrim((string) $output);
if ($output === '') {
    $output = '[' . Time::now() . '] (출력 없음)';
}
if ($code !== 0) {
    $output .= " (종료 코드 {$code})";
}
file_put_contents($logFile, $output . PHP_EOL, FILE_APPEND | LOCK_EX);

// 오래된 로그 정리
foreach (glob($logDir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $f) {
    if (filemtime($f) < time() - LOG_KEEP_DAYS * 86400) {
        @unlink($f);
    }
}
exit($code);
