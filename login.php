<?php
/**
 * 로그인 화면 (5b단계) — 유일한 공개 페이지
 *
 * 관리자가 등록한 사용자 ID·비밀번호로 로그인한다. 로그인 후 next(사이트 내부 경로만) 또는 index.php 로 간다.
 * 하단 "관리자 로그인"을 펼치면 관리자 비밀번호로 인증하고 관리 탭으로 바로 간다.
 *   (초기 상태인지 여부는 이 화면·오류 문구에 드러내지 않는다. 6c, AdminPassword)
 *   → 받은 관리자 토큰은 sessionStorage 에 잠깐 넣어 index.php 의 admin.js 가 한 번만 꺼내 쓰고 지운다.
 * 동작은 assets/login.js(인라인 스크립트 없음, CSP).
 */
define('ZZ_PUBLIC', true);
require __DIR__ . '/src/bootstrap.php';

Db::pdo(); // 첫 접속 시 DB 생성
$next = AccessGate::safeNext($_GET['next'] ?? '');
if (UserAuth::isLoggedIn()) {
    header('Location: ' . ($next ?? 'index.php'), true, 302);
    exit;
}
$csrf = Csrf::token();
$appName = (string) Config::get('APP_NAME', 'ZZieRa');
$notice = isset($_GET['expired']) ? '로그인이 만료되었습니다. 다시 로그인하세요.'
    : (isset($_GET['logout']) ? '로그아웃했습니다.' : '');

Html::sendHeaders(true);
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>로그인 - <?= Html::e($appName) ?></title>
<?= Layout::favicon() ?>
<link rel="stylesheet" href="<?= Layout::asset('assets/app.css') ?>">
</head>
<body class="login-page">
<header class="site-header">
    <div class="container header-inner">
        <?= Layout::brand() ?>
    </div>
</header>
<main class="container">
    <section class="login-box" aria-labelledby="login-title">
        <h1 id="login-title">로그인</h1>
        <p class="muted">등록된 사용자만 이용할 수 있습니다. 계정은 관리자에게 요청하세요.</p>
        <?php if ($notice !== ''): ?>
            <p class="form-message <?= isset($_GET['logout']) ? 'ok' : 'error' ?>" id="login-notice" role="status"><?= Html::e($notice) ?></p>
        <?php endif; ?>
        <form id="login-form" method="post" action="api/user_login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="next" value="<?= Html::e($next ?? '') ?>">
            <label class="login-field">
                <span class="label">ID</span>
                <input type="text" name="login_id" id="login-id" autocomplete="username" maxlength="20" required autofocus spellcheck="false" autocapitalize="off">
            </label>
            <label class="login-field">
                <span class="label">비밀번호</span>
                <input type="password" name="password" id="login-password" autocomplete="current-password" maxlength="64" required>
            </label>
            <p class="form-message error" id="login-error" role="alert" hidden></p>
            <button type="submit" class="zz-btn login-submit" id="login-submit">로그인</button>
        </form>

        <div class="admin-login">
            <button type="button" class="link-btn" id="admin-login-toggle" aria-expanded="false" aria-controls="admin-login-form">관리자 로그인</button>
            <form id="admin-login-form" method="post" action="api/admin_login.php" hidden novalidate>
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrf) ?>">
                <label class="login-field">
                    <span class="label">관리자 비밀번호</span>
                    <input type="password" name="password" id="admin-password" autocomplete="off" maxlength="64">
                </label>
                <p class="form-message error" id="admin-login-error" role="alert" hidden></p>
                <button type="submit" class="zz-btn" id="admin-login-submit">관리자 인증</button>
            </form>
        </div>
    </section>
</main>
<script src="<?= Layout::asset('assets/login.js') ?>"></script>
</body>
</html>
