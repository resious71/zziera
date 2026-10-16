<?php
/**
 * 수동 발송 범위 계산 (정기 발송 범위에는 영향을 주지 않는다)
 */
final class ManualSend
{
    /**
     * @param 'new'|'today' $mode
     * @return array{0:string,1:string,2:string} [시작, 끝(지금), 설명]
     */
    public static function range(string $mode): array
    {
        // 끝은 "지금"을 포함하도록 1초 뒤(조회는 created_at < 끝). 같은 초에 등록된 글이 빠지지 않게 한다.
        $now = Time::nowObj()->modify('+1 second')->format('Y-m-d H:i:s');
        [$todayStart] = Time::dayRange(Time::today());
        if ($mode === 'today') {
            return [$todayStart, $now, '오늘 0시 이후 등록된 글'];
        }
        // 직전 발송(정기·수동 중 마지막으로 성공한 발송)의 시각 이후 등록된 글
        $last = Db::value("SELECT sent_at FROM send_log WHERE status = 'success' ORDER BY sent_at DESC, id DESC LIMIT 1");
        if ($last === null) {
            return [$todayStart, $now, '직전 발송 기록이 없어 오늘 0시 이후 등록된 글'];
        }
        return [(string) $last, $now, '직전 발송(' . $last . ') 이후 등록된 글'];
    }
}
