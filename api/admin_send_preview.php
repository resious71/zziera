<?php
/**
 * 수동 발송 미리보기 (GET: range=new|today) — 관리자 전용
 *  new  : 직전 발송(정기·수동 중 마지막 성공) 시각 이후 등록된 글. 직전 발송이 없으면 오늘 0시 이후
 *  today: 오늘 0시 이후 등록된 글
 * 응답: 범위, 수신자 수, 포함될 찌라시 목록, 메일 설정 점검 결과
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

$range = Input::choice($_GET, 'range', '범위', ['new', 'today'], 'new');
[$from, $to, $basis] = ManualSend::range($range);
$posts = SendService::posts($from, $to);
Auth::ok([
    'range'      => $range,
    'from'       => $from,
    'to'         => $to,
    'basis'      => $basis,
    'recipients' => count(MemberRepository::activeEmails()),
    'posts'      => array_map(static fn (array $p) => [
        'id'         => (int) $p['id'],
        'title'      => (($p['source'] ?? 'user') === 'scrap' ? '[스크랩] ' : '') . $p['title'],
        'created_at' => $p['created_at'],
        'summary'    => trim((string) $p['summary']) !== '' ? $p['summary'] : DigestBuilder::NO_SUMMARY,
    ], $posts),
    'problems'   => MailConfig::check(),
]);
