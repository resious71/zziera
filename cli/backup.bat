@echo off
chcp 65001 >nul
rem ===========================================================================
rem  ZZieRa 백업: DB(data\app.sqlite)와 업로드 폴더(storage\uploads\)를 날짜별 폴더로 복사한다.
rem    DB 는 사용 중에도 안전하게 PHP 의 VACUUM INTO 로 한 파일에 복사한다.
rem    세션·잠금·임시 파일(data\sessions, locks, tmp)은 복사하지 않는다.
rem  작업 스케줄러에 매일 1회 등록해 쓰는 것을 권장한다(README 참고). 보관 기간은 KEEP_DAYS.
rem ===========================================================================
setlocal

rem ── 환경에 맞게 바꾸는 값 ─────────────────────────────────────────────
set "PHP_EXE=C:\xampp\php\php.exe"
rem 백업 위치: 기본값은 웹 폴더(htdocs) 밖. 예) C:\xampp\htdocs\ZZieRa → C:\xampp\ZZieRa_backup
for %%I in ("%~dp0..") do set "APP_NAME=%%~nxI"
for %%I in ("%~dp0..\..\..") do set "BACKUP_ROOT=%%~fI\%APP_NAME%_backup"
set "KEEP_DAYS=30"
rem ─────────────────────────────────────────────────────────────────────

set "APP_DIR=%~dp0.."
set "STAMP="
for /f %%d in ('""%PHP_EXE%" -n -r "echo (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Ymd_His');""') do set "STAMP=%%d"
if "%STAMP%"=="" (
    echo [오류] PHP 를 실행할 수 없습니다: %PHP_EXE%
    exit /b 1
)
set "DEST=%BACKUP_ROOT%\%STAMP%"
mkdir "%DEST%\data" 2>nul
mkdir "%DEST%\storage\uploads" 2>nul

rem 1) DB: 사용 중에도 일관된 사본을 만든다
"%PHP_EXE%" -r "$p=new PDO('sqlite:'.$argv[1]); $p->exec('VACUUM INTO '.$p->quote($argv[2])); echo 'DB 복사 완료'.PHP_EOL;" "%APP_DIR%\data\app.sqlite" "%DEST%\data\app.sqlite"
if errorlevel 1 (
    echo [오류] DB 복사 실패
    exit /b 1
)

rem 2) data 의 나머지(logs 등)와 업로드 파일
robocopy "%APP_DIR%\data" "%DEST%\data" /E /XD sessions locks tmp /XF app.sqlite app.sqlite-wal app.sqlite-shm /NFL /NDL /NJH /NJS /NP >nul
robocopy "%APP_DIR%\storage\uploads" "%DEST%\storage\uploads" /E /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 (
    echo [오류] 파일 복사 실패
    exit /b 1
)

rem 3) 오래된 백업 폴더 정리
forfiles /p "%BACKUP_ROOT%" /d -%KEEP_DAYS% /c "cmd /c if @isdir==TRUE rmdir /s /q @path" >nul 2>&1

echo 백업 완료: %DEST%
exit /b 0
