<?php
/**
 * 사용자 로그인 (5b단계: 사이트 접근 제한)
 *
 *  - 관리자(날짜 비밀번호, Auth)와 별개다. 세션 키도 따로 쓴다(user_*).
 *  - 로그인은 "접근 자격 확인"일 뿐이다. 사용자 ID·번호를 찌라시·로그·발송 이력에 연결해 저장하지 않는다.
 *    로그인 성공·로그아웃·열람은 기록하지 않고, 잠금 발생(user_login_locked)만 감사 로그에 남긴다.
 *  - 매 요청마다 DB 에서 계정이 active 이고 token_version 이 세션 값과 같은지 확인한다
 *    → 정지·삭제·비밀번호 재설정은 그 사용자의 다음 요청에서 바로 반영된다.
 *  - 유휴 만료: 마지막 활동 후 USER_SESSION_IDLE_SEC(기본 8시간). 요청이 통과할 때마다 연장한다.
 *  - 로그인 잠금: 입력한 ID(소문자) 해시별로 연속 5회 실패 시 5분. TempStore(서버 임시 파일)에 둔다.
 *    없는 ID 도 같은 문구·같은 잠금으로 처리하고, 더미 해시로 password_verify 를 돌려 응답 시간 차이를 줄인다.
 */
final class UserAuth
{
    private const K_ID = 'user_id';
    private const K_VERSION = 'user_token_version';
    private const K_ACTIVE = 'user_last_active';

    /** 없는 ID 일 때 비교용 해시(응답 시간 맞추기). 실제 계정과 무관 */
    private const DUMMY_HASH = '$2y$10$DWkk7OTRUszyLKnk4kSqauupHFyfSwtfaxE5cx.QOg5b2T8IKNm/y';

    public const MSG_INVALID = 'ID 또는 비밀번호가 올바르지 않습니다.';
    public const MSG_SUSPENDED = '사용이 중지된 계정입니다. 관리자에게 문의하세요.';

    /** 이번 요청에서 세션이 만료·무효화되었는지(로그인 화면 안내용) */
    private static bool $expired = false;
    /** 이번 요청에서 이미 확인한 결과(같은 요청에서 DB 를 다시 보지 않음) */
    private static ?int $checkedUserId = null;

    public static function idleSec(): int
    {
        return max(1, Config::int('USER_SESSION_IDLE_SEC', 28800));
    }

    /**
     * 로그인 시도.
     * @return array{ok:bool, message:string, lock_remaining:int}
     */
    public static function attempt(string $loginId, string $password): array
    {
        $maxFails = max(1, Config::int('USER_LOGIN_MAX_FAILS', 5));
        $lockSec = max(1, Config::int('USER_LOGIN_LOCK_SEC', 300));
        $loginId = trim($loginId);
        $norm = mb_strtolower(mb_substr($loginId, 0, 100));
        $key = 'user_login_' . hash('sha256', $norm);

        // 잠겨 있으면 비밀번호를 확인하지 않고 거부(올바른 비밀번호도 거부)
        $guard = TempStore::get($key, []);
        $until = (int) ($guard['locked_until'] ?? 0);
        if ($until > time()) {
            return self::lockedResult($until - time());
        }

        $user = ($norm !== '' && UserRepository::validLoginId($loginId))
            ? Db::row('SELECT id, password_hash, status, token_version FROM users WHERE login_id = ?', [$loginId])
            : null;
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH) && $user !== null;

        if (!$valid) {
            $outcome = TempStore::update($key, $lockSec + 3600, static function ($g) use ($maxFails, $lockSec): array {
                $g = is_array($g) ? $g : [];
                $now = time();
                if ((int) ($g['locked_until'] ?? 0) > $now) {
                    return $g; // 동시에 들어온 다른 요청이 이미 잠금
                }
                $fails = ((int) ($g['locked_until'] ?? 0) > 0 ? 0 : (int) ($g['fails'] ?? 0)) + 1;
                return $fails >= $maxFails ? ['fails' => 0, 'locked_until' => $now + $lockSec] : ['fails' => $fails];
            });
            $lockedUntil = (int) ($outcome['locked_until'] ?? 0);
            if ($lockedUntil > time()) {
                Audit::log('user_login_locked', 'user', null, '연속 ' . $maxFails . '회 실패로 ' . $lockSec . '초 잠금: ID "' . mb_substr($loginId, 0, 40) . '"');
                return self::lockedResult($lockedUntil - time(), true);
            }
            $left = $maxFails - (int) ($outcome['fails'] ?? 0);
            return ['ok' => false, 'message' => self::MSG_INVALID . ' (' . $left . '번 더 틀리면 ' . Auth::formatDuration($lockSec) . ' 동안 잠깁니다)', 'lock_remaining' => 0];
        }

        TempStore::delete($key);
        if ($user['status'] !== 'active') {
            // 비밀번호가 맞을 때만 정지 안내(ID 존재 여부를 비밀번호 없이 알 수 없게)
            return ['ok' => false, 'message' => self::MSG_SUSPENDED, 'lock_remaining' => 0];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        Db::exec('UPDATE users SET last_login_date = ? WHERE id = ?', [Time::today(), $user['id']]);

        Session::regenerate(); // 세션 고정 공격 방지(다른 세션 값은 유지)
        $_SESSION[self::K_ID] = (int) $user['id'];
        $_SESSION[self::K_VERSION] = (int) $user['token_version'];
        $_SESSION[self::K_ACTIVE] = time();
        self::$checkedUserId = (int) $user['id'];
        return ['ok' => true, 'message' => '로그인했습니다.', 'lock_remaining' => 0];
    }

    public static function login(string $id, string $pw): bool
    {
        return self::attempt($id, $pw)['ok'];
    }

    private static function lockedResult(int $remaining, bool $justLocked = false): array
    {
        $maxFails = max(1, Config::int('USER_LOGIN_MAX_FAILS', 5));
        return [
            'ok' => false,
            'message' => ($justLocked ? "{$maxFails}회 연속 실패했습니다. " : '') . '로그인이 잠겨 있습니다. ' . Auth::formatDuration($remaining) . ' 후에 다시 시도하세요.',
            'lock_remaining' => $remaining,
        ];
    }

    /**
     * 현재 요청의 사용자 세션 확인(유휴 만료·계정 상태·token_version). 통과하면 마지막 활동 시각을 갱신한다.
     * 세션 쿠키가 없으면 세션을 만들지 않는다.
     */
    public static function check(bool $touch = true): bool
    {
        if (self::$checkedUserId !== null) {
            return true;
        }
        if (!Session::hasCookie() && session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        Session::start(!$wasActive); // 읽기만(세션 잠금으로 이미지 요청이 줄 서지 않게)
        $uid = (int) ($_SESSION[self::K_ID] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        $version = (int) ($_SESSION[self::K_VERSION] ?? 0);
        $last = (int) ($_SESSION[self::K_ACTIVE] ?? 0);
        $now = time();
        $ok = $last + self::idleSec() > $now;
        if ($ok) {
            $row = Db::row('SELECT status, token_version FROM users WHERE id = ?', [$uid]);
            $ok = $row !== null && $row['status'] === 'active' && (int) $row['token_version'] === $version;
        }
        if (!$ok) {
            self::$expired = true;
            self::clearSession();
            return false;
        }
        // 마지막 활동 갱신: 자주 쓰지 않도록 유휴 시간의 1/4(최대 60초)이 지났을 때만 세션에 쓴다
        if ($touch && $now - $last >= min(60, max(1, intdiv(self::idleSec(), 4)))) {
            Session::start();
            $_SESSION[self::K_ACTIVE] = $now;
            if (!$wasActive) {
                session_write_close();
            }
        }
        self::$checkedUserId = $uid;
        return true;
    }

    public static function isLoggedIn(): bool
    {
        return self::check(false);
    }

    /** 로그인한 사용자 번호(접근 확인용. 찌라시·로그에 저장하지 않는다) */
    public static function currentUserId(): ?int
    {
        return self::check(false) ? self::$checkedUserId : null;
    }

    /** API 가드: 사용자 또는 관리자 세션이 없으면 401 */
    public static function requireUser(): void
    {
        if (!self::check() && !Auth::isAdminSessionValid()) {
            AccessGate::deny();
        }
    }

    public static function wasExpired(): bool
    {
        return self::$expired;
    }

    /** 세션에서 사용자 키만 지운다(관리자 토큰·CSRF·작성 중 이미지 등은 그대로) */
    private static function clearSession(): void
    {
        Session::start();
        unset($_SESSION[self::K_ID], $_SESSION[self::K_VERSION], $_SESSION[self::K_ACTIVE]);
        self::$checkedUserId = null;
    }

    /** 로그아웃: 사이트에서 나간다(사용자 세션과 이 브라우저의 관리자 인증을 모두 끝냄) */
    public static function logout(): void
    {
        self::$checkedUserId = null;
        if (!Session::hasCookie() && session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        Session::start();
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }
}
