<?php
/**
 * 기존 찌라시의 요약(summary)을 현재 Summarizer 규칙으로 다시 계산한다(유지보수용).
 *
 * 요약은 원래 등록 시 1회만 계산한다. 요약 규칙이 바뀌었을 때만 이 스크립트로 다시 계산한다.
 *
 * 사용법:
 *   php cli\resummarize.php --dry-run        바뀔 내용만 출력
 *   php cli\resummarize.php                  전체 글(삭제 글 포함) 다시 계산
 *   php cli\resummarize.php --id=3 --id=5    지정한 글만
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}

$dryRun = in_array('--dry-run', $argv, true);
$ids = [];
foreach ($argv as $arg) {
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $ids[] = (int) $m[1];
    }
}

$sql = 'SELECT id, title, body, summary FROM zzira';
$params = [];
if ($ids !== []) {
    $sql .= ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params = $ids;
}
$rows = Db::all($sql . ' ORDER BY id', $params);

$changed = 0;
foreach ($rows as $row) {
    $new = Summarizer::summarize(HtmlText::toPlain($row['body']));
    $same = $new === (string) $row['summary'];
    echo "#{$row['id']} {$row['title']}\n";
    echo '  이전: ' . ($row['summary'] === '' || $row['summary'] === null ? '(없음)' : $row['summary']) . "\n";
    echo '  새로: ' . ($new === '' ? '(없음)' : $new) . ($same ? '  [변경 없음]' : '') . "\n";
    if (!$same) {
        $changed++;
        if (!$dryRun) {
            Db::exec('UPDATE zzira SET summary = ? WHERE id = ?', [$new, $row['id']]);
        }
    }
}

echo "\n대상 " . count($rows) . "건, 변경 {$changed}건" . ($dryRun ? ' (--dry-run: 저장하지 않음)' : ' 저장') . "\n";
if (!$dryRun && $changed > 0) {
    Audit::log('summary_recalc', 'zzira', null, "요약 재계산 {$changed}건");
}
