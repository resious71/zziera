<?php
/**
 * 이용 안내(PRD 11장 사용자 가이드)와 관리자 가이드(PRD 12장) 본문
 *
 * 문구는 화면에 실제로 보이는 탭·메뉴·버튼 이름과 맞춘다. 제한값은 config.php 에서 읽어 숫자가 설정과 어긋나지 않게 한다.
 * 출력은 서버가 만든 고정 HTML 이다(사용자 입력 없음). 설정 문자열은 Html::e 로 이스케이프한다.
 *  - userHtml()  : guide.php (로그인한 사용자)
 *  - adminHtml() : api/admin_guide.php (관리자 인증 필요) → 관리 탭 "관리자 가이드"
 */
final class Guide
{
    private static function mb(int $bytes): string
    {
        return Upload::formatBytes($bytes);
    }

    private static function idleText(int $sec): string
    {
        return $sec % 3600 === 0 ? ($sec / 3600) . '시간' : (intdiv($sec, 60) . '분');
    }

    public static function userHtml(): string
    {
        $c = static fn (string $k, int $d = 0) => Config::int($k, $d);
        $title = $c('TITLE_MAX', 100);
        $text = number_format($c('BODY_TEXT_MAX', 10000));
        $links = $c('BODY_LINK_MAX', 10);
        $imgs = $c('INLINE_IMAGE_MAX', 5);
        $imgSize = self::mb($c('IMAGE_MAX_BYTES', 5242880));
        $rows = $c('TABLE_ROWS_MAX', 20);
        $cols = $c('TABLE_COLS_MAX', 10);
        $atts = $c('ATTACH_MAX_COUNT', 5);
        $attSize = self::mb($c('ATTACH_MAX_BYTES', 10485760));
        $perMin = $c('POST_RATE_PER_MINUTE', 3);
        $perHour = $c('POST_RATE_PER_HOUR', 20);
        $cmax = Comments::maxLen();
        $cnum = Comments::maxPerPost();
        $crm = $c('COMMENT_RATE_PER_MINUTE', 3);
        $crh = $c('COMMENT_RATE_PER_HOUR', 20);
        $keep = Html::e(Retention::fileRetentionText());
        $postKeep = Html::e(Retention::postRetentionText());
        $fails = $c('USER_LOGIN_MAX_FAILS', 5);
        $lock = intdiv($c('USER_LOGIN_LOCK_SEC', 300), 60);
        $idle = self::idleText($c('USER_SESSION_IDLE_SEC', 28800));
        $attExt = Html::e(implode(', ', (array) Config::get('ATTACH_EXTENSIONS', [])));
        $imgExt = Html::e(implode(', ', (array) Config::get('IMAGE_EXTENSIONS', [])));
        $sendTime = Html::e((string) Config::get('SEND_TIME', '07:00'));
        $deadline = Html::e((string) Config::get('SEND_DEADLINE', '12:00'));

        return <<<HTML
<h2 id="g-view">1. 로그인하고 찌라시 보기</h2>
<ol>
  <li>주소로 접속하면 <strong>로그인</strong> 화면이 나옵니다. 관리자가 알려 준 ID와 비밀번호를 입력하고 <strong>로그인</strong>을 누르면 <strong>찌라시 보기</strong> 탭이 열립니다. 메일의 링크로 접속해도 로그인한 뒤 그 글로 바로 이동합니다.
    <ul>
      <li>계정이 없거나 비밀번호를 잊었다면 관리자에게 요청하세요. 직접 가입하거나 비밀번호를 바꾸는 기능은 없습니다.</li>
      <li>비밀번호를 {$fails}번 연속 틀리면 {$lock}분 동안 로그인할 수 없습니다. {$idle} 동안 사용하지 않으면 자동으로 로그아웃되며, 사용을 마치면 오른쪽 위 <strong>로그아웃</strong>을 누르세요.</li>
      <li>로그인해도 어떤 찌라시를 누가 썼는지는 기록되지 않습니다.</li>
    </ul>
  </li>
  <li>목록 맨 위의 <strong>📌 고정된 찌라시</strong>는 관리자가 중요하다고 고정한 글입니다. 날짜를 바꿔도 항상 맨 위에 보입니다.</li>
  <li>날짜 칸이나 <strong>◀</strong>·<strong>▶</strong>·<strong>오늘</strong> 버튼으로 날짜를 고르면 그날 등록된 찌라시가 나옵니다. 제목, 등록 시각, 요약, 이미지 썸네일이 표시됩니다.
    <ul>
      <li><strong>제목·본문 검색</strong> 칸에 낱말을 넣고 <strong>검색</strong>을 누르면 그날의 글에서 찾고, <strong>전체 기간</strong>을 체크하면 모든 날짜에서 찾습니다.</li>
      <li><strong>이미지</strong>·<strong>표</strong>·<strong>링크</strong>·<strong>첨부</strong>를 체크하면 그런 내용이 있는 글만 봅니다.</li>
    </ul>
  </li>
  <li>글을 누르면 등록할 때 작성한 모양 그대로 본문(글, 이미지, 표, 링크)과 첨부파일을 볼 수 있습니다. <strong>새 창으로 보기</strong>로 따로 열 수도 있습니다.
    <ul>
      <li>본문 위와 아래의 <strong>← 이전 글</strong>·<strong>다음 글 →</strong>을 누르면 목록으로 돌아가지 않고 목록에서 바로 위·아래 글로 넘어갑니다(가운데 숫자는 목록에서의 위치). 목록의 처음·마지막 글이면 버튼이 꺼집니다.</li>
      <li>메일 링크로 연 글에서는 날짜와 관계없이 전체 글의 최신순으로 이어집니다.</li>
    </ul>
  </li>
  <li>찌라시는 등록 {$postKeep}이 지나면 댓글·추천과 함께 자동으로 삭제됩니다(관리자가 상단에 고정한 글은 제외).</li>
  <li>본문 이미지와 첨부파일은 등록 {$keep} 후 삭제됩니다. 삭제된 이미지 자리에는 “이미지가 만료되었습니다”, 첨부에는 “첨부가 만료되었습니다”라고 표시되고 글과 표, 링크는 그대로 남습니다. 필요한 파일은 그 안에 내려받으세요.</li>
  <li>제목 앞에 <span class="badge badge-scrap">스크랩</span> 배지가 붙은 글은 관리자가 정한 주제로 인터넷 뉴스에서 자동 등록된 글입니다. 제목·요약·원문 링크만 담겨 있으니 자세한 내용은 <strong>원문 보기</strong>로 확인하세요. 추천·댓글은 일반 글과 같고, 불필요한 글은 관리자에게 삭제를 요청하세요.</li>
</ol>

<h2 id="g-write">2. 찌라시 등록</h2>
<ol>
  <li><strong>찌라시 등록</strong> 탭을 엽니다.</li>
  <li><strong>제목</strong>({$title}자 이내)을 입력하고, 본문 에디터에 내용을 작성합니다(글자 {$text}자 이내). 에디터 화면은 조회 화면과 같은 모양입니다.</li>
  <li>에디터 도구 모음으로 서식을 적용합니다: <strong>B</strong>(굵게), <strong>I</strong>(기울임), <strong>U</strong>(밑줄), <strong>제목</strong>, <strong>소제목</strong>, <strong>• 목록</strong>, <strong>1. 목록</strong>, <strong>❝ 인용</strong>, <strong>― 구분선</strong>. 버튼에 마우스를 올리면 이름과 단축키가 보입니다. 필요한 곳에 다음을 넣습니다.
    <ul>
      <li><strong>링크</strong>(최대 {$links}개, http·https 주소만) — 링크를 없애려면 <strong>링크 해제</strong></li>
      <li><strong>이미지</strong>(최대 {$imgs}장, 장당 {$imgSize}, {$imgExt}) — 버튼으로 파일을 고르거나, 붙여넣기·끌어놓기도 됩니다</li>
      <li><strong>표</strong>(최대 {$rows}행 × {$cols}열) — 표 안에서 <strong>행+</strong>(행 추가)·<strong>행−</strong>(행 삭제)·<strong>열+</strong>(열 추가)·<strong>열−</strong>(열 삭제)·<strong>표 삭제</strong></li>
      <li>잘못 적용한 서식은 <strong>서식 지우기</strong>, 되돌리기는 <strong>↶</strong>(실행 취소, Ctrl+Z)·<strong>↷</strong>(다시 실행, Ctrl+Y)</li>
    </ul>
  </li>
  <li>첨부파일(최대 {$atts}개, 파일당 {$attSize}, {$attExt})은 에디터 아래 <strong>첨부파일 추가</strong>로 넣습니다.</li>
  <li><strong>미리보기</strong>로 실제로 보일 모양을 확인한 뒤 <strong>등록</strong>을 누르면 “찌라시가 등록되었습니다”가 표시되고 바로 조회 화면에 게시됩니다.</li>
</ol>
<div class="guide-box">
  <p><strong>한 번 등록한 찌라시는 수정할 수 없습니다.</strong> 등록 전에 <strong>미리보기</strong>로 내용을 확인하세요. 잘못 올렸다면 관리자에게 제목과 등록 시각을 알려 삭제를 요청한 뒤 다시 등록하세요.</p>
</div>
<ul>
  <li>이름, 이메일, IP 등 등록자를 알 수 있는 정보는 저장되지 않습니다.</li>
  <li>승인 없이 즉시 공개되며, 부적절한 글은 관리자가 나중에 삭제할 수 있습니다.</li>
  <li>짧은 시간에 연속 등록하면 제한에 걸릴 수 있습니다(분당 {$perMin}건, 시간당 {$perHour}건). 잠시 후 다시 시도하세요.</li>
  <li>실행 파일(exe, bat, sh, php 등)은 올릴 수 없습니다.</li>
  <li>본문 글이 4문장 이상이면 핵심 2문장이 자동으로 요약되어 목록과 메일에 표시됩니다(표와 이미지는 요약에 포함되지 않습니다). 3문장 이하이면 앞부분이 그대로 요약이 됩니다.</li>
  <li>다른 문서에서 복사해 붙이면 서식이 단순하게 정리될 수 있습니다. 붙여넣은 외부 이미지는 유지되지 않으니 이미지는 도구 모음의 <strong>이미지</strong>로 올려 주세요. 웹페이지의 링크는 그대로 유지됩니다.</li>
  <li>주소창·메신저 등에서 복사한 <code>http://</code>·<code>https://</code> 주소를 붙여넣으면 자동으로 링크가 됩니다. 글자를 선택한 채 주소를 붙여넣으면 선택한 글자가 그 주소의 링크가 됩니다(본문 링크는 최대 {$links}개).</li>
  <li>작성하는 동안 로그인이 만료되면 확인 창이 뜹니다. <strong>머무르기(복사하기)</strong>를 눌러 내용을 복사해 둔 뒤 다시 로그인하세요.</li>
</ul>

<h2 id="g-mail">3. 메일 받기와 수신 중지</h2>
<ul>
  <li>메일링 리스트의 가입, 주소 변경, 수신 중지는 모두 <strong>관리자</strong>가 처리합니다. 받고 싶거나 그만 받고 싶으면 관리자에게 알려 주세요.</li>
  <li>매일 오전 {$sendTime}에 전날 등록된 찌라시의 제목, 요약, 원문 링크가 메일로 옵니다. 링크를 누르면 로그인한 뒤 전체 내용을 볼 수 있습니다.</li>
  <li>서버 점검 등으로 늦어지면 같은 날 {$deadline}까지 보내지고, 그때까지 못 보내면 다음 날 메일에 함께 담깁니다.</li>
  <li>메일은 숨은 참조로 발송되어 다른 수신자의 주소가 보이지 않습니다.</li>
  <li>수신 중지를 요청하면 관리자가 처리한 다음 발송부터 메일이 오지 않습니다. 다시 받고 싶을 때도 관리자에게 요청하세요.</li>
</ul>

<h2 id="g-social">4. 추천과 댓글</h2>
<ol>
  <li>찌라시 상세 아래의 <strong>추천</strong> 버튼을 누르면 추천됩니다. 한 글에 한 번만 추천할 수 있고, 다시 누르면(<strong>추천 취소</strong>) 취소됩니다.</li>
  <li>댓글은 입력창에 {$cmax}자 이내로 쓰고 <strong>댓글 등록</strong>을 누릅니다. 한 글에는 답글을 포함해 최대 {$cnum}개까지 달 수 있습니다.</li>
  <li>다른 사람의 댓글 아래 <strong>답글</strong> 버튼으로 답글을 달고 <strong>답글 등록</strong>을 누릅니다. 답글에는 다시 답글을 달 수 없고, <strong>내가 쓴 댓글에는 답글을 달 수 없습니다</strong>.</li>
  <li>입력창 아래 <strong>😀 이모티콘</strong>을 누르면 동의·좋아요, 반대·거부, 웃음·기쁨, 화남·짜증, 슬픔·걱정, 놀람·당황, 생각·의심, 응원·기타로 나뉜 이모티콘이 나옵니다. 누르면 커서 위치에 들어갑니다(이모티콘도 글자 수에 포함).</li>
  <li>댓글에 공감하려면 그 댓글 아래 <strong>😊 공감</strong>을 누르고 같은 목록에서 이모티콘을 고릅니다. 한 댓글에는 한 사람이 이모티콘 하나만 남길 수 있고, 다른 것을 고르면(<strong>공감 바꾸기</strong>) 바뀝니다. 댓글 아래의 <strong>😂 3</strong> 같은 버튼은 이모티콘별 공감 수이며, 누르면 그 이모티콘으로 공감하고 내 공감을 다시 누르면 취소됩니다.</li>
</ol>
<ul>
  <li>댓글과 답글은 등록 후 <strong>수정할 수 없고</strong> 직접 삭제할 수도 없습니다. 잘못 올렸다면 관리자에게 삭제를 요청하세요.</li>
  <li>댓글에는 작성자가 표시되지 않으며 누가 썼는지 구분되지 않습니다. 추천하거나 공감한 사람도 알 수 없습니다.</li>
  <li>짧은 시간에 연속으로 달면 제한에 걸릴 수 있습니다(분당 {$crm}건, 시간당 {$crh}건).</li>
</ul>

<h2 id="g-faq">5. 자주 묻는 질문</h2>
<dl class="guide-faq">
  <dt>올린 글을 고칠 수 있나요?</dt><dd>없습니다. 한 번 등록한 찌라시는 수정할 수 없습니다. 관리자에게 삭제를 요청하고 새로 등록하세요.</dd>
  <dt>로그인 ID와 비밀번호는 어떻게 받나요?</dt><dd>관리자가 등록해 알려 줍니다. 분실·변경도 관리자에게 요청하세요.</dd>
  <dt>로그인하면 내가 쓴 글이 나인 줄 알 수 있나요?</dt><dd>아니요. 로그인은 접근 자격 확인용이며 찌라시에는 작성자 정보가 저장되지 않습니다.</dd>
  <dt>올린 글을 직접 지울 수 있나요?</dt><dd>없습니다. 등록자를 식별하지 않으므로 삭제는 관리자만 합니다. 삭제를 원하면 관리자에게 제목과 등록 시각을 알려 주세요.</dd>
  <dt>글은 언제까지 보이나요?</dt><dd>찌라시는 등록 후 {$postKeep} 동안 보관되고, 그 뒤에는 제목·본문·댓글·추천까지 모두 자동으로 삭제됩니다(상단 고정 글 제외). 본문 이미지와 첨부파일은 그보다 먼저 등록 {$keep} 후 사라질 수 있습니다. 보관 기간은 관리자가 정합니다.</dd>
  <dt>등록했는데 메일에 안 들어가요</dt><dd>정기 메일은 전날 등록분이 다음 날 오전 {$sendTime}에 발송됩니다. 관리자가 수동 발송을 하면 그 전에 받을 수도 있습니다.</dd>
  <dt>댓글을 고치거나 지울 수 있나요?</dt><dd>없습니다. 댓글은 수정할 수 없고 삭제는 관리자만 합니다. 관리자에게 글 제목과 댓글 내용을 알려 주세요.</dd>
  <dt>내 댓글에 답글이 안 달려요</dt><dd>자신이 쓴 댓글에는 답글을 달 수 없습니다. 답글에는 답글을 달 수도 없고(1단계만), 글당 댓글이 {$cnum}개에 도달하면 더 달 수 없습니다.</dd>
  <dt>“스크랩” 글은 뭔가요?</dt><dd>관리자가 정한 주제의 뉴스를 자동으로 찾아 등록한 글입니다. 제목·요약·원문 링크만 있습니다. 부적절하거나 불필요하면 관리자에게 삭제를 요청하세요.</dd>
  <dt>댓글이나 추천에 내 이름이 남나요?</dt><dd>아니요. 댓글에는 작성자 표시가 없고, 추천도 누가 했는지 기록·표시되지 않습니다.</dd>
  <dt>메일이 중복으로 왔어요</dt><dd>관리자의 수동 발송과 정기 발송의 내용이 겹칠 수 있습니다. 정상 동작입니다.</dd>
  <dt>메일이 스팸함에 있어요</dt><dd>발신 주소를 주소록에 추가하세요.</dd>
  <dt>메일을 그만 받고 싶어요 / 주소를 바꾸고 싶어요</dt><dd>관리자에게 요청하세요. 직접 해지하는 페이지는 없습니다.</dd>
</dl>
HTML;
    }

    public static function adminHtml(): string
    {
        $c = static fn (string $k, int $d = 0) => Config::int($k, $d);
        $adminFails = $c('LOGIN_MAX_FAILS', 5);
        $adminLock = intdiv($c('LOGIN_LOCK_SEC', 300), 60);
        $adminIdle = self::idleText($c('ADMIN_SESSION_TTL', 3600));
        $deletedDays = $c('DELETED_RETENTION_DAYS', 3);
        $keep = Html::e(Retention::fileRetentionText());
        $postKeep = Html::e(Retention::postRetentionText());
        $pinMax = $c('PIN_MAX_COUNT', 5);
        $batch = $c('MAIL_BATCH_SIZE', 50);
        $retry = $c('MAIL_RETRY_MAX', 3);
        $sendTime = Html::e((string) Config::get('SEND_TIME', '07:00'));
        $deadline = Html::e((string) Config::get('SEND_DEADLINE', '12:00'));
        $userFails = $c('USER_LOGIN_MAX_FAILS', 5);
        $userLock = intdiv($c('USER_LOGIN_LOCK_SEC', 300), 60);
        $example = Time::nowObj()->format('Ymd');
        $folder = Html::e(basename(APP_ROOT));
        $baseUrl = Html::e((string) Config::get('BASE_URL', '') ?: '(비어 있음)');

        return <<<HTML
<h2 id="a-install">1. 설치와 첫 설정</h2>
<p>자세한 내용은 배포 폴더의 <code>README.md</code>(웹에서는 열리지 않음, 서버에서 직접 열기)에 있습니다. 아래는 순서 요약입니다.</p>
<ol>
  <li><strong>준비물</strong>: Windows PC, XAMPP(Apache + PHP 8.1 이상). 필요한 라이브러리(요약·메일)는 폴더에 이미 들어 있어 따로 설치할 것이 없습니다. 인터넷은 스크랩을 쓸 때만 필요합니다.</li>
  <li><strong>폴더 복사</strong>: 프로그램 폴더 전체를 <code>C:&#92;xampp&#92;htdocs&#92;</code> 아래에 복사합니다(현재 폴더 이름: <code>{$folder}</code>). 주소는 <code>http://서버주소/{$folder}/</code>가 됩니다.</li>
  <li><strong>php.ini 확인</strong>(<code>C:&#92;xampp&#92;php&#92;php.ini</code>): <code>date.timezone = Asia/Seoul</code>, <code>extension=pdo_sqlite</code>·<code>sqlite3</code>·<code>mbstring</code>·<code>fileinfo</code>·<code>openssl</code>, <code>upload_max_filesize = 10M</code>, <code>post_max_size = 40M</code>, <code>max_file_uploads = 10</code>. 고친 뒤 XAMPP 제어판에서 Apache를 다시 시작합니다.</li>
  <li><strong>config.php의 BASE_URL</strong>: 메일의 “원문 보기” 링크 주소로, <strong>메일 받는 사람의 PC에서 열리는 주소</strong>여야 합니다(예: <code>http://192.168.0.20/{$folder}</code>). 현재 값: <code>{$baseUrl}</code>. 나머지 항목은 기본값으로 동작합니다.</li>
  <li><strong>첫 접속</strong>: 브라우저로 <code>http://서버주소/{$folder}/</code>를 엽니다. 데이터베이스(<code>data&#92;app.sqlite</code>)는 처음 접속할 때 자동으로 만들어집니다.</li>
  <li><strong>관리자 로그인</strong>: 로그인 화면 아래 <strong>관리자 로그인</strong>에 관리자 비밀번호를 입력합니다. 처음에는 오늘 날짜(yyyymmdd)입니다(2절 참고).</li>
  <li><strong>관리자 비밀번호 변경</strong>: 첫 로그인 후 바로 <strong>사용자 계정</strong> 화면 맨 위 <strong>관리자 비밀번호 변경</strong>에서 직접 정한 비밀번호로 바꿉니다(5절). 잊었으면 서버에서 <code>php cli&#92;reset_admin_password.php</code>.</li>
  <li><strong>사용자 계정·멤버 등록</strong>: <strong>사용자 계정</strong>에서 사이트에 로그인할 사람의 ID·비밀번호를 만들고(5절), <strong>멤버</strong>에서 메일 받을 주소를 추가합니다(4절). 둘은 서로 별개입니다.</li>
  <li><strong>메일 설정</strong>: <strong>메일 설정</strong>에서 발신 주소·메일 서버·포트·보안을 입력하고, 서버가 로그인을 요구하면 <strong>SMTP 인증 사용</strong>을 켭니다. <strong>테스트 메일 보내기</strong>로 도착과 한글을 확인합니다(6절).</li>
  <li><strong>작업 스케줄러 등록</strong>: <code>{$folder}&#92;cli&#92;register_tasks.bat</code>를 <strong>마우스 오른쪽 버튼 → 관리자 권한으로 실행</strong>합니다. 정기 발송(1분마다 확인, {$sendTime} 발송), 정리(매일 03:00), 스크랩(06:30부터, 켰을 때만) 작업이 등록됩니다. PHP 경로가 다르면 파일 위쪽의 <code>PHP_EXE</code>, <code>PHP_INI</code>를 고칩니다. 지울 때는 <code>unregister_tasks.bat</code>.</li>
  <li><strong>설치 점검</strong>: 관리자 인증 상태에서 <code>http://서버주소/{$folder}/install_check.php</code>를 열어 실패 항목이 없는지 확인합니다. 메일·배치 항목은 설정 전이면 경고로 나옵니다.</li>
  <li><strong>백업</strong>: <code>cli&#92;backup.bat</code>를 실행하면 웹 폴더 밖(<code>C:&#92;xampp&#92;{$folder}_backup&#92;날짜</code>)에 DB와 업로드 파일이 복사됩니다. <code>data&#92;app.key</code>도 함께 보관해야 합니다(없으면 SMTP 비밀번호를 다시 입력해야 하고 추천·댓글의 본인 판정이 끊김). 관리자 비밀번호 해시는 <code>data&#92;app.sqlite</code>에 있어 DB를 복원하면 그 시점의 비밀번호로 돌아갑니다.</li>
</ol>
<div class="guide-box">
  <ul>
    <li><strong>다른 PC로 옮길 때</strong>: 새 PC에 위 1~4번을 한 뒤 Apache를 멈추고, 기존 <code>data&#92;</code>(<code>app.sqlite</code>, <code>app.key</code>)와 <code>storage&#92;uploads&#92;</code>를 같은 위치에 덮어쓰고 Apache를 시작합니다. 서버 IP가 바뀌면 <code>BASE_URL</code>도 고치고, 새 PC에서 작업 스케줄러를 다시 등록합니다.</li>
    <li>서버 PC가 꺼져 있으면 메일이 나가지 않습니다(12:00까지 켜지면 보충 발송). 절전 모드를 끄세요.</li>
  </ul>
</div>

<h2 id="a-login">2. 로그인과 보안 수칙</h2>
<ol>
  <li>로그인 화면 아래 <strong>관리자 로그인</strong>(또는 로그인 후 <strong>관리</strong> 탭을 누르면 나오는 <strong>관리자 인증</strong> 창)에 관리자 비밀번호를 입력합니다. <strong>처음에는 오늘 날짜를 yyyymmdd 형식(예: {$example}, 서버 시각 기준 KST)</strong>으로 입력하고, 로그인 후 바로 <strong>사용자 계정</strong>의 <strong>관리자 비밀번호 변경</strong>에서 직접 정한 비밀번호로 바꾸세요. 바꾼 뒤에는 날짜로 로그인할 수 없습니다. 초기 상태인 동안에는 관리 화면 위에 변경을 권하는 노란 안내가 보입니다.</li>
  <li>{$adminFails}번 연속 틀리면 {$adminLock}분 동안 잠기며, 잠금 중에는 올바른 비밀번호도 거부되고 남은 시간이 안내됩니다.</li>
  <li>마지막 작업 후 {$adminIdle}이 지나면 인증이 만료되어 다시 입력해야 합니다(화면 위 <strong>남은 시간</strong>). 관리 화면을 쓰는 동안에는 계속 연장됩니다. 새로고침하거나 새 탭·새 창에서 열면 다시 입력해야 합니다.</li>
  <li>작업을 마치면 <strong>관리 잠그기</strong>(이 화면의 관리자 인증만 끝냄) 또는 오른쪽 위 <strong>로그아웃</strong>(사이트에서 완전히 나감)을 누르세요.</li>
</ol>
<ul>
  <li>비밀번호를 바꾸지 않으면 날짜라 추측이 쉽고, 바꿔도 HTTP로 전송되므로, 사내망 밖에서는 접속하지 않도록 접근을 제한하세요.</li>
  <li>공용 PC에서는 사용 후 반드시 로그아웃하고 브라우저를 닫으세요.</li>
  <li>이 가이드와 관리 주소는 관리자에게만 공유하세요.</li>
</ul>

<h2 id="a-moderation">3. 찌라시 모더레이션</h2>
<p>승인 절차가 없어 등록 즉시 공개되므로, 관리자는 게시 후 점검합니다.</p>
<ol>
  <li><strong>찌라시 목록</strong>에서 기간·제목·상태로 전체 글을 확인합니다(<strong>조회</strong>, <strong>초기화</strong>). 제목을 누르면 상세를 볼 수 있습니다.</li>
  <li>부적절한 글은 <strong>삭제</strong>를 누릅니다. 즉시 조회와 메일 발송 대상에서 제외됩니다.</li>
  <li>중요한 글은 <strong>고정</strong>을 눌러 <strong>찌라시 보기</strong> 맨 위 “📌 고정된 찌라시”에 올립니다(최대 {$pinMax}건, <strong>고정 해제</strong>로 내림). 삭제된 글은 고정할 수 없고, 고정한 글을 삭제하면 고정이 풀립니다. 고정은 메일 내용·순서에 영향을 주지 않습니다.</li>
  <li>삭제한 글은 <strong>삭제된 찌라시</strong>에 {$deletedDays}일간 보관됩니다. 실수로 지웠다면 <strong>보기</strong>로 확인하고 {$deletedDays}일 안에 <strong>복구</strong>를 누르세요.</li>
  <li>{$deletedDays}일이 지나면 정리 배치가 본문과 이미지, 첨부파일, 댓글·추천까지 완전히 삭제하며 복구할 수 없습니다.</li>
  <li><strong>찌라시 목록</strong>의 <strong>댓글</strong>·<strong>추천</strong> 칸에서 글마다 댓글 수와 추천 수를 봅니다. 상세(<strong>보기</strong>)의 댓글마다 있는 <strong>삭제</strong>로 부적절한 댓글을 지웁니다(복구 없음). 답글이 달린 댓글은 “삭제된 댓글입니다”로 표시되고 답글은 남습니다. 댓글 작성자는 관리자도 알 수 없습니다. 작성자 해시가 없는 댓글(답글 기능 이전에 달렸거나 관리자 인증 화면에서 단 댓글)에는 “자기 댓글 답글 금지”가 적용되지 않아 누구나 답글을 달 수 있습니다.</li>
</ol>
<div class="guide-box">
  <ul>
    <li>복구해도 이미 보관 기간({$keep})이 지나 삭제된 본문 이미지·첨부파일은 돌아오지 않습니다(이미지 자리에 “이미지가 만료되었습니다”가 표시됩니다).</li>
    <li>이미 발송된 메일에서는 글을 회수할 수 없습니다. 민감한 글은 오전 {$sendTime} 정기 발송 전에 삭제하세요.</li>
    <li>복구한 글은 지난 날짜의 글이므로 정기 발송 범위에 새로 들어가지 않습니다.</li>
    <li>삭제와 복구 이력은 <strong>감사 로그</strong>에 남습니다.</li>
    <li>관리자도 찌라시 내용을 수정할 수 없습니다. 내용에 문제가 있으면 삭제하고, 작성자가 다시 등록하게 안내하세요.</li>
  </ul>
</div>

<h2 id="a-members">4. 멤버 관리(메일 수신자) — <strong>멤버</strong> 메뉴</h2>
<table class="guide-table">
  <thead><tr><th>작업</th><th>방법</th><th>결과</th></tr></thead>
  <tbody>
    <tr><td>추가</td><td>주소를 넣고 <strong>추가</strong></td><td>즉시 수신 상태, 다음 발송부터 받음</td></tr>
    <tr><td>수정</td><td>목록의 <strong>수정</strong> → <strong>저장</strong></td><td>주소 오타 수정용</td></tr>
    <tr><td>정지</td><td><strong>정지</strong></td><td>발송 제외(멤버 정보 유지)</td></tr>
    <tr><td>재개</td><td>정지 멤버의 <strong>재개</strong></td><td>발송 재개</td></tr>
    <tr><td>구독 해지 처리</td><td>멤버가 요청하면 <strong>구독 해지</strong></td><td>다음 발송부터 제외(이력 보존)</td></tr>
    <tr><td>해지 멤버 재활성화</td><td>해지 멤버의 <strong>재활성화</strong></td><td>본인 요청이 있을 때만 수행</td></tr>
    <tr><td>삭제</td><td><strong>삭제</strong></td><td>영구 제거</td></tr>
  </tbody>
</table>
<ul>
  <li>멤버는 직접 가입·해지할 수 없습니다. 멤버의 요청(수신 중지, 주소 변경 등)은 관리자가 접수해 처리합니다. 해지된 멤버를 본인 요청 없이 재활성화하지 마세요.</li>
  <li>여러 주소는 줄바꿈·쉼표·세미콜론으로 구분해 한꺼번에 추가할 수 있습니다(결과: 추가·중복·형식 오류 건수).</li>
  <li>발송 규모는 50~300명을 전제로 하며, {$batch}명씩 묶어 숨은 참조로 발송합니다.</li>
</ul>

<h2 id="a-users">5. 사용자 계정 관리(사이트 로그인) — <strong>사용자 계정</strong> 메뉴</h2>
<p>사이트에 로그인하는 계정입니다. 메일 수신자(멤버)와는 별개이며 서로 연결되지 않습니다.</p>
<table class="guide-table">
  <thead><tr><th>작업</th><th>방법</th><th>결과</th></tr></thead>
  <tbody>
    <tr><td>추가</td><td>ID와 비밀번호 입력(<strong>임의 생성</strong> 가능) 후 <strong>추가</strong></td><td>즉시 사용 가능. 정한 비밀번호는 이 화면에서 한 번만 보이니 사용자에게 전달</td></tr>
    <tr><td>일괄 추가</td><td>한 줄에 <code>ID,비밀번호</code>(쉼표·탭·공백 구분), 최대 300줄 → <strong>일괄 추가</strong></td><td>결과: 추가·중복·형식 오류 건수와 줄 번호</td></tr>
    <tr><td>비밀번호 재설정</td><td><strong>비밀번호 재설정</strong> → 새 비밀번호 입력 또는 <strong>임의 생성</strong> → <strong>저장</strong></td><td>그 사용자의 기존 로그인이 즉시 끊김</td></tr>
    <tr><td>정지 / 재개</td><td><strong>정지</strong> / <strong>재개</strong></td><td>정지하면 즉시 로그아웃되고 로그인 불가</td></tr>
    <tr><td>삭제</td><td><strong>삭제</strong>(확인 필요)</td><td>영구 삭제, 복구 불가</td></tr>
  </tbody>
</table>
<ul>
  <li>ID는 4~20자(영문·숫자·<code>.</code>·<code>_</code>·<code>-</code>), 비밀번호는 8~64자입니다. 기존 비밀번호는 해시로만 저장되어 볼 수 없습니다.</li>
  <li>사용자가 {$userFails}번 연속 틀리면 그 ID가 {$userLock}분 동안 잠깁니다. 잠금은 시간이 지나면 풀립니다.</li>
  <li>사용자가 직접 가입하거나 비밀번호를 바꿀 수 없으므로 요청이 오면 관리자가 처리합니다.</li>
  <li>계정과 찌라시 작성자는 연결되지 않으므로 “누가 썼는지”는 확인할 수 없습니다. 이는 의도된 설계입니다.</li>
  <li>처음 설치했을 때는 로그인 화면의 <strong>관리자 로그인</strong>으로 들어와 사용자 계정부터 등록하세요.</li>
</ul>
<h3>관리자 비밀번호 변경</h3>
<p>같은 <strong>사용자 계정</strong> 화면 맨 위의 <strong>관리자 비밀번호 변경</strong>에서 현재 비밀번호(초기값은 오늘 날짜 yyyymmdd), 새 비밀번호(8~64자, 날짜 형태인 숫자 8자리 불가)와 확인을 입력하고 <strong>변경</strong>을 누릅니다(<strong>임의 생성</strong> 가능). 변경하면 지금 보고 있는 화면은 유지되고, 다른 곳에서 열려 있던 관리자 로그인은 모두 끊깁니다. 새 비밀번호는 화면에 다시 표시되지 않으니 안전한 곳에 보관하세요. 현재 비밀번호를 틀리면 로그인 실패와 같이 계산되어 {$adminFails}회 연속이면 {$adminLock}분간 잠깁니다.</p>
<p><strong>잊어버린 경우</strong>에는 서버 PC에서 <code>php cli&#92;reset_admin_password.php</code>를 실행하면 초기 상태(날짜 비밀번호)로 돌아가므로, 로그인 후 즉시 다시 설정하세요(웹에서는 복구할 수 없습니다). 사용자 계정 비밀번호와 관리자 비밀번호는 서로 별개입니다.</p>

<h2 id="a-mail">6. 메일 발송 — <strong>메일 발송</strong>·<strong>메일 설정</strong> 메뉴</h2>
<h3>정기 발송(자동)</h3>
<ul>
  <li>매일 {$sendTime}에 전날 0시~24시 등록분을 발송합니다. <strong>메일 설정</strong>만 마치고 작업 스케줄러를 등록하면 별도 설정이 필요 없습니다.</li>
  <li><strong>메일 발송</strong> 화면 위쪽 <strong>자동 발송</strong> 카드에서 <strong>자동 발송 끄기</strong>·<strong>자동 발송 켜기</strong>로 켜고 끕니다(기본 켜짐). 꺼 두면 정기 메일이 나가지 않으며, 다시 켤 때 “켠 시점(오늘 0시) 이후 등록분부터 발송” 또는 “꺼져 있던 기간의 찌라시도 함께 발송”을 고릅니다. 수동 발송과 테스트 메일은 자동 발송 설정과 무관하게 동작합니다.</li>
  <li>{$sendTime}에 못 보내면 당일 {$deadline}까지 자동 보충하고, 그래도 못 보내면 다음 날 메일에 누락 날짜분이 합쳐집니다(날짜별로 구분 표시).</li>
  <li>포함할 글이 없으면 발송하지 않으며(이력에 “건너뜀”), 별도 안내 메일도 없습니다.</li>
</ul>
<h3>수동 발송</h3>
<ol>
  <li><strong>메일 발송</strong>을 엽니다.</li>
  <li>보낼 범위를 고릅니다: <strong>직전 발송 이후 신규 등록 건</strong>(기본) 또는 <strong>당일 전체 등록 건</strong>.</li>
  <li><strong>미리보기</strong>로 수신자 수와 포함될 찌라시 목록을 확인합니다. 잘못된 글이 있으면 먼저 삭제하세요.</li>
  <li><strong>발송</strong> → 확인 창에서 <strong>발송</strong>을 누르면 보냅니다. 수동 발송은 정기 발송에 영향을 주지 않으며 내용이 겹쳐도 됩니다.</li>
</ol>
<h3>메일 설정</h3>
<p><strong>메일 설정</strong>에서 <strong>발신 주소</strong>, <strong>발신자 이름</strong>, <strong>메일 서버 주소</strong>, <strong>포트</strong>, <strong>보안</strong>(없음/TLS/SSL)을 입력하고 <strong>저장</strong>합니다. 기본값이 없으므로 처음에는 모두 비어 있고, 비어 있으면 수동 발송과 자동 발송 켜기가 비활성화됩니다. 값은 사내 IT 담당자에게 확인하세요(발신 주소는 실제 사서함이 아니어도 되지만 메일 서버가 허용해야 합니다). </p>
<p><strong>SMTP 인증</strong>: 서버가 로그인을 요구하면(Gmail·네이버 등) <strong>SMTP 인증 사용</strong>을 켜고 <strong>인증 ID</strong>·<strong>인증 비밀번호</strong>를 입력해 저장합니다. 발신 주소는 로그인 계정과 같아야 하는 서버가 많습니다. 비밀번호는 암호화해 저장하며 저장 뒤에는 “설정됨”으로만 보입니다(비워 두고 저장하면 그대로 유지, <strong>비밀번호 지우기</strong>로 삭제). 사내 서버의 자체 서명 인증서는 <strong>자체 서명 인증서 허용</strong>, 느린 서버는 <strong>연결 제한시간(초)</strong>을 조정합니다. 예전처럼 <code>config.php</code>의 <code>SMTP_USER</code>/<code>SMTP_PASS</code>를 쓸 수도 있으며, 화면에서 인증을 켜고 저장하면 화면 값이 우선합니다. <strong>설정 지우기</strong>는 인증 정보까지 모두 지웁니다.</p>
<p><strong>테스트 메일</strong>: 새로 설치했거나 메일 설정을 바꾼 뒤에는 <strong>테스트 메일 보내기</strong>에 본인 주소를 넣어 도착 여부와 한글 깨짐을 먼저 확인하세요. 실패하면 메일 서버가 돌려준 오류가 그대로 표시됩니다.</p>

<h2 id="a-history">7. 발송 이력 확인 — <strong>발송 이력</strong> 메뉴</h2>
<ul>
  <li>발송 일시, 구분(자동/수동), 포함 기간, 수신자 수, 찌라시 수, 상태(<strong>성공</strong>/<strong>실패</strong>/<strong>건너뜀</strong>/<strong>진행 중</strong>), 묶음 결과를 봅니다.</li>
  <li><strong>상세</strong>에서 숨은 참조 묶음별 성공·실패와 오류 메시지, 포함된 찌라시를 확인합니다. 개별 수신자 단위 성공 여부는 기록되지 않습니다.</li>
  <li>일부 묶음이 실패하면 오류 메시지를 확인하고(메일 서버 제한, 잘못된 주소 등), 필요하면 수동 발송으로 보완합니다. 묶음은 자동으로 최대 {$retry}회 시도합니다.</li>
</ul>

<h2 id="a-scrap">8. 스크랩(뉴스 자동 등록) — <strong>스크랩</strong> 메뉴</h2>
<ul>
  <li>제안 주제(경제, 증권, IT/AI, 반도체 등 14개)를 체크하고, 필요하면 <strong>직접 입력 키워드</strong>를 한 줄에 하나씩(2~30자, 최대 10개) 넣은 뒤 <strong>주제당 등록 건수</strong>(1~10)를 골라 <strong>저장</strong>합니다(주제는 합계 최대 15개).</li>
  <li><strong>지금 실행</strong>을 누르면 고른 주제의 최신 기사(발행 후 36시간 이내)를 구글 뉴스에서 찾아 찌라시로 등록합니다. 한 번에 전체 최대 30건, 이미 등록했거나 제목이 비슷한 기사는 건너뜁니다.</li>
  <li><strong>자동 실행 켜기</strong>(기본 꺼짐)를 누르면 매일 06:30에 실행합니다(서버가 꺼져 있었다면 12:00까지 보충, 하루 한 번). 주제가 없으면 켤 수 없습니다. 스크랩 글은 <strong>다음 날 07:00 메일</strong>에 [스크랩] 표시와 함께 들어갑니다.</li>
  <li>스크랩 글에는 제목 앞에 “스크랩” 배지가 붙고, 본문에는 짧은 요약·출처·원문 링크만 있습니다(기사 전문은 가져오지 않음). 추천·댓글·고정·삭제·복구는 일반 글과 같습니다.</li>
  <li>실행 이력의 <strong>주제별 결과</strong>에서 주제마다 등록·건너뜀·오류를 봅니다. “접속 실패”가 나오면 서버의 인터넷 연결이나 <code>config.php</code>의 <code>SCRAP_PROXY</code>(사내 프록시)를 확인하세요.</li>
</ul>

<h2 id="a-cleanup">9. 정리 — <strong>정리</strong> 메뉴</h2>
<ul>
  <li>정리 대상: 등록 후 찌라시 보관 기간({$postKeep})이 지난 찌라시(댓글·추천·파일 포함 완전 삭제, 상단 고정 글 제외), 등록 후 이미지·첨부 보관 기간({$keep})이 지난 본문 이미지·첨부파일(파일만 삭제), 삭제 후 {$deletedDays}일이 지난 찌라시(댓글·추천 포함 완전 삭제), 등록되지 않은 임시 이미지(1일), 만료된 임시 파일·중단된 발송 기록·오래된 스크랩 기록.</li>
  <li><strong>보관 기간 설정</strong>에서 찌라시 보관 기간과 이미지·첨부 보관 기간을 정합니다. 기본값은 둘 다 6개월(180일)이고, 1일부터 최대 3년까지 일·개월·년 단위로 입력합니다(1개월 = 30일, 1년 = 365일). 바꾼 값은 다음 정리부터 적용되며, 찌라시 보관 기간을 줄이면 그보다 오래된 글이 다음 정리 때 <strong>모두 완전 삭제</strong>되므로 먼저 <strong>삭제 대상 확인</strong>으로 건수를 확인하세요. 오래된 글을 남기려면 상단에 고정하세요.</li>
  <li><strong>삭제 대상 확인</strong>을 누르면 항목별 건수만 보여 주고 아무것도 지우지 않습니다. 확인한 뒤 <strong>지금 정리</strong>를 누르고 확인 창에서 한 번 더 확인하면 실행합니다. <strong>영구 삭제라 복구할 수 없습니다.</strong></li>
  <li>정리는 매일 03:00 작업 스케줄러가 자동으로 실행하므로 보통 수동 실행은 필요 없습니다. 화면의 “마지막 정리”에서 최근 실행(스케줄러·수동)을 확인하세요. 지우지 못한 파일은 “파일 오류”로 표시되고 다음 정리 때 다시 시도합니다.</li>
</ul>

<h2 id="a-ops">10. 운영 점검과 문제 해결</h2>
<table class="guide-table">
  <thead><tr><th>증상</th><th>점검 사항</th></tr></thead>
  <tbody>
    <tr><td>오전 {$sendTime}에 메일이 안 옴</td><td>서버(PC) 전원·XAMPP Apache 상태, 작업 스케줄러 “발송 확인” 작업의 마지막 실행 결과(<code>data\\logs\\send_날짜.log</code>), <strong>발송 이력</strong>의 실패/건너뜀 여부</td></tr>
    <tr><td>한글이 깨져 옴</td><td>테스트 메일로 재현한 뒤 <strong>메일 설정</strong>과 PHPMailer 소스(<code>lib/PHPMailer/</code>) 확인</td></tr>
    <tr><td>발송·자동 발송 켜기가 비활성</td><td><strong>메일 설정</strong>의 발신 주소·서버·포트·보안 입력 여부와 <code>lib/PHPMailer/</code> 존재 여부</td></tr>
    <tr><td>메일이 안 나가는데 오류도 없음</td><td><strong>메일 발송</strong> 화면에서 자동 발송이 꺼져 있지 않은지</td></tr>
    <tr><td>테스트 메일이 “Authentication Required”(530)로 실패</td><td>메일 서버가 로그인을 요구함: <strong>메일 설정</strong>에서 <strong>SMTP 인증 사용</strong>을 켜고 ID·비밀번호 입력(Gmail·네이버는 앱 비밀번호 또는 SMTP 사용 설정 필요). 535 오류면 ID·비밀번호(앱 비밀번호) 확인</td></tr>
    <tr><td>사용자가 로그인하지 못함</td><td>계정 정지 여부, {$userLock}분 잠금 여부, ID·비밀번호 확인. 필요하면 <strong>비밀번호 재설정</strong></td></tr>
    <tr><td>일부만 수신</td><td>묶음 크기(<code>MAIL_BATCH_SIZE</code>)를 줄이고 메일 서버의 수신자 수 제한 확인</td></tr>
    <tr><td>업로드가 실패함</td><td><code>php.ini</code>의 <code>upload_max_filesize</code>, <code>post_max_size</code>, <code>max_file_uploads</code></td></tr>
    <tr><td>날짜별 조회가 하루 어긋남</td><td>웹용·CLI용 <code>php.ini</code> 모두 <code>date.timezone=Asia/Seoul</code>인지</td></tr>
    <tr><td>관리자 인증이 계속 거부됨</td><td>{$adminLock}분 잠금 여부 확인. 비밀번호를 바꾼 적이 없다면 서버 시각의 오늘 날짜(yyyymmdd), 바꿨다면 직접 정한 비밀번호(날짜는 무효). 잊었다면 서버에서 <code>php cli&#92;reset_admin_password.php</code>로 초기 상태 복구 후 재설정</td></tr>
    <tr><td>메일 링크가 열리지 않음</td><td><code>config.php</code>의 <code>BASE_URL</code>이 수신자 PC에서 열리는 서버 주소인지</td></tr>
    <tr><td>스크랩이 등록되지 않음</td><td><strong>스크랩</strong> 화면 실행 이력의 오류, 자동 실행 스위치(기본 꺼짐), 서버의 외부 인터넷·프록시(<code>SCRAP_PROXY</code>), 작업 스케줄러 “스크랩” 작업 상태. 오늘 이미 자동 실행했다면(실패 포함) 같은 날 다시 실행되지 않으므로 <strong>지금 실행</strong>을 사용</td></tr>
    <tr><td>디스크 용량 증가</td><td>정리 배치 작업의 마지막 실행 결과(<code>data\\logs\\cleanup_날짜.log</code>, <strong>정리</strong> 화면의 “마지막 정리”)와 <code>storage/uploads</code> 용량. 필요하면 <strong>정리</strong> 메뉴에서 <strong>삭제 대상 확인</strong> → <strong>지금 정리</strong>로 수동 실행</td></tr>
  </tbody>
</table>
<p>전체 상태는 <code>install_check.php</code>(관리자 인증 상태에서만 열림)로 한 번에 점검할 수 있습니다.</p>
<p><strong>정기 점검(주 1회 권장)</strong>: 발송 이력 확인, 작업 스케줄러 실행 결과 확인, 백업 파일 생성 여부와 복원 가능 여부 확인(<code>cli\\backup.bat</code>), 삭제된 찌라시 목록 정리 상태 확인, 멤버·사용자 계정 목록 점검.</p>
HTML;
    }
}
