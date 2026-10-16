<?php
/**
 * 사이트 접근 게이트 (5b단계) — 기본 거부
 *
 * bootstrap.php 가 모든 웹 요청에서 enforce() 를 부른다. 공개 허용 목록의 파일만
 * require 전에 define('ZZ_PUBLIC', true) 로 확인을 건너뛴다(새 파일을 추가해도 실수로 열리지 않게).
 *
 * 통과 조건: 유효한 사용자 세션(UserAuth) 또는 유효한 관리자 세션(Auth, 연장하지 않음).
 * 미통과: 페이지는 login.php?next=<원래 경로> 로 302, API(api/*, 파일 다운로드 포함)는 401 JSON.
 * 통과한 응답은 Cache-Control: no-store(로그아웃 후 뒤로 가기로 내용이 보이지 않게).
 */
final class AccessGate
{
    public static function enforce(): void
    {
        if (IS_CLI || defined('ZZ_PUBLIC')) {
            return;
        }
        if (!UserAuth::check() && !Auth::isAdminSessionValid()) {
            self::deny();
        }
        header('Cache-Control: no-store');
    }

    /** 이 요청이 api/ 아래 스크립트인지 */
    private static function isApi(): bool
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        return basename(dirname($script)) === 'api';
    }

    public static function deny(): never
    {
        header('Cache-Control: no-store');
        $expired = UserAuth::wasExpired();
        if (self::isApi()) {
            Json::send([
                'ok'      => false,
                'error'   => 'login_required',
                'message' => $expired ? '로그인이 만료되었습니다. 다시 로그인하세요.' : '로그인이 필요합니다.',
                'expired' => $expired,
            ], 401);
        }
        $next = self::currentRelativeUrl();
        $query = [];
        if ($next !== '' && self::safeNext($next) !== null) {
            $query['next'] = $next;
        }
        if ($expired) {
            $query['expired'] = '1';
        }
        header('Location: ' . Url::basePath() . '/login.php' . ($query === [] ? '' : '?' . http_build_query($query)), true, 302);
        exit;
    }

    /** 요청 주소에서 앱 경로를 뺀 상대 주소. 예: /ZZieRa/post.php?id=1 → post.php?id=1 */
    private static function currentRelativeUrl(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $base = Url::basePath() . '/';
        if ($base !== '/' && stripos($uri, $base) === 0) {
            return substr($uri, strlen($base));
        }
        return ltrim($uri, '/');
    }

    /**
     * 로그인 후 돌아갈 주소 검증(오픈 리다이렉트 방지). 사이트 안의 페이지 상대 경로만 허용한다.
     *  허용: index.php, post.php?id=3, guide.php#x 등 루트의 *.php (login.php 제외)
     *  거부: //host, http:..., \, 제어 문자(개행), 하위 폴더(api/ 등), 너무 긴 값
     * @return string|null 안전하면 그대로, 아니면 null
     */
    public static function safeNext(mixed $next): ?string
    {
        if (!is_string($next) || $next === '' || strlen($next) > 500) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $next) || str_contains($next, '//') || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $next)) {
            return null;
        }
        if (!preg_match('/^([a-z0-9_\-]+\.php)(?:[?#][^\s]*)?$/i', $next, $m)) {
            return null;
        }
        return strcasecmp($m[1], 'login.php') === 0 ? null : $next;
    }
}
