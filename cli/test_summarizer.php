<?php
/**
 * Summarizer(TextRank 요약)·한국어 전처리 테스트
 *
 * 실행: C:\xampp\php\php.exe cli\test_summarizer.php
 * 하나라도 FAIL 이면 종료 코드 1. PHP 경고·deprecated 가 하나라도 나와도 FAIL.
 */
require __DIR__ . '/../src/bootstrap.php';

$pass = 0;
$fail = 0;
$phpWarnings = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$phpWarnings): bool {
    $phpWarnings[] = "[{$no}] {$msg} @ " . basename($file) . ":{$line}";
    return true;
});

function t(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS' : 'FAIL') . "  {$name}" . ($detail !== '' ? "\n      → {$detail}" : '') . "\n";
}

/** 요약이 원문 문장들로만 이루어졌는지(전처리된 텍스트가 새어 나가지 않았는지) */
function fromOriginal(string $summary, array $sentences): bool
{
    $rest = $summary;
    foreach ($sentences as $s) {
        $rest = str_replace($s, '', $rest);
    }
    return trim($rest) === '';
}

/** 요약에 쓰인 원문 문장 번호 목록 */
function picked(string $summary, array $sentences): array
{
    $idx = [];
    foreach ($sentences as $i => $s) {
        if (str_contains($summary, $s)) {
            $idx[] = $i;
        }
    }
    return $idx;
}

echo "== 라이브러리·오토로더\n";
t('lib/PhpScience/TextRank 로드', class_exists(\PhpScience\TextRank\Tool\Parser::class) && class_exists(\PhpScience\TextRank\TextRankFacade::class));
t('lib/LICENSE-TextRank.txt 존재', is_file(APP_ROOT . '/lib/LICENSE-TextRank.txt'));
$sw = new KoreanStopWords();
t('KoreanStopWords: 라이브러리 StopWordsAbstract 확장, 100개 이상', $sw instanceof \PhpScience\TextRank\Tool\StopWords\StopWordsAbstract && $sw->count() >= 100, $sw->count() . '개');
t('KoreanStopWords: exist()', $sw->exist('그리고') && $sw->exist('때문') && !$sw->exist('회의'));

echo "== 한국어 전처리\n";
$forms = array_map([KoreanPreprocessor::class, 'stem'], ['회의를', '회의가', '회의에서', '회의는', '회의에서는', '회의의', '회의']);
t('회의를/가/에서/는/에서는/의 → 회의', array_unique($forms) === ['회의'], implode(',', $forms));
t('긴 조사부터: 학교에서는 → 학교, 친구에게서 → 친구, 서울으로부터 → 서울',
    KoreanPreprocessor::stem('학교에서는') === '학교' && KoreanPreprocessor::stem('친구에게서') === '친구' && KoreanPreprocessor::stem('서울으로부터') === '서울');
t('어미: 기록했다/발표한다/선정됐다 → 기록/발표/선정',
    KoreanPreprocessor::stem('기록했다') === '기록' && KoreanPreprocessor::stem('발표한다') === '발표' && KoreanPreprocessor::stem('선정됐다') === '선정');
t('어간이 1글자 미만이면 원형 유지: 을, 에서, 는',
    KoreanPreprocessor::stem('을') === '을' && KoreanPreprocessor::stem('에서') === '에서' && KoreanPreprocessor::stem('는') === '는');
t('명사 끝 글자와 겹치는 조사는 보수적으로: 회의·아이·포도 유지, 책을→책',
    KoreanPreprocessor::stem('회의') === '회의' && KoreanPreprocessor::stem('아이') === '아이' && KoreanPreprocessor::stem('포도') === '포도' && KoreanPreprocessor::stem('책을') === '책');
t('영어·숫자 토큰은 그대로(소문자화만)', KoreanPreprocessor::tokens('Samsung DS 2026년 HBM4를') === ['samsung', 'ds', '2026년', 'hbm4'], json_encode(KoreanPreprocessor::tokens('Samsung DS 2026년 HBM4를'), JSON_UNESCAPED_UNICODE));
t('전처리 줄에는 문장부호가 없다(라이브러리 문장 분리와 충돌 방지)', !preg_match('/[.?!\n]/', KoreanPreprocessor::line('끝났다. 정말? 와! 다음 줄' . "\n" . '이어짐')));

echo "== 요약 규칙\n";
$s3 = "첫 번째 문장입니다. 두 번째 문장이에요! 세 번째 문장인가요?";
t('3문장 이하: 앞부분 그대로', Summarizer::summarize($s3) === $s3, Summarizer::summarize($s3));
$long3 = str_repeat('가', 100) . '. ' . str_repeat('나', 100) . '. 끝.';
$r = Summarizer::summarize($long3);
t('3문장 이하 긴 글: 120자 + 말줄임표', mb_strlen($r) === 121 && str_ends_with($r, '…'), mb_strlen($r) . '자');
t('빈 본문·거의 없는 본문 → 빈 문자열', Summarizer::summarize('') === '' && Summarizer::summarize("  \n ") === '' && Summarizer::summarize('ㅋ') === '');

$four = [
    '이번 분기 반도체 실적은 메모리 가격 상승 덕분에 크게 좋아졌다.',
    '점심 메뉴는 김치찌개였다.',
    '반도체 부문의 메모리 판매가 늘면서 반도체 영업이익도 함께 늘었다.',
    '날씨가 맑았다.',
];
$r = Summarizer::summarize(implode(' ', $four));
$p = picked($r, $four);
t('정확히 4문장: 원문 2문장을 원문 순서로', count($p) === 2 && $p === [0, 2] && fromOriginal($r, $four), $r);

$josa = [
    '오늘 회의를 오전 열 시에 시작했다.',
    '회의가 길어져서 점심이 늦어졌다.',
    '커피 자판기가 고장 났다.',
    '회의에서 예산 문제가 다시 나왔다.',
    '주차장은 붐볐다.',
    '회의는 결국 예산 재검토로 끝났다.',
];
$r = Summarizer::summarize(implode("\n", $josa));
$p = picked($r, $josa);
t('조사 변형 반복 글: "회의" 문장들이 선택됨', count($p) === 2 && array_intersect($p, [0, 1, 3, 5]) === $p && fromOriginal($r, $josa), $r);

$en = [
    'Samsung이 HBM4 양산을 시작했다.',
    'HBM4는 AI 서버용 메모리다.',
    '회사 식당 메뉴가 바뀌었다!',
    'HBM4 공급 계약은 Nvidia와 맺었다고 한다.',
    '주말에는 비가 온다고?',
];
$r = Summarizer::summarize(implode(' ', $en));
t('영어 혼용: 원문 2문장, HBM4 관련', count(picked($r, $en)) === 2 && fromOriginal($r, $en) && substr_count($r, 'HBM4') >= 2, $r);

$emoji = [
    '🎉 드디어 신제품이 나왔다!!',
    '신제품 가격은 ₩1,290,000 (부가세 포함)이다.',
    '¯\\_(ツ)_/¯ 다들 반응이 궁금하다.',
    '신제품 예약은 #오늘 #자정 부터 시작한다 😀',
    '★★★ 별점은 아직이다…',
];
$emojiText = implode(' ', $emoji);
$emojiSentences = Summarizer::splitSentences($emojiText); // "😀" 로 끝나는 문장은 마침표가 없어 다음 문장과 합쳐진다(규칙대로)
$r = Summarizer::summarize($emojiText);
t('이모지·특수문자: 예외 없이 원문 2문장', count($emojiSentences) >= 4 && count(picked($r, $emojiSentences)) === 2 && fromOriginal($r, $emojiSentences), $r);

$lines = [];
for ($i = 1; $i <= 40; $i++) {
    $lines[] = $i % 7 === 0 ? "{$i}번째 줄에서 서버 장애 원인이 디스크 장애로 확인됨" : "{$i}번째 줄 일반 메모 내용 정리";
}
$r = Summarizer::summarize(implode("\n", $lines));
t('마침표 없이 줄바꿈만 있는 긴 글: 줄 단위 문장 2개', count(picked($r, $lines)) >= 2 && fromOriginal($r, $lines), $r);

$big = '';
$n = 0;
while (mb_strlen($big) < 10000) {
    $n++;
    $big .= ($n % 5 === 0 ? "프로젝트 일정이 {$n}주차에 다시 지연되었다. " : "회의록 {$n}번 항목은 단순 공지 사항이다. ");
}
$big = mb_substr($big, 0, 10000);
$t0 = microtime(true);
$m0 = memory_get_usage();
$r = Summarizer::summarize($big);
$sec = microtime(true) - $t0;
$mem = (memory_get_peak_usage() - $m0) / 1048576;
t(sprintf('10,000자 본문: %.2f초, 추가 메모리 %.1fMB, 원문 2문장', $sec, $mem), $sec < 3 && $r !== '' && count(Summarizer::splitSentences($r)) === 2, $r);

echo "== HTML 본문 → 일반 텍스트 → 요약\n";
$html = '<h2>분기 실적 정리</h2>'
    . '<p>이번 분기 반도체 매출이 크게 늘었다. 메모리 가격이 오른 영향이다.</p>'
    . '<table><tbody><tr><td>표속비밀문장 반도체 반도체 반도체 메모리 메모리.</td><td>표 안의 숫자 123</td></tr></tbody></table>'
    . '<p><img src="api/file.php?type=image&amp;id=1" alt="그래프"> 그래프는 사내망에서 확인할 수 있다.</p>'
    . '<p>자세한 내용은 <a href="https://intra.example/linkurlsecret/반도체">반도체 실적 자료</a>를 참고한다.</p>'
    . '<ul><li>다음 분기에도 반도체 수요는 이어질 전망이다.</li></ul>';
$sani = HtmlSanitizer::sanitize($html, static fn (array $ids) => $ids);
$plain = $sani['text'];
t('HtmlText: 표 텍스트·링크 주소·이미지 제외', !str_contains($plain, '표속비밀문장') && !str_contains($plain, 'linkurlsecret') && !str_contains($plain, '그래프"') && str_contains($plain, '반도체 실적 자료'), json_encode($plain, JSON_UNESCAPED_UNICODE));
$r = Summarizer::summarize($plain);
$plainSentences = Summarizer::splitSentences($plain);
t('HTML 본문 요약: 원문 문장 2개, 표·링크 주소 미포함', count(picked($r, $plainSentences)) === 2 && fromOriginal($r, $plainSentences) && !str_contains($r, '표속비밀문장') && !str_contains($r, 'linkurlsecret'), $r);
$onlyMedia = HtmlSanitizer::sanitize('<p><img src="api/file.php?type=image&amp;id=1" alt=""></p><table><tr><td>표만 있음</td></tr></table>', static fn (array $ids) => $ids);
t('이미지·표뿐인 본문 → summary 빈 문자열', Summarizer::summarize($onlyMedia['text']) === '', json_encode($onlyMedia['text'], JSON_UNESCAPED_UNICODE));

echo "== 실패 대체\n";
$before = (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE event = 'summary_error'");
Summarizer::setEngineOverride(static function (): array { throw new RuntimeException('테스트용 강제 실패'); });
$r = Summarizer::summarize(implode(' ', $four));
t('예외 시 앞 2문장으로 대체', $r === $four[0] . ' ' . $four[1], $r);
Summarizer::setEngineOverride(static fn (): array => []);
$r = Summarizer::summarize(implode(' ', $four));
t('빈 결과 시 앞 2문장으로 대체', $r === $four[0] . ' ' . $four[1], $r);
Summarizer::setEngineOverride(null);
$after = (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE event = 'summary_error'");
t('실패는 audit_log(summary_error)에 기록', $after - $before === 2, ($after - $before) . '건');
Db::exec("DELETE FROM audit_log WHERE event = 'summary_error' AND detail LIKE '%테스트용 강제 실패%' OR (event = 'summary_error' AND detail LIKE '%결과가 비어%' AND created_at >= ?)", [Time::shift(Time::now(), -60)]);

restore_error_handler();
t('PHP 경고·deprecated 없음', $phpWarnings === [], implode(' / ', $phpWarnings));

echo "\n결과: 통과 {$pass} / 실패 {$fail}\n";
exit($fail > 0 ? 1 : 0);
