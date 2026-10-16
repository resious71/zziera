/*
 * ZZieRa 관리 탭 (4단계)
 *
 * - 인증(loc_mgmt 와 같은 방식): "관리" 탭을 누르면 비밀번호 창을 띄우고, 인증에 성공해야 탭이 열린다.
 *   비밀번호는 관리자 비밀번호(초기 상태는 오늘 날짜, 6c부터 "사용자 계정"에서 변경 가능, 서버가 확인). 성공하면 받은 토큰을 이 화면의 메모리에만 들고
 *   X-Admin-Token 헤더로 보낸다 → 새로고침·새 탭·새 창에서는 다시 비밀번호를 입력해야 한다.
 *   비밀번호 칸은 창을 열 때 만들고 닫을 때 없앤다(브라우저가 로그인 화면으로 보고 다른 칸에 자동 입력하지 않게).
 * - 5회 실패 시 서버가 잠그고 남은 시간을 알려 준다(전체 공통).
 * - 유휴 만료: 마지막 작업 후 1시간. 작업 응답의 session_remaining 으로 카운트다운을 맞추고,
 *   1분마다 상태 API(연장하지 않음)로 확인한다. 만료되면 비밀번호 창을 다시 띄운다.
 * - 사용자 입력·서버 데이터는 textContent 로만 넣는다. 예외: 찌라시 본문(post.html)은 서버가 정화한 HTML.
 */
(function () {
    'use strict';

    var Z = window.ZZ;
    var el = Z.el;
    var CONFIG = Z.CONFIG;
    function $(id) { return document.getElementById(id); }

    var state = {
        authed: false,      // 이 화면에서 비밀번호를 넣었는지
        token: null,        // 인증 토큰: 메모리에만 둔다(새로고침하면 사라져 다시 인증)
        remaining: 0, view: 'posts', countdown: null, poll: null, lockTimer: null
    };
    var STATUS_LABELS = { active: '수신', suspended: '정지', unsubscribed: '구독 해지' };

    // 이 화면의 관리자 토큰(인증했을 때만). 상세의 댓글 삭제(comments.js)가 쓴다
    Z.adminToken = function () { return state.authed ? state.token : null; };

    // ── 공통 ──────────────────────────────────────────────
    function fmtClock(sec) {
        sec = Math.max(0, Math.floor(sec));
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + String(s).padStart(2, '0');
    }
    function fmtDuration(sec) {
        sec = Math.max(0, Math.floor(sec));
        var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
        if (d > 0) return d + '일 ' + h + '시간';
        if (h > 0) return h + '시간 ' + m + '분';
        if (m > 0) return m + '분';
        return sec + '초';
    }
    function message(text, type) {
        var m = $('admin-message');
        m.textContent = text || '';
        m.className = 'form-message' + (type ? ' ' + type : '');
    }

    /** 토큰 헤더를 붙여 요청 */
    function request(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({}, opts.headers || {}, state.token ? { 'X-Admin-Token': state.token } : {});
        return Z.requestJson(url, opts);
    }

    /** 관리 API 호출. 401 이면 다시 인증받게 하고, 성공하면 남은 시간 갱신 */
    function api(url, fields) {
        var opts;
        if (fields) {
            var fd = new FormData();
            fd.append('csrf_token', CONFIG.csrf);
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
            opts = { method: 'POST', body: fd };
        }
        return request(url, opts).then(function (data) {
            if (data.loginRequired) throw new Error('auth'); // 사이트 로그인 만료: app.js 가 로그인 화면으로 보낸다
            if (data.httpStatus === 401) {
                authLost(data.error || '관리자 인증 시간이 지났습니다. 비밀번호를 다시 입력하세요.');
                throw new Error('auth');
            }
            if (data.ok && typeof data.session_remaining === 'number') startCountdown(data.session_remaining);
            return data;
        });
    }
    function ignoreAuth(e) { if (!e || e.message !== 'auth') throw e; }

    /** 확인 창(삭제 등). Promise<boolean> */
    function confirmBox(text, hint, yesLabel) {
        var d = $('confirm-dialog');
        $('confirm-message').textContent = text;
        $('confirm-hint').textContent = hint || '';
        $('confirm-yes').textContent = yesLabel || '확인';
        d.showModal();
        $('confirm-yes').focus();
        return new Promise(function (resolve) {
            function done(v) {
                d.removeEventListener('click', onClick);
                d.removeEventListener('cancel', onCancel);
                d.close();
                resolve(v);
            }
            function onClick(e) {
                var b = e.target.closest('[data-confirm]');
                if (b) done(b.getAttribute('data-confirm') === 'yes');
            }
            function onCancel(e) { e.preventDefault(); done(false); }
            d.addEventListener('click', onClick);
            d.addEventListener('cancel', onCancel);
        });
    }

    function table(headers, rows, emptyText) {
        if (!rows.length) return el('p', { className: 'empty', text: emptyText });
        return el('div', { className: 'admin-table-wrap' }, [
            el('table', { className: 'admin-table' }, [
                el('thead', null, [el('tr', null, headers.map(function (h) { return el('th', { text: h }); }))]),
                el('tbody', null, rows)
            ])
        ]);
    }
    /** 관리 목록 날짜 칸: yy/mm/dd 로 짧게, 마우스를 올리면 전체 일시(PRD 2장) */
    function dateCell(dt) {
        dt = dt || '';
        var short = dt.length >= 10 ? dt.slice(2, 4) + '/' + dt.slice(5, 7) + '/' + dt.slice(8, 10) : '-';
        return el('td', { className: 'nowrap', text: short, title: dt });
    }
    function badge(text, kind) { return el('span', { className: 'badge badge-' + kind, text: text }); }
    function btn(text, onclick) {
        // 관리 화면의 모든 버튼은 같은 .zz-btn(위험 동작도 색을 바꾸지 않고 확인 창으로 구분)
        return el('button', { type: 'button', className: 'zz-btn zz-btn-sm', text: text, onclick: onclick });
    }
    /** 입력창 옆에 두는 버튼: 입력창과 같은 높이의 기본 크기 .zz-btn(작은 변형을 쓰지 않는다, 5c) */
    function ctlBtn(text, onclick) {
        return el('button', { type: 'button', className: 'zz-btn', text: text, onclick: onclick });
    }
    function pager(page, pages, go) {
        if (pages <= 1) return null;
        return el('div', { className: 'pager' }, [
            el('button', { type: 'button', className: 'zz-btn zz-btn-sm', text: '← 이전', disabled: page <= 1, onclick: function () { go(page - 1); } }),
            el('span', { text: page + ' / ' + pages + ' 쪽' }),
            el('button', { type: 'button', className: 'zz-btn zz-btn-sm', text: '다음 →', disabled: page >= pages, onclick: function () { go(page + 1); } })
        ]);
    }
    function icons(p) {
        var out = [];
        if (p.has_image) out.push(badge('이미지', 'info'));
        if (p.has_table) out.push(badge('표', 'info'));
        if (p.has_link) out.push(badge('링크', 'info'));
        if (p.attachment_count) out.push(badge('첨부 ' + p.attachment_count, 'info'));
        return out;
    }

    // ── 인증(비밀번호 창) ──────────────────────────────────
    var adminPending = null;     // 인증에 성공하면 부를 함수
    var adminCancel = null;      // 취소하면 부를 함수
    var adminReturnFocus = null;

    /**
     * 비밀번호 칸은 창을 열 때 새로 만들고 닫을 때 없앤다.
     * 페이지에 비밀번호 칸이 늘 있으면 브라우저가 로그인 화면으로 보고 다른 입력칸에 저장된 사용자 이름을 채우기 때문이다.
     */
    function mountPasswordInput() {
        var input = el('input', {
            type: 'password', id: 'password-input', name: 'zzira-admin-pass', autocomplete: 'new-password',
            'aria-label': '관리자 비밀번호', 'data-lpignore': 'true', 'data-1p-ignore': 'true', maxlength: '64'
        });
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); submitPassword(); }
        });
        $('password-slot').replaceChildren(input);
        return input;
    }

    /** 인증된 화면이면 바로, 아니면 비밀번호 창을 띄우고 성공하면 onOk 를 부른다 */
    function requestAdmin(onOk, onCancel, errorText) {
        if (state.authed) { onOk(); return; }
        adminPending = onOk;
        adminCancel = onCancel || null;
        adminReturnFocus = document.activeElement;
        showPasswordError(errorText || '');
        var input = mountPasswordInput();
        $('password-modal').hidden = false;
        input.focus();
        // 이미 잠겨 있으면 남은 시간을 바로 보여 준다
        Z.requestJson('api/admin_status.php').then(function (s) {
            if (s.ok && s.lock_remaining > 0 && !$('password-modal').hidden) startLockCountdown(s.lock_remaining);
        });
    }

    function closePasswordModal(cancelled) {
        $('password-modal').hidden = true;
        $('password-slot').replaceChildren();
        clearInterval(state.lockTimer);
        $('password-submit-btn').disabled = false;
        var cancel = adminCancel;
        adminPending = adminCancel = null;
        if (adminReturnFocus && adminReturnFocus.focus) adminReturnFocus.focus();
        if (cancelled && cancel) cancel();
    }

    function showPasswordError(text, ok) {
        var e = $('password-error');
        e.textContent = text || '';
        e.hidden = !text;
        e.classList.toggle('ok', !!ok);
    }

    function submitPassword() {
        var input = $('password-input');
        var btn = $('password-submit-btn');
        if (!input || btn.disabled) return;
        if (!input.value.trim()) {
            showPasswordError('관리자 비밀번호를 입력하세요.');
            input.value = '';
            input.focus();
            return;
        }
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', CONFIG.csrf);
        fd.append('password', input.value);
        Z.requestJson('api/admin_login.php', { method: 'POST', body: fd }).then(function (r) {
            btn.disabled = false;
            if (r.ok && r.token) {
                state.authed = true;
                state.token = r.token;
                var cb = adminPending;
                adminPending = adminCancel = null;
                $('password-modal').hidden = true;
                $('password-slot').replaceChildren();
                message('');
                setPassDefault(r.password_default);
                startCountdown(r.session_remaining);
                if (cb) cb();
                return;
            }
            var again = $('password-input');
            if (again) { again.value = ''; again.focus(); }
            if (r.lock_remaining > 0) startLockCountdown(r.lock_remaining);
            else showPasswordError(r.error || '인증하지 못했습니다.');
        });
    }

    /** 잠금 남은 시간 카운트다운(창 안에 표시, 확인 버튼 잠금) */
    function startLockCountdown(sec) {
        clearInterval(state.lockTimer);
        var btn = $('password-submit-btn');
        var left = sec;
        function tick() {
            if ($('password-modal').hidden) { clearInterval(state.lockTimer); return; }
            if (left <= 0) {
                clearInterval(state.lockTimer);
                btn.disabled = false;
                showPasswordError('잠금이 풀렸습니다. 다시 입력하세요.', true);
                var i = $('password-input');
                if (i) i.focus();
                return;
            }
            btn.disabled = true;
            showPasswordError('비밀번호를 여러 번 틀려 잠시 막혔습니다. ' + fmtDuration(left) + (left < 60 ? '' : ' ' + (left % 60) + '초') + ' 뒤에 다시 시도해 주세요.');
            left -= 1;
        }
        tick();
        state.lockTimer = setInterval(tick, 1000);
    }

    $('password-submit-btn').addEventListener('click', submitPassword);
    $('password-cancel-btn').addEventListener('click', function () { closePasswordModal(true); });
    $('password-modal').addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closePasswordModal(true); });
    $('password-modal').addEventListener('click', function (ev) { if (ev.target === $('password-modal')) closePasswordModal(true); });

    /** 인증이 끝났을 때(유휴 만료·서버가 401): 관리 화면을 닫고 비밀번호 창을 다시 띄운다. 취소하면 조회 탭으로 */
    function authLost(text) {
        state.authed = false;
        state.token = null;
        stopTimers();
        $('admin-panel').hidden = true;
        $('admin-view').textContent = '';
        if (Z.currentTab !== 'admin') return;
        if (!$('password-modal').hidden) { showPasswordError(text); return; }
        requestAdmin(function () { showPanel(); }, function () { Z.goTab('view'); }, text);
    }

    // ── 남은 시간 ─────────────────────────────────────────
    function startCountdown(remaining) {
        state.remaining = remaining;
        renderRemaining();
        if (!state.countdown) {
            state.countdown = setInterval(function () {
                state.remaining -= 1;
                renderRemaining();
                if (state.remaining <= 0) checkStatus();
            }, 1000);
        }
        if (!state.poll) state.poll = setInterval(checkStatus, 60000); // 상태 확인(연장하지 않음)
    }
    function stopTimers() {
        clearInterval(state.countdown); clearInterval(state.poll);
        state.countdown = state.poll = null;
    }
    function renderRemaining() {
        $('admin-remaining').textContent = '남은 시간 ' + fmtClock(state.remaining);
        $('admin-remaining').classList.toggle('warn', state.remaining <= 300);
    }

    function checkStatus() {
        if (!state.authed) return Promise.resolve();
        return request('api/admin_status.php').then(function (s) {
            if (!s.ok) return;
            if (s.logged_in) { setPassDefault(s.password_default); startCountdown(s.session_remaining); }
            else authLost(s.message || '관리자 인증 시간이 지났습니다. 비밀번호를 다시 입력하세요.');
        });
    }

    /** 관리자 비밀번호 초기 상태(날짜) 경고 배너(6c). 인증된 화면에서만 서버가 알려 준다 */
    function setPassDefault(on) {
        $('admin-pass-banner').hidden = !on;
    }

    function showPanel() {
        $('admin-panel').hidden = false;
        showView(state.view);
    }

    // 관리 잠그기: 이 화면의 토큰을 버리고 조회 탭으로
    $('admin-logout').addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf_token', CONFIG.csrf);
        request('api/admin_logout.php', { method: 'POST', body: fd }).catch(function () { /* 이미 끝난 세션이어도 화면은 잠근다 */ }).then(function () {
            state.authed = false;
            state.token = null;
            stopTimers();
            $('admin-panel').hidden = true;
            $('admin-view').textContent = '';
            Z.goTab('view');
        });
    });

    // ── 메뉴 ──────────────────────────────────────────────
    document.querySelectorAll('[data-admin-view]').forEach(function (b) {
        b.addEventListener('click', function () { message(''); showView(b.getAttribute('data-admin-view')); });
    });

    function showView(view, arg) {
        state.view = view;
        document.querySelectorAll('[data-admin-view]').forEach(function (b) {
            b.setAttribute('aria-current', b.getAttribute('data-admin-view') === view ? 'page' : 'false');
        });
        var root = $('admin-view');
        root.textContent = '';
        ({ posts: viewPosts, deleted: viewDeleted, detail: viewDetail, members: viewMembers, audit: viewAudit,
            mail: viewMail, history: viewHistory, mailsettings: viewMailSettings, users: viewUsers, guide: viewGuide, scrap: viewScrap, cleanup: viewCleanup })[view](root, arg);
    }

    // ── 찌라시 목록 ───────────────────────────────────────
    var postFilter = { from: '', to: '', q: '', status: '', page: 1 };

    function viewPosts(root) {
        var f = postFilter;
        var from = el('input', { type: 'date', value: f.from, 'aria-label': '시작일' });
        var to = el('input', { type: 'date', value: f.to, 'aria-label': '종료일' });
        var q = el('input', { type: 'search', value: f.q, placeholder: '제목 검색', maxlength: '100', 'aria-label': '제목 검색' });
        var status = el('select', { 'aria-label': '상태' }, [
            el('option', { value: '', text: '전체 상태' }), el('option', { value: 'active', text: '게시 중' }), el('option', { value: 'deleted', text: '삭제됨' })
        ]);
        status.value = f.status;
        var form = el('form', { className: 'admin-filter' }, [from, el('span', { text: '~' }), to, q, status,
            el('button', { type: 'submit', className: 'zz-btn', text: '조회' }),
            el('button', { type: 'button', className: 'zz-btn', text: '초기화', onclick: function () { postFilter = { from: '', to: '', q: '', status: '', page: 1 }; showView('posts'); } })]);
        var list = el('div', { text: '불러오는 중…', className: 'muted' });
        root.appendChild(el('p', { className: 'admin-note', text: '등록된 찌라시는 수정할 수 없고 삭제만 가능합니다. 삭제한 글은 3일 안에 "삭제된 찌라시"에서 복구할 수 있습니다.' }));
        root.appendChild(form);
        root.appendChild(list);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            postFilter = { from: from.value, to: to.value, q: q.value.trim(), status: status.value, page: 1 };
            load();
        });
        function load() {
            var p = new URLSearchParams({ page: postFilter.page });
            ['from', 'to', 'q', 'status'].forEach(function (k) { if (postFilter[k]) p.set(k, postFilter[k]); });
            api('api/admin_posts.php?' + p.toString()).then(function (data) {
                list.textContent = '';
                list.className = '';
                if (!data.ok) { list.appendChild(el('p', { className: 'form-message error', text: data.error })); return; }
                list.appendChild(el('p', { className: 'list-caption', text: '총 ' + data.total + '건 · 📌 고정 ' + data.pinned_count + '/' + data.pin_max }));
                list.appendChild(table(['등록 일시', '제목', '상태', '포함', '댓글', '추천', ''], data.items.map(function (p) {
                    return el('tr', null, [
                        dateCell(p.created_at),
                        el('td', null, [p.is_pinned ? el('span', { className: 'pin-mark', title: '상단 고정 중(' + (p.pinned_at || '') + ')', text: '📌 ' }) : null,
                            p.source === 'scrap' ? el('span', { className: 'badge badge-scrap', text: '스크랩' }) : null,
                            el('a', { href: '#admin', className: 'title-link', text: p.title, onclick: function (e) { e.preventDefault(); showView('detail', p.id); } })]),
                        el('td', null, [p.status === 'active' ? badge('게시 중', 'ok') : badge('삭제됨', 'danger')]),
                        el('td', null, icons(p)),
                        el('td', { className: 'num', text: String(p.comment_count || 0) }),
                        el('td', { className: 'num', text: String(p.vote_count || 0) }),
                        el('td', { className: 'actions' }, [
                            btn('보기', function () { showView('detail', p.id); }),
                            p.status === 'active' ? btn(p.is_pinned ? '고정 해제' : '고정', function () { pinPost(p, !p.is_pinned, load); }) : null,
                            p.status === 'active' ? btn('삭제', function () { deletePost(p, load); }) : null
                        ])
                    ]);
                }), '조건에 맞는 찌라시가 없습니다.'));
                var pg = pager(data.page, data.pages, function (n) { postFilter.page = n; load(); });
                if (pg) list.appendChild(pg);
            }).catch(ignoreAuth);
        }
        load();
    }

    /** 상단 고정/해제: 확인 없이 바로 처리하고 토스트로 알린다 */
    function pinPost(p, pinned, after) {
        api('api/admin_post_pin.php', { id: p.id, pinned: pinned ? '1' : '0' }).then(function (data) {
            toast(data.ok ? data.message + ' (고정 ' + data.pinned_count + '/' + data.pin_max + ')' : data.error, data.ok ? 'ok' : 'error');
            if (data.ok) after();
        }).catch(ignoreAuth);
    }

    function deletePost(p, after) {
        confirmBox('"' + p.title + '" 찌라시를 삭제할까요?', '즉시 조회와 메일 발송 대상에서 빠집니다. 3일 안에는 복구할 수 있습니다.', '삭제').then(function (yes) {
            if (!yes) return;
            api('api/admin_post_action.php', { action: 'delete', id: p.id }).then(function (data) {
                message(data.ok ? data.message : data.error, data.ok ? 'ok' : 'error');
                if (data.ok) after();
            }).catch(ignoreAuth);
        });
    }

    function restorePost(p, after) {
        confirmBox('"' + p.title + '" 찌라시를 복구할까요?', '이미 보관 기간이 지나 지워진 이미지·첨부파일은 돌아오지 않습니다. 댓글·추천은 그대로 돌아옵니다.', '복구').then(function (yes) {
            if (!yes) return;
            api('api/admin_post_action.php', { action: 'restore', id: p.id }).then(function (data) {
                message(data.ok ? data.message : data.error, data.ok ? 'ok' : 'error');
                if (data.ok) after();
            }).catch(ignoreAuth);
        });
    }

    // ── 삭제된 찌라시 ─────────────────────────────────────
    function viewDeleted(root) {
        var list = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(el('p', { className: 'admin-note', text: '삭제 후 3일 이내의 찌라시만 보입니다. 3일이 지나면 정리 배치가 완전히 지우며 복구할 수 없습니다.' }));
        root.appendChild(list);
        function load() {
            api('api/admin_posts.php?view=deleted').then(function (data) {
                list.textContent = '';
                list.className = '';
                if (!data.ok) { list.appendChild(el('p', { className: 'form-message error', text: data.error })); return; }
                list.appendChild(table(['삭제 일시', '제목', '복구 가능', ''], data.items.map(function (p) {
                    return el('tr', null, [
                        dateCell(p.deleted_at),
                        el('td', null, [el('a', { href: '#admin', className: 'title-link', text: p.title, onclick: function (e) { e.preventDefault(); showView('detail', p.id); } })]),
                        el('td', { className: 'nowrap', text: fmtDuration(p.restore_remaining) + ' 남음' }),
                        el('td', { className: 'actions' }, [btn('보기', function () { showView('detail', p.id); }), btn('복구', function () { restorePost(p, load); })])
                    ]);
                }), '복구할 수 있는 삭제 찌라시가 없습니다.'));
            }).catch(ignoreAuth);
        }
        load();
    }

    // ── 상세 ──────────────────────────────────────────────
    function viewDetail(root, id) {
        var back = state.lastListView || 'posts';
        root.appendChild(el('div', { className: 'detail-actions' }, [btn('← 목록으로', function () { showView(back); })]));
        var box = el('article', { className: 'post-detail' }, [el('p', { className: 'muted', text: '불러오는 중…' })]);
        root.appendChild(box);
        api('api/admin_post_detail.php?id=' + encodeURIComponent(id)).then(function (data) {
            box.textContent = '';
            if (!data.ok) { box.appendChild(el('p', { text: data.error })); return; }
            var p = data.post;
            var meta = '등록 ' + p.created_at + (p.status === 'deleted' ? ' · 삭제 ' + p.deleted_at + ' · 복구 가능 ' + fmtDuration(p.restore_remaining) + ' 남음' : '');
            box.appendChild(el('div', { className: 'admin-detail-head' }, [
                p.status === 'active' ? badge('게시 중', 'ok') : badge('삭제됨', 'danger'),
                el('span', { className: 'muted', text: '#' + p.id })
            ]));
            box.appendChild(el('h2', { className: 'detail-title' }, [p.source === 'scrap' ? el('span', { className: 'badge badge-scrap', text: '스크랩' }) : null, p.title]));
            box.appendChild(el('p', { className: 'detail-meta', text: meta }));
            var content = el('div', { className: 'zz-content' });
            content.innerHTML = p.html; // 서버(ContentRenderer)가 정화한 HTML
            box.appendChild(content);
            if (p.attachments.length) {
                box.appendChild(el('section', { className: 'detail-section' }, [el('h2', { text: '첨부파일' }),
                    el('ul', { className: 'attach-list' }, p.attachments.map(function (a) {
                        return a.available
                            ? el('li', null, [el('a', { href: 'api/file.php?type=attachment&id=' + a.id, text: a.original_name }), ' ', el('span', { className: 'muted', text: '(' + Z.formatBytes(a.file_size) + ')' })])
                            : el('li', null, [el('span', { className: 'muted', text: a.original_name }), el('span', { className: 'expired-inline', text: '첨부가 만료되었습니다' })]);
                    }))]));
            }
            var actions = el('div', { className: 'admin-detail-actions' }, [
                el('span', { className: 'muted', text: '등록된 찌라시는 수정할 수 없습니다.' })
            ]);
            if (p.status === 'active') actions.appendChild(btn('삭제', function () { deletePost(p, function () { showView('detail', p.id); }); }));
            else if (p.restore_remaining > 0) actions.appendChild(btn('복구', function () { restorePost(p, function () { showView('detail', p.id); }); }));
            box.appendChild(actions);
            // 추천·댓글(5d): 관리자 토큰으로 열어 댓글 삭제 버튼이 보인다(삭제된 글도 관리자는 볼 수 있음)
            var social = el('section', { 'aria-label': '추천과 댓글' });
            box.appendChild(social);
            if (window.ZZComments) window.ZZComments.mount(social, { postId: p.id, csrf: CONFIG.csrf, adminToken: function () { return state.token; } });
        }).catch(ignoreAuth);
    }

    // ── 멤버 ──────────────────────────────────────────────
    var memberFilter = { status: '', q: '', page: 1 };

    function viewMembers(root) {
        var counts = el('p', { className: 'list-caption' });
        var bulk = el('textarea', { rows: '3', placeholder: 'a@company.com, b@company.com\n줄바꿈·쉼표·세미콜론으로 여러 개를 한꺼번에 넣을 수 있습니다.', 'aria-label': '추가할 이메일 주소' });
        var addResult = el('div', { className: 'bulk-result' });
        var addForm = el('form', { className: 'admin-add' }, [
            el('label', { className: 'label', text: '멤버 추가 (즉시 수신)' }), bulk,
            el('div', { className: 'admin-add-actions' }, [el('button', { type: 'submit', className: 'zz-btn', text: '추가' })]),
            addResult
        ]);
        var status = el('select', { 'aria-label': '상태 필터' }, [
            el('option', { value: '', text: '전체 상태' }), el('option', { value: 'active', text: '수신' }),
            el('option', { value: 'suspended', text: '정지' }), el('option', { value: 'unsubscribed', text: '구독 해지' })
        ]);
        status.value = memberFilter.status;
        var q = el('input', { type: 'search', value: memberFilter.q, placeholder: '이메일 검색', maxlength: '100', 'aria-label': '이메일 검색' });
        var filter = el('form', { className: 'admin-filter' }, [status, q, el('button', { type: 'submit', className: 'zz-btn', text: '조회' })]);
        var list = el('div', { className: 'muted', text: '불러오는 중…' });

        root.appendChild(el('p', { className: 'admin-note', text: '멤버의 가입·주소 변경·수신 중지는 모두 관리자가 처리합니다. 멤버 요청을 받으면 여기서 처리하세요. 정지·구독 해지된 멤버는 다음 발송부터 빠집니다.' }));
        root.appendChild(addForm);
        root.appendChild(filter);
        root.appendChild(counts);
        root.appendChild(list);

        addForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!bulk.value.trim()) { addResult.textContent = '추가할 주소를 입력하세요.'; addResult.className = 'bulk-result error'; return; }
            api('api/admin_member_action.php', { action: 'add', emails: bulk.value }).then(function (data) {
                addResult.textContent = '';
                addResult.className = 'bulk-result ' + (data.ok ? 'ok' : 'error');
                if (!data.ok) { addResult.textContent = data.error; return; }
                addResult.appendChild(el('strong', { text: data.message }));
                if (data.duplicate.length) addResult.appendChild(el('div', { className: 'muted', text: '중복: ' + data.duplicate.join(', ') }));
                if (data.invalid.length) addResult.appendChild(el('div', { className: 'muted', text: '형식 오류: ' + data.invalid.join(', ') }));
                // 형식 오류만 입력창에 남겨 고칠 수 있게 한다
                bulk.value = data.invalid.join('\n');
                memberFilter.page = 1;
                load();
            }).catch(ignoreAuth);
        });
        filter.addEventListener('submit', function (e) {
            e.preventDefault();
            memberFilter = { status: status.value, q: q.value.trim(), page: 1 };
            load();
        });
        status.addEventListener('change', function () { memberFilter.status = status.value; memberFilter.page = 1; load(); });

        function act(action, m, confirmText, hint, yes) {
            var run = function () {
                api('api/admin_member_action.php', { action: action, id: m.id }).then(function (data) {
                    message(data.ok ? m.email + ': ' + data.message : data.error, data.ok ? 'ok' : 'error');
                    load();
                }).catch(ignoreAuth);
            };
            if (!confirmText) { run(); return; }
            confirmBox(confirmText, hint, yes).then(function (ok) { if (ok) run(); });
        }

        function editRow(tr, m) {
            var input = el('input', { type: 'email', value: m.email, maxlength: '254', 'aria-label': '새 이메일 주소' });
            var cell = tr.firstChild;
            cell.textContent = '';
            cell.appendChild(el('div', { className: 'inline-edit' }, [input,
                ctlBtn('저장', function () {
                    api('api/admin_member_action.php', { action: 'update', id: m.id, email: input.value.trim() }).then(function (data) {
                        message(data.ok ? data.message : data.error, data.ok ? 'ok' : 'error');
                        if (data.ok) load();
                    }).catch(ignoreAuth);
                }),
                ctlBtn('취소', load)]));
            input.focus();
            input.select();
        }

        function rowActions(tr, m) {
            var out = [btn('수정', function () { editRow(tr, m); })];
            if (m.status === 'active') {
                out.push(btn('정지', function () { act('suspend', m); }));
                out.push(btn('구독 해지', function () { act('unsubscribe', m, m.email + ' 멤버를 구독 해지 처리할까요?', '멤버 본인이 수신 중지를 요청했을 때 사용합니다. 다음 발송부터 빠집니다.', '구독 해지'); }));
            } else if (m.status === 'suspended') {
                out.push(btn('재개', function () { act('resume', m); }));
                out.push(btn('구독 해지', function () { act('unsubscribe', m, m.email + ' 멤버를 구독 해지 처리할까요?', '멤버 본인이 수신 중지를 요청했을 때 사용합니다.', '구독 해지'); }));
            } else {
                out.push(btn('재활성화', function () { act('reactivate', m, m.email + ' 멤버를 다시 수신 상태로 바꿀까요?', '해지된 멤버는 본인이 다시 받기를 요청한 경우에만 재활성화하세요.', '재활성화'); }));
            }
            out.push(btn('삭제', function () { act('delete', m, m.email + ' 멤버를 삭제할까요?', '목록에서 완전히 지워집니다. 잠시 멈추려면 "정지", 수신 중지 요청이면 "구독 해지"를 쓰세요.', '삭제'); }));
            return out;
        }

        function load() {
            var p = new URLSearchParams({ page: memberFilter.page });
            if (memberFilter.status) p.set('status', memberFilter.status);
            if (memberFilter.q) p.set('q', memberFilter.q);
            api('api/admin_members.php?' + p.toString()).then(function (data) {
                list.textContent = '';
                list.className = '';
                if (!data.ok) { list.appendChild(el('p', { className: 'form-message error', text: data.error })); return; }
                var c = data.counts;
                counts.textContent = '수신 ' + c.active + '명 · 정지 ' + c.suspended + '명 · 구독 해지 ' + c.unsubscribed + '명 (조건에 맞는 ' + data.total + '명)';
                list.appendChild(table(['이메일', '상태', '등록일', ''], data.items.map(function (m) {
                    var tr = el('tr', null, [
                        el('td', { className: 'email', text: m.email }),
                        el('td', null, [badge(STATUS_LABELS[m.status], { active: 'ok', suspended: 'warn', unsubscribed: 'muted' }[m.status])]),
                        dateCell(m.created_at)
                    ]);
                    tr.appendChild(el('td', { className: 'actions' }, rowActions(tr, m)));
                    return tr;
                }), memberFilter.q || memberFilter.status ? '조건에 맞는 멤버가 없습니다.' : '등록된 멤버가 없습니다. 위에서 이메일 주소를 추가하세요.'));
                var pg = pager(data.page, data.pages, function (n) { memberFilter.page = n; load(); });
                if (pg) list.appendChild(pg);
            }).catch(ignoreAuth);
        }
        load();
    }

    // ── 사용자 계정(5b) ───────────────────────────────────
    var userFilter = { status: '', q: '', page: 1 };
    var USER_STATUS = { active: ['사용', 'ok'], suspended: ['정지', 'danger'] };

    /** 서버에서 임의 비밀번호(영문·숫자 12자)를 받아 입력칸에 넣는다(저장하지 않음) */
    function fillGenerated(input) {
        api('api/admin_users_action.php', { action: 'generate' }).then(function (r) {
            if (!r.ok) { message(r.error, 'error'); return; }
            input.value = r.password;
            input.focus();
            input.select();
        }).catch(ignoreAuth);
    }

    /** 관리자 비밀번호 변경 영역(6c) — "사용자 계정" 화면 맨 위. 검증은 모두 서버가 한다 */
    function adminPasswordCard() {
        var pwAttr = function (label) { return { type: 'password', maxlength: '64', autocomplete: 'new-password', spellcheck: 'false', 'aria-label': label, 'data-lpignore': 'true', 'data-1p-ignore': 'true' }; };
        var cur = el('input', Object.assign(pwAttr('현재 관리자 비밀번호'), { placeholder: '현재 비밀번호' }));
        var pw1 = el('input', Object.assign(pwAttr('새 관리자 비밀번호'), { placeholder: '새 비밀번호 (8~64자)' }));
        var pw2 = el('input', Object.assign(pwAttr('새 관리자 비밀번호 확인'), { placeholder: '새 비밀번호 확인' }));
        var reveal = el('div', { className: 'admin-pass-reveal' });
        var result = el('div', { className: 'bulk-result', role: 'status' });
        var genBtn = ctlBtn('임의 생성', function () {
            api('api/admin_users_action.php', { action: 'generate' }).then(function (r) {
                if (!r.ok) { message(r.error, 'error'); return; }
                pw1.value = pw2.value = r.password;
                // 만든 값은 이 화면에서만 한 번 보여 준다(새로고침하면 사라짐)
                reveal.replaceChildren('생성한 새 비밀번호 ', el('span', { className: 'pw-reveal', text: r.password }), ' — 안전한 곳에 적어 두세요.');
            }).catch(ignoreAuth);
        });
        var submitBtn = el('button', { type: 'submit', className: 'zz-btn', text: '변경' });
        var form = el('form', { className: 'admin-add admin-pass-form', novalidate: true, autocomplete: 'off' }, [
            el('label', { className: 'label', text: '관리자 비밀번호 변경' }),
            el('p', { className: 'muted small', text: '관리 탭에 들어올 때 쓰는 비밀번호입니다(사용자 계정과 별개). 바꾸면 날짜 비밀번호는 더 이상 쓸 수 없고, 다른 곳에서 열려 있던 관리자 로그인은 종료됩니다.' }),
            el('div', { className: 'inline-edit admin-pass-row' }, [cur, pw1, pw2, genBtn, submitBtn]),
            reveal, result
        ]);
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitBtn.disabled = true;
            api('api/admin_password_change.php', { current: cur.value, new_password: pw1.value, new_password_confirm: pw2.value }).then(function (r) {
                submitBtn.disabled = false;
                result.className = 'bulk-result ' + (r.ok ? 'ok' : 'error');
                result.textContent = r.ok ? r.message : r.error;
                cur.value = '';
                if (r.ok) { pw1.value = pw2.value = ''; reveal.textContent = ''; setPassDefault(false); }
                if (!r.ok) cur.focus();
            }).catch(function (e2) { submitBtn.disabled = false; ignoreAuth(e2); });
        });
        return el('section', { className: 'admin-card admin-pass-card' }, [form]);
    }

    function viewUsers(root) {
        root.appendChild(adminPasswordCard());
        root.appendChild(el('div', { className: 'zz-banner info user-note' }, [
            el('strong', { text: '찌라시 작성자와 사용자 계정은 연결되지 않아 익명이 유지됩니다. ' }),
            el('span', { text: '사용자 계정은 사이트 로그인용이며 메일 수신 멤버와 별개입니다. 비밀번호는 해시로만 저장되어 관리자도 볼 수 없습니다.' })
        ]));

        // 한 명 추가
        var newId = el('input', { type: 'text', maxlength: '20', placeholder: 'ID (4~20자, 영문·숫자·. _ -)', autocomplete: 'off', spellcheck: 'false', 'aria-label': '새 사용자 ID' });
        var newPw = el('input', { type: 'text', maxlength: '64', placeholder: '비밀번호 (8~64자)', autocomplete: 'off', spellcheck: 'false', 'aria-label': '새 사용자 비밀번호' });
        var addResult = el('div', { className: 'bulk-result' });
        var addForm = el('form', { className: 'admin-add', novalidate: true }, [
            el('label', { className: 'label', text: '사용자 추가 (즉시 사용 가능)' }),
            el('div', { className: 'inline-edit user-add-row' }, [newId, newPw, ctlBtn('임의 생성', function () { fillGenerated(newPw); }),
                el('button', { type: 'submit', className: 'zz-btn', text: '추가' })]),
            addResult
        ]);
        addForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var id = newId.value.trim(), pw = newPw.value;
            api('api/admin_users_action.php', { action: 'create', login_id: id, password: pw }).then(function (r) {
                addResult.textContent = '';
                addResult.className = 'bulk-result ' + (r.ok ? 'ok' : 'error');
                if (!r.ok) { addResult.textContent = r.error; return; }
                // 방금 정한 비밀번호는 지금 한 번만 보여 준다(사용자에게 전달용)
                addResult.appendChild(el('strong', { text: r.message }));
                addResult.appendChild(el('div', null, ['ID ', el('span', { className: 'pw-reveal', text: id }), '  비밀번호 ', el('span', { className: 'pw-reveal', text: pw })]));
                newId.value = '';
                newPw.value = '';
                userFilter.page = 1;
                load();
            }).catch(ignoreAuth);
        });

        // 일괄 추가
        var bulk = el('textarea', { rows: '4', spellcheck: 'false', placeholder: 'kim01,Passw0rd!23\nlee02\tSecret-pass-9\n한 줄에 "ID,비밀번호" (쉼표·탭·공백 구분), 최대 300줄', 'aria-label': '일괄 추가할 ID와 비밀번호' });
        var bulkResult = el('div', { className: 'bulk-result' });
        var bulkForm = el('form', { className: 'admin-add', novalidate: true }, [
            el('label', { className: 'label', text: '일괄 추가' }), bulk,
            el('div', { className: 'admin-add-actions' }, [el('button', { type: 'submit', className: 'zz-btn', text: '일괄 추가' })]),
            bulkResult
        ]);
        bulkForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!bulk.value.trim()) { bulkResult.textContent = '추가할 줄을 입력하세요.'; bulkResult.className = 'bulk-result error'; return; }
            var lines = bulk.value.split(/\r\n|\r|\n/);
            api('api/admin_users_action.php', { action: 'bulk', lines: bulk.value }).then(function (r) {
                bulkResult.textContent = '';
                bulkResult.className = 'bulk-result ' + (r.ok ? 'ok' : 'error');
                if (!r.ok) { bulkResult.textContent = r.error; return; }
                bulkResult.appendChild(el('strong', { text: r.message }));
                if (r.duplicate.length) bulkResult.appendChild(el('div', { className: 'muted', text: '중복: ' + r.duplicate.map(function (d) { return d.line + '번째 줄(' + d.id + ')'; }).join(', ') }));
                if (r.invalid.length) bulkResult.appendChild(el('div', { className: 'muted', text: '형식 오류: ' + r.invalid.map(function (d) { return d.line + '번째 줄 — ' + d.reason; }).join(' / ') }));
                // 형식 오류 줄만 남겨 고칠 수 있게 한다(추가된 줄의 비밀번호는 화면에서 지운다)
                bulk.value = r.invalid.map(function (d) { return lines[d.line - 1]; }).join('\n');
                userFilter.page = 1;
                load();
            }).catch(ignoreAuth);
        });

        var status = el('select', { 'aria-label': '상태 필터' }, [
            el('option', { value: '', text: '전체' }), el('option', { value: 'active', text: '사용' }), el('option', { value: 'suspended', text: '정지' })
        ]);
        status.value = userFilter.status;
        var q = el('input', { type: 'search', value: userFilter.q, placeholder: 'ID 검색', maxlength: '20', 'aria-label': 'ID 검색' });
        var filter = el('form', { className: 'admin-filter' }, [status, q, el('button', { type: 'submit', className: 'zz-btn', text: '조회' })]);
        var counts = el('p', { className: 'list-caption' });
        var list = el('div', { className: 'muted', text: '불러오는 중…' });

        root.appendChild(addForm);
        root.appendChild(bulkForm);
        root.appendChild(filter);
        root.appendChild(counts);
        root.appendChild(list);

        filter.addEventListener('submit', function (e) {
            e.preventDefault();
            userFilter = { status: status.value, q: q.value.trim(), page: 1 };
            load();
        });
        status.addEventListener('change', function () { userFilter.status = status.value; userFilter.page = 1; load(); });

        function act(action, u, confirmText, hint, yes) {
            var run = function () {
                api('api/admin_users_action.php', { action: action, id: u.id }).then(function (r) {
                    message(r.ok ? r.message : r.error, r.ok ? 'ok' : 'error');
                    load();
                }).catch(ignoreAuth);
            };
            if (!confirmText) { run(); return; }
            confirmBox(confirmText, hint, yes).then(function (ok) { if (ok) run(); });
        }

        function resetRow(tr, u) {
            var input = el('input', { type: 'text', maxlength: '64', placeholder: '새 비밀번호 (8~64자)', autocomplete: 'off', spellcheck: 'false', 'aria-label': u.login_id + ' 새 비밀번호' });
            var cell = tr.lastChild;
            cell.textContent = '';
            cell.appendChild(el('div', { className: 'inline-edit' }, [input,
                ctlBtn('임의 생성', function () { fillGenerated(input); }),
                ctlBtn('저장', function () {
                    var pw = input.value;
                    api('api/admin_users_action.php', { action: 'reset', id: u.id, password: pw }).then(function (r) {
                        if (!r.ok) { message(r.error, 'error'); return; }
                        message(r.message, 'ok');
                        load(function () {
                            // 새 비밀번호는 지금 한 번만 보여 준다
                            $('admin-message').appendChild(el('span', null, ['  새 비밀번호 ', el('span', { className: 'pw-reveal', text: pw })]));
                        });
                    }).catch(ignoreAuth);
                }),
                ctlBtn('취소', function () { load(); })]));
            cell.appendChild(el('small', { className: 'muted', text: '기존 비밀번호는 볼 수 없습니다(해시만 저장). 재설정하면 이 사용자의 기존 로그인이 모두 해제됩니다.' }));
            input.focus();
        }

        function rowActions(tr, u) {
            var out = [btn('비밀번호 재설정', function () { resetRow(tr, u); })];
            if (u.status === 'active') {
                out.push(btn('정지', function () { act('suspend', u, '"' + u.login_id + '" 사용자를 정지할까요?', '정지하면 즉시 로그아웃되고 다시 재개할 때까지 로그인할 수 없습니다.', '정지'); }));
            } else {
                out.push(btn('재개', function () { act('resume', u); }));
            }
            out.push(btn('삭제', function () { act('delete', u, '"' + u.login_id + '" 사용자를 삭제할까요?', '영구 삭제되며 복구할 수 없습니다. 잠시 막으려면 "정지"를 쓰세요.', '삭제'); }));
            return out;
        }

        function load(after) {
            var qs = new URLSearchParams({ page: userFilter.page });
            if (userFilter.status) qs.set('status', userFilter.status);
            if (userFilter.q) qs.set('q', userFilter.q);
            api('api/admin_users.php?' + qs.toString()).then(function (d) {
                list.textContent = '';
                list.className = '';
                if (!d.ok) { list.appendChild(el('p', { className: 'form-message error', text: d.error })); return; }
                counts.textContent = '사용 ' + d.counts.active + '명 · 정지 ' + d.counts.suspended + '명' + (userFilter.status || userFilter.q ? ' · 조회 결과 ' + d.total + '명' : '');
                list.appendChild(table(['ID', '상태', '등록일', '마지막 로그인', ''], d.items.map(function (u) {
                    var s = USER_STATUS[u.status] || [u.status, 'muted'];
                    var tr = el('tr', null, [
                        el('td', { className: 'email', text: u.login_id }),
                        el('td', null, [badge(s[0], s[1])]),
                        dateCell(u.created_at),
                        el('td', { className: 'nowrap', text: u.last_login_date || '-' })
                    ]);
                    tr.appendChild(el('td', { className: 'actions' }, rowActions(tr, u)));
                    return tr;
                }), userFilter.status || userFilter.q ? '조건에 맞는 사용자가 없습니다.' : '등록된 사용자가 없습니다. 위에서 추가하세요.'));
                var pg = pager(d.page, d.pages, function (n) { userFilter.page = n; load(); });
                if (pg) list.appendChild(pg);
                if (after) after();
            }).catch(ignoreAuth);
        }
        load();
    }

    // ── 스크랩(5f) ─────────────────────────────────────────
    var SCRAP_STATUS = { success: ['성공', 'ok'], partial: ['일부 실패', 'warn'], failed: ['실패', 'danger'], running: ['진행 중', 'muted'] };

    function shortDateTime(dt) {
        dt = dt || '';
        return dt.length >= 16 ? dt.slice(2, 4) + '/' + dt.slice(5, 7) + '/' + dt.slice(8, 10) + ' ' + dt.slice(11, 16) : dt;
    }

    /** 주제별 결과 표(등록·건너뜀·오류) */
    function scrapTopicTable(topics) {
        var names = Object.keys(topics || {});
        if (!names.length) return el('p', { className: 'muted small', text: '주제별 결과가 없습니다.' });
        return table(['주제', '등록', '건너뜀', '오류'], names.map(function (n) {
            var t = topics[n] || {};
            return el('tr', null, [el('td', { text: n }), el('td', { className: 'num', text: String(t.created || 0) }),
                el('td', { className: 'num', text: String(t.skipped || 0) }), el('td', { className: 'small pre', text: t.error || '' })]);
        }), '');
    }

    function viewScrap(root) {
        var box = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(box);
        function load() {
            api('api/admin_scrap_get.php').then(function (d) {
                box.textContent = '';
                box.className = '';
                if (!d.ok) { box.appendChild(el('p', { className: 'form-message error', text: d.error })); return; }
                render(d);
            }).catch(ignoreAuth);
        }
        load();

        function render(d) {
            // 1. 자동 실행
            var noTopic = d.topic_count === 0;
            var sw = el('button', { type: 'button', className: 'zz-btn', role: 'switch', 'aria-checked': d.enabled ? 'true' : 'false',
                text: d.enabled ? '자동 실행 끄기' : '자동 실행 켜기', disabled: noTopic && !d.enabled,
                title: noTopic && !d.enabled ? '주제를 먼저 선택하세요' : '' });
            sw.addEventListener('click', function () {
                var turnOn = !d.enabled;
                var go = function () {
                    sw.disabled = true;
                    api('api/admin_scrap_toggle.php', { enabled: turnOn ? '1' : '0' }).then(function (r) {
                        message(r.ok ? r.message : r.error, r.ok ? 'ok' : 'error');
                        load();
                    }).catch(ignoreAuth);
                };
                if (turnOn) go();
                else confirmBox('스크랩 자동 실행을 끌까요?', '꺼져 있는 동안 매일 06:30 자동 등록을 하지 않습니다. "지금 실행"은 계속 쓸 수 있습니다.', '끄기').then(function (yes) { if (yes) go(); });
            });
            var today = d.today_auto ? '오늘 자동 실행: ' + shortDateTime(d.today_auto.started_at) + ' ' + ((SCRAP_STATUS[d.today_auto.status] || [d.today_auto.status])[0]) : '오늘 자동 실행: 아직 없음';
            box.appendChild(el('section', { className: 'admin-card' }, [
                el('h3', { text: '자동 실행' }),
                el('p', null, [d.enabled ? badge('켜짐', 'ok') : badge('꺼짐(기본)', 'muted'), el('span', { className: 'muted small', text: '  ' + today })]),
                el('p', { className: 'muted small', text: '매일 06:30에 고른 주제의 최신 뉴스를 구글 뉴스에서 찾아 찌라시로 등록합니다(서버가 꺼져 있었다면 12:00까지 보충). 스크랩 글은 다음 날 07:00 메일에 포함됩니다.' }),
                el('p', { className: 'muted small', text: '외부 접속: ' + (d.proxy_set ? 'config.php 의 프록시(SCRAP_PROXY)를 거쳐 접속' : '직접 접속(사내 프록시가 필요하면 config.php 의 SCRAP_PROXY)') + ' · 대상 news.google.com 고정' }),
                noTopic ? el('p', { className: 'form-message error small', text: '주제를 먼저 선택하세요. 주제가 없으면 자동 실행을 켤 수 없습니다.' }) : null,
                el('div', { className: 'admin-card-actions' }, [sw])
            ]));

            // 2. 주제 선택
            var checks = d.suggestions.map(function (s) {
                return el('input', { type: 'checkbox', value: s.key, checked: d.keys.indexOf(s.key) >= 0 });
            });
            var counter = el('span', { className: 'muted small' });
            var kw = el('textarea', { rows: '3', spellcheck: 'false', placeholder: '직접 입력 키워드(한 줄에 하나, 2~30자, 최대 10개)\n예: 전기차 화재\n원달러 환율', 'aria-label': '직접 입력 키워드' });
            kw.value = d.keywords.join('\n');
            var per = el('select', { 'aria-label': '주제당 등록 건수' }, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(function (n) { return el('option', { value: String(n), text: n + '건' }); }));
            per.value = String(d.per_topic);
            function count() {
                var n = checks.filter(function (c) { return c.checked; }).length
                    + kw.value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean).length;
                counter.textContent = '선택 ' + n + '/' + d.max_topics;
                counter.className = 'small ' + (n > d.max_topics ? 'form-message error' : 'muted');
            }
            checks.forEach(function (c) { c.addEventListener('change', count); });
            kw.addEventListener('input', count);
            var msg = el('p', { className: 'form-message pre', role: 'alert' });
            var form = el('form', { className: 'admin-card admin-form', novalidate: true }, [
                el('h3', { text: '주제 선택' }),
                el('div', { className: 'admin-card-actions' }, [
                    btn('전체 선택', function () { checks.forEach(function (c) { c.checked = true; }); count(); }),
                    btn('전체 해제', function () { checks.forEach(function (c) { c.checked = false; }); count(); }),
                    counter
                ]),
                el('div', { className: 'scrap-topics' }, d.suggestions.map(function (s, i) {
                    return el('label', { className: 'admin-check' }, [checks[i], ' ' + s.label]);
                })),
                el('label', { className: 'admin-field' }, [el('span', { className: 'label', text: '직접 입력 키워드' }), kw]),
                el('label', { className: 'admin-field scrap-per' }, [el('span', { className: 'label', text: '주제당 등록 건수' }), per,
                    el('small', { className: 'muted', text: '한 번 실행에 전체 최대 30건, 발행 후 36시간 이내 기사만. 이미 등록했거나 제목이 비슷한 기사는 건너뜁니다.' })]),
                el('div', { className: 'admin-card-actions' }, [el('button', { type: 'submit', className: 'zz-btn', text: '저장' })]),
                msg
            ]);
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = { keywords: kw.value, per_topic: per.value };
                var picked = checks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
                // keys[] 여러 값은 FormData 로 직접 보낸다
                var body = new FormData();
                body.append('csrf_token', CONFIG.csrf);
                picked.forEach(function (k) { body.append('keys[]', k); });
                Object.keys(fd).forEach(function (k) { body.append(k, fd[k]); });
                request('api/admin_scrap_save.php', { method: 'POST', body: body }).then(function (r) {
                    if (r.loginRequired) return;
                    if (r.httpStatus === 401) { authLost(r.error); return; }
                    if (typeof r.session_remaining === 'number') startCountdown(r.session_remaining);
                    if (!r.ok) { msg.textContent = r.error; msg.className = 'form-message pre error'; return; }
                    message(r.message + (r.enabled ? '' : (r.topic_count ? '' : ' 주제가 없어 자동 실행은 꺼져 있습니다.')), 'ok');
                    load();
                });
            });
            box.appendChild(form);
            count();

            // 3. 지금 실행
            var runBtn = el('button', { type: 'button', className: 'zz-btn', text: '지금 실행', disabled: noTopic || d.running,
                title: noTopic ? '주제를 먼저 선택하고 저장하세요' : '' });
            var result = el('div', { className: 'scrap-result', role: 'status' });
            runBtn.addEventListener('click', function () {
                runBtn.disabled = true; // 중복 클릭 방지
                runBtn.textContent = '실행 중… (최대 2분)';
                result.textContent = '';
                api('api/admin_scrap_run.php', {}).then(function (r) {
                    runBtn.textContent = '지금 실행';
                    runBtn.disabled = false;
                    if (!r.ok) { result.appendChild(el('p', { className: 'form-message error', text: r.error })); return; }
                    var kind = { success: 'ok', partial: '', failed: 'error' }[r.status] || '';
                    result.appendChild(el('p', { className: 'form-message ' + kind, text: '등록 ' + r.created + '건 · 건너뜀 ' + r.skipped + '건 · 상태 ' + ((SCRAP_STATUS[r.status] || [r.status])[0]) }));
                    result.appendChild(scrapTopicTable(r.topics));
                    loadRuns();
                }).catch(function (e) { runBtn.textContent = '지금 실행'; runBtn.disabled = false; ignoreAuth(e); });
            });
            box.appendChild(el('section', { className: 'admin-card' }, [
                el('h3', { text: '지금 실행' }),
                el('p', { className: 'muted small', text: '저장된 주제로 바로 한 번 실행합니다(자동 실행이 꺼져 있어도 됩니다). 등록된 글은 일반 찌라시처럼 조회·추천·댓글·고정·삭제할 수 있습니다.' }),
                el('div', { className: 'admin-card-actions' }, [runBtn]),
                result
            ]));

            // 4. 실행 이력
            var hist = el('div');
            box.appendChild(el('h3', { className: 'admin-subtitle', text: '실행 이력(최근 20건)' }));
            box.appendChild(hist);
            function renderRuns(runs) {
                hist.textContent = '';
                hist.appendChild(table(['일시', '구분', '상태', '주제 수', '등록', '건너뜀', '상세'], runs.map(function (x) {
                    var s = SCRAP_STATUS[x.status] || [x.status, 'muted'];
                    return el('tr', null, [
                        el('td', { className: 'nowrap', text: shortDateTime(x.started_at), title: x.started_at }),
                        el('td', { className: 'nowrap', text: x.trigger_type === 'auto' ? '자동' : '수동' }),
                        el('td', null, [badge(s[0], s[1])]),
                        el('td', { className: 'num', text: String(x.topic_count) }),
                        el('td', { className: 'num', text: String(x.created_count) }),
                        el('td', { className: 'num', text: String(x.skipped_count) }),
                        el('td', null, [el('details', null, [el('summary', { text: '주제별 결과' }), scrapTopicTable(x.topics)])])
                    ]);
                }), '아직 실행한 적이 없습니다.'));
            }
            function loadRuns() {
                api('api/admin_scrap_get.php').then(function (r) { if (r.ok) renderRuns(r.runs); }).catch(ignoreAuth);
            }
            renderRuns(d.runs);
        }
    }

    // ── 정리(6b) ───────────────────────────────────────────
    function cleanupTable(items) {
        return table(['항목', '건수'], items.map(function (it) {
            return el('tr', { className: it.key === 'file_errors' && it.count > 0 ? 'row-warn' : null }, [el('td', { text: it.label }), el('td', { className: 'num', text: String(it.count) })]);
        }), '');
    }

    function viewCleanup(root) {
        var box = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(box);
        api('api/admin_cleanup_get.php').then(function (d) {
            box.textContent = '';
            box.className = '';
            if (!d.ok) { box.appendChild(el('p', { className: 'form-message error', text: d.error })); return; }
            render(d);
        }).catch(ignoreAuth);

        function lastText(l) {
            if (!l) return '마지막 정리: 기록 없음';
            return '마지막 정리: ' + (l.trigger === 'manual' ? '수동' : '스케줄러') + ' · ' + shortDateTime(l.at) + ' · ' + l.summary;
        }

        function render(d) {
            var r = d.retention;
            var preview = null; // 미리보기 결과(본 뒤에만 실행 가능)
            var last = el('p', { className: 'cleanup-last', text: lastText(d.last), title: d.last ? d.last.at : '' });
            box.appendChild(el('section', { className: 'admin-card' }, [
                el('h3', { text: '정리 대상과 보관 기간' }),
                el('ul', { className: 'cleanup-rules' }, [
                    el('li', { text: '등록 후 ' + r.file_text + '이 지난 본문 이미지·첨부파일: 파일만 지우고 글은 남깁니다("이미지가 만료되었습니다"로 표시).' }),
                    el('li', { text: '삭제 후 ' + r.deleted_days + '일이 지난 찌라시: 본문·댓글·추천·이미지·첨부까지 완전히 지웁니다(복구 불가).' }),
                    el('li', { text: '등록되지 않은 임시 이미지(' + (r.temp_hours / 24) + '일 지난 것), 만료된 임시 파일(등록 제한·잠금 기록), 중단된 발송 기록' }),
                    el('li', { text: '오래된 스크랩 기록(중복 방지 30일·실행 이력 90일)' })
                ]),
                el('p', { className: 'muted small', text: '정리는 매일 03:00 작업 스케줄러가 자동으로 실행하므로 보통은 직접 실행할 필요가 없습니다. 여기서 실행해도 같은 처리를 합니다(명령줄 cron_cleanup.php 와 동일).' }),
                last
            ]));

            var previewBtn = el('button', { type: 'button', className: 'zz-btn', text: '삭제 대상 확인', disabled: d.running });
            var runBtn = el('button', { type: 'button', className: 'zz-btn', text: '지금 정리', disabled: true, title: '먼저 삭제 대상 확인을 눌러 주세요' });
            var hint = el('p', { className: 'muted small', text: d.running ? '정리가 이미 실행 중입니다. 잠시 후 다시 열어 주세요.' : '먼저 "삭제 대상 확인"을 눌러 주세요.' });
            var out = el('div', { className: 'cleanup-out', role: 'status' });
            box.appendChild(el('section', { className: 'admin-card' }, [
                el('h3', { text: '수동 정리' }),
                el('div', { className: 'admin-card-actions' }, [previewBtn, runBtn]),
                hint,
                out
            ]));

            previewBtn.addEventListener('click', function () {
                previewBtn.disabled = runBtn.disabled = true;
                previewBtn.textContent = '확인 중…';
                out.textContent = '';
                api('api/admin_cleanup_preview.php', {}).then(function (p) {
                    previewBtn.disabled = false;
                    previewBtn.textContent = '삭제 대상 확인';
                    if (!p.ok) { out.appendChild(el('p', { className: 'form-message error', text: p.error })); return; }
                    preview = p;
                    out.appendChild(el('p', { className: 'form-message', text: '미리보기(아직 아무것도 지우지 않았습니다): ' + p.message }));
                    out.appendChild(cleanupTable(p.items));
                    runBtn.disabled = p.total === 0;
                    runBtn.title = p.total === 0 ? '정리할 항목이 없습니다' : '';
                    hint.textContent = p.total === 0 ? '정리할 항목이 없습니다.' : '대상을 확인했습니다. "지금 정리"를 누르면 영구 삭제합니다.';
                }).catch(function (e) { previewBtn.disabled = false; previewBtn.textContent = '삭제 대상 확인'; ignoreAuth(e); });
            });

            runBtn.addEventListener('click', function () {
                if (!preview || preview.total === 0) return;
                confirmBox('되돌릴 수 없습니다. 영구 삭제되는 항목이 ' + preview.total + '건 있습니다. 실행할까요?', '삭제한 파일·글·댓글은 복구할 수 없습니다.', '지금 정리').then(function (yes) {
                    if (!yes) return;
                    runBtn.disabled = previewBtn.disabled = true; // 실행 중 중복 클릭 방지
                    runBtn.textContent = '정리 중…';
                    api('api/admin_cleanup_run.php', {}).then(function (res) {
                        runBtn.textContent = '지금 정리';
                        previewBtn.disabled = false;
                        preview = null; // 다시 실행하려면 미리보기부터
                        runBtn.title = '먼저 삭제 대상 확인을 눌러 주세요';
                        out.textContent = '';
                        if (!res.ok) { out.appendChild(el('p', { className: 'form-message error', text: res.error })); hint.textContent = ''; return; }
                        out.appendChild(el('p', { className: 'form-message ok', text: res.message }));
                        out.appendChild(el('p', { className: 'muted small', text: res.summary }));
                        out.appendChild(cleanupTable(res.items));
                        if (res.file_errors > 0) out.appendChild(el('p', { className: 'form-message error', text: '파일 ' + res.file_errors + '개를 지우지 못했습니다. 다음 정리 때 다시 시도합니다.' }));
                        if (res.last) { last.textContent = lastText(res.last); last.title = res.last.at; }
                        hint.textContent = '다시 정리하려면 먼저 "삭제 대상 확인"을 눌러 주세요.';
                    }).catch(function (e) { runBtn.textContent = '지금 정리'; previewBtn.disabled = false; ignoreAuth(e); });
                });
            });
        }
    }

    // ── 관리자 가이드(6단계) ───────────────────────────────
    function viewGuide(root) {
        var box = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(box);
        api('api/admin_guide.php').then(function (d) {
            box.className = 'guide admin-guide';
            if (!d.ok) { box.textContent = d.error; return; }
            box.innerHTML = d.html; // 서버가 만든 고정 HTML(src/Guide.php, 사용자 입력 없음)
            box.querySelectorAll('a[href^="#"]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); }); });
        }).catch(ignoreAuth);
    }

    // ── 토스트(가벼운 알림) ────────────────────────────────
    function toast(text, type) {
        var box = $('zz-toast');
        var item = el('div', { className: 'zz-toast-item' + (type ? ' ' + type : ''), role: 'status', text: text });
        box.appendChild(item);
        setTimeout(function () { item.classList.add('hide'); }, 2600);
        setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 3000);
    }

    /** 메일 설정이 비었을 때 보여 줄 안내(부족 항목 + 메일 설정으로 이동) */
    function problemsBox(problems) {
        if (!problems || !problems.length) return null;
        return el('div', { className: 'zz-banner warn', role: 'alert' }, [
            el('strong', { text: '메일 설정이 비어 있어 발송할 수 없습니다: ' }),
            el('span', { text: problems.join(', ') }),
            el('div', { className: 'zz-banner-actions' }, [btn('메일 설정으로 이동', function () { showView('mailsettings'); })])
        ]);
    }

    // ── 메일 발송 ─────────────────────────────────────────
    function viewMail(root) {
        var box = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(box);
        api('api/admin_auto_send.php').then(function (st) {
            box.textContent = '';
            box.className = '';
            if (!st.ok) { box.appendChild(el('p', { className: 'form-message error', text: st.error })); return; }
            var ready = st.problems.length === 0;
            var pb = problemsBox(st.problems);
            if (pb) box.appendChild(pb);
            if (!st.base_url_set) {
                box.appendChild(el('div', { className: 'zz-banner info' }, [
                    el('span', { text: 'config.php 의 BASE_URL 이 비어 있습니다. 수동 발송은 지금 접속한 주소로 원문 링크를 만들지만, 매일 07:00 정기 발송(스케줄러)에는 BASE_URL 이 필요합니다.' })
                ]));
            }
            box.appendChild(autoSendCard(st, ready));
            box.appendChild(manualSendCard(st, ready));
        }).catch(ignoreAuth);
    }

    function autoSendCard(st, ready) {
        var card = el('section', { className: 'admin-card' });
        card.appendChild(el('h3', { text: '자동 발송(매일 ' + st.send_time + ')' }));
        var state = el('p', null, [
            st.enabled ? badge('켜짐', 'ok') : badge('꺼짐', 'danger'),
            el('span', { className: 'muted', text: '  마지막 변경: ' + (st.changed_at || '변경한 적 없음(기본: 켜짐)') + (st.enabled && st.resume_from ? ' · ' + st.resume_from + ' 이후 등록분부터 발송' : '') })
        ]);
        card.appendChild(state);
        if (!st.enabled) {
            card.appendChild(el('div', { className: 'zz-banner danger', role: 'alert', text: '자동 발송이 꺼져 있습니다. 다시 켤 때까지 매일 ' + st.send_time + ' 정기 메일이 발송되지 않습니다. (수동 발송·테스트 메일은 됩니다)' }));
            var include = el('input', { type: 'radio', name: 'resume', value: 'include' });
            var fromNow = el('input', { type: 'radio', name: 'resume', value: 'from_now', checked: true });
            card.appendChild(el('fieldset', { className: 'admin-radio' }, [
                el('legend', { text: '다시 켤 때 보낼 범위' }),
                el('label', null, [fromNow, ' 켠 시점(오늘 0시) 이후 등록분부터 발송']),
                el('label', null, [include, ' 꺼져 있던 기간의 찌라시도 함께 발송'])
            ]));
            var on = btn('자동 발송 켜기', function () {
                on.disabled = true;
                api('api/admin_auto_send.php', { enabled: '1', resume: include.checked ? 'include' : 'from_now' }).then(function (r) {
                    message(r.ok ? r.message : r.error, r.ok ? 'ok' : 'error');
                    showView('mail');
                }).catch(ignoreAuth);
            });
            on.className = 'zz-btn';
            on.disabled = !ready;
            if (!ready) on.title = '메일 설정을 먼저 완료하세요';
            card.appendChild(el('div', { className: 'admin-card-actions' }, [on]));
        } else {
            var off = btn('자동 발송 끄기', function () {
                confirmBox('자동 발송을 끌까요?', '꺼져 있는 동안 정기 메일이 발송되지 않습니다. 다시 켤 때 꺼져 있던 기간을 포함할지 고를 수 있습니다.', '끄기').then(function (yes) {
                    if (!yes) return;
                    api('api/admin_auto_send.php', { enabled: '0' }).then(function (r) {
                        message(r.ok ? r.message : r.error, r.ok ? 'ok' : 'error');
                        showView('mail');
                    }).catch(ignoreAuth);
                });
            });
            off.className = 'zz-btn';
            card.appendChild(el('p', { className: 'muted small', text: '전날 0시~24시 등록분을 매일 ' + st.send_time + '에 보냅니다. 서버가 꺼져 있었다면 ' + st.deadline + '까지 보충 발송하고, 그때도 못 보내면 다음 날 메일에 합쳐 보냅니다.' }));
            card.appendChild(el('div', { className: 'admin-card-actions' }, [off]));
        }
        return card;
    }

    function manualSendCard(st, ready) {
        var card = el('section', { className: 'admin-card' });
        card.appendChild(el('h3', { text: '수동 발송' }));
        card.appendChild(el('p', { className: 'muted small', text: '정기 발송과 무관하게 지금 바로 보냅니다(내용이 겹쳐도 됩니다). 수신 대상: 수신(active) 멤버 ' + st.recipients + '명' }));
        var rNew = el('input', { type: 'radio', name: 'send-range', value: 'new', checked: true });
        var rToday = el('input', { type: 'radio', name: 'send-range', value: 'today' });
        card.appendChild(el('fieldset', { className: 'admin-radio' }, [
            el('legend', { text: '보낼 범위' }),
            el('label', null, [rNew, ' 직전 발송 이후 신규 등록 건']),
            el('label', null, [rToday, ' 당일 전체 등록 건'])
        ]));
        var preview = el('div', { className: 'send-preview' });
        var sendBtn = btn('발송', function () { doSend(); });
        sendBtn.className = 'zz-btn';
        sendBtn.disabled = true;
        var previewBtn = btn('미리보기', function () { loadPreview(); });
        previewBtn.className = 'zz-btn';
        previewBtn.disabled = !ready;
        card.appendChild(el('div', { className: 'admin-card-actions' }, [previewBtn, sendBtn]));
        card.appendChild(preview);
        var last = null;
        [rNew, rToday].forEach(function (r) { r.addEventListener('change', function () { sendBtn.disabled = true; preview.textContent = ''; }); });

        function range() { return rToday.checked ? 'today' : 'new'; }
        function loadPreview() {
            previewBtn.disabled = true;
            api('api/admin_send_preview.php?range=' + range()).then(function (p) {
                previewBtn.disabled = false;
                preview.textContent = '';
                if (!p.ok) { preview.appendChild(el('p', { className: 'form-message error', text: p.error })); return; }
                last = p;
                preview.appendChild(el('p', null, [el('strong', { text: '수신자 ' + p.recipients + '명 · 찌라시 ' + p.posts.length + '건' }),
                    el('span', { className: 'muted', text: '  (' + p.basis + ': ' + p.from + ' ~ ' + p.to + ')' })]));
                preview.appendChild(table(['등록 일시', '제목', '요약'], p.posts.map(function (x) {
                    return el('tr', null, [el('td', { className: 'nowrap', text: x.created_at.slice(0, 16) }), el('td', { text: x.title }), el('td', { className: 'muted', text: x.summary })]);
                }), '보낼 찌라시가 없습니다. 이대로 보내면 메일 없이 "건너뜀"으로 기록됩니다.'));
                sendBtn.disabled = !ready || p.recipients === 0;
                if (p.recipients === 0) preview.appendChild(el('p', { className: 'form-message error', text: '수신(active) 멤버가 없습니다. 멤버 메뉴에서 먼저 추가하세요.' }));
            }).catch(ignoreAuth);
        }
        function doSend() {
            if (!last) return;
            confirmBox('수신자 ' + last.recipients + '명에게 찌라시 ' + last.posts.length + '건을 보낼까요?', '50명씩 숨은 참조(BCC)로 나눠 보냅니다. 보내는 동안 이 화면을 닫지 마세요.', '발송').then(function (yes) {
                if (!yes) return;
                sendBtn.disabled = previewBtn.disabled = true; // 중복 클릭 방지
                sendBtn.textContent = '보내는 중…';
                api('api/admin_send.php', { range: range() }).then(function (r) {
                    sendBtn.textContent = '발송';
                    previewBtn.disabled = false;
                    preview.textContent = '';
                    if (!r.ok) { preview.appendChild(el('p', { className: 'form-message error', text: r.error })); return; }
                    var kind = { success: 'ok', failed: 'error', skipped: '' }[r.status];
                    preview.appendChild(el('p', { className: 'form-message ' + kind, text: { success: '발송 완료: ', failed: '일부 실패: ', skipped: '건너뜀: ' }[r.status] + r.message }));
                    if (r.hint) preview.appendChild(el('p', { className: 'mail-hint', text: '→ ' + r.hint.text }));
                    if (r.batches.length) {
                        preview.appendChild(table(['묶음', '수신자', '시도', '결과', '오류'], r.batches.map(function (b) {
                            return el('tr', null, [el('td', { text: String(b.batch_no) }), el('td', { text: b.recipient_count + '명' }), el('td', { text: b.attempts + '회' }),
                                el('td', null, [b.status === 'success' ? badge('성공', 'ok') : badge('실패', 'danger')]), errorCell(b.error, b.hint)]);
                        }), ''));
                    }
                    preview.appendChild(el('div', { className: 'admin-card-actions' }, [btn('발송 이력 보기', function () { showView('history'); })]));
                    last = null;
                }).catch(function (e) { sendBtn.textContent = '발송'; ignoreAuth(e); });
            });
        }
        return card;
    }

    // ── 발송 이력 ─────────────────────────────────────────
    var historyPage = 1;
    var SEND_STATUS = { success: ['성공', 'ok'], failed: ['실패', 'danger'], skipped: ['건너뜀', 'muted'], running: ['진행 중', 'warn'] };

    function viewHistory(root, id) {
        if (id) { viewHistoryDetail(root, id); return; }
        var list = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(list);
        function load() {
            api('api/admin_send_log.php?page=' + historyPage).then(function (d) {
                list.textContent = '';
                list.className = '';
                if (!d.ok) { list.appendChild(el('p', { className: 'form-message error', text: d.error })); return; }
                list.appendChild(el('p', { className: 'list-caption', text: '총 ' + d.total + '건(최신순)' }));
                list.appendChild(table(['발송 일시', '구분', '포함 기간', '수신자', '찌라시', '상태', '묶음', '오류·사유', ''], d.items.map(function (l) {
                    var s = SEND_STATUS[l.status] || [l.status, 'muted'];
                    return el('tr', null, [
                        el('td', { className: 'nowrap', text: l.sent_at.slice(0, 16) }),
                        el('td', { className: 'nowrap', text: l.trigger_type === 'scheduled' ? '자동' : '수동' }),
                        el('td', { className: 'nowrap small', text: (l.range_from || '').slice(0, 16) + ' ~ ' + (l.range_to || '').slice(0, 16) }),
                        el('td', { text: (l.recipient_count || 0) + '명' }),
                        el('td', { text: l.item_count + '건' }),
                        el('td', null, [badge(s[0], s[1])]),
                        el('td', { className: 'nowrap', text: l.batch_count ? (l.batch_count - l.batch_failed) + '/' + l.batch_count + ' 성공' : '-' }),
                        el('td', { className: 'small pre', text: l.error_message || '' }),
                        el('td', { className: 'actions' }, [btn('상세', function () { showView('history', l.id); })])
                    ]);
                }), '발송 이력이 없습니다.'));
                var pg = pager(d.page, d.pages, function (n) { historyPage = n; load(); });
                if (pg) list.appendChild(pg);
            }).catch(ignoreAuth);
        }
        load();
    }

    function viewHistoryDetail(root, id) {
        root.appendChild(el('div', { className: 'detail-actions' }, [btn('← 발송 이력', function () { showView('history'); })]));
        var box = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(box);
        api('api/admin_send_log.php?id=' + encodeURIComponent(id)).then(function (d) {
            box.textContent = '';
            box.className = '';
            if (!d.ok) { box.appendChild(el('p', { className: 'form-message error', text: d.error })); return; }
            var l = d.log, s = SEND_STATUS[l.status] || [l.status, 'muted'];
            box.appendChild(el('section', { className: 'admin-card' }, [
                el('h3', null, [el('span', { text: (l.trigger_type === 'scheduled' ? '자동' : '수동') + ' 발송 #' + l.id + ' ' }), badge(s[0], s[1])]),
                el('p', { text: '발송 일시 ' + l.sent_at + ' · 포함 기간 ' + (l.range_from || '') + ' ~ ' + (l.range_to || '') + ' · 수신자 ' + (l.recipient_count || 0) + '명' }),
                l.error_message ? el('p', { className: 'form-message error pre', text: l.error_message }) : null
            ]));
            box.appendChild(el('h3', { className: 'admin-subtitle', text: '묶음별 결과(BCC ' + d.batches.length + '통)' }));
            box.appendChild(table(['묶음', '수신자', '시도', '결과', '오류'], d.batches.map(function (b) {
                return el('tr', null, [el('td', { text: String(b.batch_no) }), el('td', { text: b.recipient_count + '명' }), el('td', { text: b.attempts + '회' }),
                    el('td', null, [b.status === 'success' ? badge('성공', 'ok') : badge('실패', 'danger')]), errorCell(b.error_message, b.hint)]);
            }), '보낸 묶음이 없습니다(건너뜀 또는 발송 전 중단).'));
            box.appendChild(el('h3', { className: 'admin-subtitle', text: '포함된 찌라시 ' + d.items.length + '건' }));
            box.appendChild(table(['등록 일시', '제목', '상태'], d.items.map(function (p) {
                return el('tr', null, [el('td', { className: 'nowrap', text: p.created_at.slice(0, 16) }), el('td', { text: p.title }),
                    el('td', null, [p.status === 'active' ? badge('게시 중', 'ok') : badge('삭제됨', 'danger')])]);
            }), '포함된 찌라시가 없습니다.'));
        }).catch(ignoreAuth);
    }

    // ── 메일 설정 ─────────────────────────────────────────
    /** 오류 칸: 원문 + 원인별 안내(5c) */
    function errorCell(text, hint) {
        return el('td', { className: 'small pre' }, [text || '', hint ? el('div', { className: 'mail-hint', text: '→ ' + hint.text }) : null]);
    }

    function viewMailSettings(root) {
        var box = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(box);
        api('api/admin_mail_settings.php').then(function (d) {
            box.textContent = '';
            box.className = '';
            if (!d.ok) { box.appendChild(el('p', { className: 'form-message error', text: d.error })); return; }
            render(d);
        }).catch(ignoreAuth);

        function field(label, input, hint) {
            return el('label', { className: 'admin-field' }, [el('span', { className: 'label', text: label }), input, hint ? el('small', { className: 'muted', text: hint }) : null]);
        }
        function check(label, input, extra) {
            return el('label', { className: 'admin-check' }, [input, ' ' + label, extra || null]);
        }
        function render(d) {
            var s = d.settings;
            box.textContent = '';
            var status = el('div');
            if (d.problems.length) status.appendChild(el('div', { className: 'zz-banner warn', role: 'alert', text: '발송할 수 없는 상태입니다. 부족한 항목: ' + d.problems.join(', ') }));
            else status.appendChild(el('div', { className: 'zz-banner ok', text: '메일 설정 완료 — 발송할 수 있습니다. 아래 "테스트 메일 보내기"로 실제 도착을 확인하세요.' }));
            box.appendChild(status);
            if (s.allow_self_signed) box.appendChild(el('div', { className: 'zz-banner danger', text: '주의: 자체 서명 인증서 허용이 켜져 있어 메일 서버 인증서를 검증하지 않습니다' + (s.config_self_signed ? '(config.php 의 SMTP_ALLOW_SELF_SIGNED)' : '') + '. 사내망 서버에만 쓰세요.' }));

            // 서버·발신 주소
            var from = el('input', { type: 'email', value: s.mail_from, maxlength: '254', autocomplete: 'off', placeholder: '예: zzira@회사도메인' });
            var name = el('input', { type: 'text', value: s.mail_from_name, maxlength: '100', autocomplete: 'off', placeholder: 'ZZieRa' });
            var host = el('input', { type: 'text', value: s.smtp_host, maxlength: '253', autocomplete: 'off', spellcheck: 'false' });
            var port = el('input', { type: 'text', inputmode: 'numeric', value: s.smtp_port, maxlength: '5', autocomplete: 'off' });
            var secure = el('select', null, [el('option', { value: '', text: '선택하세요' }), el('option', { value: 'none', text: '없음(암호화 안 함)' }),
                el('option', { value: 'tls', text: 'TLS(STARTTLS)' }), el('option', { value: 'ssl', text: 'SSL(SMTPS)' })]);
            secure.value = s.smtp_secure || '';

            // SMTP 인증(5c)
            var auth = el('input', { type: 'checkbox', id: 'smtp-auth', checked: s.smtp_auth });
            var user = el('input', { type: 'text', value: s.smtp_user, maxlength: '254', autocomplete: 'off', spellcheck: 'false', 'aria-label': 'SMTP 인증 ID' });
            var pass = el('input', { type: 'password', maxlength: '256', autocomplete: 'new-password', 'aria-label': 'SMTP 인증 비밀번호',
                placeholder: s.pass_set ? '설정됨 — 바꿀 때만 입력' : '비밀번호' });
            var reveal = el('button', { type: 'button', className: 'zz-btn', text: '보기', 'aria-pressed': 'false', title: '방금 입력한 값만 보입니다(저장된 비밀번호는 보여 주지 않습니다)' });
            reveal.addEventListener('click', function () {
                var show = pass.type === 'password';
                pass.type = show ? 'text' : 'password';
                reveal.textContent = show ? '숨기기' : '보기';
                reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
            var passClear = el('input', { type: 'checkbox' });
            var passState = s.pass_unreadable
                ? el('p', { className: 'form-message error small', text: '저장된 비밀번호를 읽을 수 없습니다(암호화 키가 바뀌었거나 없음). 다시 입력하세요.' })
                : el('p', { className: 'muted small', text: '비밀번호: ' + (s.pass_set ? '설정됨' : '없음') + ' · 비워 두고 저장하면 기존 값을 그대로 둡니다.' });
            var sameFrom = el('p', { className: 'muted small', text: '발신 주소는 로그인 계정과 같아야 하는 서버가 많습니다(Gmail·네이버 등).' });
            var authBox = el('div', { className: 'auth-fields' }, [
                field('인증 ID', user, 'Gmail 은 전체 주소, 네이버는 아이디만(또는 전체 주소)'),
                el('label', { className: 'admin-field' }, [el('span', { className: 'label', text: '인증 비밀번호' }), el('div', { className: 'inline-edit' }, [pass, reveal])]),
                passState,
                s.pass_set ? check('비밀번호 지우기', passClear) : null,
                sameFrom
            ]);
            function syncAuth() {
                user.disabled = pass.disabled = reveal.disabled = !auth.checked;
                authBox.classList.toggle('is-off', !auth.checked);
                sameFrom.hidden = !auth.checked;
            }
            auth.addEventListener('change', syncAuth);

            // 고급
            var selfSigned = el('input', { type: 'checkbox', checked: s.smtp_allow_self_signed });
            var selfWarn = el('span', { className: 'form-message error small', text: '  보안이 약해집니다. 사내 서버에만 사용하세요.' });
            selfWarn.hidden = !selfSigned.checked;
            selfSigned.addEventListener('change', function () { selfWarn.hidden = !selfSigned.checked; });
            var timeout = el('input', { type: 'text', inputmode: 'numeric', value: s.smtp_timeout, maxlength: '3', autocomplete: 'off', placeholder: String(s.timeout_default) });

            var form = el('form', { className: 'admin-card admin-form', novalidate: true }, [
                el('h3', { text: '메일 서버·발신 주소' }),
                field('발신 주소 *', from, '실제 사서함이 아니어도 되지만 메일 서버가 허용하는 형식이어야 합니다.'),
                field('발신자 이름', name, '비워 두면 "ZZieRa"로 표시됩니다.'),
                field('메일 서버 주소 *', host, 'SMTP 서버의 호스트 이름이나 IP 주소'),
                field('포트 *', port, '보통 25(암호화 없음) / 587(TLS) / 465(SSL)'),
                field('보안 *', secure, ''),
                el('h3', { className: 'admin-subtitle', text: 'SMTP 인증' }),
                s.config_auth_in_use ? el('div', { className: 'zz-banner info small', text: '현재 config.php 값을 사용 중입니다. 화면에서 "SMTP 인증 사용"을 켜고 저장하면 화면 값이 우선합니다.' }) : null,
                check('SMTP 인증 사용', auth, el('small', { className: 'muted', text: '  (서버가 로그인을 요구할 때. 사내 릴레이는 보통 끔)' })),
                authBox,
                el('p', { className: 'muted small', text: '비밀번호는 암호화해 DB 에 저장하고 화면·응답·로그에는 내보내지 않습니다. 암호화 키(data/app.key)와 DB 가 같은 서버에 있으므로 DB 파일만 유출되는 경우를 막는 수준입니다.' }),
                s.encrypt_unavailable ? el('p', { className: 'form-message error small', text: '지금은 비밀번호를 저장할 수 없습니다: ' + s.encrypt_unavailable }) : null,
                el('h3', { className: 'admin-subtitle', text: '고급' }),
                check('자체 서명 인증서 허용', selfSigned, selfWarn),
                field('연결 제한시간(초)', timeout, '비워 두면 ' + s.timeout_default + '초. 5~120'),
                el('details', { className: 'mail-help' }, [
                    el('summary', { text: '자주 쓰는 설정 예(값을 채우지는 않습니다)' }),
                    el('ul', null, [
                        el('li', { text: 'Gmail: smtp.gmail.com, 587/TLS 또는 465/SSL, 인증 사용, 2단계 인증 후 발급한 앱 비밀번호' }),
                        el('li', { text: '네이버: smtp.naver.com, 587/TLS 또는 465/SSL, 인증 사용, 네이버 메일 설정에서 IMAP/SMTP 사용 설정 필요, ID는 아이디만(또는 전체 주소)' }),
                        el('li', { text: '사내 릴레이: 보통 25/없음, 인증 없음' })
                    ])
                ]),
                el('p', { className: 'muted small', text: s.updated_at ? '마지막 저장: ' + s.updated_at : '아직 저장한 적이 없습니다.' }),
                el('div', { className: 'admin-card-actions' }, [
                    el('button', { type: 'submit', className: 'zz-btn', text: '저장' }),
                    btn('설정 지우기', function () {
                        confirmBox('메일 설정을 지울까요?', '인증 ID·비밀번호까지 모두 지웁니다. 다시 설정할 때까지 수동·정기 메일을 보낼 수 없습니다.', '지우기').then(function (yes) {
                            if (!yes) return;
                            api('api/admin_mail_settings.php', { action: 'clear' }).then(function (r) {
                                message(r.ok ? r.message : r.error, r.ok ? 'ok' : 'error');
                                if (r.ok) render({ settings: r.settings, problems: r.problems });
                            }).catch(ignoreAuth);
                        });
                    })
                ]),
                el('p', { className: 'form-message pre', id: 'mail-settings-msg', role: 'alert' })
            ]);
            syncAuth();
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                api('api/admin_mail_settings.php', {
                    action: 'save', mail_from: from.value, mail_from_name: name.value, smtp_host: host.value, smtp_port: port.value, smtp_secure: secure.value,
                    smtp_auth: auth.checked ? '1' : '0', smtp_user: user.value, smtp_pass: auth.checked ? pass.value : '',
                    smtp_pass_clear: passClear.checked ? '1' : '0', smtp_allow_self_signed: selfSigned.checked ? '1' : '0', smtp_timeout: timeout.value
                }).then(function (r) {
                    pass.value = ''; // 입력한 비밀번호는 화면에 남기지 않는다
                    var m = form.querySelector('#mail-settings-msg');
                    if (!r.ok) { m.textContent = r.error; m.className = 'form-message pre error'; return; }
                    message(r.message, 'ok');
                    render({ settings: r.settings, problems: r.problems });
                }).catch(ignoreAuth);
            });
            box.appendChild(form);

            // 테스트 메일
            var to = el('input', { type: 'email', maxlength: '254', placeholder: '받을 주소(본인 메일)', autocomplete: 'off' });
            var result = el('div', { className: 'form-message pre', role: 'status' });
            var send = el('button', { type: 'submit', className: 'zz-btn', text: '테스트 메일 보내기' });
            var test = el('form', { className: 'admin-card admin-form', novalidate: true }, [
                el('h3', { text: '테스트 메일' }),
                el('p', { className: 'muted small', text: '입력한 주소 한 곳으로 한글 제목·본문 샘플을 보내 현재 설정을 점검합니다(발송 이력에는 남지 않음). 실패하면 메일 서버가 돌려준 오류와 원인별 안내를 보여 줍니다.' }),
                el('div', { className: 'inline-edit' }, [to, send]),
                result
            ]);
            test.addEventListener('submit', function (e) {
                e.preventDefault();
                send.disabled = true;
                send.textContent = '보내는 중…';
                result.textContent = '';
                api('api/admin_test_mail.php', { email: to.value.trim() }).then(function (r) {
                    send.disabled = false;
                    send.textContent = '테스트 메일 보내기';
                    result.className = 'form-message pre ' + (r.ok ? 'ok' : 'error');
                    result.textContent = r.ok ? r.message : r.error;
                    if (!r.ok && r.hint) {
                        result.appendChild(el('div', { className: 'mail-hint', text: '→ ' + r.hint.text }));
                        if (r.hint.focus === 'smtp_auth') {
                            result.appendChild(el('button', { type: 'button', className: 'zz-btn zz-btn-sm', text: '"SMTP 인증 사용"으로 이동', onclick: function () {
                                auth.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                auth.focus();
                            } }));
                        }
                    }
                }).catch(function (err) { send.disabled = false; send.textContent = '테스트 메일 보내기'; ignoreAuth(err); });
            });
            box.appendChild(test);
        }
    }

    // ── 감사 로그 ─────────────────────────────────────────
    function viewAudit(root) {
        var list = el('div', { className: 'muted', text: '불러오는 중…' });
        root.appendChild(el('p', { className: 'admin-note', text: '최근 100건(읽기 전용). 등록자 식별 정보는 기록하지 않습니다.' }));
        root.appendChild(list);
        api('api/admin_audit.php').then(function (data) {
            list.textContent = '';
            list.className = '';
            if (!data.ok) { list.appendChild(el('p', { className: 'form-message error', text: data.error })); return; }
            list.appendChild(table(['시각', '이벤트', '대상', '내용'], data.items.map(function (a) {
                return el('tr', null, [
                    el('td', { className: 'nowrap', text: a.created_at }),
                    el('td', { className: 'nowrap', text: a.event }),
                    el('td', { className: 'nowrap', text: (a.target_type || '') + (a.target_id ? ' #' + a.target_id : '') }),
                    el('td', { text: a.detail || '' })
                ]);
            }), '기록이 없습니다.'));
        }).catch(ignoreAuth);
    }

    // 상세에서 "목록으로"가 돌아갈 곳
    var origShowView = showView;
    showView = function (view, arg) {
        if (view === 'posts' || view === 'deleted') state.lastListView = view;
        origShowView(view, arg);
    };

    // ── 시작 ──────────────────────────────────────────────
    // 관리 탭으로 가려 하면(탭 클릭, 주소의 #admin) 먼저 인증을 받는다. 취소하면 지금 탭에 그대로 있는다.
    Z.guardTab = function (name, proceed) {
        if (name !== 'admin' || state.authed) return false;
        requestAdmin(proceed);
        return true;
    };
    // 관리 탭이 열릴 때마다 관리 화면을 다시 그린다(다른 관리자가 바꿨을 수 있으므로)
    document.addEventListener('zz:tab', function (e) {
        if (e.detail === 'admin' && state.authed) showPanel();
    });
    // #admin 주소로 들어온 경우: 조회 탭을 보여 둔 채 비밀번호 창을 띄운다
    // 로그인 화면의 "관리자 로그인"에서 넘어온 경우(5b): 넘겨받은 토큰을 한 번만 꺼내 쓰고 지운다
    var handoff = null;
    try { handoff = sessionStorage.getItem('zzAdminHandoff'); sessionStorage.removeItem('zzAdminHandoff'); } catch (x) { handoff = null; }
    if (Z.pendingTab === 'admin') {
        Z.pendingTab = null;
        if (handoff && /^[0-9a-f]{32}$/.test(handoff)) {
            Z.requestJson('api/admin_status.php', { headers: { 'X-Admin-Token': handoff } }).then(function (s) {
                if (s.ok && s.logged_in) {
                    state.authed = true;
                    state.token = handoff;
                    setPassDefault(s.password_default);
                    startCountdown(s.session_remaining);
                    Z.goTab('admin');
                } else {
                    requestAdmin(function () { Z.goTab('admin'); });
                }
            });
        } else {
            requestAdmin(function () { Z.goTab('admin'); });
        }
    }
})();
