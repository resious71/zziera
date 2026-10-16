<?php
/**
 * 찌라시 요약 (PRD 3.3, 8.2) — 등록 시 1회 계산해 zzira.summary 에 저장한다.
 *
 * 입력은 HtmlText::toPlain() 으로 뽑은 본문 일반 텍스트다(표·이미지·링크 주소 제외).
 *  - 텍스트가 거의 없으면 '' → 목록·메일은 "(본문은 링크에서 확인)"
 *  - 문장 3개 이하: 앞부분 약 120자(멀티바이트 안전, 잘리면 말줄임표)
 *  - 문장 4개 이상: 한국어 전처리 → TextRank → 상위 N문장(기본 2)을 원문 순서로 이어 붙임
 *  - 실패(예외·빈 결과): 앞 2문장으로 대체하고 Audit::log('summary_error') — 등록은 막지 않는다
 * 요약 문장은 항상 원문 문장이다(전처리된 텍스트는 밖으로 나가지 않는다).
 */
final class Summarizer
{
    public const PREVIEW_LENGTH = 120;
    /** 공백을 뺀 글자가 이보다 적으면 "텍스트가 거의 없음"으로 본다 */
    public const MIN_TEXT_CHARS = 2;

    /** 테스트용: TextRank 대신 실행할 함수(예외를 일부러 내 대체 경로를 확인) */
    private static $engineOverride = null;

    public static function summarize(string $plainText): string
    {
        $text = self::normalize($plainText);
        if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < self::MIN_TEXT_CHARS) {
            return '';
        }

        $sentences = self::splitSentences($text);
        if (count($sentences) <= 3) {
            return self::preview($text);
        }

        try {
            $picked = self::rank($sentences);
            if ($picked === []) {
                throw new RuntimeException('TextRank 결과가 비어 있습니다');
            }
            return implode(' ', array_map(static fn (int $i) => $sentences[$i], $picked));
        } catch (Throwable $e) {
            Audit::log('summary_error', 'zzira', null, mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 300));
            return implode(' ', array_slice($sentences, 0, 2));
        }
    }

    /**
     * 문장 분리: 마침표·물음표·느낌표(… 。 포함, 뒤따르는 닫는 따옴표·괄호 허용) 뒤 공백, 또는 줄바꿈.
     * "3.4%" 처럼 바로 뒤에 공백이 없는 마침표는 나누지 않는다. 약어 오분리는 허용 오차로 본다.
     * @return list<string>
     */
    public static function splitSentences(string $text): array
    {
        $parts = preg_split(
            '/(?:(?<=[.?!…。])|(?<=[.?!…。]["\'”’)\]]))[ \t]+|\n+/u',
            self::normalize($text)
        ) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s) => $s !== ''));
    }

    /** 앞부분 미리보기(줄바꿈·연속 공백은 한 칸으로) */
    public static function preview(string $plainText, int $length = self::PREVIEW_LENGTH): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $plainText) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length)) . '…';
    }

    /** 테스트 전용: null 이면 원래 TextRank 사용 */
    public static function setEngineOverride(?callable $engine): void
    {
        self::$engineOverride = $engine;
    }

    /** @return list<int> 고른 문장 번호(원문 순서) */
    private static function rank(array $sentences): array
    {
        // 매우 긴 본문은 앞쪽 N문장만 분석(시간·메모리 상한)
        $max = max(4, Config::int('SUMMARY_MAX_SENTENCES', 300));
        $sentences = array_slice($sentences, 0, $max);
        $lines = array_map([KoreanPreprocessor::class, 'line'], $sentences);
        $count = max(1, Config::int('SUMMARY_SENTENCES', 2));
        $keywords = max(1, Config::int('SUMMARY_KEYWORDS', 10));

        if (self::$engineOverride !== null) {
            return (self::$engineOverride)($lines, $count, $keywords);
        }
        return TextRankAdapter::rankSentences($lines, $count, $keywords);
    }

    /** 줄 안의 공백 정리, 줄바꿈은 유지 */
    private static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        // 주의: PCRE 의 \v 는 "세로 공백 전체"(\n 포함)라 쓰지 않는다. 세로 탭은 \x0B 로 적는다.
        return trim(preg_replace('/[ \t\f\x0B]+/u', ' ', $text) ?? '');
    }
}
