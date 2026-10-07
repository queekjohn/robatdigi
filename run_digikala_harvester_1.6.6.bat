@echo off
setlocal EnableExtensions EnableDelayedExpansion

title EVazar Digikala Harvester 1.6.6 (Category + Single Product)
color 0b

echo ===================================================================
echo   EVAZAR INDUSTRIAL HARVESTER - DIGIKALA TO WP QUEUE
echo   Version 1.6.6 - Category Harvester + Single Product Test
echo ===================================================================
echo.

where python >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Python was not found in PATH.
    echo Install Python and make sure "python" works in CMD.
    pause
    exit /b 1
)

if not exist "%~dp0digikala_harvester_1.6.6.py" (
    echo [ERROR] digikala_harvester_1.6.6.py was not found next to this BAT file.
    pause
    exit /b 1
)

echo [1] Run mode
echo     1 = Category / brand / search (existing 1.6.0 behavior)
echo     2 = Single product URL (new in 1.6.6)
set "MODE=1"
set /p "MODE_INPUT=Mode [1]: "
if defined MODE_INPUT set "MODE=%MODE_INPUT%"

if "%MODE%"=="2" goto SINGLE_PRODUCT_MODE

echo.
echo [2] Digikala category / brand / search URL
echo     Example: https://www.digikala.com/search/category-face/
set "DK_URL="
set /p "DK_URL=URL: "
if not defined DK_URL (
    echo [ERROR] URL cannot be empty.
    pause
    exit /b 2
)

goto RUN_HARVESTER

:SINGLE_PRODUCT_MODE
echo.
echo ===================================================================
echo  SINGLE PRODUCT MODE
echo ===================================================================
echo.
echo Enter a Digikala product URL.
echo Example: https://www.digikala.com/product/dkp-12345678/
set "DK_URL="
set /p "DK_URL=Product URL: "
if not defined DK_URL (
    echo [ERROR] Product URL cannot be empty.
    pause
    exit /b 2
)

echo.
echo [*] Duplicate-protection test?
echo     Y = Send the same DKP twice (recommended for this test)
echo     N = Send only once
set "DUP_TEST=Y"
set /p "DUP_INPUT=Duplicate test? [Y]: "
if defined DUP_INPUT set "DUP_TEST=%DUP_INPUT%"

set "DUP_ARG="
if /I "%DUP_TEST%"=="Y" set "DUP_ARG=--duplicate_test"

echo.
echo ===================================================================
echo  Starting Harvester 1.6.6 - Single Product...
echo  Product URL : %DK_URL%
echo  Duplicate test : %DUP_TEST%
echo ===================================================================
echo.

python "%~dp0digikala_harvester_1.6.6.py" ^
  --url "%DK_URL%" ^
  --single_product ^
  %DUP_ARG%

set "EXIT_CODE=%ERRORLEVEL%"
goto FINISH

:RUN_HARVESTER

echo.
echo [2] Minimum price in Toman
set "MIN_PRICE=50000"
set /p "MIN_INPUT=Minimum price in Toman [50000]: "
if defined MIN_INPUT set "MIN_PRICE=%MIN_INPUT%"

echo.
echo [3] Only available products?
echo     Y = Yes, only products with selling stock (recommended)
echo     N = No, include unavailable products too
set "ONLY_AVAILABLE=Y"
set /p "AVAIL_INPUT=Only available? [Y]: "
if defined AVAIL_INPUT set "ONLY_AVAILABLE=%AVAIL_INPUT%"

set "AVAIL_ARG="
if /I "%ONLY_AVAILABLE%"=="Y" set "AVAIL_ARG=--only_available"

echo.
echo [4] Auto-resolve subcategories tree?
echo     Y = Yes, break parent into leaf subcategories (100%% complete)
echo     N = No, treat URL as single flat search
set "AUTO_SUB=Y"
set /p "AUTO_SUB_INPUT=Auto-resolve subcategories? [Y]: "
if defined AUTO_SUB_INPUT set "AUTO_SUB=%AUTO_SUB_INPUT%"

set "SUB_ARG="
if /I "%AUTO_SUB%"=="N" set "SUB_ARG=--no_subcategories"

echo.
echo [5] Concurrent requests (Threads)
set "THREADS=3"
set /p "THREAD_INPUT=Threads [3]: "
if defined THREAD_INPUT set "THREADS=%THREAD_INPUT%"

echo.
echo [6] Stop upload if any API page fails?
echo     Y = Yes (strict mode)
echo     N = No, continue with warnings (recommended)
set "FAIL_ON_ERROR=N"
set /p "FAIL_INPUT=Fail on page error? [N]: "
if defined FAIL_INPUT set "FAIL_ON_ERROR=%FAIL_INPUT%"

set "FAIL_ARG="
if /I "%FAIL_ON_ERROR%"=="Y" set "FAIL_ARG=--fail_on_page_error"

echo.
echo [7] Pagination safety cap
echo     100 = recommended for Digikala category pagination
set "PAGE_CAP=100"
set /p "PAGE_CAP_INPUT=Page cap [100]: "
if defined PAGE_CAP_INPUT set "PAGE_CAP=%PAGE_CAP_INPUT%"

echo.
echo ===================================================================
echo  Starting Harvester 1.6.6...
echo  URL             : %DK_URL%
echo  Min price       : %MIN_PRICE% Toman
echo  Only available  : %ONLY_AVAILABLE%
echo  Auto Subtrees   : %AUTO_SUB%
echo  Threads         : %THREADS%
echo  Fail on error   : %FAIL_ON_ERROR%
echo  Page cap        : %PAGE_CAP%
echo ===================================================================
echo.

python "%~dp0digikala_harvester_1.6.6.py" ^
  --url "%DK_URL%" ^
  --min_price "%MIN_PRICE%" ^
  --threads "%THREADS%" ^
  %AVAIL_ARG% ^
  %SUB_ARG% ^
  %FAIL_ARG% ^
  --page_cap "%PAGE_CAP%"

set "EXIT_CODE=%ERRORLEVEL%"
goto FINISH

:FINISH
echo.
echo ===================================================================
if "%EXIT_CODE%"=="0" (
    echo [OK] Harvester 1.6.6 completed successfully.
) else (
    echo [ERROR] Harvester stopped with exit code %EXIT_CODE%.
    echo Check the report above for details.
)
echo ===================================================================
echo.
pause
exit /b %EXIT_CODE%
