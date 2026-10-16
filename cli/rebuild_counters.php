<?php
/**
 * 댓글·추천 집계값(zzira.comment_count, vote_count)을 실제 행 수로 다시 맞춘다(5d, Counters::rebuild).
 *   C:\xampp\php\php.exe cli\rebuild_counters.php
 */
require __DIR__ . '/../src/bootstrap.php';

if (!IS_CLI) {
    http_response_code(403);
    exit;
}
$before = Counters::mismatches();
$fixed = Counters::rebuild();
echo "집계가 어긋난 글 {$before}건 → {$fixed}건 고침" . PHP_EOL;
