<?php
/**
 * 화면 공통 구성요소 (헤더 타이틀·파비콘)
 *
 * 타이틀 아이콘은 assets/thought-balloon.png(배포 폴더 상위 icons 폴더에서 복사해 둔 파일)를 쓴다.
 * 파일이 없으면 <img> 를 아예 출력하지 않아 깨진 이미지 아이콘이 보이지 않게 한다.
 */
final class Layout
{
    private const ICON = 'assets/thought-balloon.png';

    private static function hasIcon(): bool
    {
        return is_file(APP_ROOT . '/' . self::ICON);
    }

    /** 헤더 타이틀: [아이콘] ZZieRa */
    public static function brand(): string
    {
        $name = Html::e((string) Config::get('APP_NAME', 'ZZieRa'));
        $icon = self::hasIcon() ? '<img src="' . self::ICON . '" alt="" class="zz-logo">' : '';
        return '<a class="brand" href="./">' . $icon . '<span>' . $name . '</span></a>';
    }

    /**
     * 헤더 오른쪽 로그아웃 버튼(5b). 스크립트 없이 동작하도록 POST 폼으로 만든다.
     * 사용자 이름은 표시하지 않는다(익명성, 화면 캡처 노출 방지).
     */
    public static function logoutForm(): string
    {
        return '<form class="logout-form" method="post" action="api/user_logout.php">'
            . '<input type="hidden" name="csrf_token" value="' . Html::e(Csrf::token()) . '">'
            . '<input type="hidden" name="redirect" value="1">'
            . '<button type="submit" class="zz-btn zz-btn-sm" id="logout-btn">로그아웃</button></form>';
    }

    /** 화면 하단: 이용 안내 링크(로그인한 화면 공통) */
    public static function footer(): string
    {
        return '<footer class="site-footer"><div class="container"><a href="guide.php">이용 안내</a></div></footer>';
    }

    /**
     * CSS·JS 주소에 파일 수정 시각을 붙인다(assets/app.js?v=1760000000).
     * 파일이 바뀌면 주소가 달라져 브라우저가 예전 캐시 대신 새 파일을 받는다.
     */
    public static function asset(string $path): string
    {
        $mtime = @filemtime(APP_ROOT . '/' . $path);
        return Html::e($path . ($mtime ? '?v=' . $mtime : ''));
    }

    /** 브라우저 탭 아이콘 */
    public static function favicon(): string
    {
        // cli/make_favicon.php 로 만든 파일이 있으면 그것을, 없으면 원본 아이콘을 쓴다
        $out = '';
        if (is_file(APP_ROOT . '/assets/favicon.ico')) {
            $out .= '<link rel="icon" href="assets/favicon.ico" sizes="any">';
        }
        if (is_file(APP_ROOT . '/assets/favicon-32.png')) {
            $out .= '<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">';
        } elseif (self::hasIcon()) {
            $out .= '<link rel="icon" type="image/png" href="' . self::ICON . '">';
        }
        if (is_file(APP_ROOT . '/assets/apple-touch-icon.png')) {
            $out .= '<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">';
        }
        return $out;
    }
}
