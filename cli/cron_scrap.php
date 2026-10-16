<?php
/**
 * 스크랩 자동 실행 (5f) — 작업 스케줄러가 06:30~12:00 사이 30분 간격으로 부른다.
 *
 *   C:\xampp\php\php.exe cli\cron_scrap.php
 *   C:\xampp\php\php.exe cli\cron_scrap.php --force-now="2026-10-08 06:30" --fixture=sample.xml --dry-run
 *
 *  --force-now : 현재 시각을 바꿔 판단(테스트용)
 *  --fixture   : 네트워크 대신 샘플 RSS XML(파일 또는 폴더: <주제 순번>.xml, 없으면 default.xml)
 *  --dry-run   : 등록하지 않고 후보만 출력(실행 기록도 남기지 않음)
 *
 * 판단: (a) 자동 실행이 꺼져 있으면 기록 없이 종료 (b) 06:30 전·12:00 이후면 종료
 *       (c) 오늘 자동 실행 기록(성공·실패 불문, running 제외)이 있으면 종료 (d) 아니면 실행.
 * 꺼져 있으면 외부 접속을 하지 않는다. 종료 코드: 0 정상(건너뜀 포함), 1 실행했으나 failed, 2 오류.
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}

const SCRAP_START = '06:30';
const SCRAP_END = '12:00';

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--force-now=(.+)$/', $arg, $m)) {
        try {
            $v = trim($m[1], "\"' ");
            Time::setNow(strlen($v) === 16 ? $v . ':00' : $v);
        } catch (Throwable $e) {
            fwrite(STDERR, "--force-now 값이 올바르지 않습니다: {$m[1]}\n");
            exit(2);
        }
    } elseif (preg_match('/^--fixture=(.+)$/', $arg, $m)) {
        Scraper::$fixture = trim($m[1], "\"' ");
    } else {
        fwrite(STDERR, "알 수 없는 옵션: {$arg}\n");
        exit(2);
    }
}

$prefix = '[' . Time::now() . ']' . ($dryRun ? ' [dry-run]' : '');
$out = static function (string $line) use ($prefix): void { echo $prefix . ' ' . $line . PHP_EOL; };

try {
    if (!ScrapTopics::enabled()) {
        $out('스크랩 자동 실행 꺼짐, 종료');
        exit(0);
    }
    $hm = Time::nowObj()->format('H:i');
    if ($hm < SCRAP_START || $hm >= SCRAP_END) {
        $out('자동 실행 시간(' . SCRAP_START . '~' . SCRAP_END . ')이 아님, 종료');
        exit(0);
    }
    if (!$dryRun) {
        Scraper::markStale();
    }
    $done = Scraper::todayAutoRun();
    if ($done !== null) {
        $out("오늘 자동 실행 이미 함({$done['started_at']} {$done['status']}), 종료");
        exit(0);
    }
    $r = Scraper::run('auto', $dryRun);
    if ($r['busy']) {
        $out('이미 실행 중, 종료');
        exit(0);
    }
    $out("스크랩 {$r['status']}: 등록 {$r['created']}건, 건너뜀 {$r['skipped']}건");
    foreach ($r['topics'] as $label => $t) {
        echo "    - {$label}: 등록 {$t['created']}, 건너뜀 {$t['skipped']}" . ($t['error'] ? " / {$t['error']}" : '') . PHP_EOL;
    }
    foreach ($r['candidates'] as $c) {
        echo "    · [{$c['topic']}] {$c['published']} {$c['title']} ({$c['source']})" . PHP_EOL;
    }
    exit($r['status'] === 'failed' ? 1 : 0);
} catch (Throwable $e) {
    $out('오류: ' . $e->getMessage());
    error_log('[ZZieRa cron_scrap] ' . $e);
    exit(2);
}
