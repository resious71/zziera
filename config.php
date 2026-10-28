<?php
/**
 * ZZieRa 설정 파일
 *
 * - 이 파일은 설정 배열을 return 할 뿐 아무것도 출력하지 않는다.
 * - 웹에서 직접 요청되면 403으로 끝낸다(루트 .htaccess 에서도 차단).
 * - 값을 바꾼 뒤에는 install_check.php 로 점검한다.
 */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit;
}

return [
    // ── 일반 ──────────────────────────────────────────────
    'APP_NAME'             => 'ZZieRa',
    'DEBUG'                => false,          // true 면 PHP 오류를 화면에 표시(개발용)
    'SESSION_NAME'         => 'ZZIRASESSID',

    // ── 메일(PHPMailer SMTP 전용) ─────────────────────────
    // 발신 주소·발신자 이름·메일 서버·포트·보안은 여기 두지 않는다(기본값 없음).
    // 관리 탭 "메일 설정" 화면에서 입력하며 DB(app_settings)에 저장된다.
    'BASE_URL'             => '',                     // 메일 속 원문 링크의 기준 주소(예: http://서버/zzira). 정기 발송(CLI)에는 필수
    'MAIL_TO_PLACEHOLDER'  => '',             // BCC 묶음 메일의 To. 비어 있으면 발신 주소 사용
    'MAIL_BATCH_SIZE'      => 50,             // 묶음당 BCC 수신자 수
    'MAIL_BATCH_DELAY_SEC' => 2,              // 묶음 사이 대기(초)
    'MAIL_RETRY_MAX'       => 3,              // 실패한 묶음의 최대 시도 횟수
    'MAIL_RETRY_DELAY_SEC' => 3,              // 재시도 사이 대기(초)
    'MAIL_FOOTER_NOTICE'   => '수신 중지·주소 변경은 관리자에게 요청하세요.', // 메일 하단 안내(관리자 연락처를 덧붙여도 됨)
    'SMTP_USER'            => '',             // 선택: 메일 서버가 로그인을 요구할 때만(비우면 인증 없는 사내 릴레이)
    'SMTP_PASS'            => '',             // 선택: SMTP_USER 의 비밀번호(화면·DB에는 저장하지 않는다)
    'SMTP_TIMEOUT'         => 20,             // 메일 서버 연결 제한시간(초)
    'SMTP_ALLOW_SELF_SIGNED' => false,        // true: 사내 서버의 자체 서명 인증서 허용(설정 화면에 경고 표시)
    'SEND_STALE_MINUTES'   => 30,             // 이 시간 넘게 running 으로 남은 발송 기록은 비정상 종료로 보고 failed 처리
    'SEND_FAILED_RETRY_MAX' => 2,             // 정기 발송이 failed 일 때 같은 날 12:00 전까지 시도할 최대 횟수(첫 시도 포함)

    // ── 상단 고정 ─────────────────────────────────────────
    'PIN_MAX_COUNT'        => 5,              // 동시에 고정할 수 있는 찌라시 수

    // ── 등록 제한 ─────────────────────────────────────────
    'POST_RATE_PER_MINUTE' => 3,              // 세션당 분당 등록 수
    'POST_RATE_PER_HOUR'   => 20,             // 세션당 시간당 등록 수
    'TITLE_MAX'            => 100,            // 제목 글자 수

    // ── 본문(WYSIWYG 에디터) 제한 ─────────────────────────
    'BODY_TEXT_MAX'        => 10000,          // 본문 일반 텍스트 글자 수(표 제외)
    'BODY_HTML_MAX'        => 204800,         // 정화 후 HTML 바이트(200KB)
    'INLINE_IMAGE_MAX'     => 5,              // 본문 이미지 수
    'BODY_LINK_MAX'        => 10,             // 본문 링크 수
    'LINK_URL_MAX'         => 500,            // 링크 URL 길이
    'TABLE_ROWS_MAX'       => 20,
    'TABLE_COLS_MAX'       => 10,
    'TABLE_CELL_MAX'       => 200,            // 셀 텍스트 글자 수
    'IMAGE_UPLOAD_PER_MIN' => 10,             // 세션당 분당 에디터 이미지 업로드 수

    // ── 요약(TextRank) ────────────────────────────────────
    'SUMMARY_SENTENCES'     => 2,             // 4문장 이상일 때 고르는 문장 수
    'SUMMARY_MAX_SENTENCES' => 300,           // 긴 본문은 앞쪽 N문장만 분석(시간·메모리 상한)
    'SUMMARY_KEYWORDS'      => 10,            // 문장 가중치에 쓰는 상위 키워드 수

    // ── 업로드 제한 ───────────────────────────────────────
    'IMAGE_MAX_BYTES'      => 5 * 1024 * 1024,
    'ATTACH_MAX_COUNT'     => 5,
    'ATTACH_MAX_BYTES'     => 10 * 1024 * 1024,
    'IMAGE_EXTENSIONS'     => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'ATTACH_EXTENSIONS'    => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'hwp', 'hwpx', 'zip', 'txt', 'csv'],
    // 어떤 경우에도 받지 않는 확장자(이중 확장자의 중간 부분까지 검사)
    'BLOCKED_EXTENSIONS'   => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht',
        'exe', 'com', 'scr', 'msi', 'dll', 'bat', 'cmd', 'sh', 'bash', 'ps1', 'psm1',
        'js', 'mjs', 'vbs', 'vbe', 'wsf', 'wsh', 'hta', 'jar', 'cgi', 'pl', 'py', 'rb',
        'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'html', 'htm', 'shtml', 'svg', 'lnk', 'reg',
    ],

    // ── 관리자 ────────────────────────────────────────────
    'LOGIN_MAX_FAILS'      => 5,              // 연속 실패 허용 횟수
    'LOGIN_LOCK_SEC'       => 300,            // 잠금 시간(초)
    'ADMIN_SESSION_TTL'    => 3600,           // 마지막 활동 후 유휴 만료(초). 관리 작업마다 연장

    // ── 댓글·추천(5d) ─────────────────────────────────────
    'COMMENT_MAX_PER_POST'  => 20,            // 글당 댓글 최대 수(답글 포함, 관리자 삭제분 제외)
    'COMMENT_MAX_LEN'       => 500,           // 댓글·답글 글자 수(1~N)
    'COMMENT_RATE_PER_MINUTE' => 3,           // 세션당 분당 댓글 등록 수
    'COMMENT_RATE_PER_HOUR' => 20,            // 세션당 시간당 댓글 등록 수

    // ── 스크랩(5f): 구글 뉴스 RSS 로 주제별 기사 자동 등록 ─────
    'SCRAP_PROXY'          => '',            // 사내 프록시가 필요할 때만(예: http://proxy.company:8080). 화면·응답에 내보내지 않음
    'SCRAP_MAX_TOPICS'     => 15,            // 선택할 수 있는 주제 합계(제안 + 직접 입력)
    'SCRAP_MAX_PER_RUN'    => 30,            // 한 번 실행에서 등록할 수 있는 전체 글 수
    'SCRAP_MAX_AGE_HOURS'  => 36,            // 발행 후 이 시간 이내 기사만
    'SCRAP_TIMEOUT_SEC'    => 10,            // RSS 요청당 제한시간(초)

    // ── 사용자 로그인(5b단계: 사이트 접근 제한) ──────────────
    'USER_SESSION_IDLE_SEC' => 28800,         // 마지막 활동 후 유휴 만료(초, 8시간). 요청마다 연장
    'USER_LOGIN_MAX_FAILS'  => 5,             // ID별 연속 실패 허용 횟수
    'USER_LOGIN_LOCK_SEC'   => 300,           // ID별 잠금 시간(초)

    // ── 발송·보관 ─────────────────────────────────────────
    'SEND_TIME'            => '07:00',        // 정기 발송 시각(고정)
    'SEND_DEADLINE'        => '12:00',        // 보충 발송 시한
    'POST_RETENTION_DAYS'  => 180,            // 찌라시 보관 일수(등록 후, 기본 6개월). 지나면 정리 배치가 완전 삭제(상단 고정 글 제외). 관리 탭 "정리"에서 바꾸면 그 값이 우선(최대 3년)
    'FILE_RETENTION_DAYS'  => 180,            // 본문 이미지·첨부 보관 일수(등록 후, 기본 6개월). 관리 탭 "정리"에서 바꾸면 그 값이 우선(최대 3년). 삭제 글 복구 기간(DELETED_RETENTION_DAYS)과는 다른 값
    'DELETED_RETENTION_DAYS' => 3,            // 삭제 글 복구 가능 일수
];
