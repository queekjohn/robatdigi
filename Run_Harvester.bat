@echo off
title Evazar Digikala Harvester
color 0B

echo =========================================================
echo   Evazar Industrial Harvester - Digikala to WP
echo   Target Site: https://evazar.ir
echo =========================================================
echo.

set /p URL="[1] Digikala Link (Category / Brand / Search): "
if "%URL%"=="" (
    echo [X] Link not provided! Exiting...
    pause
    exit
)

echo.
set /p MIN_PRICE="[2] Minimum Price in Toman [Press Enter for 100000]: "
if "%MIN_PRICE%"=="" (
    set MIN_PRICE=100000
)

echo.
set /p ONLY_AVAIL="[3] Only Available (In-Stock) Products? (Y/N) [Press Enter for Y]: "
if /I "%ONLY_AVAIL%"=="" set ONLY_AVAIL=Y
if /I "%ONLY_AVAIL%"=="Y" (
    set AVAIL_FLAG=--only_available
) else (
    set AVAIL_FLAG=
)

echo.
echo =========================================================
echo [*] Starting Harvester... Please wait...
echo =========================================================
echo.

python digikala_harvester.py --url "%URL%" --wp "https://evazar.ir/wp-json/evazar/v1/queue-ingest" --min_price %MIN_PRICE% --threads 5 %AVAIL_FLAG%

echo.
echo =========================================================
echo [!] Finished successfully.
pause
