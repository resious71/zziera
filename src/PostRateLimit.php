<?php
/**
 * 찌라시 등록 레이트 리미팅(세션 기준, 분당·시간당)
 */
final class PostRateLimit
{
    private const KEY = 'post';

    /** 제한에 걸리면 안내 메시지(429 응답용)를, 통과면 null 을 반환 */
    public static function check(): ?string
    {
        $perMinute = Config::int('POST_RATE_PER_MINUTE', 3);
        $perHour = Config::int('POST_RATE_PER_HOUR', 20);
        return SessionRateLimit::check(self::KEY, [
            [60, $perMinute, "등록이 너무 잦습니다. 1분에 {$perMinute}건까지 등록할 수 있습니다. 잠시 후 다시 시도하세요."],
            [3600, $perHour, "등록이 너무 잦습니다. 1시간에 {$perHour}건까지 등록할 수 있습니다. 잠시 후 다시 시도하세요."],
        ]);
    }

    /** 등록 성공 시 호출 */
    public static function hit(): void
    {
        SessionRateLimit::hit(self::KEY);
    }
}
