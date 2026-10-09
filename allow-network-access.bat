@echo off
echo ========================================
echo  Allow Attendance Tracker via IP Address
echo ========================================
echo.
echo This adds a Windows Firewall rule to allow
echo HTTP (port 80) so other devices can access
echo this app via your computer's IP address.
echo.
echo NOTE: Run this as Administrator!
echo Right-click this file -^> Run as administrator
echo.
pause

netsh advfirewall firewall add rule name="XAMPP Apache HTTP (Attendance Tracker)" dir=in action=allow protocol=TCP localport=80

if %errorlevel% equ 0 (
    echo.
    echo SUCCESS! Firewall rule added.
    echo.
    echo To access from another device:
    echo   1. Find your IP: Open Command Prompt and run: ipconfig
    echo      Look for "IPv4 Address" under your active connection.
    echo   2. On the other device, open: http://YOUR_IP/Attendance-tracker/
    echo.
    echo Example: http://192.168.1.100/Attendance-tracker/
    echo.
) else (
    echo.
    echo ERROR: Could not add rule. Make sure you ran as Administrator.
    echo.
)
pause
