/*
 * 찌라시 상세의 추천·댓글 (5d)
 *
 *   ZZComments.mount(container, { postId, csrf, adminToken: function () { return 토큰 또는 null } })
 *
 * - post.php(단독 상세), index.php 의 상세 보기(app.js), 관리 탭 상세(admin.js)가 함께 쓴다.
 * - 댓글에는 작성자·번호·구분 표시가 없다. 화면에는 내용과 yy/mm/dd HH:MM 만 보인다.
 * - "답글"은 남이 쓴 일반 댓글에만(서버 mine=false), 답글·삭제 표시 댓글에는 없다. 서버가 같은 규칙을 다시 검사한다.
 * - 관리자 삭제 버튼은 이 화면이 유효한 관리자 토큰을 가진 경우(서버 is_admin)에만 보인다.
 * - 사용자 입력은 textContent 로만 넣는다(줄바꿈만 <br> 요소로). innerHTML 을 쓰지 않는다.
 * - 로그인 만료(게이트 401)는 공통 처리(ZZ.loginRequired, 없으면 로그인 화면 이동)를 따른다.
 * - 이모티콘: 입력란의 "😀 이모티콘"으로 감정별 이모티콘을 골라 커서 위치에 넣는다(본문은 글자 그대로 저장).
 *   댓글마다 "공감"으로 같은 목록에서 하나를 골라 남긴다(한 사람당 댓글 하나에 하나, 같은 것을 다시 누르면 취소).
 *   목록은 서버(comment_list 의 emoji_sets, src/Emoji.php)에서 받는다. 공감을 바꿀 때는 그 댓글의 공감 줄만 다시 그려
 *   작성 중인 댓글 내용이 지워지지 않게 한다.
 */
(function () {
    'use strict';

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'className') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v === true ? '' : v);
        });
        (children || []).forEach(function (c) { if (c !== null && c !== undefined) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return node;
    }

    /** 줄바꿈을 <br> 요소로 바꾼 텍스트 노드들(HTML 해석 없음) */
    function textWithBreaks(text) {
        var frag = document.createDocumentFragment();
        String(text).split('\n').forEach(function (line, i) {
            if (i > 0) frag.appendChild(document.createElement('br'));
            frag.appendChild(document.createTextNode(line));
        });
        return frag;
    }

    function shortTime(dt) {
        dt = dt || '';
        return dt.length >= 16 ? dt.slice(2, 4) + '/' + dt.slice(5, 7) + '/' + dt.slice(8, 10) + ' ' + dt.slice(11, 16) : dt;
    }

    function goLogin() {
        if (window.ZZ && window.ZZ.loginRequired) { window.ZZ.loginRequired(); return; }
        var page = location.pathname.split('/').pop() || 'index.php';
        location.href = 'login.php?expired=1&next=' + encodeURIComponent(page + location.search);
    }

    function request(url, opts, adminToken) {
        opts = opts || {};
        var headers = {};
        var token = adminToken ? adminToken() : null;
        if (token) headers['X-Admin-Token'] = token;
        return fetch(url, { method: opts.method || 'GET', body: opts.body, headers: headers, credentials: 'same-origin' }).then(function (res) {
            return res.text().then(function (t) {
                var d = null;
                try { d = JSON.parse(t); } catch (e) { /* JSON 아님 */ }
                d = d || { ok: false, error: '서버 응답을 처리할 수 없습니다(HTTP ' + res.status + ').' };
                d.httpStatus = res.status;
                if (res.status === 401 && d.error === 'login_required') { d.error = d.message || '로그인이 필요합니다.'; goLogin(); }
                return d;
            });
        }).catch(function () { return { ok: false, error: '서버에 연결할 수 없습니다. 네트워크 상태를 확인하세요.' }; });
    }

    /** 확인 창(#confirm-dialog 가 있는 화면에서만 관리자 삭제를 쓴다) */
    function confirmDialog(text, hint) {
        var d = document.getElementById('confirm-dialog');
        if (!d || !d.showModal) return Promise.resolve(false);
        document.getElementById('confirm-message').textContent = text;
        document.getElementById('confirm-hint').textContent = hint || '';
        document.getElementById('confirm-yes').textContent = '삭제';
        d.showModal();
        return new Promise(function (resolve) {
            function done(v) { d.removeEventListener('click', onClick); d.removeEventListener('cancel', onCancel); d.close(); resolve(v); }
            function onClick(e) { var b = e.target.closest('[data-confirm]'); if (b) done(b.getAttribute('data-confirm') === 'yes'); }
            function onCancel(e) { e.preventDefault(); done(false); }
            d.addEventListener('click', onClick);
            d.addEventListener('cancel', onCancel);
        });
    }

    /**
     * 감정별 이모티콘 고르기 판. onPick(emoji) 를 부른다. current 는 강조할 이모티콘(내 공감).
     * Esc 를 누르면 onClose 를 부른다.
     */
    function emojiPicker(sets, onPick, onClose, current, label) {
        var box = el('div', { className: 'emoji-picker', role: 'group', 'aria-label': label });
        (sets || []).forEach(function (set) {
            box.appendChild(el('div', { className: 'emoji-set-label', text: set.label }));
            box.appendChild(el('div', { className: 'emoji-set' }, set.items.map(function (e) {
                return el('button', { type: 'button', className: 'zz-btn zz-btn-sm emoji-opt', text: e, title: set.label,
                    'aria-label': e + ' (' + set.label + ')', 'aria-pressed': current === e ? 'true' : null,
                    onclick: function () { onPick(e); } });
            })));
        });
        box.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { ev.stopPropagation(); onClose(); } });
        return box;
    }

    function mount(container, opts) {
        var postId = String(opts.postId);
        var csrf = opts.csrf;
        var adminToken = opts.adminToken || null;
        var state = { data: null, openReply: null, busy: false };
        container.textContent = '';
        container.classList.add('post-social');

        var voteBtn = el('button', { type: 'button', className: 'zz-btn vote-btn', 'aria-pressed': 'false', text: '추천' });
        var voteMsg = el('span', { className: 'muted small', role: 'status' });
        var title = el('h2', { className: 'comment-title', text: '댓글' });
        var notice = el('p', { className: 'comment-full', role: 'status', hidden: true });
        var list = el('div', { className: 'comment-list' }, [el('p', { className: 'muted', text: '불러오는 중…' })]);
        var mainForm = null;
        container.appendChild(el('div', { className: 'vote-row' }, [voteBtn, voteMsg]));
        container.appendChild(title);
        container.appendChild(notice);
        container.appendChild(list);

        voteBtn.addEventListener('click', function () {
            if (voteBtn.disabled) return;
            voteBtn.disabled = true; // 연타 방지
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('post_id', postId);
            request('api/vote_toggle.php', { method: 'POST', body: fd }, adminToken).then(function (r) {
                if (r.ok) {
                    state.data.vote.my_vote = r.voted;
                    state.data.vote.vote_count = r.vote_count;
                    voteMsg.textContent = '';
                } else {
                    voteMsg.textContent = r.error;
                }
                renderVote();
            });
        });

        function full() { return state.data && state.data.count >= state.data.max; }

        function renderVote() {
            var v = state.data.vote;
            voteBtn.textContent = (v.my_vote ? '추천 취소 ' : '추천 ') + v.vote_count;
            voteBtn.setAttribute('aria-pressed', v.my_vote ? 'true' : 'false');
            voteBtn.disabled = !v.can_vote;
            voteBtn.title = v.can_vote ? '' : '추천은 사용자 로그인이 필요합니다';
        }

        /** 댓글 입력 폼(일반 댓글 또는 답글) */
        function form(parentId) {
            var max = state.data.max_len;
            var ta = el('textarea', { rows: parentId ? '2' : '3', maxlength: String(max), placeholder: parentId ? '답글 내용' : '댓글 내용', 'aria-label': parentId ? '답글 내용' : '댓글 내용' });
            var count = el('span', { className: 'muted small comment-len', text: '0/' + max });
            var hp = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', className: 'hp', 'aria-hidden': 'true' });
            var submit = el('button', { type: 'submit', className: 'zz-btn', text: parentId ? '답글 등록' : '댓글 등록' });
            var msg = el('p', { className: 'form-message', role: 'alert' });
            var picker = null;
            var emojiBtn = el('button', { type: 'button', className: 'zz-btn emoji-toggle', 'aria-expanded': 'false', text: '😀 이모티콘' });
            function closePicker() { if (picker) { picker.remove(); picker = null; } emojiBtn.setAttribute('aria-expanded', 'false'); }
            emojiBtn.addEventListener('click', function () {
                if (picker) { closePicker(); ta.focus(); return; }
                picker = emojiPicker(state.data.emoji_sets, function (e) {
                    if (ta.value.length + e.length > max) { msg.textContent = '댓글은 ' + max + '자까지 쓸 수 있습니다.'; msg.className = 'form-message error'; return; }
                    var s = ta.selectionStart, en = ta.selectionEnd;
                    ta.setRangeText(e, s, en, 'end');
                    ta.dispatchEvent(new Event('input'));
                    ta.focus();
                }, function () { closePicker(); emojiBtn.focus(); }, null, '댓글에 넣을 이모티콘');
                emojiBtn.setAttribute('aria-expanded', 'true');
                f.insertBefore(picker, msg);
            });
            var actions = [count, emojiBtn, submit];
            if (parentId) actions.splice(1, 0, el('button', { type: 'button', className: 'zz-btn', text: '취소', onclick: function () { state.openReply = null; render(); } }));
            var f = el('form', { className: 'comment-form' + (parentId ? ' reply-form' : ''), novalidate: true }, [ta, hp, el('div', { className: 'comment-form-actions' }, actions), msg]);
            ta.addEventListener('input', function () { count.textContent = ta.value.length + '/' + max; });
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                if (state.busy) return;
                if (!ta.value.trim()) { msg.textContent = '내용을 입력하세요.'; msg.className = 'form-message error'; ta.focus(); return; }
                state.busy = true;
                submit.disabled = true;
                var fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('post_id', postId);
                fd.append('body', ta.value);
                fd.append('website', hp.value);
                if (parentId) fd.append('parent_id', String(parentId));
                request('api/comment_create.php', { method: 'POST', body: fd }, adminToken).then(function (r) {
                    state.busy = false;
                    submit.disabled = false;
                    if (!r.ok) { msg.textContent = r.error; msg.className = 'form-message error'; return; }
                    ta.value = '';
                    closePicker();
                    state.openReply = null;
                    load(); // 서버 순서대로 다시 그린다(댓글은 맨 아래, 답글은 부모 아래)
                });
            });
            if (full()) { ta.disabled = true; submit.disabled = true; emojiBtn.disabled = true; }
            return f;
        }

        function item(c, isReply) {
            var li = el('li', { className: 'comment' + (isReply ? ' reply' : '') + (c.deleted ? ' is-deleted' : '') });
            if (c.mine && !isReply) li.title = '내가 쓴 댓글에는 답글을 달 수 없습니다';
            li.appendChild(c.deleted
                ? el('p', { className: 'comment-body muted', text: '삭제된 댓글입니다' })
                : el('p', { className: 'comment-body' }, [textWithBreaks(c.body)]));
            var actions = el('span', { className: 'comment-actions' });
            if (!isReply && !c.deleted && !c.mine && !full()) {
                actions.appendChild(el('button', { type: 'button', className: 'zz-btn zz-btn-sm', text: '답글', onclick: function () {
                    state.openReply = state.openReply === c.id ? null : c.id;
                    render();
                    var t = container.querySelector('.reply-form textarea');
                    if (t) t.focus();
                } }));
            }
            if (state.data.is_admin && !c.deleted) {
                actions.appendChild(el('button', { type: 'button', className: 'zz-btn zz-btn-sm', text: '삭제', onclick: function () { adminDelete(c); } }));
            }
            li.appendChild(el('div', { className: 'comment-meta' }, [el('span', { className: 'muted small', text: shortTime(c.created_at) }), actions]));
            if (!c.deleted) li.appendChild(reactionRow(c));
            if (!isReply) {
                if (state.openReply === c.id) li.appendChild(form(c.id));
                if (c.replies && c.replies.length) {
                    li.appendChild(el('ul', { className: 'reply-list' }, c.replies.map(function (r) { return item(r, true); })));
                }
            }
            return li;
        }

        /** 댓글 공감 줄: 이모티콘별 개수(누르면 그 이모티콘으로 공감/취소) + "공감"(이모티콘 고르기) */
        function reactionRow(c) {
            var can = !!state.data.can_react;
            var why = can ? '' : (state.data.is_admin ? '관리자 화면에서는 공감할 수 없습니다' : '공감은 사용자 로그인이 필요합니다');
            var row = el('div', { className: 'reaction-row' });
            (c.reactions || []).forEach(function (r) {
                var mine = c.my_reaction === r.emoji;
                row.appendChild(el('button', { type: 'button', className: 'zz-btn zz-btn-sm reaction-chip' + (mine ? ' is-mine' : ''),
                    'aria-pressed': mine ? 'true' : 'false', disabled: !can, title: can ? (mine ? '내 공감 취소' : '이 이모티콘으로 공감') : why,
                    'aria-label': r.emoji + ' 공감 ' + r.count + '명' + (mine ? ' (내 공감)' : ''),
                    onclick: function () { react(c, r.emoji, row); } }, [
                    el('span', { className: 'reaction-emoji', text: r.emoji }), el('span', { className: 'reaction-count', text: String(r.count) })
                ]));
            });
            var picker = null;
            var add = el('button', { type: 'button', className: 'zz-btn zz-btn-sm reaction-add', disabled: !can, title: can ? '이모티콘으로 공감' : why,
                'aria-expanded': 'false', text: c.my_reaction ? '공감 바꾸기' : '😊 공감' });
            function closePicker() { if (picker) { picker.remove(); picker = null; } add.setAttribute('aria-expanded', 'false'); }
            add.addEventListener('click', function () {
                if (picker) { closePicker(); return; }
                picker = emojiPicker(state.data.emoji_sets, function (e) { closePicker(); react(c, e, row); },
                    function () { closePicker(); add.focus(); }, c.my_reaction, '공감할 이모티콘');
                add.setAttribute('aria-expanded', 'true');
                row.after(picker);
                var first = picker.querySelector('.emoji-opt');
                if (first) first.focus();
            });
            row.appendChild(add);
            row.appendChild(el('span', { className: 'reaction-msg muted small', role: 'status' }));
            return row;
        }

        function react(c, emoji, row) {
            if (state.reacting) return;
            state.reacting = true;
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('comment_id', String(c.id));
            fd.append('emoji', emoji);
            request('api/comment_react.php', { method: 'POST', body: fd }, adminToken).then(function (r) {
                state.reacting = false;
                if (!r.ok) { var m = row.querySelector('.reaction-msg'); if (m) m.textContent = r.error; return; }
                c.reactions = r.reactions;
                c.my_reaction = r.my_reaction;
                row.replaceWith(reactionRow(c)); // 이 댓글의 공감 줄만 다시 그린다(작성 중인 내용 유지)
            });
        }

        function adminDelete(c) {
            confirmDialog('이 댓글을 삭제할까요?', '복구할 수 없습니다. 답글이 달린 댓글은 "삭제된 댓글입니다"로 표시되고 답글은 남습니다.').then(function (yes) {
                if (!yes) return;
                var fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('id', String(c.id));
                request('api/admin_comment_delete.php', { method: 'POST', body: fd }, adminToken).then(function (r) {
                    if (!r.ok) { notice.hidden = false; notice.textContent = r.error; return; }
                    load();
                });
            });
        }

        function render() {
            var d = state.data;
            title.textContent = '댓글 ' + d.count + '/' + d.max;
            notice.hidden = !full();
            notice.textContent = full() ? '댓글은 글당 최대 ' + d.max + '개까지 달 수 있습니다.' : '';
            list.textContent = '';
            if (!d.comments.length) list.appendChild(el('p', { className: 'muted', text: '아직 댓글이 없습니다.' }));
            else list.appendChild(el('ul', { className: 'comments' }, d.comments.map(function (c) { return item(c, false); })));
            if (mainForm) mainForm.remove();
            if (d.active) {
                mainForm = el('div', { className: 'comment-new' }, [
                    form(null),
                    el('p', { className: 'muted small', text: '댓글은 수정할 수 없고 삭제는 관리자에게 요청하세요. 답글은 남의 댓글에만 한 단계로 달 수 있습니다. 댓글에는 작성자가 표시되지 않습니다.' })
                ]);
                container.appendChild(mainForm);
            }
            renderVote();
        }

        function load() {
            return request('api/comment_list.php?post_id=' + encodeURIComponent(postId), null, adminToken).then(function (r) {
                if (!r.ok) { list.textContent = ''; list.appendChild(el('p', { className: 'form-message error', text: r.error })); voteBtn.disabled = true; return; }
                state.data = r;
                render();
            });
        }
        load();
        return { reload: load };
    }

    window.ZZComments = { mount: mount };

    // post.php: data-post-id 가 있는 자리에 바로 붙인다
    document.addEventListener('DOMContentLoaded', function () {
        var box = document.getElementById('post-social');
        if (box && box.getAttribute('data-post-id')) {
            mount(box, { postId: box.getAttribute('data-post-id'), csrf: box.getAttribute('data-csrf') });
        }
    });
})();
