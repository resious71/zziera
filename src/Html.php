<?php
/**
 * HTML 출력 헬퍼
 */
final class Html
{
    /** HTML 이스케이프. 모든 출력은 이 함수를 거친다. */
    public static function e(mixed $value): string
    {
        // ENT_HTML401: 작은따옴표를 &#039; 로 출력(구형 메일 클라이언트 호환)
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    /**
     * 공통 보안 헤더(HTML 페이지용)
     * @param bool $csp 본문 HTML·에디터가 있는 페이지는 true: 같은 출처의 스크립트·스타일·이미지만 허용
     */
    public static function sendHeaders(bool $csp = false): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        if ($csp) {
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; "
                . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
        }
    }
}
