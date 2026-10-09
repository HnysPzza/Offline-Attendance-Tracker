OFFLINE NETWORK ACCESS GUIDE
=============================

This system can now work on a local network (WiFi hotspot) WITHOUT internet.

SETUP INSTRUCTIONS:
===================

1. WINDOWS FIREWALL (First Time Only)
   - Run "allow-network-access.bat" as Administrator
   - This opens port 80 for local network access

2. FIND YOUR COMPUTER IP ADDRESS
   - Open PowerShell (Press Win + R, type "powershell")
   - Run: ipconfig
   - Look for "IPv4 Address" under Ethernet or WiFi (e.g., 192.168.x.x)

3. ACCESS FROM OTHER DEVICES
   - On any device connected to the same WiFi, open browser
   - Enter: http://YOUR_IP_ADDRESS/Attendance-tracker
   - Example: http://192.168.0.100/Attendance-tracker

4. ALL CSS & JAVASCRIPT NOW LOCAL
   - No internet required for styling or charts
   - Works completely offline
   - Perfect for WiFi hotspots without data

FEATURES THAT WORK OFFLINE:
===========================
✓ Tailwind CSS (all styling)
✓ Chart.js (all graphs and analytics)
✓ Time In/Out functionality
✓ Student Management
✓ Attendance Tracking
✓ Analytics Dashboard
✓ CSV Export with Sanction Status

TROUBLESHOOTING:
================

If you can't access from another device:
1. Check both devices are on SAME WiFi network
2. Verify firewall rule was added (check "allow-network-access.bat")
3. Check your computer's IP address (step 2 above)
4. Try disabling Windows Firewall temporarily to test
5. Make sure XAMPP Apache is running

If CSS/JavaScript doesn't load:
- All resources are now local in assets/js/
- No internet connection needed
- Wait 5 seconds for page to fully load

NETWORK SETUP TIPS:
===================
- Mobile Hotspot: Create a hotspot on your phone, connect computer and other devices
- WiFi Router: Connect all devices to same network
- USB Tethering: Share computer internet via USB (not needed for this app)
