<?php
/**
 * 설정 비밀값 암호화 (5c: 메일 설정의 SMTP 인증 비밀번호)
 *
 *  - AES-256-GCM(openssl), 저장할 때마다 새 IV(12바이트), 인증 태그(16바이트) 포함.
 *    저장 형식: "v1:" . base64(IV . 태그 . 암호문)
 *  - 키: data/app.key(32바이트 random_bytes). 처음 암호화할 때 만들고, data/ 는 웹 접근이 차단된 폴더다.
 *  - 키와 DB 가 같은 서버에 있으므로 "DB 파일만 유출되는 경우"를 막는 수준이다(서버 전체 유출은 막지 못한다).
 *  - 비밀값을 로그·예외 메시지에 넣지 않는다.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1:';

    public static function keyPath(): string
    {
        return AppKey::path();
    }

    /** 암호화할 수 없는 이유(문제가 없으면 null). 키 파일이 없으면 만들 수 있는지까지 본다 */
    public static function unavailableReason(): ?string
    {
        if (!extension_loaded('openssl')) {
            return 'PHP openssl 확장이 꺼져 있어 비밀번호를 암호화할 수 없습니다. php.ini 에서 extension=openssl 을 켜고 Apache를 재시작하세요.';
        }
        $path = self::keyPath();
        if (is_file($path)) {
            return is_readable($path) && filesize($path) === 32 ? null : 'data/app.key 를 읽을 수 없거나 형식이 올바르지 않습니다.';
        }
        return is_dir(DATA_DIR) && is_writable(DATA_DIR) ? null : 'data/ 폴더에 쓸 수 없어 암호화 키(data/app.key)를 만들 수 없습니다.';
    }

    /** 키 읽기(없으면 새로 만든다). 실패하면 null — 키 파일은 AppKey 가 관리한다(5d 댓글·추천 해시와 공용) */
    private static function key(bool $create): ?string
    {
        return AppKey::load($create);
    }

    /** 암호화. 할 수 없으면 ValidationException(사유 안내) */
    public static function encrypt(string $plain): string
    {
        if (($why = self::unavailableReason()) !== null) {
            throw new ValidationException($why . ' 비밀번호는 저장하지 않았습니다.');
        }
        $key = self::key(true);
        if ($key === null) {
            throw new ValidationException('암호화 키(data/app.key)를 만들거나 읽을 수 없어 비밀번호를 저장하지 않았습니다.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new ValidationException('비밀번호를 암호화하지 못해 저장하지 않았습니다.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** 복호화. 키가 없거나 바뀌었거나 값이 손상되었으면 null */
    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX) || !extension_loaded('openssl')) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $key = self::key(false);
        if ($raw === false || strlen($raw) < 29 || $key === null) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
}
