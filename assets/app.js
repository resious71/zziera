/*
 * ZZieRa 메인 화면 스크립트 (Vanilla JS, 외부 라이브러리 없음)
 *
 * 사용자 입력·서버 데이터는 textContent 로만 화면에 넣는다.
 * 예외: 본문(post.html, 미리보기 html)은 서버가 허용 목록으로 정화·렌더링한 HTML 이라 innerHTML 로 넣는다.
 */
(function () {
    'use strict';

    var CONFIG = JSON.parse(document.getElementById('app-config').textContent);
    var L = CONFIG.limits;

    // ── 공통 유틸 ─────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    /** 요소 생성: el('a', {href: ..., className: ...}, [자식 | 문자열]) */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (key) {
                var v = attrs[key];
                if (v === null || v === undefined || v === false) return;
                if (key === 'className') node.className = v;
                else if (key === 'text') node.textContent = v;
                else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2), v);
                else node.setAttribute(key, v === true ? '' : v);
            });
        }
        (children || []).forEach(function (c) {
            if (c === null || c === undefined) return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function formatBytes(bytes) {
        if (bytes >= 1048576) return (Math.round(bytes / 104857.6) / 10) + 'MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + 'KB';
        return bytes + 'B';
    }

    function extOf(name) {
        var i = name.lastIndexOf('.');
        return i < 0 ? '' : name.slice(i + 1).toLowerCase();
    }

    /** 'YYYY-MM-DD' 날짜에 일 수를 더한다 */
    function shiftDate(dateStr, days) {
        var p = dateStr.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]) + days);
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    /** fetch 후 JSON 파싱. 서버가 JSON 이 아닌 오류 페이지를 줘도 메시지를 만든다 */
    function requestJson(url, options) {
        return fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}))
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) { /* JSON 아님 */ }
                    if (!data) {
                        var msg = res.status === 413
                            ? '첨부한 파일 전체 용량이 서버 허용치를 넘었습니다.'
                            : '서버 응답을 처리할 수 없습니다(HTTP ' + res.status + ').';
                        data = { ok: false, error: msg };
                    }
                    data.httpStatus = res.status;
                    // 사이트 로그인이 끝났으면(게이트 401) 로그인 화면으로 보낸다
                    if (res.status === 401 && data.error === 'login_required') {
                        data.loginRequired = true;
                        data.error = data.message || '로그인이 필요합니다.';
                        loginRequired();
                    }
                    return data;
                });
            })
            .catch(function () {
                return { ok: false, error: '서버에 연결할 수 없습니다. 네트워크 상태를 확인하세요.' };
            });
    }

    /** 확인 창(#confirm-dialog). Promise<boolean> */
    function askDialog(text, hint, yesLabel, noLabel) {
        var d = $('confirm-dialog');
        if (!d || !d.showModal) return Promise.resolve(true);
        $('confirm-message').textContent = text;
        $('confirm-hint').textContent = hint || '';
        $('confirm-yes').textContent = yesLabel || '확인';
        var no = d.querySelector('[data-confirm="no"]');
        var noText = no.textContent;
        no.textContent = noLabel || '취소';
        d.showModal();
        $('confirm-yes').focus();
        return new Promise(function (resolve) {
            function done(v) {
                d.removeEventListener('click', onClick);
                d.removeEventListener('cancel', onCancel);
                no.textContent = noText;
                d.close();
                resolve(v);
            }
            function onClick(e) { var b = e.target.closest('[data-confirm]'); if (b) done(b.getAttribute('data-confirm') === 'yes'); }
            function onCancel(e) { e.preventDefault(); done(false); }
            d.addEventListener('click', onClick);
            d.addEventListener('cancel', onCancel);
        });
    }

    /**
     * 로그인 만료(5b): 로그인 화면으로 이동한다. 작성 중인 글이 있으면 먼저 확인 창을 띄운다
     * ("머무르기"를 고르면 이동하지 않으므로 내용을 복사할 수 있다. 다음 요청에서 다시 묻는다).
     */
    var loginPrompting = false;
    function loginRequired() {
        if (loginPrompting) return;
        loginPrompting = true;
        var page = location.pathname.split('/').pop() || 'index.php';
        var url = 'login.php?expired=1&next=' + encodeURIComponent(page + location.search);
        var dirty = window.ZZ && window.ZZ.isWriteDirty && window.ZZ.isWriteDirty();
        if (!dirty) { location.replace(url); return; }
        askDialog('로그인이 만료되었습니다.', '작성 중인 내용은 로그인 후 사라집니다. 먼저 복사해 두세요.', '로그인 화면으로', '머무르기(복사하기)').then(function (yes) {
            loginPrompting = false;
            if (yes) location.href = url;
        });
    }

    // 관리 화면(admin.js)과 공유하는 도우미
    window.ZZ = { CONFIG: CONFIG, el: el, requestJson: requestJson, formatBytes: formatBytes, loginRequired: loginRequired };

    // ── 탭 ────────────────────────────────────────────────
    var TABS = ['view', 'write', 'admin'];

    function showTab(name) {
        if (TABS.indexOf(name) < 0) name = 'view';
        TABS.forEach(function (t) {
            var selected = t === name;
            $('tab-' + t).setAttribute('aria-selected', selected ? 'true' : 'false');
            $('tab-' + t).tabIndex = selected ? 0 : -1;
            $('panel-' + t).hidden = !selected;
        });
        document.dispatchEvent(new CustomEvent('zz:tab', { detail: name }));
    }

    function tabFromHash() {
        return (location.hash || '#view').slice(1);
    }

    /** 탭으로 바로 이동(주소의 #탭 이름도 맞춘다) */
    function goTab(name) {
        if (TABS.indexOf(name) < 0) name = 'view';
        if (tabFromHash() !== name) history.replaceState(null, '', '#' + name);
        window.ZZ.currentTab = name;
        showTab(name);
    }

    /**
     * 탭 이동 요청. 관리 탭은 admin.js 의 guardTab 이 먼저 인증(비밀번호 창)을 받고,
     * 성공하면 goTab 을 부른다. 취소하면 지금 탭에 그대로 있는다.
     */
    function navigate(name) {
        if (TABS.indexOf(name) < 0) name = 'view';
        var guard = window.ZZ.guardTab;
        if (guard && guard(name, function () { goTab(name); })) {
            if (tabFromHash() !== window.ZZ.currentTab) history.replaceState(null, '', '#' + window.ZZ.currentTab);
            return;
        }
        goTab(name);
    }
    window.ZZ.goTab = goTab;
    window.ZZ.navigate = navigate;

    document.querySelectorAll('.tab').forEach(function (btn) {
        btn.addEventListener('click', function () { navigate(btn.getAttribute('data-tab')); });
    });
    window.addEventListener('hashchange', function () { navigate(tabFromHash()); });
    // #admin 으로 열면 우선 조회 탭을 보이고, admin.js 가 비밀번호 창을 띄운다(인증은 화면마다 새로 받는다)
    if (tabFromHash() === 'admin') {
        window.ZZ.pendingTab = 'admin';
        goTab('view');
    } else {
        goTab(tabFromHash());
    }

    // ── 찌라시 보기 ───────────────────────────────────────
    var viewDate = $('view-date');
    var listRequestNo = 0;
    var navItems = [];  // 상세 화면 이전/다음 이동 순서: 지금 보이는 목록(고정된 찌라시 → 목록) 그대로
    var FILTERS = ['image', 'table', 'link', 'attachment'];

    function currentFilters() {
        var f = { q: $('search-q').value.trim(), all: $('search-all').checked };
        FILTERS.forEach(function (k) { f[k] = $('filter-' + k).checked; });
        return f;
    }

    function updateDateButtons() {
        $('date-next').disabled = viewDate.value >= CONFIG.today;
    }

    function loadList() {
        var date = viewDate.value || CONFIG.today;
        var f = currentFilters();
        var params = new URLSearchParams({ date: date });
        if (f.q) params.set('q', f.q);
        if (f.q && f.all) params.set('all', '1');
        FILTERS.forEach(function (k) { if (f[k]) params.set(k, '1'); });

        updateDateButtons();
        showList();
        var no = ++listRequestNo;
        $('list-caption').textContent = '불러오는 중…';
        requestJson('api/post_list.php?' + params.toString()).then(function (data) {
            if (no !== listRequestNo) return; // 늦게 도착한 이전 요청은 무시
            renderList(data, f, date);
        });
    }

    function renderList(data, f, date) {
        var list = $('post-list');
        list.textContent = '';
        renderPinned(data.ok ? data.pinned : null);
        navItems = data.ok ? (data.pinned || []).concat(data.items) : [];
        if (!data.ok) {
            $('list-caption').textContent = data.error;
            return;
        }
        var scope = (f.q && f.all) ? '전체 기간' : date;
        var caption = scope + ' · ' + data.items.length + '건';
        if (f.q) caption += ' · "' + f.q + '" 검색 결과';
        $('list-caption').textContent = caption;

        if (data.items.length === 0) {
            var filtered = f.q || FILTERS.some(function (k) { return f[k]; });
            list.appendChild(el('li', { className: 'empty', text: filtered ? '조건에 맞는 찌라시가 없습니다.' : '이 날짜에 등록된 찌라시가 없습니다.' }));
            return;
        }
        data.items.forEach(function (item) {
            list.appendChild(renderListItem(item, f.q && f.all));
        });
    }

    /** 관리자가 고정한 글: 목록 위 별도 영역(날짜·검색과 무관하게 항상, 있을 때만 표시) */
    function renderPinned(items) {
        var box = $('pinned-box'), ul = $('pinned-list');
        ul.textContent = '';
        box.hidden = !items || items.length === 0;
        (items || []).forEach(function (item) { ul.appendChild(renderListItem(item, true)); });
    }

    function renderListItem(item, showDate) {
        var badges = [];
        if (item.has_image) badges.push(el('span', { className: 'badge', text: '이미지' }));
        if (item.has_table) badges.push(el('span', { className: 'badge', text: '표' }));
        if (item.has_link) badges.push(el('span', { className: 'badge', text: '링크' }));
        if (item.attachment_count > 0) badges.push(el('span', { className: 'badge', title: '첨부 ' + item.attachment_count + '개', text: '첨부 ' + item.attachment_count }));

        var time = showDate ? item.created_at.slice(0, 16) : item.created_at.slice(11, 16);
        var thumb = item.thumb_id
            ? el('img', { className: 'post-thumb', src: 'api/file.php?type=image&id=' + item.thumb_id, alt: '', loading: 'lazy' })
            : null;
        var summary = item.summary
            ? el('p', { className: 'post-summary', text: item.summary })
            : el('p', { className: 'post-summary muted', text: '(본문은 링크에서 확인)' });

        var li = el('li', { className: 'post-item', tabindex: '0', role: 'link', 'aria-label': item.title }, [
            thumb,
            el('div', { className: 'post-main' }, [
                el('h3', { className: 'post-title' }, [item.source === 'scrap' ? el('span', { className: 'badge badge-scrap', text: '스크랩' }) : null, item.title]),
                el('div', { className: 'post-meta' }, [el('span', { text: time })].concat(badges, [
                    el('span', { className: 'post-social-count', title: '댓글 ' + (item.comment_count || 0) + '개 · 추천 ' + (item.vote_count || 0), text: '💬 ' + (item.comment_count || 0) + '  👍 ' + (item.vote_count || 0) })
                ])),
                summary
            ])
        ]);
        li.addEventListener('click', function () { openDetail(item.id); });
        li.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(item.id); }
        });
        return li;
    }

    var listScrollY = 0;

    function showList() {
        $('view-detail').hidden = true;
        $('view-list').hidden = false;
    }

    function openDetail(id) {
        if (!$('view-list').hidden) listScrollY = window.scrollY; // 상세에서 이전/다음으로 옮길 때는 목록 위치를 그대로 둔다
        $('view-list').hidden = true;
        $('view-detail').hidden = false;
        $('detail-permalink').href = 'post.php?id=' + encodeURIComponent(id);
        renderDetailNav(id);
        var body = $('detail-body');
        body.textContent = '';
        body.appendChild(el('p', { className: 'muted', text: '불러오는 중…' }));
        window.scrollTo(0, 0);

        requestJson('api/post_detail.php?id=' + encodeURIComponent(id)).then(function (data) {
            body.textContent = '';
            if (!data.ok) {
                body.appendChild(el('p', { text: data.error }));
                return;
            }
            renderDetail(body, data.post);
        });
    }

    /** 상세 화면 위·아래의 "← 이전 글 / 다음 글 →"(목록에서 바로 위·아래 글). 목록 끝이면 버튼을 끈다 */
    function renderDetailNav(id) {
        var idx = -1;
        navItems.forEach(function (it, i) { if (idx < 0 && it.id === id) idx = i; });
        var prev = idx > 0 ? navItems[idx - 1] : null;
        var next = idx >= 0 && idx < navItems.length - 1 ? navItems[idx + 1] : null;
        ['detail-nav-top', 'detail-nav-bottom'].forEach(function (navId) {
            var nav = $(navId);
            nav.hidden = idx < 0; // 목록에 없는 글(다른 날짜로 바뀐 경우 등)은 숨긴다
            if (idx < 0) { nav.textContent = ''; return; }
            nav.replaceChildren(
                navButton(prev, '← 이전 글', '목록의 첫 찌라시입니다'),
                el('span', { className: 'detail-nav-pos', text: (idx + 1) + ' / ' + navItems.length }),
                navButton(next, '다음 글 →', '목록의 마지막 찌라시입니다')
            );
        });
    }

    function navButton(item, label, endText) {
        // 버튼에는 화살표와 "이전 글/다음 글"만 보인다(제목은 화면 읽기 프로그램용 aria-label 에만)
        var b = el('button', { type: 'button', className: 'zz-btn detail-nav-btn', disabled: !item, text: label,
            title: item ? null : endText, 'aria-label': item ? label.replace(/[←→]/g, '').trim() + ': ' + item.title : endText });
        if (item) b.addEventListener('click', function () { openDetail(item.id); });
        return b;
    }

    function renderDetail(root, post) {
        root.appendChild(el('h2', { className: 'detail-title' }, [post.source === 'scrap' ? el('span', { className: 'badge badge-scrap', text: '스크랩' }) : null, post.title]));
        root.appendChild(el('p', { className: 'detail-meta', text: '등록 ' + post.created_at }));
        var content = el('div', { className: 'zz-content' });
        content.innerHTML = post.html; // 서버(ContentRenderer)가 정화한 HTML
        root.appendChild(content);

        if (post.attachments.length) {
            var ul = el('ul', { className: 'attach-list' });
            post.attachments.forEach(function (att) {
                ul.appendChild(att.available
                    ? el('li', null, [
                        el('a', { href: 'api/file.php?type=attachment&id=' + att.id, text: att.original_name }),
                        ' ', el('span', { className: 'muted', text: '(' + formatBytes(att.file_size) + ')' })
                    ])
                    : el('li', null, [
                        el('span', { className: 'muted', text: att.original_name }),
                        el('span', { className: 'expired-inline', text: '첨부가 만료되었습니다' })
                    ]));
            });
            root.appendChild(el('section', { className: 'detail-section' }, [el('h2', { text: '첨부파일' }), ul]));
        }
        // 추천·댓글(5d). 이 화면에서 관리자 인증을 했으면 관리자 토큰으로 삭제 버튼까지 보인다
        var social = el('section', { 'aria-label': '추천과 댓글' });
        root.appendChild(social);
        if (window.ZZComments) {
            window.ZZComments.mount(social, { postId: post.id, csrf: CONFIG.csrf, adminToken: function () { return window.ZZ.adminToken ? window.ZZ.adminToken() : null; } });
        }
    }

    $('detail-back').addEventListener('click', function () {
        showList();
        window.scrollTo(0, listScrollY);
    });
    viewDate.addEventListener('change', function () {
        if (viewDate.value > CONFIG.today) viewDate.value = CONFIG.today;
        loadList();
    });
    $('date-prev').addEventListener('click', function () { viewDate.value = shiftDate(viewDate.value || CONFIG.today, -1); loadList(); });
    $('date-next').addEventListener('click', function () {
        var next = shiftDate(viewDate.value || CONFIG.today, 1);
        if (next <= CONFIG.today) { viewDate.value = next; loadList(); }
    });
    $('date-today').addEventListener('click', function () { viewDate.value = CONFIG.today; loadList(); });
    $('search-form').addEventListener('submit', function (e) { e.preventDefault(); loadList(); });
    FILTERS.map(function (k) { return 'filter-' + k; }).concat(['search-all']).forEach(function (id) {
        $(id).addEventListener('change', loadList);
    });

    loadList();

    // ── 찌라시 등록 ───────────────────────────────────────
    var form = $('write-form');
    var message = $('write-message');
    var attachments = [];  // File 목록

    var editor = new window.ZZEditor({
        root: $('editor'),
        area: $('editor-area'),
        imageInput: $('editor-image-input'),
        uploadStatus: $('editor-upload'),
        countEl: $('editor-count'),
        noticeEl: $('editor-notice'),
        linkDialog: $('link-dialog'),
        tableDialog: $('table-dialog'),
        csrf: CONFIG.csrf,
        limits: L
    });

    /** 작성 중인 내용이 있는지(로그인 만료 시 이동 전에 확인) */
    window.ZZ.isWriteDirty = function () {
        var s = editor.stats();
        return $('w-title').value.trim() !== '' || s.hasText || s.imageCount > 0 || attachments.length > 0;
    };

    function setMessage(text, type, extra) {
        message.textContent = text;
        message.className = 'form-message' + (type ? ' ' + type : '');
        if (extra) message.appendChild(extra);
    }

    // 제목 글자 수 표시
    var titleInput = $('w-title');
    var titleCounter = document.querySelector('.counter[data-for="w-title"]');
    function updateTitleCounter() {
        var n = titleInput.value.length;
        titleCounter.textContent = n.toLocaleString() + ' / ' + L.titleMax.toLocaleString() + '자';
        titleCounter.classList.toggle('over', n > L.titleMax);
    }
    titleInput.addEventListener('input', updateTitleCounter);
    updateTitleCounter();

    // 첨부파일 선택
    function checkAttachment(file) {
        var name = file.name;
        var parts = name.toLowerCase().split('.');
        if (parts.length < 2) return '"' + name + '": 확장자가 없는 파일은 올릴 수 없습니다.';
        for (var i = 1; i < parts.length; i++) {
            if (L.blockedExts.indexOf(parts[i].trim()) >= 0) return '"' + name + '": 실행 파일이나 스크립트는 올릴 수 없습니다.';
        }
        if (L.attachExts.indexOf(extOf(name)) < 0) return '"' + name + '": 허용되지 않는 형식입니다(' + L.attachExts.join(', ') + ').';
        if (file.size > L.attachMaxBytes) return '"' + name + '": 파일이 너무 큽니다(최대 ' + formatBytes(L.attachMaxBytes) + ').';
        if (file.size === 0) return '"' + name + '": 빈 파일은 올릴 수 없습니다.';
        return null;
    }

    function renderAttachments() {
        var listEl = $('attachment-list');
        listEl.textContent = '';
        attachments.forEach(function (file, idx) {
            listEl.appendChild(el('li', null, [
                el('span', { className: 'name', text: file.name }),
                el('span', { className: 'muted', text: formatBytes(file.size) }),
                el('button', {
                    type: 'button', className: 'zz-btn zz-btn-sm', text: '삭제', 'aria-label': file.name + ' 삭제',
                    onclick: function () { attachments.splice(idx, 1); renderAttachments(); }
                })
            ]));
        });
    }

    $('w-attachments').addEventListener('change', function () {
        var input = this;
        var errors = [];
        Array.prototype.forEach.call(input.files, function (file) {
            if (attachments.length >= L.attachMaxCount) {
                errors.push('첨부파일은 최대 ' + L.attachMaxCount + '개까지 올릴 수 있습니다.');
                return;
            }
            var err = checkAttachment(file);
            if (err) errors.push(err); else attachments.push(file);
        });
        input.value = '';
        renderAttachments();
        setMessage(errors.filter(function (v, i, a) { return a.indexOf(v) === i; }).join('\n'), errors.length ? 'error' : '');
    });

    // 제출 전 클라이언트 검증(서버에서도 모두 다시 검증한다)
    function validateForm() {
        var title = titleInput.value.trim();
        if (!title) return { msg: '제목을 입력하세요.', focus: titleInput };
        if (title.length > L.titleMax) return { msg: '제목은 ' + L.titleMax + '자 이하로 입력하세요.', focus: titleInput };

        var s = editor.stats();
        if (s.uploading) return { msg: '이미지를 올리는 중입니다. 끝난 뒤 다시 눌러 주세요.' };
        if (!s.hasText && !s.imageCount && !s.tableCount) return { msg: '본문을 입력하세요.', focus: $('editor-area') };
        var errors = [];
        if (s.textLength > L.bodyTextMax) errors.push('본문 글자 수가 ' + s.textLength.toLocaleString() + '자입니다. ' + L.bodyTextMax.toLocaleString() + '자 이하로 줄여 주세요.');
        if (s.imageCount > L.inlineImageMax) errors.push('본문 이미지는 최대 ' + L.inlineImageMax + '장까지 넣을 수 있습니다.');
        if (s.linkCount > L.bodyLinkMax) errors.push('본문 링크는 최대 ' + L.bodyLinkMax + '개까지 넣을 수 있습니다.');
        if (s.cellOver) errors.push('표의 한 칸에 ' + L.tableCellMax + '자를 넘는 글이 있습니다.');
        if (errors.length) return { msg: errors.join('\n') };

        var total = attachments.reduce(function (sum, f) { return sum + f.size; }, 0);
        if (L.postMaxBytes > 0 && total > L.postMaxBytes - 1048576) {
            return { msg: '첨부파일 전체 용량(' + formatBytes(total) + ')이 서버 허용치(' + formatBytes(L.postMaxBytes) + ')에 가깝거나 넘습니다. 파일을 줄여 주세요.' };
        }
        return null;
    }

    function resetForm() {
        form.reset();
        attachments.length = 0;
        renderAttachments();
        editor.clear();
        updateTitleCounter();
        $('preview-panel').hidden = true;
    }

    var submitting = false;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submitting) return;

        var invalid = validateForm();
        if (invalid) {
            setMessage(invalid.msg, 'error');
            if (invalid.focus) invalid.focus.focus();
            return;
        }

        var fd = new FormData(form);
        fd.set('body_html', editor.getHTML());
        attachments.forEach(function (f) { fd.append('attachments[]', f, f.name); });

        submitting = true;
        $('write-submit').disabled = true;
        setMessage('등록하는 중…', '');

        requestJson('api/post_create.php', { method: 'POST', body: fd }).then(function (data) {
            submitting = false;
            $('write-submit').disabled = false;
            if (!data.ok) {
                // 작성 중이던 내용은 그대로 둔다
                setMessage(data.error || '등록에 실패했습니다.', 'error');
                return;
            }
            resetForm();
            var goView = el('button', {
                type: 'button', className: 'zz-btn', text: '조회 화면에서 보기',
                onclick: function () {
                    viewDate.value = data.date || CONFIG.today;
                    history.replaceState(null, '', '#view');
                    showTab('view');
                    loadList();
                }
            });
            setMessage(data.message || '찌라시가 등록되었습니다', 'ok', goView);
        });
    });

    // 미리보기: 서버 정화·렌더링 결과를 에디터와 같은 zz-content 로 보여 준다(저장하지 않음)
    $('write-preview').addEventListener('click', function () {
        var btn = this;
        if (editor.isUploading()) { setMessage('이미지를 올리는 중입니다. 끝난 뒤 다시 눌러 주세요.', 'error'); return; }
        var fd = new FormData();
        fd.append('csrf_token', CONFIG.csrf);
        fd.append('body_html', editor.getHTML());
        btn.disabled = true;
        requestJson('api/post_preview.php', { method: 'POST', body: fd }).then(function (data) {
            btn.disabled = false;
            var panel = $('preview-panel');
            var errorsEl = $('preview-errors');
            errorsEl.textContent = '';
            if (!data.ok) { setMessage(data.error || '미리보기를 만들 수 없습니다.', 'error'); return; }
            (data.errors || []).forEach(function (msg) { errorsEl.appendChild(el('li', { text: msg })); });
            $('preview-body').innerHTML = data.html; // 서버(ContentRenderer)가 정화한 HTML
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    $('preview-close').addEventListener('click', function () { $('preview-panel').hidden = true; });
})();
