@echo off
REM Runs one backup. Edit PHP below if XAMPP is installed somewhere else.
set PHP=C:\xampp\php\php.exe
"%PHP%" "%~dp0backup.php"
