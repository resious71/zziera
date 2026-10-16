<?php
/**
 * 메일 설정을 읽는 단일 진입점 (MailerFactory, PhpMailerMailer, MailConfig, 설치 점검, CLI 정기 발송 모두 여기서 읽는다)
 *
 * 화면에서 입력해 DB(app_settings)에 저장하는 값 — 기본값 없음, 처음에는 모두 비어 있거나 꺼져 있다:
 *   mail_from(발신 주소), mail_from_name(발신자 이름, 비면 "ZZieRa"), smtp_host, smtp_port, smtp_secure(none/tls/ssl)
 *   smtp_auth('1'/'0'), smtp_user, smtp_pass(SecretBox 로 암호화한 값만 저장), smtp_allow_self_signed('1'/'0'),
 *   smtp_timeout(빈 값이면 기본 20초, 5~120)  ← 5c 추가
 *
 * config.php 의 SMTP_USER / SMTP_PASS / SMTP_ALLOW_SELF_SIGNED / SMTP_TIMEOUT 는 하위 호환용 대체값이다.
 *   - 인증: 화면에서 "SMTP 인증 사용"을 켜고 저장했으면 화면 값, 아니면 config.php 의 SMTP_USER 가 있을 때 그 값
 *   - 자체 서명 인증서 허용: 화면에서 켰으면 켬, 아니면 config.php 값
 *   - 연결 제한시간: 화면 값이 있으면 그 값, 아니면 config.php 값(없으면 20초)
 * 비밀번호는 화면·API 응답·로그 어디에도 내보내지 않는다(forDisplay 는 "설정됨" 여부만 준다).
 */
final class MailSettings
{
    public const KEYS = ['mail_from', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_secure',
        'smtp_auth', 'smtp_user', 'smtp_allow_self_signed', 'smtp_timeout'];
    public const PASS_KEY = 'smtp_pass';
    public const SECURE_VALUES = ['none', 'tls', 'ssl'];
    public const DEFAULT_FROM_NAME = 'ZZieRa';
    public const TIMEOUT_DEFAULT = 20;
    public const TIMEOUT_MIN = 5;
    public const TIMEOUT_MAX = 120;
    public const USER_MAX = 254;
    public const PASS_MAX = 256;

    /** 화면에 저장된 값 그대로(비밀번호는 암호문). 내부용 */
    private static function stored(): array
    {
        $keys = array_merge(self::KEYS, [self::PASS_KEY]);
        $values = array_fill_keys($keys, '');
        $updated = null;
        $marks = implode(',', array_fill(0, count($keys), '?'));
        foreach (Db::all("SELECT key, value, updated_at FROM app_settings WHERE key IN ({$marks})", $keys) as $r) {
            $values[$r['key']] = (string) $r['value'];
            $updated = max((string) $updated, (string) $r['updated_at']);
        }
        $values['_updated_at'] = $updated;
        return $values;
    }

    /**
     * 발송에 실제로 쓰는 값.
     * @return array{mail_from:string, mail_from_name:string, smtp_host:string, smtp_port:string, smtp_secure:string,
     *               smtp_auth:bool, smtp_user:string, smtp_pass:string, pass_unreadable:bool, auth_source:string,
     *               allow_self_signed:bool, timeout:int, updated_at:?string}
     */
    public static function get(): array
    {
        $st = self::stored();
        $out = [];
        foreach (['mail_from', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_secure'] as $k) {
            $out[$k] = $st[$k];
        }

        // 인증: 화면에서 켰으면 화면 값, 아니면 config.php 대체값
        $passUnreadable = false;
        if ($st['smtp_auth'] === '1') {
            $pass = '';
            if ($st[self::PASS_KEY] !== '') {
                $dec = SecretBox::decrypt($st[self::PASS_KEY]);
                $passUnreadable = $dec === null;
                $pass = $dec ?? '';
            }
            $auth = ['smtp_auth' => true, 'smtp_user' => $st['smtp_user'], 'smtp_pass' => $pass, 'auth_source' => 'screen'];
        } elseif ((string) Config::get('SMTP_USER', '') !== '') {
            $auth = ['smtp_auth' => true, 'smtp_user' => (string) Config::get('SMTP_USER', ''), 'smtp_pass' => (string) Config::get('SMTP_PASS', ''), 'auth_source' => 'config'];
        } else {
            $auth = ['smtp_auth' => false, 'smtp_user' => '', 'smtp_pass' => '', 'auth_source' => 'none'];
        }

        $timeout = $st['smtp_timeout'] !== '' ? (int) $st['smtp_timeout'] : Config::int('SMTP_TIMEOUT', self::TIMEOUT_DEFAULT);
        return $out + $auth + [
            'pass_unreadable'   => $passUnreadable,
            'allow_self_signed' => $st['smtp_allow_self_signed'] === '1' || (bool) Config::get('SMTP_ALLOW_SELF_SIGNED', false),
            'timeout'           => min(self::TIMEOUT_MAX, max(self::TIMEOUT_MIN, $timeout)),
            'updated_at'        => $st['_updated_at'],
        ];
    }

    /** 화면에 보낼 값. 비밀번호(평문·암호문)는 절대 넣지 않고 "설정됨" 여부만 준다 */
    public static function forDisplay(): array
    {
        $st = self::stored();
        $eff = self::get();
        return [
            'mail_from'               => $st['mail_from'],
            'mail_from_name'          => $st['mail_from_name'],
            'smtp_host'               => $st['smtp_host'],
            'smtp_port'               => $st['smtp_port'],
            'smtp_secure'             => $st['smtp_secure'],
            'smtp_auth'               => $st['smtp_auth'] === '1',
            'smtp_user'               => $st['smtp_user'],
            'pass_set'                => $st[self::PASS_KEY] !== '',
            'pass_unreadable'         => $st[self::PASS_KEY] !== '' && SecretBox::decrypt($st[self::PASS_KEY]) === null,
            'smtp_allow_self_signed'  => $st['smtp_allow_self_signed'] === '1',
            'smtp_timeout'            => $st['smtp_timeout'],
            'timeout_default'         => Config::int('SMTP_TIMEOUT', self::TIMEOUT_DEFAULT),
            'config_auth_in_use'      => $eff['auth_source'] === 'config',
            'config_self_signed'      => (bool) Config::get('SMTP_ALLOW_SELF_SIGNED', false) && $st['smtp_allow_self_signed'] !== '1',
            'allow_self_signed'       => $eff['allow_self_signed'],
            'auth_configured'         => $eff['smtp_auth'],
            'encrypt_unavailable'     => SecretBox::unavailableReason(),
            'updated_at'              => $st['_updated_at'],
        ];
    }

    /** 발신자 이름(비어 있으면 ZZieRa) */
    public static function fromName(array $s): string
    {
        return $s['mail_from_name'] !== '' ? $s['mail_from_name'] : self::DEFAULT_FROM_NAME;
    }

    /**
     * 화면에서 입력한 값 검증 후 저장. 발신 주소·서버·포트·보안은 필수.
     * 인증: 비밀번호 칸이 비어 있으면 기존 값 유지, 값이 있으면 암호화해 교체, smtp_pass_clear=1 이면 삭제.
     * 인증을 켜고 ID·비밀번호를 비워 저장하는 것은 허용한다(MailConfig::check 가 부족 항목으로 알린다).
     * @return list<string> 바뀐 키 이름(값은 담지 않는다)
     */
    public static function save(array $input): array
    {
        $from = self::single($input['mail_from'] ?? '', '발신 주소');
        $name = self::single($input['mail_from_name'] ?? '', '발신자 이름');
        $host = self::single($input['smtp_host'] ?? '', '메일 서버 주소');
        $port = trim((string) ($input['smtp_port'] ?? ''));
        $secure = trim((string) ($input['smtp_secure'] ?? ''));
        $auth = ($input['smtp_auth'] ?? '0') === '1' ? '1' : '0';
        $user = self::single($input['smtp_user'] ?? '', 'SMTP 인증 ID');
        $pass = is_string($input['smtp_pass'] ?? null) ? $input['smtp_pass'] : '';
        $passClear = ($input['smtp_pass_clear'] ?? '0') === '1';
        $selfSigned = ($input['smtp_allow_self_signed'] ?? '0') === '1' ? '1' : '0';
        $timeout = trim((string) ($input['smtp_timeout'] ?? ''));

        $errors = [];
        if ($from === '') {
            $errors[] = '발신 주소를 입력하세요.';
        } elseif (Input::email($from) === null) {
            $errors[] = '발신 주소의 이메일 형식이 올바르지 않습니다.';
        }
        if (mb_strlen($name) > 100) {
            $errors[] = '발신자 이름은 100자 이하로 입력하세요.';
        }
        if ($host === '') {
            $errors[] = '메일 서버 주소를 입력하세요.';
        } elseif (!self::validHost($host)) {
            $errors[] = '메일 서버 주소는 영문·숫자·점(.)·하이픈(-)으로 된 호스트 이름이나 IP 주소만 입력할 수 있습니다.';
        }
        if ($port === '') {
            $errors[] = '포트를 입력하세요.';
        } elseif (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $errors[] = '포트는 1~65535 사이의 숫자로 입력하세요.';
        }
        if (!in_array($secure, self::SECURE_VALUES, true)) {
            $errors[] = '보안 방식을 선택하세요(없음 / TLS / SSL).';
        }
        if (mb_strlen($user) > self::USER_MAX) {
            $errors[] = 'SMTP 인증 ID는 ' . self::USER_MAX . '자 이하로 입력하세요.';
        }
        if (mb_strlen($pass) > self::PASS_MAX) {
            $errors[] = 'SMTP 인증 비밀번호는 ' . self::PASS_MAX . '자 이하로 입력하세요.';
        } elseif (str_contains($pass, "\0") || preg_match('/[\r\n]/', $pass)) {
            $errors[] = 'SMTP 인증 비밀번호에 줄바꿈이나 제어 문자를 넣을 수 없습니다.';
        }
        if ($timeout !== '' && (!ctype_digit($timeout) || (int) $timeout < self::TIMEOUT_MIN || (int) $timeout > self::TIMEOUT_MAX)) {
            $errors[] = '연결 제한시간은 ' . self::TIMEOUT_MIN . '~' . self::TIMEOUT_MAX . '초로 입력하세요(비우면 기본 ' . self::TIMEOUT_DEFAULT . '초).';
        }
        if ($errors !== []) {
            throw new ValidationException(implode("\n", $errors));
        }
        // 비밀번호는 다른 값을 저장하기 전에 암호화해 본다(실패하면 아무것도 저장하지 않음)
        $passCipher = ($pass !== '' && !$passClear) ? SecretBox::encrypt($pass) : null;

        $new = [
            'mail_from'              => Input::email($from),
            'mail_from_name'         => $name,
            'smtp_host'              => strtolower($host),
            'smtp_port'              => (string) (int) $port,
            'smtp_secure'            => $secure,
            'smtp_auth'              => $auth,
            'smtp_user'              => $user,
            'smtp_allow_self_signed' => $selfSigned,
            'smtp_timeout'           => $timeout === '' ? '' : (string) (int) $timeout,
        ];
        $old = self::stored();
        $changed = [];
        Db::transaction(static function () use ($new, $old, $passCipher, $passClear, &$changed): void {
            foreach ($new as $key => $value) {
                if ($old[$key] !== $value || AppSettings::row($key) === null) {
                    AppSettings::set($key, $value);
                    if ($old[$key] !== $value) {
                        $changed[] = $key;
                    }
                }
            }
            if ($passClear) {
                if ($old[self::PASS_KEY] !== '') {
                    AppSettings::delete(self::PASS_KEY);
                    $changed[] = self::PASS_KEY . '(삭제)';
                }
            } elseif ($passCipher !== null) {
                AppSettings::set(self::PASS_KEY, $passCipher);
                $changed[] = self::PASS_KEY;
            }
        });
        return $changed;
    }

    /** 설정 지우기: 인증 정보·암호화된 비밀번호까지 모두 지워 다시 미설정 상태로 */
    public static function clear(): void
    {
        foreach (array_merge(self::KEYS, [self::PASS_KEY]) as $key) {
            AppSettings::delete($key);
        }
    }

    /** 한 줄 값: 개행·제어 문자가 있으면 거부(헤더 인젝션 방지), 앞뒤 공백 제거 */
    private static function single(mixed $value, string $label): string
    {
        $value = is_string($value) ? $value : '';
        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $value)) {
            throw new ValidationException("{$label}에 줄바꿈이나 제어 문자를 넣을 수 없습니다.");
        }
        return trim($value);
    }

    public static function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return strlen($host) <= 253
            && (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host);
    }
}
