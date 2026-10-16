<?php
/**
 * 이용 안내(PRD 11장 사용자 가이드) — 로그인한 사용자용. 본문은 src/Guide.php.
 * 모든 화면 하단의 "이용 안내" 링크로 연다.
 */
require __DIR__ . '/src/bootstrap.php';

$appName = (string) Config::get('APP_NAME', 'ZZieRa');
Html::sendHeaders(true);
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>이용 안내 - <?= Html::e($appName) ?></title>
<?= Layout::favicon() ?>
<link rel="stylesheet" href="<?= Layout::asset('assets/app.css') ?>">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <?= Layout::brand() ?>
        <?= Layout::logoutForm() ?>
    </div>
</header>
<main class="container">
    <div class="detail-actions">
        <a class="zz-btn" href="index.php#view">← 찌라시 보기</a>
    </div>
    <article class="guide">
        <h1>이용 안내</h1>
        <nav class="guide-toc" aria-label="목차">
            <a href="#g-view">로그인하고 찌라시 보기</a>
            <a href="#g-write">찌라시 등록</a>
            <a href="#g-mail">메일 받기와 수신 중지</a>
            <a href="#g-social">추천과 댓글</a>
            <a href="#g-faq">자주 묻는 질문</a>
        </nav>
        <?= Guide::userHtml() ?>
    </article>
</main>
<?= Layout::footer() ?>
<script src="<?= Layout::asset('assets/pageguard.js') ?>"></script>
</body>
</html>
