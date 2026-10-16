<?php
/**
 * 앱 비밀 키 data/app.key (32바이트, random_bytes)
 *
 * 5c 의 SMTP 비밀번호 암호화(SecretBox)와 5d 의 댓글·추천 해시(HMAC)가 함께 쓴다.
 * 처음 필요할 때 만들고, data/ 는 웹 접근이 차단된 폴더다. 백업에 반드시 포함한다
 * (키가 바뀌면 저장된 SMTP 비밀번호를 읽을 수 없고, 기존 추천·댓글의 "내 것" 판정이 끊긴다).
 */
final class AppKey
{
    private const FILE = 'app.key';
    private static ?string $cached = null;

    public static function path(): string
    {
        return DATA_DIR . DIRECTORY_SEPARATOR . self::FILE;
    }

    /**
     * 키를 읽는다. 없으면 $create 일 때 새로 만든다. 읽거나 만들 수 없으면 null.
     */
    public static function load(bool $create = true): ?string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $path = self::path();
        if (!is_file($path)) {
            if (!$create) {
                return null;
            }
            // 동시에 만들려는 다른 요청과 겹쳐도 하나만 남도록 x 모드(이미 있으면 실패)로 쓴다
            $fp = @fopen($path, 'xb');
            if ($fp !== false) {
                fwrite($fp, random_bytes(32));
                fclose($fp);
            }
        }
        $key = @file_get_contents($path);
        if (!is_string($key) || strlen($key) !== 32) {
            return null;
        }
        return self::$cached = $key;
    }

    /** 키(없으면 만든다). 만들 수 없으면 RuntimeException */
    public static function get(): string
    {
        $key = self::load(true);
        if ($key === null) {
            throw new RuntimeException('앱 키(data/app.key)를 만들거나 읽을 수 없습니다. data/ 폴더 쓰기 권한을 확인하세요.');
        }
        return $key;
    }
}
