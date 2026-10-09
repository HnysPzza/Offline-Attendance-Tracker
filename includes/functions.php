<?php
/**
 * Global Helper Functions & Activity Logger
 */

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function app_timezone() {
    global $TIMEZONE;
    return $TIMEZONE ?? 'Asia/Manila';
}

/**
 * True when accessed via localhost/127.0.0.1 (full administrative access).
 * False when accessed via IP address (restricted check-in mode).
 */
function is_local_access() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = strtolower(trim(explode(':', $host)[0]));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

/** Pages allowed when accessed via IP (restricted mode) */
function allowed_remote_pages() {
    return ['index.php', 'students.php', 'attendance.php'];
}

/** Check if current page is allowed for the current access type */
function can_access_page($script_name) {
    if (is_local_access()) {
        return true;
    }
    return in_array(basename($script_name), allowed_remote_pages(), true);
}

/**
 * Log an activity for admin audit trail.
 * @param string $action e.g. 'ip_login_success', 'time_in', 'time_out', 'student_create'
 * @param string|array $details Human-readable activity details
 */
function log_activity($action, $details = '') {
    global $mysqli;
    if (!$mysqli) {
        return;
    }
    $source = is_local_access() ? 'local' : 'ip';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $details = is_string($details) ? $details : json_encode($details);
    $stmt = $mysqli->prepare('INSERT INTO activity_logs (source, ip_address, action, details, page) VALUES (?, ?, ?, ?, ?)');
    if ($stmt) {
        $stmt->bind_param('sssss', $source, $ip, $action, $details, $page);
        $stmt->execute();
        $stmt->close();
    }
}
