<?php
/**
 * ZZieRa 메인 페이지: "찌라시 보기 / 찌라시 등록 / 관리" 3개 탭
 *
 * 탭 전환과 목록·상세·등록은 assets/app.js, 본문 에디터는 assets/editor.js 가 담당한다.
 * 서버 설정값(제한값, CSRF 토큰, 오늘 날짜)은 #app-config JSON 으로 넘긴다(실행되지 않는 데이터 블록).
 */
require __DIR__ . '/src/bootstrap.php';

Db::pdo();             // 첫 접속 시 DB 생성
$csrf = Csrf::token(); // 세션 시작 + 토큰 발급

$appName = (string) Config::get('APP_NAME', 'ZZieRa');
$today = Time::today();
$initialDate = (isset($_GET['date']) && is_string($_GET['date']) && Time::isValidDate($_GET['date'])) ? $_GET['date'] : $today;

/** php.ini 크기 문자열(예: 40M) → 바이트 */
function iniBytes(string $value): int
{
    $value = trim($value);
    $num = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
}

$config = [
    'csrf'        => $csrf,
    'today'       => $today,
    'initialDate' => $initialDate,
    'limits'      => [
        'titleMax'       => Config::int('TITLE_MAX'),
        'bodyTextMax'    => Config::int('BODY_TEXT_MAX'),
        'inlineImageMax' => Config::int('INLINE_IMAGE_MAX'),
        'bodyLinkMax'    => Config::int('BODY_LINK_MAX'),
        'linkUrlMax'     => Config::int('LINK_URL_MAX'),
        'tableRowsMax'   => Config::int('TABLE_ROWS_MAX'),
        'tableColsMax'   => Config::int('TABLE_COLS_MAX'),
        'tableCellMax'   => Config::int('TABLE_CELL_MAX'),
        'imageMaxBytes'  => Config::int('IMAGE_MAX_BYTES'),
        'attachMaxCount' => Config::int('ATTACH_MAX_COUNT'),
        'attachMaxBytes' => Config::int('ATTACH_MAX_BYTES'),
        'imageExts'      => array_values((array) Config::get('IMAGE_EXTENSIONS')),
        'attachExts'     => array_values((array) Config::get('ATTACH_EXTENSIONS')),
        'blockedExts'    => array_values((array) Config::get('BLOCKED_EXTENSIONS')),
        'postMaxBytes'   => iniBytes((string) ini_get('post_max_size')),
    ],
];
$imageAccept = implode(',', array_map(static fn ($e) => '.' . $e, (array) Config::get('IMAGE_EXTENSIONS')));
$attachAccept = implode(',', array_map(static fn ($e) => '.' . $e, (array) Config::get('ATTACH_EXTENSIONS')));
$L = $config['limits'];

Html::sendHeaders(true);
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Html::e($appName) ?></title>
<?= Layout::favicon() ?>
<link rel="stylesheet" href="<?= Layout::asset('assets/app.css') ?>">
<link rel="stylesheet" href="<?= Layout::asset('assets/content.css') ?>">
<link rel="stylesheet" href="<?= Layout::asset('assets/editor.css') ?>">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <?= Layout::brand() ?>
        <nav class="tabs" role="tablist" aria-label="메뉴">
            <button type="button" class="tab" role="tab" id="tab-view" data-tab="view" aria-controls="panel-view">찌라시 보기</button>
            <button type="button" class="tab" role="tab" id="tab-write" data-tab="write" aria-controls="panel-write">찌라시 등록</button>
            <button type="button" class="tab" role="tab" id="tab-admin" data-tab="admin" aria-controls="panel-admin">관리</button>
        </nav>
        <?= Layout::logoutForm() ?>
    </div>
</header>

<main class="container">
    <!-- ── 찌라시 보기 ─────────────────────────────── -->
    <section class="panel" id="panel-view" role="tabpanel" aria-labelledby="tab-view">
        <div id="view-list">
            <div class="toolbar">
                <div class="date-nav">
                    <button type="button" class="zz-btn zz-btn-icon" id="date-prev" aria-label="전날">◀</button>
                    <input type="date" id="view-date" value="<?= Html::e($initialDate) ?>" max="<?= Html::e($today) ?>" aria-label="날짜 선택">
                    <button type="button" class="zz-btn zz-btn-icon" id="date-next" aria-label="다음 날">▶</button>
                    <button type="button" class="zz-btn" id="date-today">오늘</button>
                </div>
                <form class="search" id="search-form" role="search">
                    <input type="search" id="search-q" placeholder="제목·본문 검색" maxlength="100" aria-label="검색어">
                    <label class="check"><input type="checkbox" id="search-all"> 전체 기간</label>
                    <button type="submit" class="zz-btn">검색</button>
                </form>
                <div class="filters" aria-label="필터">
                    <label class="check"><input type="checkbox" id="filter-image"> 이미지</label>
                    <label class="check"><input type="checkbox" id="filter-table"> 표</label>
                    <label class="check"><input type="checkbox" id="filter-link"> 링크</label>
                    <label class="check"><input type="checkbox" id="filter-attachment"> 첨부</label>
                </div>
            </div>
            <section class="pinned-box" id="pinned-box" aria-labelledby="pinned-title" hidden>
                <h2 id="pinned-title">📌 고정된 찌라시</h2>
                <ul class="post-list" id="pinned-list"></ul>
            </section>
            <p class="list-caption" id="list-caption" aria-live="polite"></p>
            <ul class="post-list" id="post-list"></ul>
        </div>

        <div id="view-detail" hidden>
            <div class="detail-actions">
                <button type="button" class="zz-btn" id="detail-back">← 목록으로</button>
                <a class="zz-btn" id="detail-permalink" href="#" target="_blank" rel="noopener">새 창으로 보기</a>
            </div>
            <nav class="detail-nav" id="detail-nav-top" aria-label="이전·다음 찌라시"></nav>
            <article class="post-detail" id="detail-body"></article>
            <nav class="detail-nav detail-nav-bottom" id="detail-nav-bottom" aria-label="이전·다음 찌라시"></nav>
        </div>
    </section>

    <!-- ── 찌라시 등록 ─────────────────────────────── -->
    <section class="panel" id="panel-write" role="tabpanel" aria-labelledby="tab-write" hidden>
        <div class="notice">
            이름·이메일·IP 등 등록자를 알 수 있는 정보는 저장되지 않습니다. 등록 즉시 공개되며, 본문 이미지와 첨부파일은 등록 <?= Html::e(Retention::fileRetentionText()) ?> 후 삭제됩니다.
        </div>
        <form id="write-form" class="write-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrf) ?>">
            <!-- 허니팟: 사람에게는 보이지 않는 칸 -->
            <div class="hp" aria-hidden="true">
                <label>웹사이트 <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="field">
                <label for="w-title">제목 <span class="req">*</span></label>
                <input type="text" id="w-title" name="title" maxlength="<?= (int) $L['titleMax'] ?>" required>
                <div class="counter" data-for="w-title"></div>
            </div>

            <div class="field">
                <span class="label" id="w-body-label">본문 <span class="req">*</span>
                    <small>(글 <?= number_format($L['bodyTextMax']) ?>자 · 이미지 <?= (int) $L['inlineImageMax'] ?>장 · 링크 <?= (int) $L['bodyLinkMax'] ?>개 · 표 <?= (int) $L['tableRowsMax'] ?>행×<?= (int) $L['tableColsMax'] ?>열까지)</small>
                </span>
                <div class="zz-editor" id="editor">
                    <div class="zz-toolbar" role="toolbar" aria-label="본문 서식" aria-controls="editor-area">
                        <div class="zz-tool-group">
                            <button type="button" data-cmd="bold" aria-label="굵게 (Ctrl+B)" title="굵게 (Ctrl+B)"><b>B</b></button>
                            <button type="button" data-cmd="italic" aria-label="기울임 (Ctrl+I)" title="기울임 (Ctrl+I)"><i>I</i></button>
                            <button type="button" data-cmd="underline" aria-label="밑줄 (Ctrl+U)" title="밑줄 (Ctrl+U)"><u>U</u></button>
                        </div>
                        <div class="zz-tool-group">
                            <button type="button" data-cmd="h2" aria-label="제목(중)" title="제목(중)">제목</button>
                            <button type="button" data-cmd="h3" aria-label="제목(소)" title="제목(소)">소제목</button>
                        </div>
                        <div class="zz-tool-group">
                            <button type="button" data-cmd="ul" aria-label="글머리 목록" title="글머리 목록">• 목록</button>
                            <button type="button" data-cmd="ol" aria-label="번호 목록" title="번호 목록">1. 목록</button>
                            <button type="button" data-cmd="blockquote" aria-label="인용" title="인용">❝ 인용</button>
                            <button type="button" data-cmd="hr" aria-label="구분선" title="구분선">― 구분선</button>
                        </div>
                        <div class="zz-tool-group">
                            <button type="button" data-cmd="link" aria-label="링크 삽입 (Ctrl+K)" title="링크 삽입 (Ctrl+K)">링크</button>
                            <button type="button" data-cmd="unlink" aria-label="링크 해제" title="링크 해제">링크 해제</button>
                            <button type="button" data-cmd="image" aria-label="이미지 삽입" title="이미지 삽입(파일 선택·붙여넣기·끌어놓기)">이미지</button>
                            <button type="button" data-cmd="table" aria-label="표 삽입" title="표 삽입">표</button>
                        </div>
                        <div class="zz-tool-group zz-table-tools" aria-label="표 편집">
                            <button type="button" data-cmd="row-add" aria-label="행 추가" title="행 추가(아래)" disabled>행+</button>
                            <button type="button" data-cmd="row-del" aria-label="행 삭제" title="현재 행 삭제" disabled>행−</button>
                            <button type="button" data-cmd="col-add" aria-label="열 추가" title="열 추가(오른쪽)" disabled>열+</button>
                            <button type="button" data-cmd="col-del" aria-label="열 삭제" title="현재 열 삭제" disabled>열−</button>
                            <button type="button" data-cmd="table-del" aria-label="표 삭제" title="표 삭제" disabled>표 삭제</button>
                        </div>
                        <div class="zz-tool-group">
                            <button type="button" data-cmd="clear" aria-label="서식 지우기" title="서식 지우기">서식 지우기</button>
                            <button type="button" data-cmd="undo" aria-label="실행 취소 (Ctrl+Z)" title="실행 취소 (Ctrl+Z)">↶</button>
                            <button type="button" data-cmd="redo" aria-label="다시 실행 (Ctrl+Y)" title="다시 실행 (Ctrl+Y)">↷</button>
                        </div>
                    </div>
                    <div class="zz-content zz-editor-area" id="editor-area" contenteditable="true" role="textbox"
                         aria-multiline="true" aria-labelledby="w-body-label" data-placeholder="내용을 입력하세요. 이미지는 붙여넣거나 끌어다 놓아도 됩니다."></div>
                    <div class="zz-editor-status">
                        <span id="editor-upload" class="zz-upload-status" aria-live="polite"></span>
                        <span id="editor-count" class="zz-count"></span>
                    </div>
                    <input type="file" id="editor-image-input" accept="<?= Html::e($imageAccept) ?>" multiple hidden>
                </div>
                <div class="zz-editor-notice" id="editor-notice" role="status" aria-live="polite"></div>
            </div>

            <div class="field">
                <span class="label">첨부파일 <small>(<?= Html::e(implode(', ', $L['attachExts'])) ?> · 최대 <?= (int) $L['attachMaxCount'] ?>개 · 파일당 <?= Html::e(Upload::formatBytes($L['attachMaxBytes'])) ?>)</small></span>
                <label class="zz-btn zz-btn-file">첨부파일 추가<input type="file" id="w-attachments" accept="<?= Html::e($attachAccept) ?>" multiple></label>
                <ul class="file-list" id="attachment-list"></ul>
            </div>

            <p class="no-edit-notice" role="note">한 번 등록된 찌라시는 수정할 수 없습니다. 등록 전에 미리보기로 내용을 확인하세요. 잘못 올린 글은 관리자에게 삭제를 요청한 뒤 다시 등록하세요.</p>
            <div class="form-message" id="write-message" role="status" aria-live="polite"></div>
            <div class="form-actions">
                <button type="button" class="zz-btn" id="write-preview">미리보기</button>
                <button type="submit" class="zz-btn zz-btn-primary" id="write-submit">등록</button>
            </div>
        </form>

        <section class="preview-panel" id="preview-panel" hidden aria-labelledby="preview-title">
            <div class="preview-head">
                <h2 id="preview-title">미리보기 <small>(서버에서 정화한 결과 · 저장되지 않음)</small></h2>
                <button type="button" class="zz-btn zz-btn-sm" id="preview-close">닫기</button>
            </div>
            <ul class="preview-errors" id="preview-errors"></ul>
            <div class="zz-content" id="preview-body"></div>
        </section>
    </section>

    <!-- ── 관리 ───────────────────────────────────── -->
    <section class="panel" id="panel-admin" role="tabpanel" aria-labelledby="tab-admin" hidden>
        <!-- 관리 화면: 관리 탭을 누르면 비밀번호 창(#password-modal)으로 인증한 뒤에만 열린다 -->
        <div class="admin-panel" id="admin-panel" hidden>
            <div class="admin-lock-row">
                <span class="hint">관리자 인증됨 · 새로고침하거나 새 탭·새 창에서 열면 다시 비밀번호를 입력해야 합니다.</span>
                <span class="admin-session">
                    <span id="admin-remaining" title="관리 작업을 할 때마다 1시간으로 다시 늘어납니다"></span>
                    <button type="button" class="zz-btn zz-btn-sm" id="admin-logout">관리 잠그기</button>
                </span>
            </div>
            <div class="admin-bar">
                <nav class="admin-nav" aria-label="관리 메뉴">
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="posts">찌라시 목록</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="deleted">삭제된 찌라시</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="members">멤버</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="users">사용자 계정</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="mail">메일 발송</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="history">발송 이력</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="mailsettings">메일 설정</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="audit">감사 로그</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="scrap">스크랩</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="cleanup">정리</button>
                    <button type="button" class="zz-btn zz-btn-sm" data-admin-view="guide">관리자 가이드</button>
                </nav>
            </div>
            <div class="zz-banner warn" id="admin-pass-banner" role="note" hidden>관리자 비밀번호가 초기 상태(날짜)입니다. '사용자 계정'에서 변경하세요.</div>
            <p class="form-message" id="admin-message" role="status" aria-live="polite"></p>
            <div id="admin-view"></div>
        </div>
    </section>
</main>

<!-- 링크 입력 창 -->
<dialog class="zz-dialog" id="link-dialog" aria-labelledby="link-dialog-title">
    <form method="dialog" id="link-form" novalidate>
        <h2 id="link-dialog-title">링크 삽입</h2>
        <label>주소(URL) <input type="url" id="link-url" placeholder="https://" maxlength="<?= (int) $L['linkUrlMax'] ?>" autocomplete="off"></label>
        <label>표시할 글자 <input type="text" id="link-text" placeholder="비워 두면 주소를 표시" maxlength="200" autocomplete="off"></label>
        <p class="zz-dialog-error" id="link-error" role="alert"></p>
        <div class="zz-dialog-actions">
            <button type="button" class="zz-btn" data-close>취소</button>
            <button type="submit" class="zz-btn zz-btn-primary">삽입</button>
        </div>
    </form>
</dialog>

<!-- 표 삽입 창 -->
<dialog class="zz-dialog" id="table-dialog" aria-labelledby="table-dialog-title">
    <form method="dialog" id="table-form" novalidate>
        <h2 id="table-dialog-title">표 삽입</h2>
        <div class="zz-dialog-row">
            <label>행 <input type="number" id="table-rows" min="1" max="<?= (int) $L['tableRowsMax'] ?>" value="3"></label>
            <label>열 <input type="number" id="table-cols" min="1" max="<?= (int) $L['tableColsMax'] ?>" value="3"></label>
        </div>
        <label class="check"><input type="checkbox" id="table-header" checked> 첫 행을 제목 행으로</label>
        <p class="zz-dialog-hint">최대 <?= (int) $L['tableRowsMax'] ?>행 × <?= (int) $L['tableColsMax'] ?>열, 칸마다 <?= (int) $L['tableCellMax'] ?>자까지</p>
        <p class="zz-dialog-error" id="table-error" role="alert"></p>
        <div class="zz-dialog-actions">
            <button type="button" class="zz-btn" data-close>취소</button>
            <button type="submit" class="zz-btn zz-btn-primary">삽입</button>
        </div>
    </form>
</dialog>

<!-- 관리자 인증 창(loc_mgmt 와 같은 방식). 비밀번호 칸은 창을 열 때 만들고 닫을 때 없앤다(admin.js) -->
<div id="password-modal" class="modal-overlay" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="password-title">
        <h3 id="password-title">관리자 인증</h3>
        <p>관리자 비밀번호를 입력하세요</p>
        <div id="password-slot"></div>
        <p class="field-error" id="password-error" role="alert" hidden></p>
        <div class="modal-actions">
            <button type="button" class="zz-btn" id="password-cancel-btn">취소</button>
            <button type="button" class="zz-btn" id="password-submit-btn">확인</button>
        </div>
    </div>
</div>

<!-- 확인 창(삭제 등) -->
<dialog class="zz-dialog" id="confirm-dialog" aria-labelledby="confirm-title">
    <form method="dialog">
        <h2 id="confirm-title">확인</h2>
        <p id="confirm-message"></p>
        <p class="zz-dialog-hint" id="confirm-hint"></p>
        <div class="zz-dialog-actions">
            <button type="button" class="zz-btn" data-confirm="no">취소</button>
            <button type="button" class="zz-btn" data-confirm="yes" id="confirm-yes">확인</button>
        </div>
    </form>
</dialog>

<script type="application/json" id="app-config"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?= Layout::footer() ?>
<script src="<?= Layout::asset('assets/pageguard.js') ?>"></script>
<script src="<?= Layout::asset('assets/editor.js') ?>"></script>
<script src="<?= Layout::asset('assets/comments.js') ?>"></script>
<script src="<?= Layout::asset('assets/app.js') ?>"></script>
<script src="<?= Layout::asset('assets/admin.js') ?>"></script>
<div id="zz-toast" aria-live="polite"></div>
</body>
</html>
