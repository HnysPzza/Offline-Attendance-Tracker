<?php
require_once __DIR__ . '/config.php';
if (!is_local_access()) {
    header('Location: index.php');
    exit;
}
$db = db();

// Handle truncate all logs action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'truncate_all_logs') {
    try {
        // Disable foreign key checks before truncating
        if (!$db->query('SET FOREIGN_KEY_CHECKS=0')) {
            throw new Exception('Failed to disable foreign key checks');
        }
        
        // Truncate the table
        if (!$db->query('TRUNCATE TABLE activity_logs')) {
            throw new Exception('Failed to truncate activity_logs table');
        }
        
        // Re-enable foreign key checks
        if (!$db->query('SET FOREIGN_KEY_CHECKS=1')) {
            throw new Exception('Failed to enable foreign key checks');
        }
        
        // Verify truncation was successful by checking row count
        $result = $db->query('SELECT COUNT(*) as cnt FROM activity_logs');
        $row = $result->fetch_assoc();
        
        if ($row['cnt'] == 0) {
            header('Location: logs.php?truncated=1');
            exit;
        } else {
            header('Location: logs.php?error=1');
            exit;
        }
    } catch (Exception $e) {
        error_log('Truncate logs error: ' . $e->getMessage());
        header('Location: logs.php?error=1');
        exit;
    }
}

$filter_action = trim($_GET['action'] ?? '');
$filter_source = trim($_GET['source'] ?? '');
$filter_date = trim($_GET['date'] ?? '');
$perPage = 100;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$truncated = isset($_GET['truncated']) && $_GET['truncated'] === '1';
$clearError = isset($_GET['error']) && $_GET['error'] === '1';

$where = ['1=1'];
$params = [];
$types = '';
if ($filter_action !== '') {
    $where[] = 'l.action = ?';
    $params[] = $filter_action;
    $types .= 's';
}
if ($filter_source !== '' && in_array($filter_source, ['local', 'ip'])) {
    $where[] = 'l.source = ?';
    $params[] = $filter_source;
    $types .= 's';
}
if ($filter_date !== '') {
    $where[] = 'DATE(l.created_at) = ?';
    $params[] = $filter_date;
    $types .= 's';
}

$sql = 'SELECT l.* FROM activity_logs l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC';
$countSql = 'SELECT COUNT(*) c FROM activity_logs l WHERE ' . implode(' AND ', $where);

if (count($params) > 0) {
    $stmt = $db->prepare($countSql);
    $refs = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    $stmt->bind_param($types, ...$refs);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
} else {
    $count = (int)$db->query($countSql)->fetch_assoc()['c'];
}
$totalPages = max(1, (int)ceil($count / $perPage));

$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';
$stmt = $db->prepare($sql . ' LIMIT ? OFFSET ?');
$refs = [];
foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
$stmt->bind_param($types, ...$refs);
$stmt->execute();
$logs = [];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $logs[] = $row; }
$stmt->close();

$actionLabels = [
    'ip_login_success' => 'IP Access Granted',
    'ip_login_fail' => 'IP Login Failed',
    'time_in' => 'Time In',
    'time_out' => 'Time Out',
    'student_create' => 'Student Added',
    'student_update' => 'Student Updated',
    'student_delete' => 'Student Deleted',
    'student_import' => 'Students Imported',
    'schedule_create' => 'Schedule Created',
    'schedule_delete' => 'Schedule Deleted',
    'schedule_import' => 'Schedules Imported',
    'attendance_export' => 'Attendance Exported',
];

// Get login statistics
$loginStats = [
    'total_logins' => 0,
    'today_logins' => 0,
    'unique_students' => 0
];

$res = $db->query("SELECT COUNT(*) as count FROM activity_logs WHERE action = 'time_in'");
$row = $res->fetch_assoc();
$loginStats['total_logins'] = (int)$row['count'];

$res = $db->query("SELECT COUNT(*) as count FROM activity_logs WHERE action = 'time_in' AND DATE(created_at) = CURDATE()");
$row = $res->fetch_assoc();
$loginStats['today_logins'] = (int)$row['count'];

// Get unique students from logs (extract student ID from details)
$res = $db->query("SELECT COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(details, '(', -1), ')', 1)) as unique_count FROM activity_logs WHERE action = 'time_in' AND details LIKE '%(%)'");
$row = $res->fetch_assoc();
$loginStats['unique_students'] = (int)$row['unique_count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Activity Log - Attendance Tracker</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body class="bg-gray-50 min-h-screen antialiased">
  <header class="sticky top-0 bg-[#0F3D87] text-white shadow-sm z-20 relative">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between relative">
      <div class="flex items-center gap-2 sm:gap-3 min-w-0">
        <img src="assets/logo.jpg" alt="Graduating Council Logo" class="h-10 w-10 sm:h-12 sm:w-12 rounded-full ring-2 ring-[#D4AF37] ring-offset-2 ring-offset-[#0F3D87] shadow-md object-cover flex-shrink-0" onerror="this.style.display='none'">
        <div class="leading-tight min-w-0">
          <div class="text-[9px] sm:text-[10px] md:text-xs uppercase tracking-widest text-[#D4AF37] truncate">Graduating 2026 Council</div>
          <div class="text-xs sm:text-sm md:text-base font-semibold truncate">CTU-Naga Extension Campus</div>
        </div>
      </div>
      <button type="button" class="nav-mobile-toggle touch-target md:hidden" id="navToggle" aria-label="Open menu">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <nav class="nav-desktop hidden md:flex items-center gap-4 lg:gap-6 text-sm">
        <a href="dashboard.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='dashboard.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Dashboard</a>
        <a href="index.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='index.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Time In/Out</a>
        <a href="students.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='students.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Students</a>
        <a href="schedules.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='schedules.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Schedules</a>
        <a href="attendance.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='attendance.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Attendance</a>
        <a href="logs.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='logs.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Logs</a>
        <a href="qr.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='qr.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">QR Access</a>
      </nav>
    </div>
    <nav class="nav-mobile" id="navMobile" aria-hidden="true">
      <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='dashboard.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Dashboard</a>
      <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='index.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Time In/Out</a>
      <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='students.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Students</a>
      <a href="schedules.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='schedules.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Schedules</a>
      <a href="attendance.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='attendance.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Attendance</a>
      <a href="logs.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='logs.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Logs</a>
      <a href="qr.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='qr.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">QR Access</a>
    </nav>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600 font-medium">Total Logins</p>
            <p class="text-4xl font-bold text-[#0F3D87] mt-2"><?php echo $loginStats['total_logins']; ?></p>
          </div>
          <svg class="w-12 h-12 text-[#D4AF37]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
        </div>
      </div>

      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600 font-medium">Today's Logins</p>
            <p class="text-4xl font-bold text-green-600 mt-2"><?php echo $loginStats['today_logins']; ?></p>
          </div>
          <svg class="w-12 h-12 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m7 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
      </div>

      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600 font-medium">Unique Students</p>
            <p class="text-4xl font-bold text-blue-600 mt-2"><?php echo $loginStats['unique_students']; ?></p>
          </div>
          <svg class="w-12 h-12 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10a3 3 0 11-6 0 3 3 0 016 0zM6 20h12a6 6 0 00-6-6 6 6 0 00-6 6z"/></svg>
        </div>
      </div>
    </div>

    <div class="bg-white shadow-md ring-1 ring-gray-200 rounded-xl p-4 sm:p-6">
      <h1 class="text-lg font-semibold text-gray-900 mb-4">Activity Log</h1>
      <p class="text-sm text-gray-600 mb-4">View who requested access and what actions were taken by users (IP access, time in/out, student changes, schedule changes, exports).</p>
      <?php if ($truncated): ?>
        <div class="mb-4 p-3 rounded bg-green-100 text-green-700 text-sm"><strong>Success!</strong> All activity logs have been permanently deleted from the database.</div>
      <?php endif; ?>
      <?php if ($clearError): ?>
        <div class="mb-4 p-3 rounded bg-red-100 text-red-700 text-sm"><strong>Error!</strong> Failed to truncate logs. Please try again or check database permissions.</div>
      <?php endif; ?>

      <form method="get" class="flex flex-wrap gap-3 mb-6">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Action</label>
          <select name="action" class="rounded-md border border-gray-300 text-sm focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">All</option>
            <?php foreach ($actionLabels as $k => $label): ?>
              <option value="<?php echo h($k); ?>" <?php echo $filter_action === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Source</label>
          <select name="source" class="rounded-md border border-gray-300 text-sm focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">All</option>
            <option value="local" <?php echo $filter_source === 'local' ? 'selected' : ''; ?>>Local</option>
            <option value="ip" <?php echo $filter_source === 'ip' ? 'selected' : ''; ?>>IP (Network)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Date</label>
          <input type="date" name="date" value="<?php echo h($filter_date); ?>" class="rounded-md border border-gray-300 text-sm focus:ring-[#0F3D87] focus:border-[#0F3D87]">
        </div>
        <div class="flex items-end gap-2">
          <button type="submit" class="px-4 py-2 rounded-md bg-[#0F3D87] text-white text-sm hover:opacity-95">Filter</button>
          <a href="?page=1" class="px-4 py-2 rounded-md border border-gray-300 text-gray-700 text-sm hover:bg-gray-50">Clear</a>
        </div>
      </form>

      <form method="post" onsubmit="return confirm('⚠️ WARNING: This will permanently delete ALL logs from the database. This action CANNOT be undone! Are you absolutely sure?');" class="inline">
        <input type="hidden" name="action" value="truncate_all_logs" />
        <button type="submit" class="px-4 py-2 rounded-md bg-red-600 text-white text-sm hover:bg-red-700 font-semibold">🗑️ Truncate All Logs</button>
      </form>

      <div class="overflow-x-auto -mx-2 sm:mx-0">
        <table class="table-responsive-cards min-w-full text-sm divide-y divide-gray-200">
          <thead>
            <tr class="text-left bg-gray-50 text-gray-600 text-xs uppercase tracking-wide">
              <th class="py-2 pr-4 font-medium">Date & Time</th>
              <th class="py-2 pr-4 font-medium">Source</th>
              <th class="py-2 pr-4 font-medium">IP Address</th>
              <th class="py-2 pr-4 font-medium">Action</th>
              <th class="py-2 pr-4 font-medium">Details</th>
              <th class="py-2 pr-4 font-medium">Page</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $row): ?>
              <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2 pr-4" data-label="Date & Time"><?php echo h($row['created_at']); ?></td>
                <td class="py-2 pr-4" data-label="Source">
                  <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo $row['source'] === 'ip' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo h($row['source']); ?>
                  </span>
                </td>
                <td class="py-2 pr-4 font-mono text-xs" data-label="IP"><?php echo h($row['ip_address']); ?></td>
                <td class="py-2 pr-4" data-label="Action"><?php echo h($actionLabels[$row['action']] ?? $row['action']); ?></td>
                <td class="py-2 pr-4 text-gray-600 max-w-xs truncate" data-label="Details" title="<?php echo h($row['details']); ?>"><?php echo h($row['details']); ?></td>
                <td class="py-2 pr-4 text-gray-500" data-label="Page"><?php echo h($row['page']); ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($logs) === 0): ?>
              <tr><td colspan="6" class="py-8 text-center text-gray-500">No log entries found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="flex items-center justify-between mt-4 text-sm">
        <div>Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?> (<?php echo (int)$count; ?> total)</div>
        <div class="flex gap-2">
          <?php if ($page > 1): ?>
            <?php $q = http_build_query(array_filter(['action' => $filter_action, 'source' => $filter_source, 'date' => $filter_date, 'page' => $page - 1])); ?>
            <a href="?<?php echo $q; ?>" class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50">Prev</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <?php $q = http_build_query(array_filter(['action' => $filter_action, 'source' => $filter_source, 'date' => $filter_date, 'page' => $page + 1])); ?>
            <a href="?<?php echo $q; ?>" class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50">Next</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </main>

  <script>
    document.getElementById('navToggle')?.addEventListener('click', function() {
      document.getElementById('navMobile').classList.toggle('open');
      this.setAttribute('aria-label', document.getElementById('navMobile').classList.contains('open') ? 'Close menu' : 'Open menu');
    });
    document.getElementById('navMobile')?.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => document.getElementById('navMobile').classList.remove('open'));
    });
  </script>
</body>
</html>
