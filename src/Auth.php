<?php
/**
 * 관리자 인증 (PRD 4장 H-1, H-1a)
 *
 *  - 비밀번호: AdminPassword 가 판정한다. 초기 상태는 오늘 날짜 yyyymmdd(KST), 관리자가 바꾼 뒤에는 그 비밀번호(해시)만 유효(6c).
 *          관리자 계정 테이블은 없다. 세션에는 로그인 시점의 admin_pass_version 을 두고, 값이 바뀌면(비밀번호 변경·CLI 초기화)
 *          그 세션의 관리자 토큰을 모두 무효로 한다(다른 곳에서 열려 있던 관리자 로그인 종료).
 *  - 페이지 토큰(loc_mgmt 와 같은 방식): 인증에 성공하면 "그 화면 하나"에만 쓰는 토큰을 준다.
 *          화면은 토큰을 메모리에만 들고 X-Admin-Token 헤더로 보낸다 → 새로고침·새 탭·새 창에서는 다시 비밀번호를 넣어야 한다.
 *          세션에는 토큰 목록(토큰 → 마지막 활동 시각)만 둔다.
 *  - 유휴 만료: 토큰마다 마지막 활동 후 ADMIN_SESSION_TTL(기본 3600초)이 지나면 만료.
 *          실제 관리 작업 요청(requireAdmin)이 성공할 때마다 연장한다. 상태 조회(status)는 연장하지 않는다.
 *  - 로그인 잠금: 연속 LOGIN_MAX_FAILS(5)회 실패 시 LOGIN_LOCK_SEC(300초) 동안 잠근다.
 *          카운터는 TempStore(서버 임시 파일)에 "전체 공통"으로 둔다 → 쿠키·세션을 지워도 우회되지 않는다.
 *          (loc_mgmt 는 IP별로 세지만, 이 앱은 IP 를 저장하지 않으므로 전체 공통으로 센다.)
 *  - 감사 로그: 로그인 성공·실패·잠금·관리 잠그기·비밀번호 변경. 입력한 비밀번호와 IP 는 남기지 않는다.
 *          비밀번호 변경 화면에서 현재 비밀번호를 틀린 것도 로그인 실패와 같은 전역 카운터로 센다.
 */
final class Auth
{
    private const KEY_TOKENS = 'admin_tokens';   // [토큰 => 마지막 활동 시각]
    private const TOKEN_HEADER = 'HTTP_X_ADMIN_TOKEN';
    private const TOKEN_MAX = 50;                // 한 세션에 남겨 두는 페이지 토큰 수(오래된 것부터 버림)
    private const LOCK_KEY = 'admin_login_guard';
    private const KEY_PASS_VERSION = 'admin_pass_version'; // 세션: 로그인 시점의 관리자 비밀번호 버전

    public const STATE_NONE = 'none';
    public const STATE_VALID = 'valid';
    public const STATE_EXPIRED = 'expired';
    public const STATE_REVOKED = 'revoked'; // 관리자 비밀번호가 바뀌어 무효가 됨

    public static function ttl(): int
    {
        return max(1, Config::int('ADMIN_SESSION_TTL', 3600));
    }

    /** 요청 헤더의 페이지 토큰(형식이 맞지 않으면 '') */
    private static function requestToken(): string
    {
        $token = (string) ($_SERVER[self::TOKEN_HEADER] ?? '');
        return preg_match('/^[0-9a-f]{32}$/', $token) ? $token : '';
    }

    /**
     * 이 요청의 페이지 토큰 상태.
     *  none: 토큰 없음·모르는 토큰 / valid: 유효 / expired: 유휴 시간이 지남(세션에서 지운다)
     *  revoked: 로그인한 뒤 관리자 비밀번호가 바뀜(이 세션의 관리자 토큰을 모두 지운다)
     * @param bool $touch valid 이면 마지막 활동 시각을 지금으로(만료 연장)
     */
    public static function tokenState(bool $touch = false): string
    {
        $token = self::requestToken();
        if ($token === '' || (!Session::hasCookie() && session_status() !== PHP_SESSION_ACTIVE)) {
            return self::STATE_NONE;
        }
        Session::start();
        $tokens = is_array($_SESSION[self::KEY_TOKENS] ?? null) ? $_SESSION[self::KEY_TOKENS] : [];
        if (!isset($tokens[$token])) {
            return self::STATE_NONE;
        }
        if (!self::versionMatches()) {
            unset($_SESSION[self::KEY_TOKENS], $_SESSION[self::KEY_PASS_VERSION]);
            return self::STATE_REVOKED;
        }
        if ((int) $tokens[$token] + self::ttl() <= time()) {
            unset($_SESSION[self::KEY_TOKENS][$token]);
            return self::STATE_EXPIRED;
        }
        if ($touch) {
            $_SESSION[self::KEY_TOKENS][$token] = time();
        }
        return self::STATE_VALID;
    }

    /** 이 요청이 유효한 관리자 페이지 토큰을 가졌는지 */
    public static function isAdmin(bool $touch = false): bool
    {
        return self::tokenState($touch) === self::STATE_VALID;
    }

    /**
     * 이 브라우저 세션에 유효한 관리자 토큰이 하나라도 있는지(세션은 읽기만, 연장하지 않음).
     * <img src> 처럼 헤더를 보낼 수 없는 요청(삭제된 글의 이미지·첨부)에만 쓴다.
     */
    public static function sessionHasAdmin(): bool
    {
        if (!Session::hasCookie() && session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        Session::start(true);
        if (empty($_SESSION[self::KEY_TOKENS]) || !self::versionMatches()) {
            return false;
        }
        foreach ((array) ($_SESSION[self::KEY_TOKENS] ?? []) as $last) {
            if ((int) $last + self::ttl() > time()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 사이트 접근 게이트(5b)용: 이 브라우저 세션에 유효한 관리자 인증이 있는지. 만료 시각을 연장하지 않는다.
     * (페이지 이동은 X-Admin-Token 헤더를 보낼 수 없으므로 세션 단위로 본다. 관리 API 권한은 여전히 requireAdmin)
     */
    public static function isAdminSessionValid(): bool
    {
        return self::sessionHasAdmin();
    }

    /**
     * 관리자 API 공통 가드: 미인증 401, POST 는 CSRF 확인(403), 통과하면 만료 연장.
     */
    public static function requireAdmin(): void
    {
        $state = self::tokenState(false);
        if ($state !== self::STATE_VALID) {
            $expired = $state === self::STATE_EXPIRED || $state === self::STATE_REVOKED;
            Json::error(
                self::stateMessage($state),
                401,
                ['auth' => false, 'expired' => $expired]
            );
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Csrf::check();
        }
        self::tokenState(true); // 실제 작업 요청이므로 만료 연장
    }

    /** 인증이 없거나 끝난 이유를 화면 문구로 */
    public static function stateMessage(string $state): string
    {
        return match ($state) {
            self::STATE_EXPIRED => '관리자 인증 시간이 지났습니다. 비밀번호를 다시 입력하세요.',
            self::STATE_REVOKED => '관리자 비밀번호가 바뀌어 관리자 인증이 끝났습니다. 비밀번호를 다시 입력하세요.',
            default => '관리자 인증이 필요합니다.',
        };
    }

    /** 이 세션이 로그인한 시점의 비밀번호 버전이 지금과 같은지(6c). 버전을 모르는 옛 세션은 1로 본다 */
    private static function versionMatches(): bool
    {
        return (int) ($_SESSION[self::KEY_PASS_VERSION] ?? 1) === AdminPassword::version();
    }

    /** 관리자 API 성공 응답: 남은 시간을 함께 보내 화면의 카운트다운을 맞춘다 */
    public static function ok(array $data = []): never
    {
        Json::ok($data + ['session_remaining' => self::remaining(), 'session_ttl' => self::ttl()]);
    }

    /** 이 페이지 토큰의 남은 시간(초). 유효하지 않으면 0. 연장하지 않는다 */
    public static function remaining(): int
    {
        if (!self::isAdmin(false)) {
            return 0;
        }
        return max(0, (int) $_SESSION[self::KEY_TOKENS][self::requestToken()] + self::ttl() - time());
    }

    /** 로그인 잠금·실패 카운터 초기화(CLI 분실 복구 전용) */
    public static function clearLock(): void
    {
        TempStore::delete(self::LOCK_KEY);
    }

    /** 로그인 잠금 남은 시간(초). 잠겨 있지 않으면 0 */
    public static function lockRemaining(): int
    {
        $guard = TempStore::get(self::LOCK_KEY, []);
        $until = (int) ($guard['locked_until'] ?? 0);
        return max(0, $until - time());
    }

    /**
     * 로그인 시도.
     * @return array{ok:bool, message:string, lock_remaining:int}
     */
    public static function login(string $input): array
    {
        $maxFails = max(1, Config::int('LOGIN_MAX_FAILS', 5));
        $lockSec = max(1, Config::int('LOGIN_LOCK_SEC', 300));
        $outcome = self::guardedAttempt(AdminPassword::verify($input));

        $result = $outcome['_result'];
        $lockRemaining = max(0, (int) ($outcome['locked_until'] ?? 0) - time());

        if ($result === 'ok') {
            Session::regenerate(); // 세션 고정 공격 방지(세션 값은 유지)
            if (!self::versionMatches()) {
                $_SESSION[self::KEY_TOKENS] = []; // 예전 비밀번호로 받은 토큰은 버린다
            }
            $_SESSION[self::KEY_PASS_VERSION] = AdminPassword::version();
            $token = bin2hex(random_bytes(16));
            $tokens = is_array($_SESSION[self::KEY_TOKENS] ?? null) ? $_SESSION[self::KEY_TOKENS] : [];
            $now = time();
            // 만료된 토큰은 버리고, 개수가 많으면 오래된 것부터 버린다
            $tokens = array_filter($tokens, static fn ($last) => (int) $last + self::ttl() > $now);
            $tokens[$token] = $now;
            $_SESSION[self::KEY_TOKENS] = array_slice($tokens, -self::TOKEN_MAX, null, true);
            Audit::log('admin_login', 'admin', null, '관리자 인증');
            return ['ok' => true, 'message' => '인증되었습니다.', 'lock_remaining' => 0, 'token' => $token, 'password_default' => AdminPassword::isDefault()];
        }
        if ($result === 'locked') {
            Audit::log('admin_login_blocked', 'admin', null, '잠금 중 로그인 시도');
            return ['ok' => false, 'message' => self::lockMessage($lockRemaining), 'lock_remaining' => $lockRemaining];
        }
        if ($result === 'now_locked') {
            Audit::log('admin_login_fail', 'admin', null, "로그인 실패({$maxFails}회째)");
            Audit::log('admin_login_locked', 'admin', null, "연속 {$maxFails}회 실패로 {$lockSec}초 잠금");
            return ['ok' => false, 'message' => "비밀번호가 {$maxFails}회 연속 틀렸습니다. " . self::lockMessage($lockRemaining), 'lock_remaining' => $lockRemaining];
        }
        $fails = (int) ($outcome['fails'] ?? 0);
        Audit::log('admin_login_fail', 'admin', null, "로그인 실패({$fails}회째)");
        return [
            'ok' => false,
            'message' => '비밀번호가 올바르지 않습니다. (' . ($maxFails - $fails) . '번 더 틀리면 ' . self::formatDuration($lockSec) . ' 동안 잠깁니다)',
            'lock_remaining' => 0,
        ];
    }

    /**
     * 전역 잠금 카운터와 함께 비밀번호 확인 결과를 반영한다(로그인·비밀번호 변경 공통).
     * 비밀번호 확인(password_verify)은 호출하는 쪽에서 잠금 밖에서 미리 해 둔다(전역 잠금을 오래 잡지 않게).
     * 잠금 중이면 맞는 비밀번호도 'locked'. 성공하면 카운터를 지운다.
     * @return array{_result:string, fails?:int, locked_until?:int}
     */
    private static function guardedAttempt(bool $passwordOk): array
    {
        $lockSec = max(1, Config::int('LOGIN_LOCK_SEC', 300));
        $maxFails = max(1, Config::int('LOGIN_MAX_FAILS', 5));
        // 잠금 확인과 실패 카운트는 한 번의 잠금 구간에서 처리한다(동시 시도에도 정확하게)
        $outcome = TempStore::update(self::LOCK_KEY, $lockSec + 3600, static function ($guard) use ($passwordOk, $lockSec, $maxFails): array {
            $guard = is_array($guard) ? $guard : [];
            unset($guard['_result']); // 이전 호출의 결과 표시는 판정에 쓰지 않는다
            $now = time();
            $until = (int) ($guard['locked_until'] ?? 0);
            if ($until > $now) {
                return array_merge($guard, ['_result' => 'locked']);
            }
            if ($until > 0) {
                $guard = []; // 잠금이 끝났으면 카운터를 새로 시작
            }
            if ($passwordOk) {
                return ['_result' => 'ok'];
            }
            $fails = (int) ($guard['fails'] ?? 0) + 1;
            if ($fails >= $maxFails) {
                return ['fails' => 0, 'locked_until' => $now + $lockSec, '_result' => 'now_locked'];
            }
            return ['fails' => $fails, '_result' => 'fail'];
        });
        if ($outcome['_result'] === 'ok') {
            TempStore::delete(self::LOCK_KEY);
        }
        return $outcome;
    }

    /**
     * 관리자 비밀번호 변경(6c). requireAdmin 을 통과한 요청에서만 부른다.
     * 순서: 잠금 확인 → 새 비밀번호 형식 → 현재 비밀번호(틀리면 로그인 실패와 같은 카운터) → 현재와 같은지 → 저장.
     * 성공하면 버전이 올라 다른 관리자 세션이 끊기고, 이 세션은 새 버전으로 갱신(세션 ID 재발급)해 이 화면의 토큰만 남긴다.
     * @return array{ok:bool, message:string, lock_remaining:int}
     */
    public static function changePassword(string $current, string $new, string $confirm): array
    {
        $maxFails = max(1, Config::int('LOGIN_MAX_FAILS', 5));
        $lockSec = max(1, Config::int('LOGIN_LOCK_SEC', 300));
        $lock = self::lockRemaining();
        if ($lock > 0) {
            return ['ok' => false, 'message' => self::lockMessage($lock), 'lock_remaining' => $lock];
        }
        if ($current === '') {
            return ['ok' => false, 'message' => '현재 비밀번호를 입력하세요.', 'lock_remaining' => 0];
        }
        $invalid = AdminPassword::validateNew($new, $confirm);
        if ($invalid !== null) {
            return ['ok' => false, 'message' => $invalid, 'lock_remaining' => 0];
        }

        $outcome = self::guardedAttempt(AdminPassword::verify($current));
        $result = $outcome['_result'];
        $lockRemaining = max(0, (int) ($outcome['locked_until'] ?? 0) - time());
        if ($result === 'locked') {
            return ['ok' => false, 'message' => self::lockMessage($lockRemaining), 'lock_remaining' => $lockRemaining];
        }
        if ($result === 'now_locked') {
            Audit::log('admin_login_fail', 'admin', null, "비밀번호 변경: 현재 비밀번호 틀림({$maxFails}회째)");
            Audit::log('admin_login_locked', 'admin', null, "연속 {$maxFails}회 실패로 {$lockSec}초 잠금");
            return ['ok' => false, 'message' => "비밀번호가 {$maxFails}회 연속 틀렸습니다. " . self::lockMessage($lockRemaining), 'lock_remaining' => $lockRemaining];
        }
        if ($result === 'fail') {
            $fails = (int) ($outcome['fails'] ?? 0);
            Audit::log('admin_login_fail', 'admin', null, "비밀번호 변경: 현재 비밀번호 틀림({$fails}회째)");
            return [
                'ok' => false,
                'message' => '현재 비밀번호가 올바르지 않습니다. (' . ($maxFails - $fails) . '번 더 틀리면 ' . self::formatDuration($lockSec) . ' 동안 잠깁니다)',
                'lock_remaining' => 0,
            ];
        }
        if (AdminPassword::sameAsCurrent($new)) {
            return ['ok' => false, 'message' => '새 비밀번호가 현재 비밀번호와 같습니다.', 'lock_remaining' => 0];
        }

        $version = AdminPassword::store($new);
        $token = self::requestToken();
        Session::regenerate();
        $last = (int) ($_SESSION[self::KEY_TOKENS][$token] ?? time());
        $_SESSION[self::KEY_TOKENS] = [$token => max($last, time())]; // 이 화면의 토큰만 남긴다
        $_SESSION[self::KEY_PASS_VERSION] = $version;
        Audit::log('admin_password_change', 'admin', null, '관리자 비밀번호 변경');
        return ['ok' => true, 'message' => '비밀번호가 변경되었습니다. 다른 곳에서 열려 있던 관리자 로그인은 종료되었습니다.', 'lock_remaining' => 0];
    }

    /** 관리 잠그기: 이 화면의 토큰만 버린다(같은 브라우저의 다른 화면 토큰은 그대로) */
    public static function logout(): void
    {
        $token = self::requestToken();
        if ($token === '' || (!Session::hasCookie() && session_status() !== PHP_SESSION_ACTIVE)) {
            return;
        }
        Session::start();
        if (isset($_SESSION[self::KEY_TOKENS][$token])) {
            unset($_SESSION[self::KEY_TOKENS][$token]);
            Audit::log('admin_logout', 'admin', null, '관리 잠그기');
        }
    }

    public static function lockMessage(int $remaining): string
    {
        return '로그인이 잠겨 있습니다. ' . self::formatDuration($remaining) . ' 후에 다시 시도하세요.';
    }

    /** 초 → "4분 32초" */
    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return ($m > 0 ? "{$m}분 " : '') . "{$s}초";
    }
}
