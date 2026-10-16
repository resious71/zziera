<?php
/**
 * 찌라시 상세 페이지 (post.php?id=)
 *
 * 메일의 "원문 보기" 링크가 도착하는 단독 페이지. 로그인 필요(5b): 로그인하지 않았으면 게이트가
 * login.php?next=post.php?id=N 으로 보내고, 로그인하면 이 글로 돌아온다.
 * 본문은 ContentRenderer 가 출력 직전에 다시 정화한 HTML 을 에디터와 같은 zz-content 클래스로 보여 준다.
 */
require __DIR__ . '/src/bootstrap.php';

$appName = (string) Config::get('APP_NAME', 'ZZieRa');
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$post = $id === false ? null : PostRepository::find($id);

Html::sendHeaders(true);
header('Cache-Control: no-store');
if ($post === null) {
    http_response_code(404);
}
$pageTitle = $post === null ? '찌라시를 찾을 수 없습니다' : $post['title'];
$neighbors = $post === null ? ['prev' => null, 'next' => null] : PostRepository::neighbors((int) $post['id']);
/** 이전/다음 글 바(위·아래 공통). 목록과 같은 최신순: 이전 = 더 최신 글, 다음 = 더 오래된 글 */
$navButton = static function (?array $item, string $label, string $endText): string {
    if ($item === null) {
        return '<button type="button" class="zz-btn detail-nav-btn" disabled title="' . Html::e($endText) . '">' . Html::e($label) . '</button>';
    }
    // 버튼에는 화살표와 "이전 글/다음 글"만 보인다(제목은 화면 읽기 프로그램용 aria-label 에만)
    return '<a class="zz-btn detail-nav-btn" href="post.php?id=' . (int) $item['id'] . '" aria-label="'
        . Html::e(trim(str_replace(['←', '→'], '', $label)) . ': ' . $item['title']) . '">' . Html::e($label) . '</a>';
};
$detailNav = static function (string $extraClass) use ($navButton, $neighbors): string {
    return '<nav class="detail-nav' . $extraClass . '" aria-label="이전·다음 찌라시">'
        . $navButton($neighbors['prev'], '← 이전 글', '가장 최신 찌라시입니다')
        . $navButton($neighbors['next'], '다음 글 →', '가장 오래된 찌라시입니다') . '</nav>';
};
$backUrl = 'index.php' . ($post !== null ? '?date=' . rawurlencode(substr($post['created_at'], 0, 10)) : '') . '#view';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= Html::e($pageTitle) ?> - <?= Html::e($appName) ?></title>
<?= Layout::favicon() ?>
<link rel="stylesheet" href="<?= Layout::asset('assets/app.css') ?>">
<link rel="stylesheet" href="<?= Layout::asset('assets/content.css') ?>">
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
        <a class="zz-btn" href="<?= Html::e($backUrl) ?>">← 목록으로</a>
    </div>
<?php if ($post === null): ?>
    <div class="placeholder-box">
        <h1>찌라시를 찾을 수 없습니다</h1>
        <p>삭제되었거나 없는 글입니다.</p>
    </div>
<?php else: ?>
    <?= $detailNav('') ?>
    <article class="post-detail">
        <h1 class="detail-title"><?php if ($post['source'] === 'scrap'): ?><span class="badge badge-scrap">스크랩</span><?php endif; ?><?= Html::e($post['title']) ?></h1>
        <p class="detail-meta">등록 <?= Html::e($post['created_at']) ?></p>
        <?php /* 본문: 서버가 정화한 HTML(ContentRenderer) — 이스케이프하지 않고 그대로 출력 */ ?>
        <div class="zz-content"><?= $post['html'] ?></div>

        <?php if ($post['attachments'] !== []): ?>
            <section class="detail-section">
                <h2>첨부파일</h2>
                <ul class="attach-list">
                    <?php foreach ($post['attachments'] as $att): ?>
                        <li>
                            <?php if ($att['available']): ?>
                                <a href="api/file.php?type=attachment&amp;id=<?= (int) $att['id'] ?>"><?= Html::e($att['original_name']) ?></a>
                                <span class="muted">(<?= Html::e(Upload::formatBytes($att['file_size'])) ?>)</span>
                            <?php else: ?>
                                <span class="muted"><?= Html::e($att['original_name']) ?></span>
                                <span class="expired-inline">첨부가 만료되었습니다</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </article>
    <?php /* 추천·댓글(5d): assets/comments.js 가 채운다 */ ?>
    <section id="post-social" class="post-social" data-post-id="<?= (int) $post['id'] ?>" data-csrf="<?= Html::e(Csrf::token()) ?>" aria-label="추천과 댓글"></section>
    <?= $detailNav(' detail-nav-bottom') ?>
<?php endif; ?>
</main>
<?= Layout::footer() ?>
<script src="<?= Layout::asset('assets/pageguard.js') ?>"></script>
<script src="<?= Layout::asset('assets/comments.js') ?>"></script>
</body>
</html>
