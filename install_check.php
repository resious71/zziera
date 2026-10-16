<?php
/**
 * 설치 점검 페이지
 *
 * 서버 환경·php.ini·메일 설정·접근 차단·배치 실행 상태를 점검한다(PRD 9.3).
 * 관리자 로그인 상태에서만 열린다(사이트 게이트 통과 + 관리자 세션). 점검을 마친 뒤 이 파일을 지워도 앱 동작에는 영향이 없다.
 */
require __DIR__ . '/src/bootstrap.php';

if (!Auth::isAdminSessionValid()) {
    Html::sendHeaders(true);
    header('Cache-Control: no-store');
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><title>접근 거부</title></head><body>'
        . '<p>설치 점검은 관리자만 볼 수 있습니다. <a href="login.php">로그인 화면</a>의 "관리자 로그인"으로 인증한 뒤 다시 여세요.</p></body></html>';
    exit;
}

/** php.ini 크기 값(예: 40M) → 바이트 */
function iniSize(string $value): int
{
    $value = trim($value);
    $num = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
}

const CHECK_PASS = 'pass';
const CHECK_WARN = 'warn';
const CHECK_FAIL = 'fail';
const CHECK_UNKNOWN = 'unknown';

/** @var array<string, list<array{label:string,status:string,detail:string,fix:string}>> */
$sections = [];

function addCheck(array &$sections, string $section, string $label, string $status, string $detail = '', string $fix = ''): void
{
    $sections[$section][] = compact('label', 'status', 'detail', 'fix');
}

// ── 1. PHP 환경 ───────────────────────────────────────────
$sec = 'PHP 환경';
addCheck(
    $sections, $sec, 'PHP 버전 8.1 이상',
    version_compare(PHP_VERSION, '8.1.0', '>=') ? CHECK_PASS : CHECK_FAIL,
    PHP_VERSION,
    'XAMPP를 PHP 8.1 이상 버전으로 업그레이드하세요.'
);
foreach (['pdo_sqlite', 'sqlite3', 'mbstring', 'fileinfo', 'dom', 'libxml'] as $ext) {
    addCheck(
        $sections, $sec, "확장 모듈 {$ext}",
        extension_loaded($ext) ? CHECK_PASS : CHECK_FAIL,
        extension_loaded($ext) ? '로드됨' : '로드되지 않음',
        "php.ini 에서 ;extension={$ext} 앞의 세미콜론을 지우고 Apache를 재시작하세요."
    );
}
$faviconReady = is_file(APP_ROOT . '/assets/favicon.ico') && is_file(APP_ROOT . '/assets/apple-touch-icon.png');
foreach (['gd' => 'favicon 생성(cli/make_favicon.php)에 필요' . ($faviconReady ? '. favicon 은 이미 만들어져 있어 없어도 됨' : ''),
             'openssl' => '메일 서버 보안 방식이 tls/ssl 일 때, SMTP 인증 비밀번호를 암호화해 저장할 때 필요'] as $ext => $why) {
    $ok = extension_loaded($ext) || ($ext === 'gd' && $faviconReady);
    addCheck($sections, $sec, "확장 모듈 {$ext}", $ok ? CHECK_PASS : CHECK_WARN,
        (extension_loaded($ext) ? '로드됨' : '로드되지 않음') . " — {$why}",
        "php.ini 에서 ;extension={$ext} 앞의 세미콜론을 지우고 Apache를 재시작하세요.");
}
foreach ([['upload_max_filesize', 10 * 1024 ** 2, '10M'], ['post_max_size', 40 * 1024 ** 2, '40M']] as [$key, $min, $minText]) {
    $v = (string) ini_get($key);
    addCheck($sections, $sec, "php.ini {$key} ≥ {$minText}", iniSize($v) >= $min ? CHECK_PASS : CHECK_FAIL, $v,
        "php.ini 의 {$key} 를 {$minText} 이상으로 바꾸고 Apache를 재시작하세요.");
}
$mfu = (int) ini_get('max_file_uploads');
addCheck($sections, $sec, 'php.ini max_file_uploads ≥ 10', $mfu >= 10 ? CHECK_PASS : CHECK_FAIL, (string) $mfu,
    'php.ini 의 max_file_uploads 를 10 이상으로 바꾸고 Apache를 재시작하세요.');
$tz = (string) ini_get('date.timezone');
addCheck(
    $sections, $sec, 'php.ini date.timezone = Asia/Seoul (웹)',
    $tz === 'Asia/Seoul' ? CHECK_PASS : CHECK_FAIL,
    ($tz === '' ? '(설정 없음)' : $tz) . ' / 앱 동작 시간대: ' . date_default_timezone_get(),
    'php.ini 의 date.timezone 을 Asia/Seoul 로 바꾸고 Apache를 재시작하세요. CLI용 php.ini 도 같이 확인하세요.'
);

$textRankOk = class_exists(\PhpScience\TextRank\Tool\Parser::class) && is_file(APP_ROOT . '/lib/LICENSE-TextRank.txt');
addCheck(
    $sections, $sec, 'TextRank 라이브러리 (lib/PhpScience/TextRank)',
    $textRankOk ? CHECK_PASS : CHECK_FAIL,
    $textRankOk ? '로드됨' : '없음',
    'PHP-Science-TextRank 의 src/ 폴더를 lib/PhpScience/TextRank/ 에 넣고, 라이선스를 lib/LICENSE-TextRank.txt 로 두세요.'
);

// ── 2. 폴더 쓰기 권한과 DB ────────────────────────────────
$sec = '폴더와 데이터베이스';
foreach (['data/' => DATA_DIR, 'storage/uploads/' => UPLOAD_DIR] as $label => $dir) {
    $ok = is_dir($dir) && is_writable($dir);
    addCheck(
        $sections, $sec, "{$label} 쓰기 권한",
        $ok ? CHECK_PASS : CHECK_FAIL,
        $ok ? '쓰기 가능' : (is_dir($dir) ? '쓰기 불가' : '폴더 없음'),
        "{$label} 폴더를 만들고 웹 서버(Apache) 계정에 쓰기 권한을 주세요."
    );
}
try {
    Db::pdo();
    $tables = Db::all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
    $names = array_column($tables, 'name');
    $expected = ['audit_log', 'members', 'send_log', 'send_log_batches', 'send_log_items',
        'app_settings', 'users', 'zzira', 'zzira_comments', 'zzira_votes', 'zzira_comment_reactions', 'scrap_seen', 'scrap_runs', 'zzira_attachments', 'zzira_images'];
    $missing = array_diff($expected, $names);
    $journal = (string) Db::value('PRAGMA journal_mode');
    addCheck(
        $sections, $sec, 'DB 생성 (data/app.sqlite)',
        $missing === [] ? CHECK_PASS : CHECK_FAIL,
        $missing === []
            ? '테이블 ' . count($expected) . '개, journal_mode=' . $journal . ', 스키마 버전 ' . Db::value('PRAGMA user_version')
            : '없는 테이블: ' . implode(', ', $missing),
        'data/ 쓰기 권한을 확인한 뒤 app.sqlite 를 지우고 다시 접속하세요.'
    );
} catch (Throwable $e) {
    addCheck($sections, $sec, 'DB 생성 (data/app.sqlite)', CHECK_FAIL, $e->getMessage(), 'pdo_sqlite 확장과 data/ 쓰기 권한을 확인하세요.');
}

// 사용자 계정(5b)
try {
    $userCount = UserRepository::count();
    addCheck($sections, $sec, '사용자 계정 (users)', $userCount > 0 ? CHECK_PASS : CHECK_WARN,
        $userCount . '명 등록 (사용 ' . (int) Db::value("SELECT COUNT(*) FROM users WHERE status = 'active'") . '명)',
        '관리 탭 → 사용자 계정에서 로그인할 사용자를 추가하세요. 사용자가 없으면 관리자만 사이트에 들어올 수 있습니다.');
} catch (Throwable $e) {
    addCheck($sections, $sec, '사용자 계정 (users)', CHECK_FAIL, $e->getMessage(), 'DB 스키마를 확인하세요.');
}

// 6c: 관리자 비밀번호 초기 상태(날짜)면 경고(실패 아님)
try {
    $passDefault = AdminPassword::isDefault();
    $changedAt = AppSettings::get(AdminPassword::KEY_CHANGED_AT);
    addCheck($sections, $sec, '관리자 비밀번호', $passDefault ? CHECK_WARN : CHECK_PASS,
        $passDefault ? '관리자 비밀번호가 초기 상태(날짜)입니다' : '직접 설정함' . ($changedAt ? ' (마지막 변경 ' . $changedAt . ')' : ''),
        '관리 탭 → 사용자 계정 맨 위 "관리자 비밀번호 변경"에서 바꾸세요. 잊었으면 서버에서 php cli\\reset_admin_password.php 로 초기 상태로 되돌립니다.');
} catch (Throwable $e) {
    addCheck($sections, $sec, '관리자 비밀번호', CHECK_FAIL, $e->getMessage(), 'DB 스키마를 확인하세요.');
}

// 5d: 파일 보관 기간·업로드 폴더 용량·댓글/추천 집계
$uploadBytes = 0;
$uploadFiles = 0;
if (is_dir(UPLOAD_DIR)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && !in_array($file->getFilename(), ['.htaccess', 'index.html'], true)) {
            $uploadBytes += $file->getSize();
            $uploadFiles++;
        }
    }
}
addCheck($sections, $sec, 'storage/uploads/ 용량', CHECK_PASS,
    Upload::formatBytes($uploadBytes) . " (파일 {$uploadFiles}개) · 이미지·첨부 보관 기간 " . Retention::fileRetentionDays() . '일(FILE_RETENTION_DAYS), 삭제 글 복구 기간 ' . Config::int('DELETED_RETENTION_DAYS', 3) . '일',
    '');
try {
    $mismatch = Counters::mismatches();
    addCheck($sections, $sec, '댓글·추천 집계값', $mismatch === 0 ? CHECK_PASS : CHECK_WARN,
        $mismatch === 0 ? '실제 행 수와 일치' : "어긋난 글 {$mismatch}건",
        'C:\\xampp\\php\\php.exe cli\\rebuild_counters.php 로 다시 맞추세요.');
} catch (Throwable $e) {
    addCheck($sections, $sec, '댓글·추천 집계값', CHECK_FAIL, $e->getMessage(), 'DB 스키마(v6)를 확인하세요.');
}

// ── 3. 메일 발송 ─────────────────────────────────────────
$sec = '메일 발송';
$pm = MailConfig::phpMailerAvailable();
addCheck(
    $sections, $sec, 'PHPMailer (lib/PHPMailer)',
    $pm ? CHECK_PASS : CHECK_FAIL,
    $pm ? '로드됨 (' . \PHPMailer\PHPMailer\PHPMailer::VERSION . ')' : '없음',
    'PHPMailer 의 src/ 안 PHPMailer.php, SMTP.php, Exception.php 를 lib/PHPMailer/ 에 넣으세요.'
);
$baseUrl = trim((string) Config::get('BASE_URL', ''));
addCheck(
    $sections, $sec, 'config.php BASE_URL',
    $baseUrl !== '' ? CHECK_PASS : CHECK_WARN,
    $baseUrl !== '' ? $baseUrl : '(비어 있음)',
    '메일 속 원문 링크용 주소입니다. 정기 발송(스케줄러)은 웹 주소를 알 수 없어 꼭 필요합니다. 예: ' . Url::base()
);
// 5c: SMTP 인증 비밀번호 암호화 키(data/app.key). 비밀번호 값은 표시하지 않는다
$keyWhy = SecretBox::unavailableReason();
addCheck(
    $sections, $sec, '암호화 키 data/app.key (SMTP 인증 비밀번호용)',
    $keyWhy === null ? CHECK_PASS : CHECK_WARN,
    $keyWhy === null ? (is_file(SecretBox::keyPath()) ? '있음' : '아직 없음(처음 비밀번호를 저장할 때 만듦)') : $keyWhy,
    'openssl 확장을 켜고 data/ 폴더에 쓰기 권한을 주세요. 인증이 필요 없는 메일 서버라면 무시해도 됩니다.'
);
$mailSettings = MailSettings::get();
addCheck(
    $sections, $sec, 'SMTP 인증', CHECK_PASS,
    $mailSettings['smtp_auth'] ? '사용(' . ($mailSettings['auth_source'] === 'config' ? 'config.php 값' : '메일 설정 화면 값') . ', ID ' . ($mailSettings['smtp_user'] !== '' ? '입력됨' : '없음') . ', 비밀번호 ' . ($mailSettings['smtp_pass'] !== '' ? '입력됨' : ($mailSettings['pass_unreadable'] ? '읽을 수 없음' : '없음')) . ')' : '사용 안 함(인증 없는 메일 서버)',
    ''
);
$mailProblems = MailConfig::check();
addCheck(
    $sections, $sec, '메일 설정 (관리 → 메일 설정)',
    $mailProblems === [] ? CHECK_PASS : CHECK_WARN,
    $mailProblems === [] ? '입력 완료' : '비어 있는 항목: ' . implode(', ', $mailProblems),
    '관리 탭 → 메일 설정에서 발신 주소·메일 서버·포트·보안 방식(인증이 필요한 서버는 SMTP 인증 ID·비밀번호)을 입력하고 테스트 메일로 확인하세요.'
);
if ($mailSettings['allow_self_signed']) {
    addCheck($sections, $sec, '자체 서명 인증서 허용', CHECK_WARN, '켜짐 — 메일 서버 인증서를 검증하지 않음',
        '사내 서버가 자체 서명 인증서를 쓸 때만 켜 두세요(메일 설정 화면 또는 config.php 의 SMTP_ALLOW_SELF_SIGNED).');
}

// ── 스크랩(5f) ───────────────────────────────────────────
$sec = '스크랩(뉴스 자동 등록)';
try {
    addCheck($sections, $sec, '자동 실행', CHECK_PASS,
        (ScrapTopics::enabled() ? '켜짐(매일 06:30)' : '꺼짐(기본)') . ' · 선택한 주제 ' . ScrapTopics::count() . '개', '');
} catch (Throwable $e) {
    addCheck($sections, $sec, '자동 실행', CHECK_FAIL, $e->getMessage(), 'DB 스키마(v7)를 확인하세요.');
}
// news.google.com HTTPS 접속 가능 여부(타임아웃 5초, SCRAP_PROXY 반영). 스크랩을 쓰지 않으면 무시해도 되므로 경고 수준
$scrapProxy = trim((string) Config::get('SCRAP_PROXY', ''));
$reach = null;
if (function_exists('curl_init')) {
    $ch = curl_init('https://news.google.com/rss?hl=ko&gl=KR&ceid=KR:ko');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 5, CURLOPT_USERAGENT => 'ZZieRa-Scrap/1.0']);
    if ($scrapProxy !== '') {
        curl_setopt($ch, CURLOPT_PROXY, $scrapProxy);
    }
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);
    $reach = $err === '' && $code >= 200 && $code < 400 ? 'HTTP ' . $code : null;
    $reachDetail = $reach ?? ($err !== '' ? '접속 실패: ' . ($scrapProxy !== '' ? str_replace($scrapProxy, '(프록시)', $err) : $err) : 'HTTP ' . $code);
} else {
    $reachDetail = 'cURL 확장이 없어 확인하지 못함(스크랩은 stream 방식으로 접속)';
}
addCheck($sections, $sec, 'news.google.com HTTPS 접속' . ($scrapProxy !== '' ? '(프록시 경유)' : ''),
    $reach !== null ? CHECK_PASS : CHECK_WARN, mb_substr($reachDetail, 0, 200),
    '스크랩을 쓰려면 서버가 외부 인터넷에 나갈 수 있어야 합니다. 사내 프록시가 필요하면 config.php 의 SCRAP_PROXY 를 설정하세요(스크랩을 쓰지 않으면 무시).');

// ── 4. 웹 접근 차단 (서버 자신에게 HTTP 요청) ─────────────
$sec = '웹 접근 차단';

/** @return array{status:int, body:string}|null 요청 실패 시 null */
function httpGet(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body === false || $status === 0) ? null : ['status' => $status, 'body' => (string) $body];
    }
    if (!ini_get('allow_url_fopen')) {
        return null;
    }
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0]]);
    $body = @file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    if ($body === false || !preg_match('/\s(\d{3})\s/', $statusLine . ' ', $m)) {
        return null;
    }
    return ['status' => (int) $m[1], 'body' => (string) $body];
}

$base = Url::base();
// 대조 요청: 공개 허용 페이지인 login.php(5b 이후 index.php 는 로그인 없이는 302)
$control = httpGet($base . '/login.php');
if ($control === null || $control['status'] !== 200) {
    addCheck(
        $sections, $sec, '자기 자신에게 HTTP 요청',
        CHECK_UNKNOWN,
        $base . '/login.php → ' . ($control === null ? '요청 실패' : 'HTTP ' . $control['status']),
        '서버가 자기 자신(' . ($_SERVER['HTTP_HOST'] ?? '') . ')에 접속할 수 없습니다. 아래 주소들을 브라우저로 직접 열어 403인지 확인하세요.'
    );
}
$targets = [
    'data/app.sqlite', 'config.php', 'src/Db.php', 'src/bootstrap.php',
    'lib/index.html', 'lib/autoload.php', 'cli/index.html', 'cli/cron_send.php', 'cli/backup.bat', 'docs/PRD.md',
    'storage/uploads/index.html', 'data/index.html', 'data/logs/', 'README.md',
];
// 사이트 접근 게이트(5b): 로그인 없이 부르면 페이지는 302(로그인 화면), API 는 401 이어야 한다
foreach (['index.php' => 302, 'api/post_list.php' => 401] as $path => $expect) {
    $res = ($control !== null && $control['status'] === 200) ? httpGet($base . '/' . $path) : null;
    if ($res === null) {
        addCheck($sections, $sec, "로그인 없이 {$path}", CHECK_UNKNOWN, '확인 불가', "브라우저의 시크릿 창으로 {$base}/{$path} 를 열어 로그인 화면으로 가는지 확인하세요.");
        continue;
    }
    $ok = $res['status'] === $expect && ($expect !== 401 || str_contains($res['body'], 'login_required'));
    addCheck($sections, $sec, "로그인 없이 {$path}", $ok ? CHECK_PASS : CHECK_FAIL, 'HTTP ' . $res['status'] . " (기대값 {$expect})",
        'src/bootstrap.php 의 AccessGate::enforce() 호출과, 해당 파일이 ZZ_PUBLIC 을 정의하지 않았는지 확인하세요.');
}
foreach ($targets as $path) {
    $url = $base . '/' . $path;
    $res = ($control !== null && $control['status'] === 200) ? httpGet($url) : null;
    if ($res === null) {
        addCheck($sections, $sec, $path, CHECK_UNKNOWN, '확인 불가', '브라우저로 ' . $url . ' 을 열어 403인지 확인하세요.');
        continue;
    }
    $blocked = in_array($res['status'], [401, 403, 404], true)
        || ($res['status'] === 200 && trim($res['body']) === '');
    addCheck(
        $sections, $sec, $path,
        $blocked ? CHECK_PASS : CHECK_FAIL,
        'HTTP ' . $res['status'] . ($res['status'] === 200 ? ' (본문 ' . strlen($res['body']) . '바이트)' : ''),
        'Apache 설정에서 AllowOverride All(또는 AuthConfig 이상)이 허용되는지, 해당 폴더에 .htaccess 가 있는지 확인하세요.'
    );
}

// ── 5. 배치 실행(작업 스케줄러) ───────────────────────────
$sec = '배치 실행';
$logs = glob(DATA_DIR . '/logs/send_*.log') ?: [];
$lastLog = $logs === [] ? 0 : max(array_map('filemtime', $logs));
addCheck($sections, $sec, '발송 확인 작업(1분마다, data/logs/send_*.log)',
    $lastLog > time() - 600 ? CHECK_PASS : CHECK_WARN,
    $lastLog > 0 ? '마지막 기록 ' . date('Y-m-d H:i:s', $lastLog) : '기록 없음',
    '관리자 권한으로 cli\\register_tasks.bat 를 실행해 작업 스케줄러에 등록하고, 작업 스케줄러에서 마지막 실행 결과를 확인하세요.');
try {
    $lastSend = Db::row("SELECT sent_at, status FROM send_log WHERE trigger_type = 'scheduled' ORDER BY id DESC LIMIT 1");
    $recentSend = $lastSend !== null && strtotime($lastSend['sent_at']) > strtotime(Time::now()) - 2 * 86400;
    addCheck($sections, $sec, '최근 정기 발송(send_log)',
        $recentSend ? CHECK_PASS : CHECK_WARN,
        $lastSend !== null ? $lastSend['sent_at'] . ' ' . $lastSend['status'] : '기록 없음',
        '자동 발송이 켜져 있는지("메일 발송" 화면), 발송 확인 작업이 실행되는지 확인하세요. 새로 설치했다면 다음 날 07:00 이후 다시 확인하세요.');
    $lastClean = Db::value("SELECT created_at FROM audit_log WHERE event = 'cleanup' ORDER BY id DESC LIMIT 1");
    $recentClean = $lastClean !== null && strtotime((string) $lastClean) > strtotime(Time::now()) - 2 * 86400;
    addCheck($sections, $sec, '최근 정리 배치(audit_log cleanup)',
        $recentClean ? CHECK_PASS : CHECK_WARN,
        $lastClean !== null ? (string) $lastClean : '기록 없음',
        '작업 스케줄러의 정리 작업(매일 03:00)을 확인하세요. 지금 확인하려면 cli\\run_cleanup.bat 를 한 번 실행하세요.');
} catch (Throwable $e) {
    addCheck($sections, $sec, '발송·정리 기록', CHECK_FAIL, $e->getMessage(), 'DB 를 확인하세요.');
}

// ── 출력 ─────────────────────────────────────────────────
$labels = [CHECK_PASS => '통과', CHECK_WARN => '경고', CHECK_FAIL => '실패', CHECK_UNKNOWN => '확인 불가'];
$totals = array_fill_keys(array_keys($labels), 0);
foreach ($sections as $checks) {
    foreach ($checks as $c) {
        $totals[$c['status']]++;
    }
}

Html::sendHeaders();
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ZZieRa 설치 점검</title>
<style>
    body { font-family: "Malgun Gothic", system-ui, sans-serif; margin: 0; padding: 16px; background: #f5f7fa; color: #1f2933; }
    .wrap { max-width: 900px; margin: 0 auto; }
    .notice { background: #fff4e5; border: 1px solid #f0b75a; padding: 12px 16px; border-radius: 8px; }
    .summary span { display: inline-block; margin-right: 12px; }
    table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 24px; }
    th, td { border-bottom: 1px solid #e4e7eb; padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: #eef2f7; }
    td.st { white-space: nowrap; font-weight: bold; }
    .pass { color: #1b873f; } .warn { color: #b7791f; } .fail { color: #c53030; } .unknown { color: #616e7c; }
    .fix { color: #616e7c; font-size: .9em; }
    code { word-break: break-all; }
</style>
</head>
<body>
<div class="wrap">
    <h1>ZZieRa 설치 점검</h1>
    <p class="notice">관리자만 볼 수 있는 페이지입니다. <strong>점검을 마친 뒤에는 이 파일(install_check.php)을 삭제해도 됩니다</strong>(앱 동작에는 영향 없음). 다시 점검할 때 배포 폴더에 복사해 쓰세요.</p>
    <p class="summary">
        <?php foreach ($labels as $key => $label): ?>
            <span class="<?= Html::e($key) ?>"><?= Html::e($label) ?> <?= (int) $totals[$key] ?></span>
        <?php endforeach; ?>
        <span>점검 시각 <?= Html::e(Time::now()) ?> (KST)</span>
    </p>
    <?php foreach ($sections as $title => $checks): ?>
        <h2><?= Html::e($title) ?></h2>
        <table>
            <thead><tr><th>항목</th><th>결과</th><th>상세</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td><code><?= Html::e($c['label']) ?></code></td>
                    <td class="st <?= Html::e($c['status']) ?>"><?= Html::e($labels[$c['status']]) ?></td>
                    <td>
                        <?= Html::e($c['detail']) ?>
                        <?php if ($c['status'] !== CHECK_PASS && $c['fix'] !== ''): ?>
                            <div class="fix">해결: <?= Html::e($c['fix']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</div>
</body>
</html>
