# Student Attendance Tracker System

**Graduating Council — Cebu Technological University (CTU) Naga Extension Campus**

A lightweight, offline-ready web application designed for managing student rosters, scheduling campus events, recording real-time time-in/time-out attendance, and generating analytics and reports over a local network.

---

## 📁 Architecture Overview

- **`includes/`**: Core modular backend logic (`db.php`, `functions.php`, `auth.php`, `header.php`, `footer.php`, `config.php`).
- **`assets/`**: Offline-ready Tailwind CSS, JavaScript libraries, and branding.
- **`database/`**: Database schemas (`attendance_db.sql`) and backups.
- **`docs/`**: Setup documentation (`ACCESS.md`, `NETWORK_SETUP.md`) and templates.
- **Root (`*.php`)**: Standalone page endpoints (`index.php`, `dashboard.php`, `students.php`, etc.).

---

## 🚀 Quick Start Guide

### 1. Requirements
- **XAMPP** (Apache 2.4+ and MySQL 5.7+ / MariaDB 10.4+)
- **PHP** 8.0 or newer
- Modern web browser (Chrome, Edge, Firefox, Safari)

### 2. Installation
1. Place the project folder into your XAMPP web root:
   ```text
   C:\xampp\htdocs\Attendance-tracker\
   ```
2. Open **XAMPP Control Panel** and click **Start** for both **Apache** and **MySQL**.
3. Open **phpMyAdmin** in your browser:
   ```text
   http://localhost/phpmyadmin/
   ```
4. Create database `attendance_db` and import `database/attendance_db.sql`.
5. Open the app in your browser:
   ```text
   http://localhost/Attendance-tracker/
   ```
---

## 🌐 Local Network / Wi-Fi Access

To allow other computers, tablets, or smartphones to record attendance without internet:

1. **Firewall Rule**: Right-click `allow-network-access.bat` → select **Run as administrator**.
2. **Find Server IP**:
   - Open Command Prompt and run `ipconfig`.
   - Note your **IPv4 Address** (e.g., `192.168.1.100` or `10.0.1.106`).
3. **Connect Devices**:
   - Connect client devices to the same Wi-Fi or mobile hotspot.
   - Open browser on the client device and go to:
     ```text
     http://YOUR_SERVER_IP/Attendance-tracker/
     ```
   - Or open `http://localhost/Attendance-tracker/qr.php` on the host PC and let students/officers scan the QR code.

---

## 📋 Student Import Format (CSV / XLSX)

When importing student rosters via **Students** (`students.php`), the spreadsheet must include the following headers:

| Header Name | Type / Allowed Values | Example |
|---|---|---|
| `Student ID` | Text / Alphanumeric | `1349854` or `4A-01` |
| `Full Name (Last Name, First Name, Middle Initial)` | Text (`Last, First M.`) | `ACABAL, JOHN ANDREI G.` |
| `Course` | `BSIT`, `BIT`, `BEED`, `BSED`, `BTLED` | `BSIT` |
| `Year` | `1`, `2`, `3`, `4` | `4` |
| `Section` | `A`, `B`, `1`, `2`, etc. | `A` |
| `Gender` | `Male`, `Female` | `Male` |
| `Department` | `Technology` (for BSIT, BIT) or `Education` (for BEED, BSED, BTLED) | `Technology` |

---

## 🛡️ Security & Access Control

- **Localhost (`127.0.0.1`)**: Full administrative access to all pages (Dashboard, Schedules, Student Management, Logs).
- **Network IP (`http://<IP>/...`)**: Password-protected access intended for terminal check-in/out stations.
