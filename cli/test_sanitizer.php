<?php
/**
 * HtmlSanitizer 테스트
 *
 * 실행: C:\xampp\php\php.exe cli\test_sanitizer.php
 * 하나라도 FAIL 이면 종료 코드 1.
 *
 * 이미지 id 검증은 DB 대신 가짜 필터를 쓴다:
 *   id 1~5, 7 = 이 글(또는 이 세션)이 쓸 수 있는 이미지, id 99 = 다른 글에 연결된 이미지(허용 안 됨)
 */
require __DIR__ . '/../src/bootstrap.php';

$pass = 0;
$fail = 0;
$allowed = [1, 2, 3, 4, 5, 6, 7];
$filter = static fn (array $ids): array => array_values(array_intersect($ids, $allowed));

function sani(string $html): array
{
    global $filter;
    return HtmlSanitizer::sanitize($html, $filter);
}

function t(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS' : 'FAIL') . "  {$name}" . (!$ok && $detail !== '' ? "\n      → {$detail}" : '') . "\n";
}

/** 결과 HTML 에 위험 요소가 하나도 없는지 */
function safe(string $html): bool
{
    return !preg_match('/<\s*(script|style|iframe|svg|math|form|base|meta|link|object|embed)\b|\son\w+\s*=|javascript:|data:|style\s*=|expression\s*\(/i', $html);
}

function img(int $id): string
{
    return '<img src="api/file.php?type=image&amp;id=' . $id . '" alt="">';
}

echo "== 위험 요소 제거\n";
$cases = [
    'script 태그'               => ['<p>안녕<script>alert(1)</script>하세요</p>', '<p>안녕하세요</p>'],
    'img onerror (외부 src)'     => ['<p>a<img src=x onerror="alert(1)">b</p>', '<p>ab</p>'],
    'img onerror (허용 src)'     => ['<p><img src="api/file.php?type=image&id=1" onerror="alert(1)" style="x"></p>', '<p>' . img(1) . '</p>'],
    'a href=javascript:'        => ['<p><a href="javascript:alert(1)">클릭</a></p>', '<p>클릭</p>'],
    '대소문자 JaVaScRiPt:'        => ['<p><a href="JaVaScRiPt:alert(1)">x</a></p>', '<p>x</p>'],
    '탭으로 쪼갠 java\tscript:'    => ["<p><a href=\"java\tscript:alert(1)\">x</a></p>", '<p>x</p>'],
    '개행으로 쪼갠 java\nscript:'  => ["<p><a href=\"java\nscript:alert(1)\">x</a></p>", '<p>x</p>'],
    '엔티티 &#106;avascript:'     => ['<p><a href="&#106;avascript:alert(1)">x</a></p>', '<p>x</p>'],
    '엔티티 &#x09; 삽입'           => ['<p><a href="jav&#x09;ascript:alert(1)">x</a></p>', '<p>x</p>'],
    '앞 공백 " javascript:"'      => ['<p><a href="  javascript:alert(1)">x</a></p>', '<p>x</p>'],
    'vbscript:'                 => ['<p><a href="vbscript:msgbox(1)">x</a></p>', '<p>x</p>'],
    'data: 이미지'               => ['<p><img src="data:image/png;base64,iVBORw0KGgo="></p>', ''],
    'data: 링크'                 => ['<p><a href="data:text/html,<script>alert(1)</script>">x</a></p>', '<p>x</p>'],
    '외부 이미지 URL'             => ['<p>a<img src="http://evil.example/x.png">b</p>', '<p>ab</p>'],
    '상대 경로 조작 이미지'          => ['<p><img src="api/file.php?type=image&id=1&x=../../config.php"><img src="../api/file.php?type=image&id=1"><img src="api/file.php?type=attachment&id=1"></p>', ''],
    'svg onload'                => ['<p>a<svg onload="alert(1)"><circle/></svg>b</p>', '<p>ab</p>'],
    'iframe'                    => ['<p>a</p><iframe src="http://evil"></iframe><p>b</p>', '<p>a</p><p>b</p>'],
    'form/input/button'         => ['<form action="http://evil"><input value="x"><button>보내기</button></form><p>본문</p>', '<p>본문</p>'],
    'base'                      => ['<base href="http://evil/"><p>본문</p>', '<p>본문</p>'],
    'meta refresh'              => ['<meta http-equiv="refresh" content="0;url=http://evil"><p>본문</p>', '<p>본문</p>'],
    'math'                      => ['<p>a<math><mi xlink:href="javascript:alert(1)">x</mi></math>b</p>', '<p>ab</p>'],
    'style 속성(expression)'     => ['<p style="width:expression(alert(1))" class="c" id="i" onclick="x">글</p>', '<p>글</p>'],
    'style 태그'                 => ['<style>body{background:url(javascript:alert(1))}</style><p>글</p>', '<p>글</p>'],
    'template/noscript'         => ['<template><img src=x onerror=alert(1)></template><noscript><p>x</p></noscript><p>글</p>', '<p>글</p>'],
    '주석'                       => ['<p>a<!-- <script>alert(1)</script> -->b</p>', '<p>ab</p>'],
];
foreach ($cases as $name => [$in, $expected]) {
    $r = sani($in);
    t($name, $r['html'] === $expected && safe($r['html']), 'got: ' . $r['html']);
}

echo "== 정규화·구조\n";
$r = sani('<p><b>굵게</b> <i>기울임</i> <u>밑줄</u></p>');
t('<b>→<strong>, <i>→<em>', $r['html'] === '<p><strong>굵게</strong> <em>기울임</em> <u>밑줄</u></p>', $r['html']);
$r = sani('<h1>큰제목</h1><h4>작은제목</h4>');
t('h1→h2, h4→h3', $r['html'] === '<h2>큰제목</h2><h3>작은제목</h3>', $r['html']);
$r = sani('맨 글자 <strong>굵게</strong><ul><li>항목</li></ul>뒤 글자');
t('블록 밖 글자는 <p> 로 감쌈', $r['html'] === '<p>맨 글자 <strong>굵게</strong></p><ul><li>항목</li></ul><p>뒤 글자</p>', $r['html']);
$r = sani('<div>첫 줄</div><div>둘째 줄</div><div><br></div><div><br></div><div>넷째 줄</div>');
t('div 줄들은 단락으로, 빈 단락 연속은 하나로', $r['html'] === '<p>첫 줄</p><p>둘째 줄</p><p><br></p><p>넷째 줄</p>', $r['html']);
$r = sani('<p><br></p><p>가운데</p><p><br></p><p></p>');
t('맨 앞·뒤 빈 단락 제거', $r['html'] === '<p>가운데</p>', $r['html']);
$r = sani('<p>줄1<span><br></span>줄2</p>');
t('span 을 벗겨도 안쪽 <br> 은 유지', $r['html'] === '<p>줄1<br>줄2</p>', $r['html']);
$r = sani('<p>a<p>b</div></div></div><p>c</p><b>d<p>e');
t('깨진 HTML(닫히지 않은 태그·남는 </div>)', safe($r['html']) && str_contains($r['html'], 'c') && str_contains($r['html'], 'e'), $r['html']);
$r = sani('<blockquote>인용<p>단락</p></blockquote><hr><ol><li>하나<ul><li>중첩</li></ul></li></ol>');
t('인용·구분선·중첩 목록', $r['html'] === '<blockquote><p>인용</p><p>단락</p></blockquote><hr><ol><li>하나<ul><li>중첩</li></ul></li></ol>', $r['html']);
$r = sani('<p><a href="https://example.com/a?b=1&c=2" title="t" onclick="x">링크</a></p>');
t('링크: href 만 남기고 rel·target 부여', $r['html'] === '<p><a href="https://example.com/a?b=1&amp;c=2" rel="noopener noreferrer" target="_blank">링크</a></p>', $r['html']);
$r = sani('<p><a href="HTTPS://Example.com/한글 경로">한글</a></p>');
t('링크: 스킴 소문자·한글/공백 퍼센트 인코딩', str_contains($r['html'], 'href="https://Example.com/%ED%95%9C%EA%B8%80%20%EA%B2%BD%EB%A1%9C"'), $r['html']);
$r = sani('<p><a href="https://a.com/' . str_repeat('x', 500) . '">긴 링크</a></p>');
t('링크: 500자 초과 URL 은 링크를 풀고 글자만', $r['html'] === '<p>긴 링크</p>', $r['html']);
$r = sani('<p><a href="https://a.com"><a href="https://b.com">중첩</a></a></p>');
t('링크 안의 링크는 풀기', substr_count($r['html'], '<a ') === 1 && safe($r['html']), $r['html']);

echo "== 표\n";
$table = static function (int $rows, int $cols, string $cell = '칸'): string {
    $h = '<table><tbody>';
    for ($r = 0; $r < $rows; $r++) {
        $h .= '<tr>' . str_repeat('<td>' . $cell . '</td>', $cols) . '</tr>';
    }
    return $h . '</tbody></table>';
};
$r = sani($table(20, 10));
t('20행×10열 표 허용', $r['errors'] === [] && $r['has_table'] && substr_count($r['html'], '<tr>') === 20, implode(' / ', $r['errors']));
$r = sani($table(21, 2));
t('21행 표 거부', in_array('표는 최대 20행까지 만들 수 있습니다.', $r['errors'], true), implode(' / ', $r['errors']));
$r = sani($table(2, 11));
t('11열 표 거부', in_array('표는 최대 10열까지 만들 수 있습니다.', $r['errors'], true), implode(' / ', $r['errors']));
$r = sani('<table><tr><td colspan="6">a</td><td colspan="5">b</td></tr></table>');
t('colspan 합 11열 거부', $r['errors'] !== [], $r['html']);
$r = sani($table(1, 1, str_repeat('가', 201)));
t('셀 201자 거부', $r['errors'] !== [] && str_contains($r['errors'][0], '200자'), implode(' / ', $r['errors']));
$r = sani($table(1, 1, str_repeat('가', 200)));
t('셀 200자 허용', $r['errors'] === [], implode(' / ', $r['errors']));
$r = sani('<table><tr><td>바깥<table><tr><td>안쪽</td></tr></table></td></tr></table>');
t('중첩 표 제거', substr_count($r['html'], '<table') === 1 && !str_contains($r['html'], '안쪽'), $r['html']);
$r = sani('<table><tr><td><b>굵게</b> <a href="https://a.com">링크</a>' . img(1) . '<ul><li>목록</li></ul></td><td style="x" colspan="2" rowspan="99">x</td></tr></table>');
t('셀: 인라인만 허용(이미지 제거·목록은 글자만), span 최대 10',
    str_contains($r['html'], '<td><strong>굵게</strong> <a href="https://a.com" rel="noopener noreferrer" target="_blank">링크</a><br>목록</td>')
    && str_contains($r['html'], '<td colspan="2" rowspan="10">x</td>') && !str_contains($r['html'], '<img'), $r['html']);
$r = sani('<table><thead><tr><th>머리</th></tr></thead><tbody><tr><td>몸</td></tr></tbody></table>');
t('thead/th 유지', $r['html'] === '<table><thead><tr><th>머리</th></tr></thead><tbody><tr><td>몸</td></tr></tbody></table>', $r['html']);

echo "== 이미지·링크·글자 수 제한\n";
$r = sani('<p>' . img(1) . img(2) . img(3) . img(4) . img(5) . '</p>');
t('이미지 5장 허용', $r['errors'] === [] && $r['image_ids'] === [1, 2, 3, 4, 5] && $r['has_image'], implode(' / ', $r['errors']));
$r = sani('<p>' . img(1) . img(2) . img(3) . img(4) . img(5) . img(6) . '</p>');
t('이미지 6장 거부', $r['errors'] !== [] && str_contains($r['errors'][0], '최대 5장'), implode(' / ', $r['errors']));
$r = sani('<p>글' . img(99) . img(1) . '</p>');
t('다른 글에 연결된 이미지 id 는 제거하고 rejected 로 보고', $r['image_ids'] === [1] && $r['rejected_image_ids'] === [99] && !str_contains($r['html'], 'id=99'), $r['html']);
$links = '';
for ($i = 1; $i <= 11; $i++) {
    $links .= '<a href="https://a.com/' . $i . '">링크' . $i . '</a> ';
}
$r = sani('<p>' . $links . '</p>');
t('링크 11개 거부', $r['errors'] !== [] && str_contains($r['errors'][0], '최대 10개'), implode(' / ', $r['errors']));
$r = sani('<p>' . str_repeat('가', 10001) . '</p>');
t('본문 10,001자 거부', $r['errors'] !== [] && str_contains($r['errors'][0], '10,000자'), implode(' / ', $r['errors']));
$r = sani('<p>' . str_repeat('가', 10000) . '</p>' . $table(1, 1, str_repeat('나', 200)));
t('본문 10,000자 + 표 글자는 별도(허용)', $r['errors'] === [], implode(' / ', $r['errors']));
$r = sani('<p> &nbsp; </p><p><br></p><script>x</script>');
t('빈 본문 거부', $r['errors'] === ['본문을 입력하세요.'], implode(' / ', $r['errors']));
$r = sani('<p>' . img(1) . '</p>');
t('이미지만 있는 본문 허용', $r['errors'] === [] && $r['text'] === '', implode(' / ', $r['errors']));
$r = sani($table(1, 1, ''));
t('빈 칸 표만 있는 본문 허용', $r['errors'] === [] && $r['has_table'], implode(' / ', $r['errors']));

echo "== 문자·멱등성\n";
$r = sani('<p>한글 😀 이모지 &amp; &lt;태그&gt; ©</p>');
t('한글·이모지·엔티티 보존', $r['html'] === '<p>한글 😀 이모지 &amp; &lt;태그&gt; ©</p>' && $r['text'] === '한글 😀 이모지 & <태그> ©', $r['html'] . ' | ' . $r['text']);
$samples = [
    '<p>한글 😀 <b>굵게</b><br>둘째 줄</p><h2>제목</h2><ul><li>a<ol><li>b</li></ol></li></ul>',
    'loose <i>text</i><div>div<p>p in div</p></div><blockquote>q<blockquote>qq</blockquote></blockquote>',
    $table(3, 3, '<b>x</b> &amp; y') . '<p><a href="https://a.com/한 글?x=1&y=2">링크</a>' . img(1) . '</p>',
    '<p>a<p>b</div><table><tr><td>c<table><tr><td>d</td></tr></table></td></tr></table><p><br></p><p>e<br><br></p>',
    "<p>탭\t과 줄\n바꿈 그리고&nbsp;nbsp</p><p></p><p>  </p><p>끝</p>",
];
$idem = true;
$bad = '';
foreach ($samples as $s) {
    $once = sani($s)['html'];
    $twice = sani($once)['html'];
    if ($once !== $twice) {
        $idem = false;
        $bad = "1회: {$once}\n      → 2회: {$twice}";
        break;
    }
}
t('정화 멱등성(두 번 정화해도 동일)', $idem, $bad);
$r = sani("<p>첫 단락</p><ul><li>항목1</li><li>항목2</li></ul><p>a<br>b</p>" . $table(1, 2, '표글자'));
t('HtmlText::toPlain 블록 경계 줄바꿈·표 제외', $r['text'] === "첫 단락\n항목1\n항목2\na\nb", json_encode($r['text'], JSON_UNESCAPED_UNICODE));
t('HtmlText::toPlain(includeTables)', HtmlText::toPlain($r['html'], true) === "첫 단락\n항목1\n항목2\na\nb\n표글자 표글자", json_encode(HtmlText::toPlain($r['html'], true), JSON_UNESCAPED_UNICODE));

echo "\n결과: 통과 {$pass} / 실패 {$fail}\n";
exit($fail > 0 ? 1 : 0);
