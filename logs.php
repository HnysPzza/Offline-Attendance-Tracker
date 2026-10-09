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
  <title>Activity Logs - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'logs.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-[92rem] w-full mx-auto px-4 sm:px-6 py-6 space-y-6 flex-1">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-200">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">System Activity Logs</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Audit trail of system events, time stamps, network IP clients, and administrative changes.</p>
      </div>
      <div class="flex items-center gap-2 self-start sm:self-auto">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 border border-blue-200 text-[#0F3D87]">
          <i data-lucide="history" class="w-3.5 h-3.5"></i>
          <span>Total Log Entries: <strong class="text-[#0F3D87] font-bold"><?php echo $count; ?></strong></span>
        </span>
      </div>
    </div>

    <!-- Unified 3-Metric Toolbar -->
    <div class="border border-slate-200 bg-white rounded-lg shadow-xs overflow-hidden grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Check-Ins</span>
          <div class="text-2xl sm:text-3xl font-extrabold text-[#0F3D87] mt-1 font-mono"><?php echo $loginStats['total_logins']; ?></div>
        </div>
        <div class="p-2.5 rounded-full bg-blue-50 text-[#0F3D87]">
          <i data-lucide="log-in" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Today's Check-Ins</span>
          <div class="text-2xl sm:text-3xl font-extrabold text-emerald-600 mt-1 font-mono"><?php echo $loginStats['today_logins']; ?></div>
        </div>
        <div class="p-2.5 rounded-full bg-emerald-50 text-emerald-600">
          <i data-lucide="calendar-check" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Unique Active Students</span>
          <div class="text-2xl sm:text-3xl font-extrabold text-indigo-600 mt-1 font-mono"><?php echo $loginStats['unique_students']; ?></div>
        </div>
        <div class="p-2.5 rounded-full bg-indigo-50 text-indigo-600">
          <i data-lucide="users" class="w-5 h-5"></i>
        </div>
      </div>
    </div>

    <!-- Main Log Surface -->
    <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs space-y-4">
      <?php if ($truncated): ?>
        <div class="p-3 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2">
          <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i>
          <span>All activity logs have been successfully cleared.</span>
        </div>
      <?php endif; ?>
      <?php if ($clearError): ?>
        <div class="p-3 rounded-md bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
          <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600"></i>
          <span>Failed to truncate logs. Please try again.</span>
        </div>
      <?php endif; ?>

      <!-- Filter Controls & Actions Bar -->
      <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 pb-3 border-b border-slate-100 text-xs">
        <form method="get" class="flex flex-wrap items-end gap-3 flex-1">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Event Action</label>
            <select name="action" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
              <option value="">All Actions</option>
              <?php foreach ($actionLabels as $k => $label): ?>
                <option value="<?php echo h($k); ?>" <?php echo $filter_action === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Source Origin</label>
            <select name="source" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
              <option value="">All Sources</option>
              <option value="local" <?php echo $filter_source === 'local' ? 'selected' : ''; ?>>Local Host</option>
              <option value="ip" <?php echo $filter_source === 'ip' ? 'selected' : ''; ?>>Network Device (IP)</option>
            </select>
          </div>

          <div>
            <label class="block font-semibold text-slate-700 mb-1">Date</label>
            <input type="date" name="date" value="<?php echo h($filter_date); ?>" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
          </div>

          <div class="flex items-center gap-1.5">
            <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md bg-[#0F3D87] text-white hover:bg-blue-900 font-semibold shadow-2xs transition-colors">
              <i data-lucide="filter" class="w-3.5 h-3.5"></i>
              <span>Filter</span>
            </button>
            <a href="?page=1" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-600 font-medium transition-colors">
              <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
              <span>Clear</span>
            </a>
          </div>
        </form>

        <form method="post" onsubmit="return confirm('⚠️ WARNING: This will permanently delete ALL logs from the database. This action CANNOT be undone! Are you absolutely sure?');" class="self-start md:self-end">
          <input type="hidden" name="action" value="truncate_all_logs" />
          <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100 font-semibold transition-colors">
            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
            <span>Truncate All Logs</span>
          </button>
        </form>
      </div>

      <!-- Logs Table -->
      <div class="table-container">
        <table class="table-modern">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Source</th>
              <th>IP Client</th>
              <th>Action Event</th>
              <th>Details & Metadata</th>
              <th>Origin View</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($logs)): ?>
              <tr>
                <td colspan="6" class="text-center py-8 text-xs text-slate-400">No activity logs recorded matching criteria.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($logs as $row): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                  <td class="font-mono text-xs text-slate-600 whitespace-nowrap"><?php echo h($row['created_at']); ?></td>
                  <td>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-semibold <?php echo $row['source'] === 'ip' ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-slate-100 text-slate-700'; ?>">
                      <?php echo h($row['source'] === 'ip' ? 'Remote IP' : 'Local'); ?>
                    </span>
                  </td>
                  <td class="font-mono text-xs text-slate-600 whitespace-nowrap"><?php echo h($row['ip_address']); ?></td>
                  <td class="font-semibold text-slate-900 whitespace-nowrap"><?php echo h($actionLabels[$row['action']] ?? $row['action']); ?></td>
                  <td class="text-xs text-slate-600 max-w-sm truncate" title="<?php echo h($row['details']); ?>"><?php echo h($row['details']); ?></td>
                  <td class="font-mono text-xs text-slate-400 whitespace-nowrap"><?php echo h($row['page']); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer -->
      <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500">
          <div>Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?> (<?php echo (int)$count; ?> total entries)</div>
          <div class="flex items-center gap-1">
            <?php if ($page > 1): ?>
              <?php $q = http_build_query(array_filter(['action' => $filter_action, 'source' => $filter_source, 'date' => $filter_date, 'page' => $page - 1])); ?>
              <a href="?<?php echo $q; ?>" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors">Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <?php $q = http_build_query(array_filter(['action' => $filter_action, 'source' => $filter_source, 'date' => $filter_date, 'page' => $page + 1])); ?>
              <a href="?<?php echo $q; ?>" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors">Next</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>
  <?php include __DIR__ . '/includes/footer.php'; ?>
