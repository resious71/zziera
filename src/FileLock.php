<?php
/**
 * data/locks/ 아래 파일 잠금(flock)
 *
 * $lock = FileLock::acquire('cron_send');           // 즉시 시도, 실패 시 null
 * $lock = FileLock::acquire('tempstore', true);     // 잠금이 풀릴 때까지 대기
 * ...
 * $lock->release();   // 또는 객체 소멸 시 자동 해제
 */
final class FileLock
{
    /** @var resource|null */
    private $handle;

    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function dir(): string
    {
        $dir = DATA_DIR . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function acquire(string $name, bool $wait = false): ?self
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
        $handle = @fopen(self::dir() . DIRECTORY_SEPARATOR . $safe . '.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('잠금 파일을 열 수 없습니다: ' . $safe);
        }
        $flags = LOCK_EX | ($wait ? 0 : LOCK_NB);
        if (!flock($handle, $flags)) {
            fclose($handle);
            return null;
        }
        return new self($handle);
    }

    /** 잠금 안에서 콜백 실행(대기형) */
    public static function synchronized(string $name, callable $fn): mixed
    {
        $lock = self::acquire($name, true);
        try {
            return $fn();
        } finally {
            $lock?->release();
        }
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
