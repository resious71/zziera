@echo off
chcp 65001 >nul
rem ZZieRa 작업 스케줄러 작업 삭제 — "관리자 권한으로 실행"하세요. register_tasks.bat 로 만든 작업 3개를 지운다.
setlocal
for %%I in ("%~dp0..") do set "APP_NAME=%%~nxI"
schtasks /delete /f /tn "%APP_NAME%_send"
schtasks /delete /f /tn "%APP_NAME%_cleanup"
schtasks /delete /f /tn "%APP_NAME%_scrap"
echo 삭제를 마쳤습니다(없던 작업은 오류로 표시될 수 있습니다).
pause
