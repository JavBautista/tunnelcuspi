@echo off
REM Script launcher para ejecutar backup en background verdadero
start /B "" "C:\laragon\bin\php\php-7.4.33-nts-Win32-vc15-x86\php.exe" "%~dp0backup_worker.php" %1 %2
exit