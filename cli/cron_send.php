<?php
/**
 * 정기 발송 스케줄러 — Windows 작업 스케줄러가 1분마다 실행한다.
 *
 *   C:\xampp\php\php.exe cli\cron_send.php
 *   C:\xampp\php\php.exe cli\cron_send.php --force-now="2026-10-07 07:05:00" --dry-run
 *
 *  --force-now : 현재 시각을 바꿔 판단(테스트용)
 *  --dry-run   : 실제 발송·기록 없이 판단 결과와 범위·대상만 출력
 *
 * 결과는 표준 출력에 한 줄로 남긴다(작업 스케줄러 로그 확인용). 종료 코드: 0 정상, 1 발송 실패, 2 오류.
 * 판단 규칙은 src/ScheduledSend.php 참고.
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
function out(string $line): void
{
    global $prefix;
    echo $prefix . ' ' . $line . PHP_EOL;
}

// 같은 스크립트가 겹쳐 실행되지 않도록(작업 스케줄러 설정과 별개로 한 번 더 막는다)
$lock = FileLock::acquire('cron_send');
if ($lock === null) {
    out('이미 실행 중, 종료');
    exit(0);
}

try {
    if (!$dryRun) {
        $stale = SendService::cleanupStale();
        if ($stale > 0) {
            Audit::log('send_stale_cleanup', 'send_log', null, "running 상태로 남은 발송 기록 {$stale}건을 failed 로 정리");
        }
    }

    $d = ScheduledSend::decide();
    if ($d['action'] === 'exit') {
        out($d['reason']);
        exit(0);
    }
    if ($d['action'] === 'skip_deadline') {
        if (!$dryRun && !ScheduledSend::deadlineLogged(Time::today())) {
            Audit::log('send_skip_deadline', 'send_log', null, Time::today() . ' 정기 발송 못 함: ' . $d['reason']);
        }
        out($d['reason']);
        exit(0);
    }

    // 발송
    $posts = SendService::posts($d['from'], $d['to']);
    $range = $d['from'] . ' ~ ' . $d['to'];
    if ($dryRun) {
        $problems = MailConfig::check();
        out($d['reason'] . " 예정: 범위 {$range}, 찌라시 " . count($posts) . '건, 수신 ' . count(MemberRepository::activeEmails()) . '명'
            . ($problems !== [] ? ' (메일 설정 미완료: ' . implode(', ', $problems) . ' → skipped 예정)' : ''));
        foreach ($posts as $p) {
            echo "    - #{$p['id']} {$p['created_at']} {$p['title']}" . PHP_EOL;
        }
        exit(0);
    }
    $r = SendService::run('scheduled', $d['from'], $d['to']);
    out($d['reason'] . " {$r['status']}: 범위 {$range}, 찌라시 {$r['posts']}건, 수신 {$r['recipients']}명 — {$r['message']}");
    exit($r['status'] === 'failed' ? 1 : 0);
} catch (Throwable $e) {
    out('오류: ' . $e->getMessage());
    error_log('[ZZieRa cron_send] ' . $e);
    exit(2);
} finally {
    $lock->release();
}
