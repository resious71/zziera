/*
 * 뒤로 가기 캐시(bfcache) 방지(5b): 로그아웃한 뒤 뒤로 가기로 돌아오면 저장된 화면 대신 서버에 다시 요청한다
 * → 게이트가 로그인 화면으로 보낸다. 서버도 Cache-Control: no-store 를 보낸다.
 */
window.addEventListener('pageshow', function (e) {
    if (e.persisted) location.reload();
});
