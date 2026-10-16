<?php
/**
 * 정화된 본문 HTML → 일반 텍스트
 *
 * 블록 경계(p, li, h2, h3, blockquote, br, tr 등)는 줄바꿈으로 바꾸고, 엔티티는 풀고, 공백은 정리한다.
 * 표·이미지는 기본적으로 뺀다($includeTables=true 면 표 글자도 넣는다).
 * 글자 수 제한, 요약, 미리보기, 검색에 사용한다.
 */
final class HtmlText
{
    private const BLOCKS = ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'tr', 'div',
        'ul', 'ol', 'table', 'thead', 'tbody', 'hr', 'pre', 'section', 'article'];

    public static function toPlain(string $html, bool $includeTables = false): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
            . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        $buf = '';
        if ($body !== null) {
            self::walk($body, $buf, $includeTables);
        }

        // 공백 정리: 줄 안의 연속 공백은 한 칸, 줄 앞뒤 공백 제거, 빈 줄 제거
        $buf = str_replace("\u{00A0}", ' ', $buf);
        $lines = array_map(
            static fn (string $l) => trim(preg_replace('/[ \t\f\x0B]+/u', ' ', $l) ?? ''), // \v 는 \n 까지 포함하므로 쓰지 않음
            explode("\n", $buf)
        );
        return implode("\n", array_values(array_filter($lines, static fn (string $l) => $l !== '')));
    }

    private static function walk(DOMNode $node, string &$buf, bool $includeTables): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                // HTML 안의 줄바꿈 문자는 화면에서 공백이므로 공백으로 바꾼다
                $buf .= str_replace(["\r", "\n"], ' ', $child->data);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['script', 'style', 'img', 'head', 'title'], true)) {
                continue;
            }
            if ($tag === 'table' && !$includeTables) {
                $buf .= "\n";
                continue;
            }
            if ($tag === 'br') {
                $buf .= "\n";
                continue;
            }
            if ($tag === 'td' || $tag === 'th') {
                $buf .= ' ';
                self::walk($child, $buf, $includeTables);
                $buf .= ' ';
                continue;
            }
            $isBlock = in_array($tag, self::BLOCKS, true);
            if ($isBlock) {
                $buf .= "\n";
            }
            self::walk($child, $buf, $includeTables);
            if ($isBlock) {
                $buf .= "\n";
            }
        }
    }
}
