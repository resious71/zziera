<?php
/**
 * KST 시각 유틸리티
 *
 * DB에는 'YYYY-MM-DD HH:MM:SS' 문자열로 저장한다.
 * 날짜 범위는 [from, to) 반열린 구간이다: 해당 날짜 00:00:00 이상, 다음 날 00:00:00 미만.
 */
final class Time
{
    public const FORMAT = 'Y-m-d H:i:s';

    /** 테스트용 현재 시각 주입(cli의 --force-now 등) */
    private static ?DateTimeImmutable $fixedNow = null;

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone('Asia/Seoul');
    }

    public static function setNow(?string $datetime): void
    {
        self::$fixedNow = $datetime === null ? null : new DateTimeImmutable($datetime, self::zone());
    }

    public static function nowObj(): DateTimeImmutable
    {
        return self::$fixedNow ?? new DateTimeImmutable('now', self::zone());
    }

    /** 현재 KST 시각 문자열 */
    public static function now(): string
    {
        return self::nowObj()->format(self::FORMAT);
    }

    /** 오늘 날짜(Y-m-d) */
    public static function today(): string
    {
        return self::nowObj()->format('Y-m-d');
    }

    /** 'Y-m-d' 형식이며 실제 존재하는 날짜인지 */
    public static function isValidDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::zone());
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /**
     * 해당 날짜(KST)의 0시~24시 범위
     * @return array{0:string,1:string} [시작(포함), 끝(미포함)]
     */
    public static function dayRange(string $date): array
    {
        if (!self::isValidDate($date)) {
            throw new InvalidArgumentException('잘못된 날짜 형식입니다: ' . $date);
        }
        $start = new DateTimeImmutable($date . ' 00:00:00', self::zone());
        return [$start->format(self::FORMAT), $start->modify('+1 day')->format(self::FORMAT)];
    }

    /** 기준 시각에서 초 단위로 더하거나 뺀 시각 문자열 */
    public static function shift(string $datetime, int $seconds): string
    {
        $d = new DateTimeImmutable($datetime, self::zone());
        return $d->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format(self::FORMAT);
    }

    /** 두 시각 문자열의 차이(초): $b - $a */
    public static function diffSeconds(string $a, string $b): int
    {
        return (new DateTimeImmutable($b, self::zone()))->getTimestamp()
            - (new DateTimeImmutable($a, self::zone()))->getTimestamp();
    }
}
