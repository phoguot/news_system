@echo off
setlocal
title Van Lang News CMS - Local Server
cd /d "%~dp0"

rem ===== Cau hinh: sua PORT neu muon doi cong =====
set "PORT=8000"

where php >nul 2>nul
if errorlevel 1 (
    echo [LOI] Khong tim thay "php" trong PATH. Hay cai PHP 8.3 hoac them thu muc PHP vao PATH.
    pause
    exit /b 1
)

echo.
echo  Dang dong bo config cache (phong khi module.config.php vua sua)...
php bin/clear-config-cache.php >nul 2>nul

echo.
echo  ============================================
echo   Van Lang News CMS
echo   Website:    http://127.0.0.1:%PORT%/
echo   Quan tri:   http://127.0.0.1:%PORT%/admin/login
echo   (Trang chu / cua website van con la placeholder Laminas)
echo  ============================================
echo.

rem Mo trinh duyet sau 2 giay de server kip khoi dong
start "" cmd /c "timeout /t 2 /nobreak >nul & start http://127.0.0.1:%PORT%/admin/login"

php -S 127.0.0.1:%PORT% -t public

pause
