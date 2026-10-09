# Access Attendance Tracker via IP Address

To open this project from another computer/phone on your network:

## Access restrictions

- **localhost** (`http://localhost/Attendance-tracker/`): Full access — no password required. All pages: Dashboard, Time In/Out, Students, Schedules, Attendance
- **IP address** (`http://YOUR_IP/Attendance-tracker/`): Password required. Users must enter the access password before using the system. Restricted to **Time In/Out**, **Students**, and **Attendance** only.

---

## 1. Allow through Windows Firewall

Run **`allow-network-access.bat`** as Administrator:
- Right-click the file → **Run as administrator**

This adds a firewall rule for Apache (port 80).

## 2. Find your computer's IP address

- Open **Command Prompt** and run: `ipconfig`
- Find **IPv4 Address** under your active connection (Wi‑Fi or Ethernet)  
  Example: `192.168.1.100`

## 3. Start XAMPP

Make sure **Apache** and **MySQL** are running in XAMPP Control Panel.

## 4. Access from another device

On any device on the same network, open in a browser:

```
http://YOUR_IP/Attendance-tracker/
```

Example: `http://192.168.1.100/Attendance-tracker/`

---

**Notes:**
- The other device must be on the **same local network** (same Wi‑Fi/router)
- Your router may assign different IPs; run `ipconfig` again if it changes
- For quick lookup: on the XAMPP PC, you can use `http://localhost/Attendance-tracker/`
