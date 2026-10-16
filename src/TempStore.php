<?php
/**
 * data/tmp/ 아래 임시 파일 기반 키-값 저장소(만료 시각 포함)
 *
 * 쿠키·세션과 무관한 "전체 공통" 카운터·잠금에 쓴다(관리자 로그인 잠금, 해지 요청 제한 등).
 * 파일명은 키의 해시라 원래 키(예: 이메일)가 파일명에 드러나지 않는다.
 * 쓰기는 임시 파일에 쓴 뒤 rename 하는 원자적 방식이고,
 * 읽기-수정-쓰기(update/increment)는 FileLock 으로 직렬화한다.
 */
final class TempStore
{
    private const LOCK_NAME = 'tempstore';

    public static function dir(): string
    {
        $dir = DATA_DIR . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** 값 조회. 없거나 만료되었으면 $default */
    public static function get(string $key, mixed $default = null): mixed
    {
        $entry = self::read(self::file($key));
        return $entry === null ? $default : $entry['value'];
    }

    /** 남은 유효 시간(초). 없으면 0 */
    public static function ttl(string $key): int
    {
        $entry = self::read(self::file($key));
        return $entry === null ? 0 : max(0, $entry['expires'] - time());
    }

    /** 값 저장. $ttlSeconds 후 만료 */
    public static function set(string $key, mixed $value, int $ttlSeconds): void
    {
        FileLock::synchronized(self::LOCK_NAME, static fn () => self::write(self::file($key), $value, time() + $ttlSeconds));
    }

    public static function delete(string $key): void
    {
        FileLock::synchronized(self::LOCK_NAME, static function () use ($key): void {
            $file = self::file($key);
            if (is_file($file)) {
                @unlink($file);
            }
        });
    }

    /**
     * 잠금 안에서 값 갱신: $fn(현재값|null) 의 반환값을 저장한다.
     * $keepExpiry 가 true 이고 기존 값이 살아 있으면 기존 만료 시각을 유지한다.
     */
    public static function update(string $key, int $ttlSeconds, callable $fn, bool $keepExpiry = false): mixed
    {
        return FileLock::synchronized(self::LOCK_NAME, static function () use ($key, $ttlSeconds, $fn, $keepExpiry) {
            $file = self::file($key);
            $entry = self::read($file);
            $newValue = $fn($entry['value'] ?? null);
            $expires = ($keepExpiry && $entry !== null) ? $entry['expires'] : time() + $ttlSeconds;
            self::write($file, $newValue, $expires);
            return $newValue;
        });
    }

    /** 카운터 1 증가 후 새 값 반환. 첫 증가 시점부터 $windowSeconds 동안 유지 */
    public static function increment(string $key, int $windowSeconds): int
    {
        return (int) self::update($key, $windowSeconds, static fn ($v) => ((int) $v) + 1, true);
    }

    /** 만료된 파일 삭제. 삭제 건수 반환(정리 배치용). $dryRun 이면 지우지 않고 셈만 한다(6b 미리보기) */
    public static function purgeExpired(bool $dryRun = false): int
    {
        return (int) FileLock::synchronized(self::LOCK_NAME, static function () use ($dryRun): int {
            $count = 0;
            foreach (glob(self::dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                $raw = @file_get_contents($file);
                $data = $raw === false ? null : json_decode($raw, true);
                if (!is_array($data) || !isset($data['expires']) || $data['expires'] <= time()) {
                    if ($dryRun || @unlink($file)) {
                        $count++;
                    }
                }
            }
            // 쓰다가 남은 임시 파일 정리(1시간 이상 된 것)
            foreach (glob(self::dir() . DIRECTORY_SEPARATOR . '*.tmp') ?: [] as $file) {
                if (filemtime($file) < time() - 3600 && ($dryRun || @unlink($file))) {
                    $count++;
                }
            }
            return $count;
        });
    }

    private static function file(string $key): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    /** @return array{value:mixed, expires:int}|null */
    private static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('value', $data) || !isset($data['expires'])) {
            return null;
        }
        if ((int) $data['expires'] <= time()) {
            return null;
        }
        return ['value' => $data['value'], 'expires' => (int) $data['expires']];
    }

    private static function write(string $file, mixed $value, int $expires): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['value' => $value, 'expires' => $expires], JSON_UNESCAPED_UNICODE);
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('임시 저장소에 쓸 수 없습니다(data/tmp 쓰기 권한 확인).');
        }
    }
}
