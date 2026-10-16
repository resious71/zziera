@echo off
chcp 65001 >nul
rem ===========================================================================
rem  ZZieRa 작업 스케줄러 등록 — 반드시 "관리자 권한으로 실행"하세요.
rem    (파일을 마우스 오른쪽 버튼으로 누르고 "관리자 권한으로 실행")
rem  배포 폴더(예: C:\xampp\htdocs\ZZieRa\cli\)에 있는 이 파일을 실행해야 합니다.
rem
rem  등록하는 작업(SYSTEM 계정, 로그인하지 않아도 실행):
rem    <폴더이름>_send    : 1분마다 정기 발송 확인(cli\run_send.bat → cron_send.php)
rem    <폴더이름>_cleanup : 매일 03:00 정리 배치(cli\run_cleanup.bat → cron_cleanup.php)
rem    <폴더이름>_scrap   : 매일 06:30~12:00 30분마다 스크랩 확인(cli\run_scrap.bat → cron_scrap.php)
rem                        관리 탭 "스크랩"에서 자동 실행을 켜기 전에는 아무것도 하지 않는다(기본 꺼짐)
rem  "이미 실행 중이면 새 인스턴스를 시작하지 않음"은 schtasks 로 만든 작업의 기본 정책(IgnoreNew)이고,
rem  cron_send.php / cron_cleanup.php 도 파일 잠금으로 겹쳐 실행되지 않게 막는다.
rem ===========================================================================
setlocal

rem ── 환경에 맞게 바꾸는 값 ─────────────────────────────────────────────
set "PHP_EXE=C:\xampp\php\php.exe"
set "PHP_INI=C:\xampp\php\php.ini"
rem ─────────────────────────────────────────────────────────────────────

set "CLI_DIR=%~dp0"
for %%I in ("%~dp0..") do set "APP_NAME=%%~nxI"
set "TASK_SEND=%APP_NAME%_send"
set "TASK_CLEAN=%APP_NAME%_cleanup"
set "TASK_SCRAP=%APP_NAME%_scrap"

net session >nul 2>&1
if errorlevel 1 (
    echo [오류] 관리자 권한이 필요합니다. 이 파일을 마우스 오른쪽 버튼으로 눌러 "관리자 권한으로 실행"하세요.
    pause
    exit /b 1
)
if not exist "%PHP_EXE%" (
    echo [오류] PHP를 찾을 수 없습니다: %PHP_EXE%
    echo        이 파일 위쪽의 PHP_EXE 값을 고친 뒤 다시 실행하세요.
    pause
    exit /b 1
)

echo 앱 폴더  : %CLI_DIR%..
echo PHP      : %PHP_EXE%
echo php.ini  : %PHP_INI%
echo.

schtasks /create /f /tn "%TASK_SEND%" /ru SYSTEM /rl HIGHEST /sc minute /mo 1 ^
  /tr "\"%CLI_DIR%run_send.bat\" \"%PHP_EXE%\" \"%PHP_INI%\""
if errorlevel 1 goto fail

schtasks /create /f /tn "%TASK_CLEAN%" /ru SYSTEM /rl HIGHEST /sc daily /st 03:00 ^
  /tr "\"%CLI_DIR%run_cleanup.bat\" \"%PHP_EXE%\" \"%PHP_INI%\""
if errorlevel 1 goto fail

schtasks /create /f /tn "%TASK_SCRAP%" /ru SYSTEM /rl HIGHEST /sc daily /st 06:30 /ri 30 /du 0005:30 ^
  /tr "\"%CLI_DIR%run_scrap.bat\" \"%PHP_EXE%\" \"%PHP_INI%\""
if errorlevel 1 goto fail

echo.
echo 등록 완료: %TASK_SEND% (1분마다), %TASK_CLEAN% (매일 03:00), %TASK_SCRAP% (매일 06:30~12:00, 30분마다)
echo 실행 기록은 %CLI_DIR%..\data\logs\ 에 날짜별로 남습니다.
echo 지금 한 번 실행해 보려면: schtasks /run /tn "%TASK_SEND%"
pause
exit /b 0

:fail
echo [오류] 작업 등록에 실패했습니다. 관리자 권한으로 실행했는지 확인하세요.
pause
exit /b 1
