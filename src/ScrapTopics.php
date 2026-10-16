<?php
/**
 * 스크랩 주제 (5f) — 제안 목록과 관리자가 고른 주제·직접 입력 키워드·주제당 건수·자동 실행 설정
 *
 * app_settings: scrap_enabled('0'/'1', 기본 '0'), scrap_topics(JSON {"keys":[...], "keywords":[...]}), scrap_per_topic(기본 '3')
 * 검색어는 구글 뉴스 RSS 검색어로만 쓰인다(Scraper 가 URL 인코딩해 news.google.com 고정 주소에 붙인다).
 */
final class ScrapTopics
{
    public const ENABLED_KEY = 'scrap_enabled';
    public const TOPICS_KEY = 'scrap_topics';
    public const PER_TOPIC_KEY = 'scrap_per_topic';
    public const PER_TOPIC_DEFAULT = 3;
    public const KEYWORD_MIN = 2;
    public const KEYWORD_MAX = 30;
    public const KEYWORD_COUNT_MAX = 10;

    /** 제안 목록: 키 => [표시 이름, 검색어] */
    private const SUGGESTIONS = [
        'economy'  => ['경제', '경제'],
        'stock'    => ['증권', '증시 OR 코스피'],
        'itai'     => ['IT/AI', 'IT OR 인공지능'],
        'semi'     => ['반도체', '반도체'],
        'energy'   => ['배터리·에너지', '배터리 OR 2차전지 OR 에너지'],
        'auto'     => ['자동차', '자동차'],
        'realty'   => ['부동산', '부동산'],
        'politics' => ['정치', '정치'],
        'society'  => ['사회', '사회'],
        'world'    => ['국제', '국제'],
        'sports'   => ['스포츠', '스포츠'],
        'ent'      => ['연예', '연예'],
        'game'     => ['게임', '게임'],
        'health'   => ['건강·의학', '건강 OR 의학'],
    ];

    /** @return list<array{key:string, label:string, query:string}> */
    public static function suggestions(): array
    {
        $out = [];
        foreach (self::SUGGESTIONS as $key => [$label, $query]) {
            $out[] = ['key' => $key, 'label' => $label, 'query' => $query];
        }
        return $out;
    }

    public static function maxTopics(): int
    {
        return max(1, Config::int('SCRAP_MAX_TOPICS', 15));
    }

    /** @return array{keys:list<string>, keywords:list<string>, per_topic:int} */
    public static function settings(): array
    {
        $raw = json_decode((string) AppSettings::get(self::TOPICS_KEY, ''), true);
        $keys = array_values(array_filter((array) ($raw['keys'] ?? []), static fn ($k) => is_string($k) && isset(self::SUGGESTIONS[$k])));
        $keywords = array_values(array_filter((array) ($raw['keywords'] ?? []), 'is_string'));
        $per = (int) AppSettings::get(self::PER_TOPIC_KEY, (string) self::PER_TOPIC_DEFAULT);
        return ['keys' => $keys, 'keywords' => $keywords, 'per_topic' => min(10, max(1, $per))];
    }

    /**
     * 실행할 주제(제안 주제 → 직접 입력 순서).
     * @return list<array{label:string, query:string}>
     */
    public static function resolved(): array
    {
        $s = self::settings();
        $out = [];
        foreach ($s['keys'] as $k) {
            $out[] = ['label' => self::SUGGESTIONS[$k][0], 'query' => self::SUGGESTIONS[$k][1]];
        }
        foreach ($s['keywords'] as $kw) {
            $out[] = ['label' => $kw, 'query' => $kw];
        }
        return $out;
    }

    public static function count(): int
    {
        $s = self::settings();
        return count($s['keys']) + count($s['keywords']);
    }

    /**
     * 주제·건수 저장(검증 후). 바뀐 내용 요약을 돌려준다(감사 로그용, 주제 이름·건수만).
     * @param mixed $keys 제안 주제 키 배열
     */
    public static function save(mixed $keys, string $keywordsRaw, mixed $perTopic): string
    {
        $keys = is_array($keys) ? array_values(array_unique(array_filter($keys, 'is_string'))) : [];
        foreach ($keys as $k) {
            if (!isset(self::SUGGESTIONS[$k])) {
                throw new ValidationException('알 수 없는 주제입니다: ' . mb_substr($k, 0, 20));
            }
        }
        if (!mb_check_encoding($keywordsRaw, 'UTF-8')) {
            throw new ValidationException('직접 입력 키워드의 문자 인코딩이 올바르지 않습니다.');
        }
        $keywords = [];
        foreach (preg_split('/\r\n|\r|\n/', $keywordsRaw) ?: [] as $line) {
            $kw = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line) ?? '');
            $kw = preg_replace('/\s+/u', ' ', $kw) ?? $kw;
            if ($kw === '') {
                continue;
            }
            $len = mb_strlen($kw);
            if ($len < self::KEYWORD_MIN || $len > self::KEYWORD_MAX) {
                throw new ValidationException('직접 입력 키워드는 ' . self::KEYWORD_MIN . '~' . self::KEYWORD_MAX . '자로 입력하세요: "' . mb_substr($kw, 0, 40) . '"');
            }
            if (!in_array(mb_strtolower($kw), array_map('mb_strtolower', $keywords), true)) {
                $keywords[] = $kw;
            }
        }
        if (count($keywords) > self::KEYWORD_COUNT_MAX) {
            throw new ValidationException('직접 입력 키워드는 최대 ' . self::KEYWORD_COUNT_MAX . '개까지 넣을 수 있습니다.');
        }
        if (count($keys) + count($keywords) > self::maxTopics()) {
            throw new ValidationException('주제는 직접 입력을 포함해 최대 ' . self::maxTopics() . '개까지 고를 수 있습니다.');
        }
        $per = filter_var($perTopic, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($per === false) {
            throw new ValidationException('주제당 등록 건수는 1~10 사이로 고르세요.');
        }
        AppSettings::set(self::TOPICS_KEY, json_encode(['keys' => $keys, 'keywords' => $keywords], JSON_UNESCAPED_UNICODE));
        AppSettings::set(self::PER_TOPIC_KEY, (string) $per);
        // 주제를 모두 해제하면 자동 실행도 끈다(켤 조건이 사라졌으므로)
        if ($keys === [] && $keywords === [] && self::enabled()) {
            AppSettings::set(self::ENABLED_KEY, '0');
            Audit::log('scrap_off', 'settings', null, '주제를 모두 해제해 자동 실행을 끔');
        }
        $labels = array_merge(array_map(static fn ($k) => self::SUGGESTIONS[$k][0], $keys), $keywords);
        return '주제 ' . count($labels) . '개(' . implode(', ', $labels) . '), 주제당 ' . $per . '건';
    }

    public static function enabled(): bool
    {
        return AppSettings::get(self::ENABLED_KEY, '0') === '1';
    }

    /** 자동 실행 켜기/끄기. 주제가 없으면 켤 수 없다 */
    public static function setEnabled(bool $on): void
    {
        if ($on && self::count() === 0) {
            throw new ValidationException('주제를 먼저 선택하세요. 주제가 없으면 자동 실행을 켤 수 없습니다.');
        }
        AppSettings::set(self::ENABLED_KEY, $on ? '1' : '0');
        Audit::log($on ? 'scrap_on' : 'scrap_off', 'settings', null, $on ? '스크랩 자동 실행 켬(매일 06:30)' : '스크랩 자동 실행 끔');
    }
}
