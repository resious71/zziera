<?php
/**
 * 세션 기준 횟수 제한
 *
 * 카운터(최근 시각 목록)는 PHP 세션에만 둔다. DB·파일·로그에는 남기지 않는다(익명성).
 *
 *   $msg = SessionRateLimit::check('post', [[60, 3, '1분에 3건…'], [3600, 20, '1시간에 20건…']]);
 *   if ($msg !== null) { Json::error($msg, 429); }
 *   ... 처리 성공 후 ...
 *   SessionRateLimit::hit('post');
 */
final class SessionRateLimit
{
    private const PREFIX = '_rate_';

    /**
     * @param list<array{0:int,1:int,2:string}> $windows [창(초), 허용 횟수, 초과 시 메시지]
     * @return string|null 초과면 메시지, 통과면 null
     */
    public static function check(string $key, array $windows): ?string
    {
        Session::start();
        $times = self::recent($key, max(array_map(static fn ($w) => $w[0], $windows)));
        $now = time();
        foreach ($windows as [$seconds, $limit, $message]) {
            $count = count(array_filter($times, static fn (int $t) => $t > $now - $seconds));
            if ($count >= $limit) {
                return $message;
            }
        }
        return null;
    }

    public static function hit(string $key, int $keepSeconds = 3600): void
    {
        Session::start();
        $times = self::recent($key, $keepSeconds);
        $times[] = time();
        $_SESSION[self::PREFIX . $key] = $times;
    }

    private static function recent(string $key, int $keepSeconds): array
    {
        $now = time();
        $times = $_SESSION[self::PREFIX . $key] ?? [];
        if (!is_array($times)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $times), static fn (int $t) => $t > $now - $keepSeconds));
    }
}
