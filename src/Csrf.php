<?php
/**
 * CSRF 토큰 (세션당 1개)
 *
 * 폼: <input type="hidden" name="csrf_token" value="...">
 * fetch: 헤더 X-CSRF-Token
 */
final class Csrf
{
    private const KEY = '_csrf_token';
    public const FIELD = 'csrf_token';

    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function verify(?string $token): bool
    {
        Session::start();
        $expected = $_SESSION[self::KEY] ?? null;
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    /** 요청(POST 필드 또는 헤더)에서 토큰을 꺼내 검증. 실패 시 403 JSON 응답 후 종료 */
    public static function check(): void
    {
        $token = $_POST[self::FIELD] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify(is_string($token) ? $token : null)) {
            Json::error('보안 토큰이 유효하지 않습니다. 페이지를 새로고침한 뒤 다시 시도하세요.', 403);
        }
    }

    /** 폼에 넣을 hidden input */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . Html::e(self::token()) . '">';
    }
}
