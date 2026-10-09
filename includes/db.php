<?php
/**
 * Database Connection & Schema Management
 */

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = ''; // <-- Set your MySQL root password here
$DB_NAME = 'attendance_db';
$TIMEZONE = 'Asia/Manila';

date_default_timezone_set($TIMEZONE);

$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS);
if ($mysqli->connect_errno) {
    die('Database connection failed: ' . $mysqli->connect_error);
}

$mysqli->query("CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$mysqli->select_db($DB_NAME);
$mysqli->query("SET time_zone = '" . $mysqli->real_escape_string(date('P')) . "'");

// Students table
$mysqli->query("CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  year_level VARCHAR(20) NOT NULL DEFAULT '',
  section VARCHAR(50) NOT NULL,
  gender VARCHAR(20) NOT NULL DEFAULT '',
  department ENUM('Education','Technology') NOT NULL,
  course VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure required columns exist
$studentColumns = [];
$studentColsRes = $mysqli->query("SHOW COLUMNS FROM students");
if ($studentColsRes) {
    while ($c = $studentColsRes->fetch_assoc()) {
        $studentColumns[$c['Field']] = true;
    }
    $studentColsRes->free();
}
if (!isset($studentColumns['year_level'])) {
    $mysqli->query("ALTER TABLE students ADD COLUMN year_level VARCHAR(20) NOT NULL DEFAULT '' AFTER name");
}
if (!isset($studentColumns['gender'])) {
    $mysqli->query("ALTER TABLE students ADD COLUMN gender VARCHAR(20) NOT NULL DEFAULT '' AFTER section");
}

// Schedules table
$mysqli->query("CREATE TABLE IF NOT EXISTS schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  schedule_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  late_minutes INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_schedule_date (schedule_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Attendance table
$mysqli->query("CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  schedule_id INT NOT NULL,
  time_in DATETIME DEFAULT NULL,
  time_out DATETIME DEFAULT NULL,
  status ENUM('On time','Late') DEFAULT NULL,
  minutes_late INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_attendance (student_id, schedule_id),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Activity logs table
$mysqli->query("CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  source ENUM('local','ip') NOT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  action VARCHAR(64) NOT NULL,
  details TEXT,
  page VARCHAR(64) NOT NULL DEFAULT '',
  INDEX idx_created (created_at),
  INDEX idx_action (action),
  INDEX idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function db() {
    global $mysqli;
    return $mysqli;
}
