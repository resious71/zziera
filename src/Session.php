<?php
/**
 * PHP 세션 시작
 *
 * HTTP 운영이므로 Secure 쿠키는 쓰지 않는다. HttpOnly + SameSite=Lax. 쿠키 수명은 브라우저 세션(0).
 * 등록자 식별 정보 비저장 원칙에 따라 세션 ID를 DB나 로그에 남기지 않는다.
 *
 * 세션 파일은 data/sessions/ 에 따로 둔다(4단계):
 *  PHP 의 세션 GC 는 "그 요청을 처리한 앱의 gc_maxlifetime" 기준으로 같은 폴더의 파일을 지운다.
 *  기본 폴더를 같은 서버의 다른 앱과 함께 쓰면, 그 앱의 기본값(1440초)으로 관리자 세션이
 *  1시간(유휴 만료) 전에 지워질 수 있으므로 폴더를 분리하고 gc_maxlifetime 을 관리자 세션 시간 이상으로 둔다.
 */
final class Session
{
    /**
     * @param bool $readOnly true 면 세션 값을 읽기만 하고 바로 닫는다(세션 잠금으로 이미지 요청이 줄 서지 않게).
     */
    public static function start(bool $readOnly = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        $dir = DATA_DIR . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        // 관리자 유휴 만료(기본 3600초)·사용자 유휴 만료(기본 8시간)보다 먼저 세션 파일이 지워지지 않도록
        ini_set('session.gc_maxlifetime', (string) (max(3600, Config::int('ADMIN_SESSION_TTL', 3600), Config::int('USER_SESSION_IDLE_SEC', 28800)) + 600));
        session_name((string) Config::get('SESSION_NAME', 'ZZIRASESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start($readOnly ? ['read_and_close' => true] : []);
    }

    /** 세션 쿠키가 요청에 있는지(없으면 세션을 새로 만들 필요가 없다) */
    public static function hasCookie(): bool
    {
        return isset($_COOKIE[(string) Config::get('SESSION_NAME', 'ZZIRASESSID')]);
    }

    /** 로그인 등 권한 변경 시 세션 ID 재발급 */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }
}
