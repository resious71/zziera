<?php

use PhpScience\TextRank\Tool\Graph;
use PhpScience\TextRank\Tool\Parser;
use PhpScience\TextRank\Tool\Score;
use PhpScience\TextRank\Tool\Summarize;

/**
 * PHP-Science-TextRank 어댑터 (lib/ 소스는 고치지 않는다)
 *
 * 라이브러리의 TextRankFacade 는 "3글자 넘는 단어만" 키워드로 쓰도록 고정되어 있어
 * 2~3음절이 대부분인 한국어에서는 거의 모든 단어가 빠진다. 그래서 Facade 대신
 * Tool 클래스(Parser → Graph → Score → Summarize)를 직접 조합하고 최소 단어 길이를 낮춘다.
 *
 * 원문 문장 ↔ 전처리 문장 매핑:
 *  - 문장 분리는 Summarizer 가 하고, 여기에는 "전처리된 문장"을 한 줄에 하나씩 넘긴다.
 *  - 라이브러리는 줄바꿈으로 문장을 나누고 문장 번호(0부터)로 결과를 돌려주므로,
 *    그 번호가 곧 원문 문장 번호다. 번호가 어긋나지 않도록 빈 줄·한 글자 줄을 만들지 않는다.
 */
final class TextRankAdapter
{
    /** 빈 문장(불용어만 있는 문장 등) 자리를 채우는 값: 문장 수를 유지하되 단어로는 쓰이지 않는다(문장부호) */
    private const EMPTY_LINE = '__';

    /**
     * 전처리된 문장 목록에서 중요한 문장 번호를 고른다.
     *
     * @param list<string> $lines 전처리된 문장(원문 문장과 같은 순서)
     * @return list<int> 고른 문장 번호(원문 순서로 정렬). 키워드가 없으면 빈 배열
     */
    public static function rankSentences(array $lines, int $count, int $keywordLimit): array
    {
        $prepared = array_map(static function (string $l): string {
            $l = trim(preg_replace('/\s+/u', ' ', $l) ?? '');
            // 라이브러리가 길이 1(바이트)인 조각을 문장부호로 보고 빼 버리므로 그런 줄은 자리표시로 바꾼다
            return strlen($l) < 2 ? self::EMPTY_LINE : $l;
        }, $lines);

        $parser = new Parser();
        $parser->setMinimumWordLength(1); // 2글자 이상 단어부터 키워드(한 글자는 대부분 조사·의존 명사)
        $parser->setStopWords(new KoreanStopWords());
        $parser->setRawText(implode("\n", $prepared));
        $text = $parser->parse();

        if (count($text->getSentences()) !== count($prepared)) {
            // 문장 번호가 어긋나면 원문 매핑을 믿을 수 없다 → 호출 측이 대체 요약을 쓰게 한다
            throw new RuntimeException('TextRank 문장 수 불일치: ' . count($text->getSentences()) . ' != ' . count($prepared));
        }

        $graph = new Graph();
        $graph->createGraph($text);
        $scores = (new Score())->calculate($graph, $text);
        if ($scores === []) {
            return [];
        }

        $picked = (new Summarize())->getSummarize($scores, $graph, $text, $keywordLimit, $count, Summarize::GET_ALL_IMPORTANT);
        $indexes = array_map('intval', array_keys($picked));

        // 상위 키워드가 일부 문장에만 몰려 있으면 $count 보다 적게 나온다 → 앞쪽 문장으로 채운다
        for ($i = 0; count($indexes) < min($count, count($prepared)) && $i < count($prepared); $i++) {
            if (!in_array($i, $indexes, true)) {
                $indexes[] = $i;
            }
        }
        sort($indexes);
        return array_slice($indexes, 0, $count);
    }
}
