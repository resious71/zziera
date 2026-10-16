@echo off
chcp 65001 >nul
rem 작업 스케줄러가 호출하는 래퍼: cron_send.php 를 실행해 결과를 data\logs\send_YYYYMMDD.log 에 덧붙이고 종료 코드를 돌려준다.
rem   run_send.bat [PHP 경로] [php.ini 경로]   (register_tasks.bat 가 넘겨 준다. 없으면 아래 기본값)
rem 날짜 계산·로그 기록·30일 지난 로그 정리는 cli\run_logged.php 가 한다.
setlocal
set "PHP_EXE=%~1"
if "%PHP_EXE%"=="" set "PHP_EXE=C:\xampp\php\php.exe"
set "PHP_INI=%~2"
if "%PHP_INI%"=="" set "PHP_INI=C:\xampp\php\php.ini"
"%PHP_EXE%" -c "%PHP_INI%" "%~dp0run_logged.php" send
exit /b %ERRORLEVEL%
