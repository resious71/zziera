<?php
/**
 * 정리 배치 — Windows 작업 스케줄러가 매일 03:00 에 실행한다(PRD 9.2, 처리 내용은 src/Cleanup.php).
 *
 *   C:\xampp\php\php.exe cli\cron_cleanup.php
 *   C:\xampp\php\php.exe cli\cron_cleanup.php --force-now="2026-10-10 03:00:00" --dry-run
 *
 *  --force-now : 기준 시각을 바꿔 판단(테스트용)
 *  --dry-run   : 지우지 않고 대상 건수만 출력(감사 로그도 남기지 않음)
 *
 * 처리 건수를 표준 출력 한 줄과 audit_log(cleanup)에 남긴다. 종료 코드: 0 정상, 2 오류.
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--force-now=(.+)$/', $arg, $m)) {
        try {
            Time::setNow(trim($m[1], "\"' "));
        } catch (Throwable $e) {
            fwrite(STDERR, "--force-now 값이 올바르지 않습니다: {$m[1]}\n");
            exit(2);
        }
    } else {
        fwrite(STDERR, "알 수 없는 옵션: {$arg}\n");
        exit(2);
    }
}

$prefix = '[' . Time::now() . ']' . ($dryRun ? ' [dry-run]' : '');

$lock = FileLock::acquire('cron_cleanup');
if ($lock === null) {
    echo $prefix . ' 이미 실행 중, 종료' . PHP_EOL;
    exit(0);
}

try {
    $r = Cleanup::run($dryRun);
    $summary = Cleanup::summary($r);
    if (!$dryRun) {
        Audit::log('cleanup', 'system', null, '정리 배치: ' . $summary);
    }
    echo $prefix . ' 정리 ' . ($dryRun ? '예정' : '완료') . ': ' . $summary . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo $prefix . ' 오류: ' . $e->getMessage() . PHP_EOL;
    error_log('[ZZieRa cron_cleanup] ' . $e);
    if (!$dryRun) {
        Audit::log('cleanup', 'system', null, '정리 배치 오류: ' . mb_substr($e->getMessage(), 0, 300));
    }
    exit(2);
} finally {
    $lock->release();
}
