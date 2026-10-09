@echo off
chcp 65001 >nul
cd /d "%~dp0"
python digikala_unique_test.py --url-file categories_test.txt --threads 16 --min-price 50000 --retries 2
pause
