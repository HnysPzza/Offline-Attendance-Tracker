<?php
require_once __DIR__ . '/config.php';
if (!can_access_page(__FILE__)) {
    header('Location: index.php');
    exit;
}
$db = db();
$message = '';
$error = '';

// Handle Edit - Fetch schedule data for editing
$edit_schedule = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $stmt = $db->prepare('SELECT * FROM schedules WHERE id = ?');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_schedule = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $schedule_date = $_POST['schedule_date'] ?? '';
        $time_in_start = $_POST['time_in_start'] ?? '';
        $time_in_end = $_POST['time_in_end'] ?? '';
        $time_out_start = $_POST['time_out_start'] ?? '';
        $time_out_end = $_POST['time_out_end'] ?? '';
        $late_minutes = intval($_POST['late_minutes'] ?? 0);

        if ($title === '' || $schedule_date === '' || $time_in_start === '' || $time_in_end === '' || $time_out_start === '' || $time_out_end === '') {
            $error = 'All fields are required.';
        } else {
            if (strtotime($time_in_end) <= strtotime($time_in_start) || strtotime($time_out_start) < strtotime($time_in_end) || strtotime($time_out_end) <= strtotime($time_out_start)) {
                $error = 'Invalid time sequence. Please ensure times are in chronological order and do not overlap incorrectly.';
            } else {
                $stmt = $db->prepare('INSERT INTO schedules (title, schedule_date, start_time, end_time, late_minutes, time_in_start, time_in_end, time_out_start, time_out_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssssissss', $title, $schedule_date, $time_in_start, $time_out_end, $late_minutes, $time_in_start, $time_in_end, $time_out_start, $time_out_end);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    $message = 'Schedule created.';
                    log_activity('schedule_create', $title . ' - ' . $schedule_date);
                } else { $error = 'Failed to create schedule.'; }
            }
        }
    }
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('SELECT title, schedule_date, start_time FROM schedules WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $del = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = $db->prepare('DELETE FROM schedules WHERE id = ?');
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                $message = 'Schedule deleted.';
                log_activity('schedule_delete', $del ? $del['title'] . ' - ' . $del['schedule_date'] : 'ID ' . $id);
            } else { $error = 'Failed to delete schedule.'; }
        }
    }
    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $schedule_date = $_POST['schedule_date'] ?? '';
        $time_in_start = $_POST['time_in_start'] ?? '';
        $time_in_end = $_POST['time_in_end'] ?? '';
        $time_out_start = $_POST['time_out_start'] ?? '';
        $time_out_end = $_POST['time_out_end'] ?? '';
        $late_minutes = intval($_POST['late_minutes'] ?? 0);

        if ($title === '' || $schedule_date === '' || $time_in_start === '' || $time_in_end === '' || $time_out_start === '' || $time_out_end === '') {
            $error = 'All fields are required.';
        } else {
            if (strtotime($time_in_end) <= strtotime($time_in_start) || strtotime($time_out_start) < strtotime($time_in_end) || strtotime($time_out_end) <= strtotime($time_out_start)) {
                $error = 'Invalid time sequence. Please ensure times are in chronological order and do not overlap incorrectly.';
            } else {
                $stmt = $db->prepare('UPDATE schedules SET title = ?, schedule_date = ?, start_time = ?, end_time = ?, late_minutes = ?, time_in_start = ?, time_in_end = ?, time_out_start = ?, time_out_end = ? WHERE id = ?');
                $stmt->bind_param('ssssissssi', $title, $schedule_date, $time_in_start, $time_out_end, $late_minutes, $time_in_start, $time_in_end, $time_out_start, $time_out_end, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    $message = 'Schedule updated.';
                    log_activity('schedule_edit', $title . ' - ' . $schedule_date);
                } else { $error = 'Failed to update schedule.'; }
            }
        }
    }
    if ($action === 'truncate') {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $ok1 = $db->query('TRUNCATE TABLE schedules');
        $ok2 = $db->query('TRUNCATE TABLE attendance');
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        if ($ok1 && $ok2) {
            $message = 'All schedules and attendance records have been deleted.';
            log_activity('schedule_truncate', 'All schedules and attendance truncated');
        } else {
            $error = 'Failed to truncate tables.';
        }
    }
    if ($action === 'import' && isset($_FILES['import_file'])) {
        $file = $_FILES['import_file']['tmp_name'];
        $filename = $_FILES['import_file']['name'];
        
        if ($_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext === 'xlsx' || $ext === 'csv') {
                $imported = 0;
                $errors = [];
                $handle = fopen($file, 'r');
                $header = fgetcsv($handle);
                
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) >= 5 && !empty($row[0])) {
                        $title = trim($row[0]);
                        $schedule_date = trim($row[1]);
                        $time_in_start = trim($row[2]);
                        $time_out_end = trim($row[3]);
                        $late_str = trim($row[4]);
                        $late_minutes = intval(str_replace('m', '', $late_str));
                        
                        // Parse date format "May 18, 2026" to YYYY-MM-DD
                        $dateObj = DateTime::createFromFormat('F j, Y', $schedule_date);
                        if ($dateObj) {
                            $schedule_date = $dateObj->format('Y-m-d');
                        }
                        
                        // For simplicity, use same times for time windows
                        $time_in_end = $time_in_start;
                        $time_out_start = $time_out_end;
                        
                        if (!empty($title) && !empty($schedule_date) && !empty($time_in_start) && !empty($time_out_end)) {
                            $stmt = $db->prepare('INSERT INTO schedules (title, schedule_date, start_time, end_time, late_minutes, time_in_start, time_in_end, time_out_start, time_out_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            $stmt->bind_param('ssssissss', $title, $schedule_date, $time_in_start, $time_out_end, $late_minutes, $time_in_start, $time_in_end, $time_out_start, $time_out_end);
                            if ($stmt->execute()) {
                                $imported++;
                                log_activity('schedule_import', $title . ' - ' . $schedule_date);
                            } else {
                                $errors[] = "Failed: $title";
                            }
                            $stmt->close();
                        }
                    }
                }
                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Successfully imported $imported schedule(s).";
                }
                if (count($errors) > 0) {
                    $error = count($errors) > 3 ? implode(' | ', array_slice($errors, 0, 3)) . '...' : implode(' | ', $errors);
                }
            } else {
                $error = 'Please upload a valid CSV or XLSX file.';
            }
        } else {
            $error = 'File upload error.';
        }
    }
}

// Handle Export Schedules to XLSX
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="schedules_' . date('Y-m-d_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['TITLE', 'DATE', 'START', 'END', 'LATE']);
    $res = $db->query('SELECT title, schedule_date, time_in_start, time_out_end, late_minutes FROM schedules ORDER BY schedule_date DESC');
    while ($row = $res->fetch_assoc()) {
        $formattedDate = date('F j, Y', strtotime($row['schedule_date']));
        $formattedStart = date('g:i A', strtotime($row['time_in_start']));
        $formattedEnd = date('g:i A', strtotime($row['time_out_end']));
        $formattedLate = $row['late_minutes'] . 'm';
        
        fputcsv($output, [
            $row['title'],
            $formattedDate,
            $formattedStart,
            $formattedEnd,
            $formattedLate
        ]);
    }
    fclose($output);
    exit;
}

$schedules = [];
$res = $db->query('SELECT * FROM schedules ORDER BY schedule_date DESC, start_time ASC');
while ($row = $res->fetch_assoc()) { $schedules[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Schedules</title>
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
        <?php if (is_local_access()): ?><a href="dashboard.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='dashboard.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Dashboard</a><?php endif; ?>
        <a href="index.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='index.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Time In/Out</a>
        <a href="students.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='students.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Students</a>
        <?php if (is_local_access()): ?><a href="schedules.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='schedules.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Schedules</a><a href="logs.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='logs.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Logs</a><a href="qr.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='qr.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">QR Access</a><?php endif; ?>
        <a href="attendance.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='attendance.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Attendance</a><a href="analytics.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='analytics.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Analytics</a>
      </nav>
    </div>
    <nav class="nav-mobile" id="navMobile" aria-hidden="true">
      <?php if (is_local_access()): ?><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='dashboard.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Dashboard</a><a href="schedules.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='schedules.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Schedules</a><a href="logs.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='logs.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Logs</a><a href="qr.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='qr.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">QR Access</a><?php endif; ?>
      <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='index.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Time In/Out</a>
      <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='students.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Students</a>
      <a href="attendance.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='attendance.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Attendance</a><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='analytics.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Analytics</a>
    </nav>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 sm:py-8 grid md:grid-cols-2 gap-4 sm:gap-6">
    <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6">
      <h2 class="text-lg font-semibold mb-4"><?php echo $edit_schedule ? 'Edit Schedule' : 'Create Schedule'; ?></h2>
      <?php if ($error): ?>
        <div class="mb-4 p-3 rounded bg-red-100 text-red-700 text-sm hidden"><?php echo h($error); ?></div>
      <?php elseif ($message): ?>
        <div class="mb-4 p-3 rounded bg-green-100 text-green-700 text-sm hidden"><?php echo h($message); ?></div>
      <?php endif; ?>
      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="<?php echo $edit_schedule ? 'edit' : 'create'; ?>" />
        <?php if ($edit_schedule): ?>
          <input type="hidden" name="id" value="<?php echo $edit_schedule['id']; ?>" />
        <?php endif; ?>
        <div>
          <label class="block text-sm font-medium text-gray-700">Title</label>
          <input name="title" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" value="<?php echo $edit_schedule ? h($edit_schedule['title']) : ''; ?>" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Date</label>
          <input name="schedule_date" type="date" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" value="<?php echo $edit_schedule ? h($edit_schedule['schedule_date']) : ''; ?>" />
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-5">
          <div class="sm:col-span-2 font-medium text-gray-800">Time In Window</div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Time In Start</label>
            <input name="time_in_start" type="time" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" value="<?php echo $edit_schedule ? h($edit_schedule['time_in_start']) : ''; ?>" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Time In End</label>
            <input name="time_in_end" type="time" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" value="<?php echo $edit_schedule ? h($edit_schedule['time_in_end']) : ''; ?>" />
          </div>
          <div class="sm:col-span-2 font-medium text-gray-800">Time Out Window</div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Time Out Start</label>
            <input name="time_out_start" type="time" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" value="<?php echo $edit_schedule ? h($edit_schedule['time_out_start']) : ''; ?>" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Time Out End</label>
            <input name="time_out_end" type="time" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" value="<?php echo $edit_schedule ? h($edit_schedule['time_out_end']) : ''; ?>" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Late Minutes</label>
          <input id="late_minutes_input" name="late_minutes" type="number" min="0" value="<?php echo $edit_schedule ? (int)$edit_schedule['late_minutes'] : '0'; ?>" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
          <p id="late_time_label" class="text-xs text-gray-500 mt-1"></p>
        </div>
        <div class="flex gap-2">
          <button class="inline-flex items-center px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:opacity-95"><?php echo $edit_schedule ? 'Update' : 'Save'; ?></button>
          <?php if ($edit_schedule): ?>
            <a href="schedules.php" class="inline-flex items-center px-4 py-2 rounded-md bg-gray-500 text-white hover:bg-gray-600">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
      <hr class="my-6">
      <div class="space-y-6">
        <div>
          <h4 class="text-base font-semibold mb-2">Export Schedules</h4>
          <p class="text-xs text-gray-500 mb-3">Download all schedules to an Excel file.</p>
          <a href="?export=xlsx" class="inline-flex items-center px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700">Download XLSX</a>
        </div>
        <div>
          <h4 class="text-base font-semibold mb-2">Import Schedules</h4>
          <p class="text-xs text-gray-500 mb-3">Upload a CSV or XLSX file to import schedules.</p>
          <form method="post" enctype="multipart/form-data" class="flex gap-2 flex-wrap">
            <input type="hidden" name="action" value="import" />
            <input type="file" name="import_file" accept=".csv,.xlsx" required class="text-sm border border-gray-300 rounded-md px-3 py-2" />
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700">Import</button>
          </form>
        </div>
        <div>
          <h4 class="text-base font-semibold mb-2">Truncate Schedules</h4>
          <p class="text-xs text-gray-500 mb-3">This will permanently delete all schedules and attendance records from the database. This action cannot be undone.</p>
          <form method="post" onsubmit="return handleTruncate(event);">
            <input type="hidden" name="action" value="truncate" />
            <button type="button" class="inline-flex items-center px-4 py-2 rounded-md bg-red-600 text-white hover:bg-red-700" onclick="handleTruncate(event)">Truncate Data</button>
          </form>
        </div>
      </div>
    </div>

    <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 overflow-x-auto">
      <h2 class="text-lg font-semibold mb-4">Schedules List</h2>
      <div class="overflow-x-auto -mx-2 sm:mx-0">
      <table class="table-responsive-cards min-w-full text-sm divide-y divide-gray-200">
        <thead>
          <tr class="text-left bg-gray-50 text-gray-600 text-xs uppercase tracking-wide">
            <th class="py-2 pr-4 font-medium">Title</th>
            <th class="py-2 pr-4 font-medium">Date</th>
            <th class="py-2 pr-4 font-medium">Start</th>
            <th class="py-2 pr-4 font-medium">End</th>
            <th class="py-2 pr-4 font-medium">Late</th>
            <th class="py-2 pr-4 font-medium">Actions</th>
           </tr>
        </thead>
        <tbody>
          <?php foreach ($schedules as $s): ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
              <td class="py-2 pr-4" data-label="Title"><?php echo h($s['title']); ?></td>
              <td class="py-2 pr-4" data-label="Date"><?php echo h($date = date('F j, Y', strtotime($s['schedule_date']))); ?></td>
              <td class="py-2 pr-4" data-label="Start"><?php echo h(date('h:i A', strtotime($s['start_time']))); ?></td>
              <td class="py-2 pr-4" data-label="End"><?php echo h(date('h:i A', strtotime($s['end_time']))); ?></td>
              <td class="py-2 pr-4" data-label="Late"><?php echo (int)$s['late_minutes']; ?>m</td>
              <td class="py-2 pr-4" data-label="Actions">
                <div class="flex gap-2">
                  <a href="?edit_id=<?php echo (int)$s['id']; ?>" class="inline-flex items-center px-3.5 py-2 sm:py-1.5 rounded-md bg-blue-600 text-white hover:bg-blue-700 text-xs font-medium touch-target">Edit</a>
                  <form method="post" onsubmit="return confirm('Delete this schedule?');">
                    <input type="hidden" name="action" value="delete" />
                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>" />
                    <button class="px-3.5 py-2 sm:py-1.5 rounded-md bg-red-600 text-white hover:bg-red-700 text-xs font-medium touch-target">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </main>
  <div id="appModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/50" data-modal-close></div>
    <div id="appModalPanel" class="relative bg-white rounded-xl shadow-xl ring-1 ring-gray-200 max-w-md w-full mx-4 border-l-4" style="border-left-color:#0F3D87;">
      <div class="px-6 py-4 border-b border-gray-200">
        <h3 id="appModalTitle" class="text-base font-semibold text-gray-900">Notice</h3>
      </div>
      <div class="px-6 py-4">
        <p id="appModalMsg" class="text-sm text-gray-700"></p>
      </div>
      <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2">
        <button type="button" class="px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:opacity-95" data-modal-close>OK</button>
      </div>
    </div>
  </div>
  <script>
    function openModal(title, message, type) {
      const overlay = document.getElementById('appModal');
      const panel = document.getElementById('appModalPanel');
      const ttl = document.getElementById('appModalTitle');
      const msg = document.getElementById('appModalMsg');
      ttl.textContent = title || 'Notice';
      msg.textContent = message || '';
      const color = type === 'error' ? '#dc2626' : (type === 'success' ? '#16a34a' : '#0F3D87');
      panel.style.borderLeftColor = color;
      overlay.classList.remove('hidden');
      overlay.classList.add('flex');
      const close = () => { overlay.classList.add('hidden'); overlay.classList.remove('flex'); };
      overlay.querySelectorAll('[data-modal-close]').forEach(el => { el.onclick = close; });
      document.addEventListener('keydown', function esc(e){ if(e.key==='Escape'){ close(); document.removeEventListener('keydown', esc);} });
    }
  </script>
  <?php if ($error): ?>
  <script>document.addEventListener('DOMContentLoaded',()=>openModal('Error', <?php echo json_encode($error); ?>, 'error'));</script>
  <?php elseif ($message): ?>
  <script>document.addEventListener('DOMContentLoaded',()=>openModal('Success', <?php echo json_encode($message); ?>, 'success'));</script>
  <?php endif; ?>
  <script>
    document.getElementById('navToggle')?.addEventListener('click', function() {
      document.getElementById('navMobile').classList.toggle('open');
      this.setAttribute('aria-label', document.getElementById('navMobile').classList.contains('open') ? 'Close menu' : 'Open menu');
    });
    document.getElementById('navMobile')?.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => document.getElementById('navMobile').classList.remove('open'));
    });
    
    document.addEventListener('DOMContentLoaded', function() {
        const startTimeInput = document.querySelector('input[name="time_in_start"]');
        const lateMinutesInput = document.getElementById('late_minutes_input');
        const lateTimeLabel = document.getElementById('late_time_label');

        function calculateLateTime() {
            const startTime = startTimeInput.value;
            const lateMinutes = parseInt(lateMinutesInput.value, 10);

            if (startTime && !isNaN(lateMinutes)) {
                const [hours, minutes] = startTime.split(':').map(Number);
                const startDate = new Date();
                startDate.setHours(hours, minutes, 0, 0);

                const lateDate = new Date(startDate.getTime() + lateMinutes * 60000);
                
                const lateTime = lateDate.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });

                if (lateMinutes > 0) {
                    lateTimeLabel.textContent = `Students will be marked late after ${lateTime}.`;
                } else {
                    lateTimeLabel.textContent = '';
                }
            } else {
                lateTimeLabel.textContent = '';
            }
        }

        startTimeInput.addEventListener('input', calculateLateTime);
        lateMinutesInput.addEventListener('input', calculateLateTime);

        // Initial calculation on page load
        calculateLateTime();
    });
    
    // Truncate with scary green skull warning
    function handleTruncate(event) {
      event.preventDefault();
      showSkullWarning(event.target.closest('form'));
      return false;
    }
    
    function showSkullWarning(form) {
      const overlay = document.createElement('div');
      overlay.className = 'fixed inset-0 bg-black/80 flex items-center justify-center z-50';
      
      const container = document.createElement('div');
      container.className = 'text-center p-8 max-w-2xl';
      
      const skullSvg = document.createElement('div');
      skullSvg.innerHTML = `
        <style>
          @keyframes rotateSkull {
            from { transform: rotate(0deg) scale(1); }
            to { transform: rotate(360deg) scale(1); }
          }
          @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
          }
          .scary-skull {
            animation: rotateSkull 3s linear infinite;
            display: inline-block;
            filter: drop-shadow(0 0 30px rgba(34, 197, 94, 0.8)) drop-shadow(0 0 60px rgba(34, 197, 94, 0.5));
          }
          .pulse-text {
            animation: pulse 1.5s ease-in-out infinite;
          }
        </style>
        <svg class="scary-skull" width="200" height="200" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
          <!-- Skull -->
          <path d="M60 15C85 15 100 32 100 55C100 72 92 85 85 92L72 98C72 105 66 112 60 112C54 112 48 105 48 98L35 92C28 85 20 72 20 55C20 32 35 15 60 15Z" fill="#22c55e" stroke="#15803d" stroke-width="2"/>
          <!-- Left eye socket -->
          <circle cx="45" cy="52" r="12" fill="#000" stroke="#22c55e" stroke-width="1"/>
          <circle cx="45" cy="52" r="7" fill="#4ade80" opacity="0.6"/>
          <!-- Right eye socket -->
          <circle cx="75" cy="52" r="12" fill="#000" stroke="#22c55e" stroke-width="1"/>
          <circle cx="75" cy="52" r="7" fill="#4ade80" opacity="0.6"/>
          <!-- Nose cavity -->
          <ellipse cx="60" cy="68" rx="6" ry="10" fill="#000"/>
          <!-- Teeth -->
          <rect x="38" y="82" width="44" height="6" fill="#22c55e" stroke="#15803d" stroke-width="1"/>
          <line x1="42" y1="82" x2="42" y2="90" stroke="#15803d" stroke-width="1.5"/>
          <line x1="48" y1="82" x2="48" y2="90" stroke="#15803d" stroke-width="1.5"/>
          <line x1="54" y1="82" x2="54" y2="90" stroke="#15803d" stroke-width="1.5"/>
          <line x1="60" y1="82" x2="60" y2="90" stroke="#15803d" stroke-width="1.5"/>
          <line x1="66" y1="82" x2="66" y2="90" stroke="#15803d" stroke-width="1.5"/>
          <line x1="72" y1="82" x2="72" y2="90" stroke="#15803d" stroke-width="1.5"/>
          <line x1="78" y1="82" x2="78" y2="90" stroke="#15803d" stroke-width="1.5"/>
        </svg>
      `;
      
      const warningText = document.createElement('h2');
      warningText.className = 'text-white text-4xl font-bold mt-8 mb-4 pulse-text';
      warningText.textContent = '⚠️ WARNING ⚠️';
      
      const message = document.createElement('p');
      message.className = 'text-white text-xl font-semibold mb-6 leading-relaxed';
      message.innerHTML = 'You are about to <span class="text-red-400 text-2xl">PERMANENTLY DELETE</span> all data!<br><br>This action <span class="text-red-400">CANNOT BE UNDONE</span>!';
      
      const buttonContainer = document.createElement('div');
      buttonContainer.className = 'flex gap-6 justify-center mt-8';
      
      const confirmBtn = document.createElement('button');
      confirmBtn.className = 'px-8 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg text-lg transition';
      confirmBtn.textContent = 'YES, TRUNCATE NOW';
      confirmBtn.onclick = () => {
        document.body.removeChild(overlay);
        if (confirm('Are you sure to truncate all of this data?') && confirm('Weeeeeehhh?') && confirm('Okaaaaaayyyy!!')) {
          form.submit();
        }
      };
      
      const cancelBtn = document.createElement('button');
      cancelBtn.className = 'px-8 py-3 bg-gray-500 hover:bg-gray-600 text-white font-bold rounded-lg text-lg transition';
      cancelBtn.textContent = 'CANCEL';
      cancelBtn.onclick = () => {
        document.body.removeChild(overlay);
      };
      
      buttonContainer.appendChild(confirmBtn);
      buttonContainer.appendChild(cancelBtn);
      
      container.appendChild(skullSvg);
      container.appendChild(warningText);
      container.appendChild(message);
      container.appendChild(buttonContainer);
      overlay.appendChild(container);
      document.body.appendChild(overlay);
    }
  </script>
</body>
</html>
