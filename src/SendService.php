<?php

use Mail\Mailer;
use Mail\MailerFactory;

/**
 * 다이제스트 발송 (정기·수동 공통)
 *
 *  - 수신자: status='active' 멤버 전체를 MAIL_BATCH_SIZE(50)명씩 묶어 BCC 로 한 통씩. To 는 MAIL_TO_PLACEHOLDER(비면 발신 주소).
 *  - 묶음 사이 MAIL_BATCH_DELAY_SEC 대기. 실패한 묶음만 MAIL_RETRY_MAX(3)회까지 다시 시도.
 *  - send_log: running 으로 시작 → success(모든 묶음 성공) / failed(하나라도 최종 실패) / skipped(보낼 것 없음·설정 미완료).
 *    묶음별 결과는 send_log_batches, 포함한 찌라시는 send_log_items. 개별 수신자 단위 결과는 기록하지 않는다.
 *  - 한 번에 하나의 발송만(파일 잠금 mail_send). 정기·수동이 겹치면 나중 것이 거부된다.
 */
final class SendService
{
    /** 테스트용: 묶음 대기·재시도 대기를 끈다 */
    public static bool $noDelay = false;

    /** 범위 안의 발송 대상 찌라시(삭제되지 않은 글, 등록 시각 오름차순). 고정 여부와 무관 */
    public static function posts(string $from, string $to): array
    {
        return Db::all(
            "SELECT id, title, summary, created_at, source FROM zzira
             WHERE status = 'active' AND created_at >= ? AND created_at < ?
             ORDER BY created_at ASC, id ASC",
            [$from, $to]
        );
    }

    /**
     * 발송 실행
     * @param 'scheduled'|'manual' $trigger
     * @return array{id:int, status:string, posts:int, recipients:int, batches:list<array>, message:string}
     */
    public static function run(string $trigger, string $from, string $to, ?Mailer $mailer = null): array
    {
        $lock = FileLock::acquire('mail_send');
        if ($lock === null) {
            throw new ValidationException('다른 메일 발송이 진행 중입니다. 잠시 후 다시 시도하세요.');
        }
        @set_time_limit(0);
        try {
            $posts = self::posts($from, $to);
            $recipients = MemberRepository::activeEmails();
            $now = Time::now();
            Db::exec(
                'INSERT INTO send_log (send_date, trigger_type, range_from, range_to, recipient_count, status, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [Time::today(), $trigger, $from, $to, count($recipients), 'running', $now]
            );
            $logId = Db::lastId();

            // 보낼 수 없는 경우는 skipped(안내 메일 없음)
            $problems = MailConfig::check();
            $skipReason = null;
            if ($mailer === null && $problems !== []) {
                $skipReason = '메일 설정 미완료: ' . implode(', ', $problems);
            } elseif ($posts === []) {
                $skipReason = '포함할 찌라시 없음';
            } elseif ($recipients === []) {
                $skipReason = '수신자(active 멤버) 0명';
            }
            if ($skipReason !== null) {
                self::finish($logId, 'skipped', $skipReason);
                Audit::log('send', 'send_log', $logId, self::label($trigger) . " skipped: {$skipReason}");
                return ['id' => $logId, 'status' => 'skipped', 'posts' => count($posts), 'recipients' => count($recipients), 'batches' => [], 'message' => $skipReason];
            }

            foreach ($posts as $p) {
                Db::exec('INSERT INTO send_log_items (send_log_id, zzira_id) VALUES (?, ?)', [$logId, $p['id']]);
            }

            try {
                $digest = DigestBuilder::build($posts, $from, $to);
                $mailer ??= MailerFactory::create();
            } catch (Throwable $e) {
                self::finish($logId, 'failed', $e->getMessage());
                Audit::log('send', 'send_log', $logId, self::label($trigger) . ' failed: ' . $e->getMessage());
                return ['id' => $logId, 'status' => 'failed', 'posts' => count($posts), 'recipients' => count($recipients), 'batches' => [], 'message' => $e->getMessage()];
            }

            $settings = MailSettings::get();
            $toAddress = trim((string) Config::get('MAIL_TO_PLACEHOLDER', '')) ?: $settings['mail_from'];
            $batchSize = max(1, Config::int('MAIL_BATCH_SIZE', 50));
            $retryMax = max(1, Config::int('MAIL_RETRY_MAX', 3));
            $batches = array_chunk($recipients, $batchSize);
            $results = [];
            $failed = 0;

            foreach ($batches as $i => $bcc) {
                if ($i > 0) {
                    self::sleep(Config::int('MAIL_BATCH_DELAY_SEC', 2));
                }
                $attempts = 0;
                $error = null;
                while ($attempts < $retryMax) {
                    $attempts++;
                    try {
                        $mailer->send($toAddress, $bcc, $digest['subject'], $digest['html'], $digest['text']);
                        $error = null;
                        break;
                    } catch (Throwable $e) {
                        $error = mb_substr($e->getMessage(), 0, 1000);
                        if ($attempts < $retryMax) {
                            self::sleep(Config::int('MAIL_RETRY_DELAY_SEC', 3));
                        }
                    }
                }
                $status = $error === null ? 'success' : 'failed';
                if ($error !== null) {
                    $failed++;
                }
                Db::exec(
                    'INSERT INTO send_log_batches (send_log_id, batch_no, recipient_count, attempts, status, error_message) VALUES (?, ?, ?, ?, ?, ?)',
                    [$logId, $i + 1, count($bcc), $attempts, $status, $error]
                );
                $results[] = ['batch_no' => $i + 1, 'recipient_count' => count($bcc), 'attempts' => $attempts, 'status' => $status, 'error' => $error];
            }

            $final = $failed === 0 ? 'success' : 'failed';
            $message = $failed === 0
                ? count($posts) . '건을 ' . count($recipients) . '명에게 보냈습니다(' . count($batches) . '묶음).'
                : count($batches) . '묶음 중 ' . $failed . '묶음 실패: ' . (string) self::firstError($results);
            self::finish($logId, $final, $failed === 0 ? null : $message);
            Audit::log('send', 'send_log', $logId, self::label($trigger) . " {$final}: 찌라시 " . count($posts) . '건, 수신 ' . count($recipients) . '명, 묶음 ' . count($batches) . "개(실패 {$failed})");
            return ['id' => $logId, 'status' => $final, 'posts' => count($posts), 'recipients' => count($recipients), 'batches' => $results, 'message' => $message];
        } finally {
            $lock->release();
        }
    }

    /** 비정상 종료로 남은 running 기록 정리 */
    public static function cleanupStale(bool $dryRun = false): int
    {
        $limit = Time::shift(Time::now(), -60 * max(1, Config::int('SEND_STALE_MINUTES', 30)));
        if ($dryRun) { // 6b 미리보기: 셈만 한다
            return (int) Db::value("SELECT COUNT(*) FROM send_log WHERE status = 'running' AND sent_at < ?", [$limit]);
        }
        return Db::exec(
            "UPDATE send_log SET status = 'failed', error_message = '비정상 종료(일정 시간 running 상태로 남아 정리함)'
             WHERE status = 'running' AND sent_at < ?",
            [$limit]
        );
    }

    private static function finish(int $logId, string $status, ?string $error): void
    {
        Db::exec('UPDATE send_log SET status = ?, error_message = ? WHERE id = ?', [$status, $error, $logId]);
    }

    private static function firstError(array $results): ?string
    {
        foreach ($results as $r) {
            if ($r['error'] !== null) {
                return $r['error'];
            }
        }
        return null;
    }

    private static function label(string $trigger): string
    {
        return $trigger === 'scheduled' ? '정기 발송' : '수동 발송';
    }

    private static function sleep(int $seconds): void
    {
        if (!self::$noDelay && $seconds > 0) {
            sleep($seconds);
        }
    }
}
