@echo off
rem Menjalankan aplikasi agar bisa dibuka dari perangkat lain di jaringan yang sama.
rem Buka dari HP/laptop lain: http://<IP komputer ini>:8000
cd /d "%~dp0"
echo Alamat IP komputer ini:
ipconfig | findstr /C:"IPv4"
echo.
echo Buka dari perangkat lain: http://IP-di-atas:8000
echo Tekan Ctrl+C untuk menghentikan.
echo.
C:\php\php.exe artisan serve --host=0.0.0.0 --port=8000
