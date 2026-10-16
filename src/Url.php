<?php
/**
 * 앱 URL 계산. 폴더 이름(zzira 등)을 코드에 박지 않고 요청에서 추론한다.
 */
final class Url
{
    /**
     * 웹 루트 기준 앱 경로. 예: /zzira (최상위에 두면 빈 문자열)
     * 현재 스크립트의 실제 파일 경로와 SCRIPT_NAME 을 비교해 계산한다.
     */
    public static function basePath(): string
    {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
        $root = realpath(APP_ROOT) ?: APP_ROOT;
        if ($scriptName === '' || $scriptFile === '' || stripos($scriptFile, $root) !== 0) {
            return '';
        }
        $relative = str_replace('\\', '/', substr($scriptFile, strlen($root))); // 예: /api/x.php
        if ($relative !== '' && strcasecmp(substr($scriptName, -strlen($relative)), $relative) === 0) {
            return rtrim(substr($scriptName, 0, -strlen($relative)), '/');
        }
        return rtrim(dirname($scriptName), '/');
    }

    /** 현재 요청 기준 앱 절대 URL. 예: http://server/zzira */
    public static function base(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        return ($https ? 'https' : 'http') . '://' . $host . self::basePath();
    }
}
