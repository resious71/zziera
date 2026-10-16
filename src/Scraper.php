<?php
/**
 * 스크랩 (5f) — 고른 주제의 최신 기사를 구글 뉴스 RSS 에서 찾아 찌라시로 등록한다.
 *
 *  - 접속 대상은 news.google.com 고정(FEED_BASE). 사용자 입력은 검색어로만 URL 인코딩해 붙인다(SSRF 방지).
 *    HTTPS 인증서 검증은 끄지 않고, 리다이렉트를 따라가지 않는다. 기사 원문 페이지에는 접속하지 않는다.
 *  - 저장 내용: 제목(100자), 출처(매체명), 발행 시각, RSS 설명에서 뽑은 짧은 요약, 원문 링크, 관련 보도(최대 4건).
 *    본문은 HtmlSanitizer 를 거쳐 저장한다. 작성자·실행 관리자 정보는 저장하지 않는다(source='scrap' 등 출처 구분만).
 *  - 중복 방지: URL 정규화 SHA-256(scrap_seen), 정규화 제목 동일, 최근 7일 제목과 글자 2-gram 유사도 0.8 이상, 같은 실행 안 중복.
 *  - 한 주제가 실패해도 다음 주제를 계속한다. 모두 실패하면 failed, 일부면 partial. 결과는 scrap_runs 에 남긴다.
 *  - 테스트: self::$fixture(파일 또는 폴더)를 주면 네트워크 대신 샘플 XML 을 읽는다(cli/cron_scrap.php --fixture).
 */
final class Scraper
{
    private const FEED_BASE = 'https://news.google.com/rss/search?q=';
    private const FEED_TAIL = '&hl=ko&gl=KR&ceid=KR:ko';
    private const MAX_BYTES = 3000000;
    public const RUN_LIMIT_SEC = 120;
    private const SIMILAR = 0.8;
    private const RELATED_MAX = 4;
    private const STALE_MINUTES = 15;
    public const NOTICE = '※ 스크랩으로 자동 등록된 글입니다. 전문은 원문 링크에서 확인하세요.';

    /** 테스트용 샘플 XML: 파일이면 모든 주제에 같은 파일, 폴더면 <순번>.xml(없으면 default.xml) */
    public static ?string $fixture = null;

    /** 구글 뉴스 RSS 검색 주소(호스트 고정, 검색어만 인코딩) */
    public static function feedUrl(string $query): string
    {
        return self::FEED_BASE . rawurlencode($query . ' when:1d') . self::FEED_TAIL;
    }

    // ── 실행 ──────────────────────────────────────────────

    /**
     * @param 'auto'|'manual' $trigger
     * @return array{busy:bool, run_id:?int, status:string, created:int, skipped:int, topics:array, candidates:list<array>}
     */
    public static function run(string $trigger, bool $dryRun = false): array
    {
        $lock = FileLock::acquire('scrap');
        if ($lock === null) {
            return ['busy' => true, 'run_id' => null, 'status' => 'running', 'created' => 0, 'skipped' => 0, 'topics' => [], 'candidates' => []];
        }
        try {
            @set_time_limit(self::RUN_LIMIT_SEC + 10);
            self::markStale();
            $topics = ScrapTopics::resolved();
            $per = ScrapTopics::settings()['per_topic'];
            $maxRun = max(1, Config::int('SCRAP_MAX_PER_RUN', 30));
            $maxAge = max(1, Config::int('SCRAP_MAX_AGE_HOURS', 36));
            $now = Time::nowObj();
            $runId = null;
            if (!$dryRun) {
                Db::exec("INSERT INTO scrap_runs (trigger_type, started_at, status, topic_count) VALUES (?, ?, 'running', ?)",
                    [$trigger, Time::now(), count($topics)]);
                $runId = Db::lastId();
            }

            $since7 = $now->modify('-7 days')->format('Y-m-d H:i:s');
            $recentTitles = Db::all('SELECT title_norm FROM scrap_seen WHERE created_at >= ?', [$since7]);
            $knownTitles = array_column($recentTitles, 'title_norm');
            $runHashes = [];
            $created = 0;
            $skipped = 0;
            $results = [];
            $candidates = [];
            $errors = 0;
            $deadline = microtime(true) + self::RUN_LIMIT_SEC - 10;

            foreach ($topics as $i => $t) {
                $label = $t['label'];
                $res = ['created' => 0, 'skipped' => 0, 'error' => null];
                if ($created >= $maxRun) {
                    $res['error'] = "전체 상한({$maxRun}건)에 도달해 건너뜀";
                    $results[$label] = $res;
                    continue;
                }
                if (microtime(true) > $deadline) {
                    $res['error'] = '실행 시간 제한(' . self::RUN_LIMIT_SEC . '초)으로 건너뜀';
                    $results[$label] = $res;
                    $errors++;
                    continue;
                }
                $feed = self::loadFeed($t['query'], $i + 1);
                if (!$feed['ok']) {
                    $res['error'] = $feed['error'];
                    $results[$label] = $res;
                    $errors++;
                    continue;
                }
                $items = self::parse($feed['body']);
                if ($items === null) {
                    $res['error'] = 'RSS 형식을 읽을 수 없음';
                    $results[$label] = $res;
                    $errors++;
                    continue;
                }
                foreach ($items as $it) {
                    if ($res['created'] >= $per || $created >= $maxRun) {
                        break;
                    }
                    if ($it['published'] === null || $it['published'] < $now->modify("-{$maxAge} hours") || $it['published'] > $now->modify('+1 hour')) {
                        continue; // 너무 오래되었거나 시각을 알 수 없는 기사
                    }
                    $hash = self::urlHash($it['link']);
                    $norm = self::normalizeTitle($it['title']);
                    if ($norm === '' || isset($runHashes[$hash])
                        || Db::value('SELECT 1 FROM scrap_seen WHERE url_hash = ?', [$hash]) !== null
                        || self::similarToAny($norm, $knownTitles)) {
                        $res['skipped']++;
                        continue;
                    }
                    $runHashes[$hash] = true;
                    $knownTitles[] = $norm;
                    if ($dryRun) {
                        $candidates[] = ['topic' => $label, 'title' => $it['title'], 'source' => $it['source'], 'published' => $it['published']->format('Y-m-d H:i')];
                    } else {
                        $postId = self::createPost($it, $label);
                        Db::exec('INSERT OR REPLACE INTO scrap_seen (url_hash, title_norm, zzira_id, created_at) VALUES (?, ?, ?, ?)',
                            [$hash, $norm, $postId, Time::now()]);
                    }
                    $res['created']++;
                    $created++;
                }
                $skipped += $res['skipped'];
                $results[$label] = $res;
            }

            $status = $topics === [] || $errors === count($topics) ? 'failed' : ($errors > 0 ? 'partial' : 'success');
            $message = $topics === [] ? json_encode(['오류' => '선택한 주제가 없습니다'], JSON_UNESCAPED_UNICODE)
                : json_encode($results, JSON_UNESCAPED_UNICODE);
            if (!$dryRun) {
                Db::exec('UPDATE scrap_runs SET finished_at = ?, status = ?, created_count = ?, skipped_count = ?, message = ? WHERE id = ?',
                    [Time::now(), $status, $created, $skipped, $message, $runId]);
                Audit::log($trigger === 'auto' ? 'scrap_run_auto' : 'scrap_run_manual', 'scrap', $runId,
                    '스크랩 ' . ($trigger === 'auto' ? '자동' : '수동') . " 실행 {$status}: 주제 " . count($topics) . "개, 등록 {$created}건, 건너뜀 {$skipped}건");
            }
            return ['busy' => false, 'run_id' => $runId, 'status' => $status, 'created' => $created, 'skipped' => $skipped, 'topics' => $results, 'candidates' => $candidates];
        } finally {
            $lock->release();
        }
    }

    /** 비정상 종료로 running 에 남은 실행 기록을 failed 로 정리(실행한 것으로 간주) */
    public static function markStale(): int
    {
        $cut = Time::nowObj()->modify('-' . self::STALE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        return Db::exec("UPDATE scrap_runs SET status = 'failed', finished_at = ?, message = ? WHERE status = 'running' AND started_at < ?",
            [Time::now(), json_encode(['오류' => '비정상 종료(일정 시간 running 상태로 남아 정리함)'], JSON_UNESCAPED_UNICODE), $cut]);
    }

    /** 오늘(KST) 자동 실행 기록(running 제외). 없으면 null */
    public static function todayAutoRun(): ?array
    {
        [$from, $to] = Time::dayRange(Time::today());
        return Db::row("SELECT id, started_at, status FROM scrap_runs WHERE trigger_type = 'auto' AND status <> 'running' AND started_at >= ? AND started_at < ? ORDER BY id DESC LIMIT 1", [$from, $to]);
    }

    /** 오래된 기록 정리(6단계 정리 배치가 부른다): scrap_seen 30일, scrap_runs 90일 */
    public static function cleanup(bool $dryRun = false): array
    {
        $seenCut = Time::nowObj()->modify('-30 days')->format('Y-m-d H:i:s');
        $runCut = Time::nowObj()->modify('-90 days')->format('Y-m-d H:i:s');
        if ($dryRun) {
            return ['seen' => (int) Db::value('SELECT COUNT(*) FROM scrap_seen WHERE created_at < ?', [$seenCut]),
                    'runs' => (int) Db::value("SELECT COUNT(*) FROM scrap_runs WHERE started_at < ? AND status <> 'running'", [$runCut])];
        }
        return ['seen' => Db::exec('DELETE FROM scrap_seen WHERE created_at < ?', [$seenCut]),
                'runs' => Db::exec("DELETE FROM scrap_runs WHERE started_at < ? AND status <> 'running'", [$runCut])];
    }

    // ── 수집 ──────────────────────────────────────────────

    /** @return array{ok:bool, body?:string, error?:string} */
    private static function loadFeed(string $query, int $index): array
    {
        if (self::$fixture !== null) {
            $path = self::$fixture;
            if (is_dir($path)) {
                $path = is_file("{$path}/{$index}.xml") ? "{$path}/{$index}.xml" : "{$path}/default.xml";
            }
            $body = is_file($path) ? file_get_contents($path) : false;
            return $body === false ? ['ok' => false, 'error' => '샘플 XML 없음: ' . basename($path)] : ['ok' => true, 'body' => $body];
        }
        return self::fetch(self::feedUrl($query), $query);
    }

    /** RSS 받기(HTTPS·호스트 고정, 인증서 검증 유지, 리다이렉트 안 따라감) */
    private static function fetch(string $url, string $query): array
    {
        $timeout = max(1, Config::int('SCRAP_TIMEOUT_SEC', 10));
        $proxy = trim((string) Config::get('SCRAP_PROXY', ''));
        $hint = '"' . mb_substr($query, 0, 30) . '"';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'ZZieRa-Scrap/1.0',
                CURLOPT_ENCODING       => '',
            ]);
            if ($proxy !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_errno($ch) ? self::cleanError(curl_error($ch), $proxy) : '';
            curl_close($ch);
            if ($body === false || $err !== '') {
                return ['ok' => false, 'error' => "{$hint} 접속 실패: " . ($err !== '' ? $err : '응답 없음')];
            }
        } else {
            $ctx = stream_context_create([
                'http' => ['timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'ZZieRa-Scrap/1.0']
                    + ($proxy !== '' ? ['proxy' => preg_replace('#^http://#i', 'tcp://', $proxy), 'request_fulluri' => true] : []),
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
            $line = $http_response_header[0] ?? '';
            $code = preg_match('/\s(\d{3})\s/', $line . ' ', $m) ? (int) $m[1] : 0;
            if ($body === false) {
                return ['ok' => false, 'error' => "{$hint} 접속 실패: " . self::cleanError((string) (error_get_last()['message'] ?? '응답 없음'), $proxy)];
            }
        }
        if ($code !== 200) {
            return ['ok' => false, 'error' => "{$hint} HTTP {$code}"];
        }
        if (strlen((string) $body) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => "{$hint} 응답이 너무 큼"];
        }
        return ['ok' => true, 'body' => (string) $body];
    }

    /** 오류 문구에서 프록시 주소(계정 정보가 들어 있을 수 있음)를 지운다 */
    private static function cleanError(string $msg, string $proxy): string
    {
        $msg = $proxy !== '' ? str_replace($proxy, '(프록시)', $msg) : $msg;
        $msg = preg_replace('#[a-z][a-z0-9+.\-]*://[^\s@/]+@#i', '://', $msg) ?? $msg; // user:pass@ 제거
        return mb_substr(trim($msg), 0, 200);
    }

    /**
     * RSS 2.0 파싱(외부 엔티티·DTD 거부). 발행 시각 최신순.
     * @return list<array{title:string, link:string, source:string, published:?DateTimeImmutable, summary:string, related:list<array{title:string, link:string, source:string}>}>|null
     */
    public static function parse(string $xml): ?array
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null; // DTD·엔티티가 있는 문서는 받지 않는다(XXE 방지)
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false || !isset($doc->channel)) {
            return null;
        }
        $kst = new DateTimeZone('Asia/Seoul');
        $out = [];
        foreach ($doc->channel->item as $item) {
            $source = self::text((string) ($item->source ?? ''));
            $title = self::text((string) $item->title);
            if ($source !== '' && str_ends_with($title, ' - ' . $source)) {
                $title = trim(mb_substr($title, 0, mb_strlen($title) - mb_strlen(' - ' . $source)));
            }
            $link = trim((string) $item->link);
            if ($title === '' || !preg_match('#^https?://#i', $link) || filter_var($link, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            try {
                $pub = trim((string) $item->pubDate) !== '' ? (new DateTimeImmutable((string) $item->pubDate))->setTimezone($kst) : null;
            } catch (Throwable) {
                $pub = null;
            }
            [$summary, $related] = self::describe((string) $item->description, $title, $source, $link);
            $out[] = ['title' => $title, 'link' => $link, 'source' => $source, 'published' => $pub, 'summary' => $summary, 'related' => $related];
        }
        usort($out, static fn ($a, $b) => ($b['published']?->getTimestamp() ?? 0) <=> ($a['published']?->getTimestamp() ?? 0));
        return $out;
    }

    /** 설명(HTML)에서 요약 글과 관련 보도 목록을 뽑는다 */
    private static function describe(string $html, string $title, string $source, string $link): array
    {
        $related = [];
        if ($html !== '' && stripos($html, '<li') !== false) {
            $dom = new DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            foreach ($dom->getElementsByTagName('li') as $li) {
                $a = $li->getElementsByTagName('a')->item(0);
                if ($a === null) {
                    continue;
                }
                $href = trim($a->getAttribute('href'));
                $text = self::text($a->textContent);
                $font = $li->getElementsByTagName('font')->item(0);
                $src = $font ? self::text($font->textContent) : '';
                if ($text === '' || !preg_match('#^https?://#i', $href) || $href === $link || self::normalizeTitle($text) === self::normalizeTitle($title)) {
                    continue;
                }
                $related[] = ['title' => mb_substr($text, 0, 150), 'link' => $href, 'source' => $src];
                if (count($related) >= self::RELATED_MAX) {
                    break;
                }
            }
        }
        // 요약: 설명 글에서 제목·매체명을 뺀 나머지(관련 보도 목록이 있으면 요약으로 쓰지 않는다)
        $noScript = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html; // 스크립트·스타일은 내용째 버린다
        $plain = $related === [] ? self::text(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $noScript))) : '';
        foreach ([$title, $source] as $cut) {
            if ($cut !== '') {
                $plain = trim(str_replace($cut, '', $plain));
            }
        }
        $plain = trim($plain, " \t·-|");
        return [mb_strlen($plain) >= 10 ? mb_substr($plain, 0, 120) : '', $related];
    }

    private static function text(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    // ── 등록 ──────────────────────────────────────────────

    /** 찌라시 등록(본문 정화, 요약은 RSS 요약 또는 "출처 · 발행 시각") */
    private static function createPost(array $it, string $topic): int
    {
        $e = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = mb_substr($it['title'], 0, Config::int('TITLE_MAX', 100));
        $when = $it['published']?->format('Y-m-d H:i') ?? '';
        $summary = $it['summary'] !== '' ? $it['summary'] : trim(($it['source'] !== '' ? $it['source'] : '출처 미상') . ' · ' . $when, ' ·');
        $html = '<p>' . $e($summary) . '</p>'
            . '<p>출처: ' . $e($it['source'] !== '' ? $it['source'] : '미상') . ($when !== '' ? ' · ' . $e($when) : '')
            . ' · <a href="' . $e($it['link']) . '" rel="noopener nofollow" target="_blank">원문 보기</a></p>';
        if ($it['related'] !== []) {
            $html .= '<p>관련 보도</p><ul>';
            foreach ($it['related'] as $r) {
                $html .= '<li><a href="' . $e($r['link']) . '" rel="noopener nofollow" target="_blank">' . $e($r['title']) . '</a>'
                    . ($r['source'] !== '' ? ' (' . $e($r['source']) . ')' : '') . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<p>' . $e(self::NOTICE) . '</p>';
        $body = HtmlSanitizer::sanitize($html, static fn () => false);
        if ($body['errors'] !== []) {
            throw new RuntimeException('스크랩 본문 정화 실패: ' . implode(' / ', $body['errors']));
        }
        return PostRepository::create($title, $body, [], [
            'source' => 'scrap', 'source_name' => $it['source'] !== '' ? mb_substr($it['source'], 0, 100) : null,
            'source_url' => $it['link'], 'scrap_topic' => $topic, 'summary' => $summary,
        ]);
    }

    // ── 중복 판정 ─────────────────────────────────────────

    /** URL 정규화(스킴·호스트 소문자, 추적용 쿼리·조각 제거) 후 SHA-256 */
    public static function urlHash(string $url): string
    {
        $p = parse_url($url) ?: [];
        $query = [];
        parse_str($p['query'] ?? '', $query);
        foreach (array_keys($query) as $k) {
            if (str_starts_with((string) $k, 'utm_') || in_array($k, ['oc', 'hl', 'gl', 'ceid'], true)) {
                unset($query[$k]);
            }
        }
        ksort($query);
        $norm = strtolower($p['scheme'] ?? '') . '://' . strtolower($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '')
            . rtrim($p['path'] ?? '/', '/') . ($query !== [] ? '?' . http_build_query($query) : '');
        return hash('sha256', $norm);
    }

    /** 제목 정규화: 매체명 접미 제거된 제목에서 공백·구두점·기호 제거, 소문자 */
    public static function normalizeTitle(string $title): string
    {
        $t = preg_replace('/\s+-\s+[^-]{1,30}$/u', '', $title) ?? $title; // " - 매체명" 접미
        $t = mb_strtolower($t);
        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $t) ?? '';
    }

    /** 글자 2-gram 다이스 유사도 */
    public static function similarity(string $a, string $b): float
    {
        $grams = static function (string $s): array {
            $chars = mb_str_split($s);
            $g = [];
            for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
                $g[$chars[$i] . $chars[$i + 1]] = true;
            }
            return $g;
        };
        $ga = $grams($a);
        $gb = $grams($b);
        if ($ga === [] || $gb === []) {
            return $a === $b ? 1.0 : 0.0;
        }
        return 2 * count(array_intersect_key($ga, $gb)) / (count($ga) + count($gb));
    }

    private static function similarToAny(string $norm, array $known): bool
    {
        foreach ($known as $k) {
            if ($k === $norm || self::similarity($norm, (string) $k) >= self::SIMILAR) {
                return true;
            }
        }
        return false;
    }
}
