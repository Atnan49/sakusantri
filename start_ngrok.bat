@echo off
REM Quick launcher for ngrok tunnel (calls PowerShell script)
SET SCRIPT_DIR=%~dp0
powershell -ExecutionPolicy Bypass -File "%SCRIPT_DIR%start_ngrok.ps1" %*
IF %ERRORLEVEL% NEQ 0 (
  echo.
  echo [ERROR] Script gagal. Pastikan PowerShell dan ngrok ter-install.
  pause
)