<?php
/**
 * 정기 발송 판단 (cli/cron_send.php 가 1분마다 호출)
 *
 * 순서:
 *  0) 자동 발송이 꺼져 있으면 아무것도 기록하지 않고 종료
 *  1) 07:00 이전이면 종료
 *  2) 오늘 정기 발송 기록이 running/success/skipped 이면 종료(하루 1회).
 *     failed 만 있으면 12:00 전·시도 횟수 상한(SEND_FAILED_RETRY_MAX) 안에서 한 번 더 시도
 *  3) 12:00 이후인데 오늘 기록이 없으면 오늘은 건너뜀(감사 로그 1회) → 다음 날 범위에 합산
 *  4) 07:00~12:00 이고 오늘 기록이 없으면 발송
 *
 * 범위: [시작, 오늘 0시)
 *  시작 = 마지막으로 success·skipped 처리된 정기 발송의 range_to(없으면 어제 0시).
 *        자동 발송을 "켠 시점 이후"로 다시 켰다면 max(시작, auto_send_from).
 *  수동 발송은 이 계산에 영향을 주지 않는다.
 */
final class ScheduledSend
{
    public const AUTO_KEY = 'auto_send_enabled';
    public const AUTO_FROM_KEY = 'auto_send_from';

    public static function autoEnabled(): bool
    {
        return AppSettings::get(self::AUTO_KEY, '1') !== '0';
    }

    /**
     * 지금 해야 할 일
     * @return array{action:'exit'|'send'|'skip_deadline', reason:string, from?:string, to?:string, retry?:bool}
     */
    public static function decide(): array
    {
        $now = Time::nowObj();
        $today = $now->format('Y-m-d');
        [$todayStart] = Time::dayRange($today);

        if (!self::autoEnabled()) {
            return ['action' => 'exit', 'reason' => '자동 발송 꺼짐, 종료'];
        }
        $sendAt = self::at($today, (string) Config::get('SEND_TIME', '07:00'));
        $deadline = self::at($today, (string) Config::get('SEND_DEADLINE', '12:00'));
        if ($now < $sendAt) {
            return ['action' => 'exit', 'reason' => '발송 시각(' . Config::get('SEND_TIME', '07:00') . ') 전, 종료'];
        }

        $rows = Db::all("SELECT status FROM send_log WHERE trigger_type = 'scheduled' AND send_date = ?", [$today]);
        $statuses = array_column($rows, 'status');
        if (in_array('running', $statuses, true)) {
            return ['action' => 'exit', 'reason' => '오늘 정기 발송 진행 중, 종료'];
        }
        if (in_array('success', $statuses, true) || in_array('skipped', $statuses, true)) {
            return ['action' => 'exit', 'reason' => '오늘 정기 발송 이미 처리됨, 종료'];
        }

        $retry = false;
        if ($statuses !== []) { // failed 만 있음
            $max = max(1, Config::int('SEND_FAILED_RETRY_MAX', 2));
            if (count($statuses) >= $max) {
                return ['action' => 'exit', 'reason' => "오늘 정기 발송 실패 {$max}회로 재시도 한도 도달, 종료"];
            }
            if ($now >= $deadline) {
                return ['action' => 'exit', 'reason' => '오늘 정기 발송 실패, 보충 시한(' . Config::get('SEND_DEADLINE', '12:00') . ') 지나 재시도 안 함, 종료'];
            }
            $retry = true;
        } elseif ($now >= $deadline) {
            return ['action' => 'skip_deadline', 'reason' => '보충 시한(' . Config::get('SEND_DEADLINE', '12:00') . ') 지나 오늘은 건너뜀(다음 정기 발송에 합산)'];
        }

        [$from, $to] = self::range($todayStart);
        return [
            'action' => 'send',
            'reason' => $retry ? '정기 발송 재시도' : '정기 발송',
            'from' => $from,
            'to' => $to,
            'retry' => $retry,
        ];
    }

    /** @return array{0:string,1:string} [시작, 오늘 0시) */
    public static function range(string $todayStart): array
    {
        $last = Db::value(
            "SELECT range_to FROM send_log
             WHERE trigger_type = 'scheduled' AND status IN ('success','skipped') AND range_to IS NOT NULL
             ORDER BY range_to DESC LIMIT 1"
        );
        $from = $last !== null ? (string) $last : Time::shift($todayStart, -86400);
        $autoFrom = AppSettings::get(self::AUTO_FROM_KEY);
        if ($autoFrom !== null && $autoFrom !== '' && $autoFrom > $from) {
            $from = $autoFrom;
        }
        if ($from > $todayStart) {
            $from = $todayStart;
        }
        return [$from, $todayStart];
    }

    /** 오늘 이미 "보충 시한 지나 건너뜀"을 기록했는지(1분마다 실행되므로 하루 한 번만 기록) */
    public static function deadlineLogged(string $today): bool
    {
        [$start, $end] = Time::dayRange($today);
        return Db::value(
            "SELECT 1 FROM audit_log WHERE event = 'send_skip_deadline' AND created_at >= ? AND created_at < ? LIMIT 1",
            [$start, $end]
        ) !== null;
    }

    /**
     * 자동 발송 켜기/끄기
     * @param 'include'|'from_now'|null $resumeMode 켤 때: include=꺼져 있던 기간 포함, from_now=켠 날 0시 이후 등록분부터
     */
    public static function setEnabled(bool $enabled, ?string $resumeMode = null): void
    {
        if ($enabled) {
            if ($resumeMode === 'from_now') {
                AppSettings::set(self::AUTO_FROM_KEY, Time::dayRange(Time::today())[0]);
            } else {
                AppSettings::delete(self::AUTO_FROM_KEY);
            }
            AppSettings::set(self::AUTO_KEY, '1');
            Audit::log('auto_send_on', 'settings', null, '자동 발송 켬(' . ($resumeMode === 'from_now' ? '켠 시점 이후 등록분부터' : '꺼져 있던 기간 포함') . ')');
        } else {
            AppSettings::set(self::AUTO_KEY, '0');
            Audit::log('auto_send_off', 'settings', null, '자동 발송 끔');
        }
    }

    private static function at(string $date, string $hm): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' ' . $hm . ':00', Time::zone());
    }
}
