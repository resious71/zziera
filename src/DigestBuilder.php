<?php
/**
 * 다이제스트 메일 본문 생성 (HTML + 텍스트)
 *
 *  - 항목마다 제목, 요약(summary, 반드시 이스케이프), "원문 보기" 링크(post.php?id= 의 절대 URL).
 *    본문 HTML·이미지·첨부는 넣지 않는다. 요약이 비어 있으면 "(본문은 링크에서 확인)".
 *  - 여러 날이면 날짜별 섹션("2026-10-05 (월)"), 제목에 기간 표시.
 *  - 하단에 MAIL_FOOTER_NOTICE(수신 중지·주소 변경은 관리자에게 요청). 구독 해지 링크는 없다.
 */
final class DigestBuilder
{
    public const NO_SUMMARY = '(본문은 링크에서 확인)';
    private const WEEKDAYS = ['일', '월', '화', '수', '목', '금', '토'];

    /**
     * 메일 속 링크의 기준 주소. config BASE_URL → 없으면 웹 요청의 호스트로 추론.
     * CLI(정기 발송)에서는 추론할 수 없으므로 BASE_URL 이 필수다.
     */
    public static function baseUrl(): string
    {
        $base = rtrim(trim((string) Config::get('BASE_URL', '')), '/');
        if ($base !== '') {
            return $base;
        }
        if (IS_CLI) {
            throw new RuntimeException('config.php 의 BASE_URL 이 비어 있어 메일 속 원문 링크를 만들 수 없습니다(정기 발송에는 BASE_URL 이 필요합니다).');
        }
        return Url::base();
    }

    /**
     * @param list<array{id:int|string, title:string, summary:?string, created_at:string}> $posts 등록 시각 오름차순
     * @return array{subject:string, html:string, text:string}
     */
    public static function build(array $posts, string $rangeFrom, string $rangeTo): array
    {
        $base = self::baseUrl();
        $appName = (string) Config::get('APP_NAME', 'ZZieRa');
        $footer = (string) Config::get('MAIL_FOOTER_NOTICE', '수신 중지·주소 변경은 관리자에게 요청하세요.');

        // 날짜별로 묶기
        $byDate = [];
        foreach ($posts as $p) {
            $byDate[substr($p['created_at'], 0, 10)][] = $p;
        }
        ksort($byDate);
        $multi = count($byDate) > 1;
        $subject = '[' . $appName . '] ' . self::periodLabel($rangeFrom, $rangeTo, array_keys($byDate)) . ' 찌라시 ' . count($posts) . '건';

        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f5f7fa;">'
            . '<div style="max-width:640px;margin:0 auto;padding:20px 16px;font-family:\'Malgun Gothic\',\'Apple SD Gothic Neo\',sans-serif;color:#1f2933;font-size:15px;line-height:1.6;">'
            . '<h1 style="margin:0 0 16px;font-size:20px;">' . $e($subject) . '</h1>';
        $text = $subject . "\n" . str_repeat('=', 40) . "\n";

        foreach ($byDate as $date => $items) {
            if ($multi) {
                $label = self::dateLabel($date);
                $html .= '<h2 style="margin:24px 0 8px;padding-bottom:4px;border-bottom:2px solid #cbd2d9;font-size:16px;">' . $e($label) . '</h2>';
                $text .= "\n■ " . $label . "\n";
            }
            foreach ($items as $p) {
                $url = $base . '/post.php?id=' . (int) $p['id'];
                $summary = trim((string) ($p['summary'] ?? '')) !== '' ? (string) $p['summary'] : self::NO_SUMMARY;
                $html .= '<div style="margin:0 0 12px;padding:12px 14px;background:#ffffff;border:1px solid #e4e7eb;border-radius:8px;">'
                    . '<div style="font-weight:700;font-size:16px;margin:0 0 4px;">' . $e(self::itemTitle($p)) . '</div>'
                    . '<div style="color:#3e4c59;margin:0 0 8px;">' . $e($summary) . '</div>'
                    . '<a href="' . $e($url) . '" style="color:#2f5bea;">원문 보기</a>'
                    . '<span style="color:#9aa5b1;font-size:13px;"> · ' . $e(substr($p['created_at'], 0, 16)) . '</span>'
                    . '</div>';
                $text .= "\n- " . self::itemTitle($p) . "\n  " . $summary . "\n  원문 보기: " . $url . "\n";
            }
        }

        $html .= '<p style="margin:24px 0 0;padding-top:12px;border-top:1px solid #e4e7eb;color:#616e7c;font-size:13px;">' . $e($footer) . '</p>'
            . '</div></body></html>';
        $text .= "\n" . str_repeat('-', 40) . "\n" . $footer . "\n";

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** "2026-10-05 (월)" */
    public static function dateLabel(string $date): string
    {
        $w = (int) (new DateTimeImmutable($date, Time::zone()))->format('w');
        return $date . ' (' . self::WEEKDAYS[$w] . ')';
    }

    /**
     * 제목의 기간: 포함된 글의 날짜가 하루면 "2026-10-05", 여러 날이면 "2026-10-03~10-05"(해가 다르면 전체 날짜)
     * @param list<string> $dates
     */
    private static function periodLabel(string $rangeFrom, string $rangeTo, array $dates): string
    {
        $first = $dates[0] ?? substr($rangeFrom, 0, 10);
        $last = $dates[count($dates) - 1] ?? substr(Time::shift($rangeTo, -1), 0, 10);
        if ($first === $last) {
            return $first;
        }
        return $first . '~' . (substr($first, 0, 4) === substr($last, 0, 4) ? substr($last, 5) : $last);
    }

    /** 메일 항목 제목: 스크랩 글(5f)은 앞에 [스크랩] */
    private static function itemTitle(array $p): string
    {
        return (($p['source'] ?? 'user') === 'scrap' ? '[스크랩] ' : '') . $p['title'];
    }
}
