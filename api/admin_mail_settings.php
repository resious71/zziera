<?php
/**
 * 메일 설정 (관리자 전용)
 *  GET : 현재 설정과 점검 결과. 비밀번호는 평문·암호문 모두 보내지 않는다(pass_set: 설정됨 여부만)
 *  POST: action=save(mail_from, mail_from_name, smtp_host, smtp_port, smtp_secure,
 *                    smtp_auth, smtp_user, smtp_pass, smtp_pass_clear, smtp_allow_self_signed, smtp_timeout) | clear
 *        smtp_pass 가 비어 있으면 기존 비밀번호 유지. 감사 로그에는 바뀐 키 이름만 남긴다.
 */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = Input::choice($_POST, 'action', '작업', ['save', 'clear']);
    if ($action === 'clear') {
        MailSettings::clear();
        Audit::log('mail_settings_update', 'settings', null, '메일 설정 지움');
        $message = '메일 설정을 지웠습니다. 다시 설정하기 전까지 메일을 보낼 수 없습니다.';
    } else {
        $changed = MailSettings::save($_POST);
        $s = MailSettings::get();
        if ($changed !== []) {
            Audit::log('mail_settings_update', 'settings', null, '변경: ' . implode(', ', $changed)
                . ' (서버 ' . $s['smtp_host'] . ':' . $s['smtp_port'] . ', 발신 ' . $s['mail_from'] . ')');
        }
        $message = $changed === [] ? '바뀐 내용이 없습니다.' : '메일 설정을 저장했습니다. "테스트 메일 보내기"로 확인해 보세요.';
    }
    Auth::ok(['message' => $message, 'settings' => MailSettings::forDisplay(), 'problems' => MailConfig::check()]);
}
Auth::ok(['settings' => MailSettings::forDisplay(), 'problems' => MailConfig::check()]);
