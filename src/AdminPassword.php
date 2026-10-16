<?php
/**
 * 관리자 비밀번호 (PRD 4장 H-1, H-1b, 결정 21)
 *
 *  - 초기 상태: app_settings 에 admin_pass_hash 가 없으면 오늘 날짜 yyyymmdd(KST)와 비교한다(기존 설치 그대로 동작).
 *  - 변경 후: admin_pass_hash(password_hash) 가 있으면 password_verify 만 쓰고 날짜는 더 이상 통과하지 않는다.
 *  - admin_pass_version: 바꿀 때마다(또는 CLI 초기화) +1. 관리자 세션은 로그인 시점의 값을 들고 있다가
 *    값이 달라지면 무효가 된다(Auth::tokenState / sessionHasAdmin).
 *  - 평문 비밀번호는 어디에도 저장하지 않는다(DB·로그·응답·세션).
 */
final class AdminPassword
{
    public const KEY_HASH = 'admin_pass_hash';
    public const KEY_VERSION = 'admin_pass_version';
    public const KEY_CHANGED_AT = 'admin_pass_changed_at';
    public const MIN_LEN = 8;
    public const MAX_LEN = 64;

    /** 초기 상태(날짜 비밀번호)인지 */
    public static function isDefault(): bool
    {
        return self::hash() === null;
    }

    public static function version(): int
    {
        return max(1, (int) AppSettings::get(self::KEY_VERSION, '1'));
    }

    private static function hash(): ?string
    {
        $h = AppSettings::get(self::KEY_HASH);
        return $h === null || $h === '' ? null : $h;
    }

    /** 입력이 지금 유효한 관리자 비밀번호인지 */
    public static function verify(string $input): bool
    {
        if ($input === '') {
            return false;
        }
        $hash = self::hash();
        if ($hash === null) {
            return hash_equals(Time::nowObj()->format('Ymd'), trim($input)); // 초기 상태: 앞뒤 공백 허용(기존 동작)
        }
        return password_verify($input, $hash);
    }

    /**
     * 새 비밀번호 형식 검사. 문제가 없으면 null, 있으면 화면에 보일 문구.
     * (현재 비밀번호와 같은지는 현재 비밀번호를 확인한 뒤 sameAsCurrent 로 따로 본다)
     */
    public static function validateNew(string $new, string $confirm): ?string
    {
        $len = mb_strlen($new, 'UTF-8');
        if ($new === '' || trim($new) === '') {
            return '새 비밀번호를 입력하세요(공백만으로는 만들 수 없습니다).';
        }
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return '새 비밀번호는 ' . self::MIN_LEN . '~' . self::MAX_LEN . '자로 입력하세요.';
        }
        if (preg_match('/^\s*\d{8}\s*$/', $new)) {
            return '숫자 8자리(날짜 형태)는 새 비밀번호로 쓸 수 없습니다.';
        }
        if (!hash_equals($new, $confirm)) {
            return '새 비밀번호와 확인이 일치하지 않습니다.';
        }
        return null;
    }

    /** 새 비밀번호가 현재 비밀번호와 같은지(현재 비밀번호가 확인된 뒤에만 부른다) */
    public static function sameAsCurrent(string $new): bool
    {
        return self::verify($new);
    }

    /** 새 비밀번호 저장(해시) + 버전 증가. 새 버전을 돌려준다 */
    public static function store(string $new): int
    {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        return Db::transaction(static function () use ($hash): int {
            $version = self::version() + 1;
            AppSettings::set(self::KEY_HASH, $hash);
            AppSettings::set(self::KEY_VERSION, (string) $version);
            AppSettings::set(self::KEY_CHANGED_AT, Time::now());
            return $version;
        });
    }

    /** 초기 상태로 되돌림(CLI 분실 복구 전용). 버전을 올려 열려 있던 관리자 세션을 끊는다 */
    public static function resetToDefault(): int
    {
        return Db::transaction(static function (): int {
            $version = self::version() + 1;
            AppSettings::delete(self::KEY_HASH);
            AppSettings::set(self::KEY_VERSION, (string) $version);
            AppSettings::set(self::KEY_CHANGED_AT, Time::now());
            return $version;
        });
    }
}
