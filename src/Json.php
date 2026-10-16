<?php
/**
 * JSON 응답 헬퍼
 *
 * 성공: {"ok":true, ...data}
 * 실패: {"ok":false, "error":"메시지"}
 */
final class Json
{
    public static function send(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok(array $data = []): never
    {
        self::send(['ok' => true] + $data);
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::send(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /**
     * API 진입 파일에서 호출: 처리되지 않은 예외를 JSON 오류로 바꾼다.
     * ValidationException 은 400, NotFoundException 은 404 + 메시지, 그 외는 500 + 일반 메시지(상세는 서버 로그).
     */
    public static function installErrorHandler(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            if ($e instanceof ValidationException) {
                self::error($e->getMessage(), 400);
            }
            if ($e instanceof NotFoundException) {
                self::error($e->getMessage(), 404);
            }
            error_log('[ZZieRa] ' . $e);
            self::error('서버 오류가 발생했습니다. 잠시 후 다시 시도하세요.', 500);
        });
    }
}
