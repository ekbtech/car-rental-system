@echo off
cd /d "%~dp0"
set PHP_EXE=C:\wamp64\bin\php\php8.4.15\php.exe
set CHROME_EXE=C:\Program Files\Google\Chrome\Application\chrome.exe
set START_PORT=8080
set PORT=0

if not exist "%PHP_EXE%" (
  echo PHP runtime not found at %PHP_EXE%
  echo Falling back to the system PHP runtime if it is available.
  where php >nul 2>nul
  if errorlevel 1 (
    pause
    exit /b 1
  )
  set PHP_EXE=php
)

for %%p in (8080 8081 8082 8083 8084 8085 8086 8087 8088 8089 8090) do (
  call :IsPortFree %%p
  if errorlevel 1 (
    set PORT=%%p
    goto :PortFound
  )
)

echo ERROR: No free port found in the range 8080-8090.
echo Please stop services using those ports or update start_procar_server.bat.
pause
exit /b 1

:PortFound
if "%PORT%"=="0" (
  echo ERROR: Failed to choose a port.
  pause
  exit /b 1
)

echo Starting server on 127.0.0.1:%PORT%
echo Project URL: http://127.0.0.1:%PORT%/index.html

if not exist "%CHROME_EXE%" (
  echo Chrome not found at %CHROME_EXE%
  echo Falling back to the system default browser.
  start "Pro Car PHP Server" "%PHP_EXE%" -S 127.0.0.1:%PORT% -t .
  start "http://127.0.0.1:%PORT%/index.html" http://127.0.0.1:%PORT%/index.html
  exit /b 0
)

start "Pro Car PHP Server" "%PHP_EXE%" -S 127.0.0.1:%PORT% -t .
start "" "%CHROME_EXE%" --new-window http://127.0.0.1:%PORT%/index.html
exit /b 0

:IsPortFree
  setlocal
  set "checkPort=%1"
  netstat -ano | findstr /C:":%checkPort%" | findstr /C:"LISTENING" >nul 2>nul
  if errorlevel 1 (
    endlocal
    exit /b 1
  )
  endlocal
  exit /b 0
