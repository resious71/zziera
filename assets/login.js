/*
 * 로그인 화면(login.php)
 * - 사용자 로그인: api/user_login.php → 성공하면 서버가 검증한 redirect 로 이동. 잠금이면 남은 시간을 센다.
 * - 관리자 로그인: api/admin_login.php(4단계 로직) → 받은 토큰을 sessionStorage 에 잠깐 두고 index.php#admin 으로 이동.
 *   index.php 의 admin.js 가 토큰을 한 번 꺼내 쓰고 바로 지운다(새로고침하면 다시 비밀번호를 입력해야 한다).
 * - 비밀번호는 POST 본문으로만 보낸다(URL·로그에 남지 않게).
 */
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }
    var lockTimer = null;

    function post(url, form) {
        return fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin' }).then(function (res) {
            return res.json().catch(function () { return { ok: false, error: '서버 응답을 처리할 수 없습니다(HTTP ' + res.status + ').' }; });
        }).catch(function () { return { ok: false, error: '서버에 연결할 수 없습니다. 네트워크 상태를 확인하세요.' }; });
    }
    function showError(el, text) { el.textContent = text || ''; el.hidden = !text; }
    function fmt(sec) { var m = Math.floor(sec / 60), s = sec % 60; return (m ? m + '분 ' : '') + s + '초'; }

    /** 잠금 카운트다운: 버튼을 막고 남은 시간을 보여 준다 */
    function lockCountdown(errEl, btn, sec) {
        clearInterval(lockTimer);
        var left = sec;
        function tick() {
            if (left <= 0) {
                clearInterval(lockTimer);
                btn.disabled = false;
                showError(errEl, '잠금이 풀렸습니다. 다시 시도하세요.');
                return;
            }
            btn.disabled = true;
            showError(errEl, '로그인이 잠겨 있습니다. ' + fmt(left) + ' 후에 다시 시도하세요.');
            left -= 1;
        }
        tick();
        lockTimer = setInterval(tick, 1000);
    }

    var form = $('login-form');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = $('login-submit'), err = $('login-error');
        if (!$('login-id').value.trim() || !$('login-password').value) { showError(err, 'ID와 비밀번호를 입력하세요.'); return; }
        btn.disabled = true;
        post('api/user_login.php', form).then(function (r) {
            if (r.ok) { location.replace(r.redirect || 'index.php'); return; }
            btn.disabled = false;
            $('login-password').value = '';
            if (r.lock_remaining > 0) lockCountdown(err, btn, r.lock_remaining);
            else showError(err, r.error || '로그인하지 못했습니다.');
            $('login-password').focus();
        });
    });

    var toggle = $('admin-login-toggle'), adminForm = $('admin-login-form');
    toggle.addEventListener('click', function () {
        adminForm.hidden = !adminForm.hidden;
        toggle.setAttribute('aria-expanded', adminForm.hidden ? 'false' : 'true');
        if (!adminForm.hidden) $('admin-password').focus();
    });
    adminForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = $('admin-login-submit'), err = $('admin-login-error');
        if (!$('admin-password').value.trim()) { showError(err, '관리자 비밀번호를 입력하세요.'); return; }
        btn.disabled = true;
        post('api/admin_login.php', adminForm).then(function (r) {
            if (r.ok && r.token) {
                try { sessionStorage.setItem('zzAdminHandoff', r.token); } catch (x) { /* 저장 불가하면 관리 탭에서 다시 입력 */ }
                location.replace('index.php#admin');
                return;
            }
            btn.disabled = false;
            $('admin-password').value = '';
            if (r.lock_remaining > 0) lockCountdown(err, btn, r.lock_remaining);
            else showError(err, r.error || '인증하지 못했습니다.');
            $('admin-password').focus();
        });
    });
})();
