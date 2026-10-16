<?php
/**
 * 관리 화면에서 바꾸는 설정 저장소 (app_settings 테이블: key, value, updated_at)
 *
 * 메일 서버·발신 주소(MailSettings), 자동 발송 켜기/끄기(auto_send_enabled, auto_send_from) 등에 쓴다.
 */
final class AppSettings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = Db::value('SELECT value FROM app_settings WHERE key = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    /** @return array{value:string, updated_at:string}|null */
    public static function row(string $key): ?array
    {
        return Db::row('SELECT value, updated_at FROM app_settings WHERE key = ?', [$key]);
    }

    public static function set(string $key, string $value): void
    {
        Db::exec(
            'INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, $value, Time::now()]
        );
    }

    public static function delete(string $key): void
    {
        Db::exec('DELETE FROM app_settings WHERE key = ?', [$key]);
    }
}
