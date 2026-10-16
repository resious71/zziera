# ZZieRa 찌라시 쭉림

> An anonymous internal bulletin board ("찌라시") for small intranets, with a daily summarized e-mail digest.
> Plain PHP 8.1+ and SQLite on Windows + XAMPP. No framework, no Composer, no CDN. UI and docs are in Korean.
> Licensed under the MIT License.

사내망용 익명 "찌라시" 게시판입니다. 관리자가 등록한 사용자만 로그인해 찌라시를 보고 올립니다. 매일 07:00(KST)에 전날 글의 요약을 메일링 멤버에게 보냅니다.

- 작성자는 저장하지 않습니다. 로그인 ID, IP, 세션도 찌라시와 연결하지 않습니다.
- 등록한 글은 수정할 수 없고, 관리자만 삭제·복구합니다.
- 본문 이미지와 첨부파일은 등록 한 달(30일) 뒤 자동으로 삭제됩니다(`config.php`의 `FILE_RETENTION_DAYS`). 글, 표, 링크, 댓글, 추천 수는 계속 남습니다.
- 글마다 추천(한 사람 한 번, 다시 누르면 취소)과 댓글(글당 답글 포함 20개, 답글은 남의 댓글에 한 단계만)이 있습니다. 댓글에는 감정별 이모티콘(97개)을 넣을 수 있고, 댓글마다 이모티콘으로 공감(한 사람당 하나, 바꾸기·취소 가능)할 수 있습니다. 작성자·추천한 사람은 저장·표시하지 않습니다(사용자 ID 대신 `data/app.key`로 만든 해시만 저장).
- 사용 방법은 화면 하단의 **이용 안내**와 관리 탭의 **관리자 가이드**에 있습니다.

## 1. 준비물

| 항목 | 내용 |
| --- | --- |
| OS | Windows (작업 스케줄러 사용) |
| 웹 서버 | XAMPP (Apache + PHP 8.1 이상) |
| PHP 확장 | `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo`, `dom`, `libxml`(필수), `openssl`(메일 서버가 TLS/SSL일 때) |
| 외부 라이브러리 | 이 폴더에 이미 포함되어 있습니다(Composer 불필요). `lib/PhpScience/TextRank`(요약), `lib/PHPMailer`(메일, LGPL) |
| 외부 인터넷 | 기본 기능에는 필요 없습니다. **스크랩**(뉴스 자동 등록)을 쓸 때만 서버가 `https://news.google.com` 에 접속할 수 있어야 하고, 사내 프록시가 필요하면 `config.php`의 `SCRAP_PROXY`를 넣습니다 |

## 2. 설치

1. **폴더 복사**: 이 폴더 전체를 `C:\xampp\htdocs\` 아래에 복사합니다. 예: `C:\xampp\htdocs\ZZieRa\`. 폴더 이름은 자유이며, 주소는 `http://서버주소/폴더이름/`이 됩니다.
2. **`php.ini` 확인** (`C:\xampp\php\php.ini`). 고친 뒤에는 XAMPP 제어판에서 Apache를 재시작하세요.
   ```ini
   date.timezone = Asia/Seoul
   extension=pdo_sqlite
   extension=sqlite3
   extension=mbstring
   extension=fileinfo
   extension=openssl        ; 메일 서버가 tls/ssl 일 때
   upload_max_filesize = 10M
   post_max_size = 40M
   max_file_uploads = 10
   ```
   웹과 작업 스케줄러(CLI)가 같은 `php.ini`를 쓰게 등록됩니다(`cli\register_tasks.bat`의 `PHP_INI`). 앱 코드는 `php.ini`와 무관하게 KST로 동작합니다.
3. **`config.php` 필수 항목**:

   | 항목 | 설명 |
   | --- | --- |
   | `BASE_URL` | 메일의 "원문 보기" 링크에 쓰는 주소입니다. **수신자 PC에서 열리는 주소**여야 합니다. 예: `http://192.168.0.20/ZZieRa`. 비어 있으면 정기 발송 메일에 링크를 만들 수 없습니다. |
   | `SMTP_USER` / `SMTP_PASS` | (선택) 예전 방식의 대체값입니다. 보통은 관리 탭 **메일 설정**의 **SMTP 인증**에서 입력합니다. 화면에서 인증을 켜고 저장하면 화면 값이 우선합니다. |

   나머지(등록 제한, 보관 일수, 세션 시간, 묶음 크기 등)는 기본값으로 동작합니다. 항목마다 설명이 주석으로 달려 있습니다.
4. **첫 접속**: 브라우저로 `http://서버주소/폴더이름/`을 엽니다. 처음 요청 때 `data/app.sqlite`가 자동으로 만들어집니다.
5. **관리자로 들어가기**: 로그인 화면 아래 **관리자 로그인**에 관리자 비밀번호를 입력합니다. 처음에는 오늘 날짜를 `yyyymmdd`로 입력합니다(예: 20261006, 서버 시각 기준).
   - **첫 로그인 후 관리자 비밀번호 변경**: 관리 탭 → **사용자 계정** 맨 위 **관리자 비밀번호 변경**에서 직접 정한 비밀번호(8~64자, 숫자 8자리 불가)로 바꿉니다. 바꾼 뒤에는 날짜로 로그인할 수 없고, 해시로만 저장됩니다.
   - **잊어버렸을 때**: 서버 PC에서 `C:\xampp\php\php.exe cli\reset_admin_password.php`를 실행하면 초기 상태(날짜)로 돌아가고 잠금이 풀리며 열려 있던 관리자 로그인은 끊깁니다. 웹에서는 복구할 수 없습니다.
6. **사용자 계정 등록**: 관리 탭 → **사용자 계정**에서 사이트에 로그인할 사람의 ID와 비밀번호를 만듭니다(**임의 생성** 가능).
7. **멤버 등록**: 관리 탭 → **멤버**에서 메일 받을 주소를 추가합니다. 사용자 계정과 멤버는 서로 별개입니다.
8. **설치 점검**: 관리자 인증 상태에서 `http://서버주소/폴더이름/install_check.php`를 엽니다. 실패 항목이 없어야 합니다. 메일 설정과 배치 실행 항목은 설정 전이면 경고로 나옵니다. 점검을 마친 뒤 이 파일은 지워도 됩니다.

## 3. 메일 설정

1. PHPMailer는 `lib/PHPMailer/`에 이미 들어 있습니다(`PHPMailer.php`, `SMTP.php`, `Exception.php`, `LICENSE`). 다른 버전으로 바꿀 때는 PHPMailer 저장소 `src/`의 같은 파일들을 이 폴더에 덮어씁니다.
2. 관리 탭 → **메일 설정**에서 **발신 주소**, **발신자 이름**, **메일 서버 주소**, **포트**, **보안**(없음/TLS/SSL)을 입력하고 **저장**합니다. 기본값이 없으므로 값은 사내 IT 담당자에게 확인하세요. 흔한 조합은 25/없음, 587/TLS, 465/SSL입니다.
3. 서버가 로그인을 요구하면(테스트 메일이 `530 Authentication Required`로 실패하면) 같은 화면의 **SMTP 인증 사용**을 켜고 **인증 ID**와 **인증 비밀번호**를 입력해 저장합니다.
   - Gmail: `smtp.gmail.com`, 587/TLS 또는 465/SSL, 2단계 인증 후 발급한 앱 비밀번호
   - 네이버: `smtp.naver.com`, 587/TLS 또는 465/SSL, 네이버 메일 환경설정에서 IMAP/SMTP 사용을 켜야 함, ID는 아이디만(또는 전체 주소)
   - 발신 주소는 로그인 계정과 같아야 하는 서버가 많습니다.
   - 비밀번호는 `data/app.key`(처음 저장할 때 자동 생성)로 AES-256-GCM 암호화해 DB에 저장하고, 화면·응답·로그에는 내보내지 않습니다. 암호화 키와 DB가 같은 서버에 있으므로 **DB 파일만 유출되는 경우를 막는 수준**입니다. `openssl` 확장이 필요합니다.
   - 비밀번호 칸을 비워 두고 저장하면 기존 값이 유지되고, **비밀번호 지우기**로 지울 수 있습니다.
   - 자체 서명 인증서를 쓰는 사내 서버는 **자체 서명 인증서 허용**을, 느린 서버는 **연결 제한시간(초)**(5~120, 기본 20)을 조정합니다.
4. **테스트 메일 보내기**에 본인 주소를 넣어 도착 여부와 한글 깨짐을 확인합니다.
5. 발송은 50명씩 숨은 참조(BCC)로 나눠 보냅니다. 메일 서버에 수신자 수 제한이 있으면 `config.php`의 `MAIL_BATCH_SIZE`를 줄입니다.

## 3-1. 스크랩(뉴스 자동 등록)

관리 탭 → **스크랩**에서 주제를 고르면 구글 뉴스 RSS에서 최신 기사를 찾아 찌라시로 등록합니다(제목·짧은 요약·출처·원문 링크만, 기사 전문은 가져오지 않음).

1. **전제:** 서버가 외부 인터넷(`news.google.com`, HTTPS)에 나갈 수 있어야 합니다. 사내 프록시가 필요하면 `config.php`의 `SCRAP_PROXY`에 넣습니다(예: `http://proxy.company:8080`). 접속 가능 여부는 `install_check.php`에서 확인합니다.
2. 주제(제안 14개 + 직접 입력 키워드)와 주제당 건수를 고르고 **저장** → **지금 실행**으로 결과를 확인합니다.
3. **자동 실행**은 기본 꺼짐입니다. 켜면 매일 06:30(12:00까지 보충, 하루 한 번)에 실행하며, 그날 등록된 스크랩 글은 다음 날 07:00 메일에 `[스크랩]` 표시와 함께 들어갑니다.
4. 같은 기사·비슷한 제목은 다시 등록하지 않습니다(30일 기록). 제한값은 `config.php`의 `SCRAP_MAX_TOPICS`(15), `SCRAP_MAX_PER_RUN`(30), `SCRAP_MAX_AGE_HOURS`(36), `SCRAP_TIMEOUT_SEC`(10)입니다.

## 4. 작업 스케줄러 등록

배포 폴더의 `cli\register_tasks.bat`를 **마우스 오른쪽 버튼 → 관리자 권한으로 실행**합니다. 예: `C:\xampp\htdocs\ZZieRa\cli\register_tasks.bat`. 작업은 SYSTEM 계정으로 등록되어, 로그인하지 않아도 실행됩니다.

| 작업 이름 | 주기 | 하는 일 | 실행 기록 |
| --- | --- | --- | --- |
| `<폴더이름>_send` | 1분마다 | 정기 발송 확인. 07:00에 발송하고, 못 보냈으면 12:00까지 보충 | `data\logs\send_YYYYMMDD.log` |
| `<폴더이름>_cleanup` | 매일 03:00 | 만료 파일·임시 이미지 삭제, 삭제 후 3일 지난 글 완전 삭제, 임시 파일·오래된 스크랩 기록 정리 | `data\logs\cleanup_YYYYMMDD.log` |
| `<폴더이름>_scrap` | 매일 06:30~12:00, 30분마다 | 스크랩 자동 실행 확인. 관리 탭 "스크랩"에서 켜기 전에는 아무것도 하지 않음(기본 꺼짐), 켜면 하루 한 번 구글 뉴스 RSS 에서 주제별 기사를 찌라시로 등록 | `data\logs\scrap_YYYYMMDD.log` |

- PHP 경로가 다르면 `register_tasks.bat` 위쪽의 `PHP_EXE`, `PHP_INI`를 고친 뒤 실행합니다.
- 등록 직후 한 번 실행해 보려면 `schtasks /run /tn "<폴더이름>_send"`를 실행하고, `data\logs\`에 기록이 생겼는지 확인합니다.
- "이미 실행 중이면 새 인스턴스를 시작하지 않음"은 `schtasks`로 만든 작업의 기본 정책입니다. 스크립트도 파일 잠금으로 중복 실행을 막습니다.
- 정리는 관리 탭 → **정리**에서도 수동으로 실행할 수 있습니다(**삭제 대상 확인** → **지금 정리**, 명령줄 `cron_cleanup.php`와 동일 동작, 같은 잠금으로 동시 실행 방지).
- 로그는 30일 뒤 자동으로 지워집니다. 작업을 지우려면 `cli\unregister_tasks.bat`를 관리자 권한으로 실행합니다.
- 수동 점검용 명령(시각을 바꿔 판단만 보기):
  ```
  C:\xampp\php\php.exe cli\cron_send.php --force-now="2026-10-07 07:05:00" --dry-run
  C:\xampp\php\php.exe cli\cron_cleanup.php --dry-run
  ```

## 5. 백업과 복원

- **백업 대상**: `data\`(DB `app.sqlite`, 암호화 키 `app.key`, 로그)와 `storage\uploads\`(업로드 파일). `app.key`가 없으면 저장된 SMTP 인증 비밀번호를 읽을 수 없어 다시 입력해야 하고, 기존 추천·댓글의 "내 것" 판정이 끊겨 같은 사람이 한 번 더 추천할 수 있게 됩니다.
- **백업 방법**: `cli\backup.bat`를 실행하면 웹 폴더 밖에 날짜별 폴더가 만들어집니다. 예: `C:\xampp\ZZieRa_backup\20261006_230000\`.
  - DB는 사용 중에도 안전하게 복사됩니다(`VACUUM INTO`).
  - 30일 지난 백업은 지워집니다. 위치와 보관 기간은 파일 위쪽의 `BACKUP_ROOT`, `KEEP_DAYS`에서 바꿉니다.
- **매일 자동 백업**(선택, 관리자 권한 명령 프롬프트):
  ```
  schtasks /create /tn "ZZieRa_backup" /ru SYSTEM /sc daily /st 02:30 /tr "\"C:\xampp\htdocs\ZZieRa\cli\backup.bat\""
  ```
- **복원**: Apache를 멈추고, 백업 폴더의 `data\app.sqlite`와 `storage\uploads\`를 배포 폴더의 같은 위치에 덮어쓴 뒤 Apache를 시작합니다.
- **관리자 비밀번호**: 관리자 비밀번호 해시는 `data\app.sqlite`에 있어 DB를 복원하면 그 시점의 비밀번호로 돌아갑니다.

## 6. 폴더 구조와 보안

```
index.php, post.php, guide.php, login.php   화면(로그인 화면만 공개)
install_check.php                            설치 점검(관리자 전용, 점검 후 삭제 가능)
config.php                                   설정(직접 열어도 아무것도 출력하지 않음)
api/        JSON API(로그인 필요. 로그인·로그아웃·관리자 인증만 공개)
assets/     CSS·JS·아이콘(공개)
src/ lib/ cli/ data/ storage/   웹 접근 차단(.htaccess + 빈 index.html)
```

- 업로드 파일은 `api/file.php`를 통해서만 내려받습니다(로그인 필요). 보관 기간(기본 30일)이 지난 파일은 정리 배치가 지웁니다. 디스크 사용량은 `install_check.php`에서 확인합니다.
- 새 PHP 진입 파일을 추가하면 기본으로 로그인이 필요합니다(`src/bootstrap.php`의 접근 게이트).
- 비밀번호 규칙:
  - 사용자 비밀번호는 해시로만 저장합니다. ID별로 5번 틀리면 5분 동안 잠기고, 8시간 동안 쓰지 않으면 로그아웃됩니다.
  - 관리자 비밀번호는 처음에는 오늘 날짜이고, 관리 탭 → 사용자 계정에서 바꾸면 그 비밀번호(해시 저장)만 유효합니다. 5번 틀리면(비밀번호 변경 화면의 현재 비밀번호 포함) 5분 동안 잠기고, 마지막 작업 후 1시간이 지나면 만료됩니다. 비밀번호를 바꾸면 다른 곳의 관리자 로그인은 끊깁니다.

## 7. 알려진 제한과 수용 위험

- **HTTP 평문 전송**: HTTP에서는 관리자 비밀번호(초기 날짜 비밀번호 포함)와 **사용자 비밀번호**가 암호화 없이 전송됩니다. 사내망 안에서만 운영하세요.
- **스크랩 외부 접속**: 스크랩을 켜면 서버가 `news.google.com`에 접속합니다(검색어만 전송, 접속 대상은 고정). 외부 접속이 정책상 허용되지 않으면 스크랩을 쓰지 마세요.
- **날짜 비밀번호**: 관리자 비밀번호를 바꾸지 않으면 오늘 날짜라 추측하기 쉽습니다(관리 화면 위 안내와 `install_check.php` 경고로 알려 줌). 설치 직후 바꾸세요. 관리 주소는 관리자에게만 공유하고, 사내망 밖에서의 접근을 막으세요.
- **웹 서버 접근 로그의 IP**: 앱은 IP를 저장하지 않지만 Apache 접근 로그(`C:\xampp\apache\logs\access.log`)에는 남습니다. 정책상 필요하면 Apache 로그 설정을 조정하세요.
- **ID 잠금 악용**: 다른 사람이 일부러 틀린 비밀번호를 넣어 특정 ID를 5분 동안 잠글 수 있습니다(수용).
- **보관하지 않는 정보**: 메일 발송 결과는 50명 묶음 단위로만 기록되고, 수신자별 성공 여부는 남지 않습니다.
- **서버 시각 기준**: 모든 날짜와 시각은 서버 시각(KST)을 따릅니다. 서버 시계가 틀리면 (초기 상태의) 관리자 비밀번호와 발송 시각도 함께 어긋납니다.

## 8. 개발·점검용 명령

```
C:\xampp\php\php.exe cli\test_sanitizer.php      본문 HTML 정화 테스트
C:\xampp\php\php.exe cli\test_summarizer.php     요약(TextRank) 테스트
C:\xampp\php\php.exe cli\resummarize.php         기존 글 요약 다시 계산
C:\xampp\php\php.exe cli\test_mail_settings.php  메일 오류 안내·비밀번호 암호화 테스트
C:\xampp\php\php.exe cli\rebuild_counters.php    댓글·추천 집계값을 실제 행 수로 다시 맞추기
C:\xampp\php\php.exe cli\reset_admin_password.php  관리자 비밀번호를 초기 상태(날짜)로 되돌리기(분실 복구, 웹 불가)
C:\xampp\php\php.exe -d extension=gd cli\make_favicon.php   아이콘을 바꿨을 때 favicon 다시 만들기
```

## 9. 라이선스

- 이 프로젝트는 [MIT 라이선스](LICENSE)로 배포합니다.
- 함께 들어 있는 외부 구성 요소는 각자의 라이선스를 따릅니다. 자세한 내용은 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)를 보세요.
  - `lib/PhpScience/TextRank/`: PHP-Science-TextRank, MIT
  - `lib/PHPMailer/`: PHPMailer 7.1.1, LGPL-2.1(수정하지 않은 원본을 그대로 포함, 새 버전으로 교체 가능)
  - `assets/thought-balloon.png`와 이 이미지로 만든 favicon: Microsoft Fluent Emoji, MIT
- 저장소에는 코드만 들어 있습니다. DB(`data/app.sqlite`), 암호화 키(`data/app.key`), 로그, 업로드 파일은 설치한 서버에서 처음 실행할 때 만들어지며 커밋하지 않습니다(`.gitignore`).
