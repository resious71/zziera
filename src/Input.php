<?php
/**
 * 요청 입력 검증 헬퍼
 *
 * 검증 실패 시 ValidationException(사용자에게 보여 줄 한국어 메시지)을 던진다.
 * 글자 수는 mb_strlen 기준(멀티바이트 안전).
 */
final class Input
{
    /** POST 요청이 아니면 405 */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Json::error('허용되지 않은 요청 방식입니다.', 405);
        }
    }

    /**
     * 문자열 값. 앞뒤 공백 제거, 줄바꿈은 \n 으로 통일, 제어 문자 제거.
     */
    public static function string(array $src, string $key, string $label, int $maxLen, bool $required = true, bool $multiline = false): string
    {
        $raw = $src[$key] ?? '';
        if (!is_string($raw)) {
            throw new ValidationException($label . ' 값이 올바르지 않습니다.');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new ValidationException($label . '의 문자 인코딩이 올바르지 않습니다(UTF-8만 지원).');
        }
        $value = self::clean($raw, $multiline);
        if ($required && $value === '') {
            throw new ValidationException($label . '을(를) 입력하세요.');
        }
        if (mb_strlen($value) > $maxLen) {
            throw new ValidationException($label . '은(는) ' . number_format($maxLen) . '자 이하로 입력하세요.');
        }
        return $value;
    }

    /** 정수 값(범위 검사 포함). 없으면 $default, 그것도 null이면 오류 */
    public static function int(array $src, string $key, string $label, ?int $default = null, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $raw = $src[$key] ?? null;
        if ($raw === null || $raw === '') {
            if ($default === null) {
                throw new ValidationException($label . ' 값이 필요합니다.');
            }
            return $default;
        }
        if (!is_scalar($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new ValidationException($label . ' 값이 올바르지 않습니다.');
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new ValidationException($label . ' 값의 범위가 올바르지 않습니다.');
        }
        return $value;
    }

    /** 'Y-m-d' 날짜. 없으면 $default */
    public static function date(array $src, string $key, string $label, ?string $default = null): string
    {
        $raw = $src[$key] ?? '';
        if ($raw === '' && $default !== null) {
            return $default;
        }
        if (!is_string($raw) || !Time::isValidDate($raw)) {
            throw new ValidationException($label . ' 형식이 올바르지 않습니다(YYYY-MM-DD).');
        }
        return $raw;
    }

    /** 허용 목록 중 하나 */
    public static function choice(array $src, string $key, string $label, array $allowed, ?string $default = null): string
    {
        $raw = $src[$key] ?? $default;
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw new ValidationException($label . ' 값이 올바르지 않습니다.');
        }
        return $raw;
    }

    /** 이메일 주소 검증 후 소문자로 정규화 */
    public static function email(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 254 || preg_match('/[\r\n]/', $value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /** http/https URL 만 허용 */
    public static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }

    /** 잘못된 UTF-8·제어 문자 제거, 줄바꿈 통일 */
    public static function clean(string $value, bool $multiline = false): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // 탭과 (여러 줄일 때) 줄바꿈을 제외한 제어 문자 제거
        $value = preg_replace($multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return trim($value);
    }
}
