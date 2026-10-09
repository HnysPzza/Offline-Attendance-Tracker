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
  <title>Schedules - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo filemtime(__DIR__ . '/assets/css/responsive.css'); ?>">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'schedules.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-[92rem] w-full mx-auto px-4 sm:px-6 py-6 space-y-6 flex-1">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-200">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Schedule Management</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Define session time windows, grace periods, and import/export timelines.</p>
      </div>
      <div class="flex items-center gap-2 self-start sm:self-auto">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 border border-blue-200 text-[#0F3D87]">
          <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
          <span>Total Schedules: <strong class="text-[#0F3D87] font-bold"><?php echo count($schedules); ?></strong></span>
        </span>
      </div>
    </div>

    <!-- Mobile-Only Form Toggle Banner -->
    <div class="lg:hidden flex items-center justify-between gap-3 bg-white p-3.5 rounded-lg border border-slate-200 shadow-xs mb-4">
      <div class="flex items-center gap-2.5 min-w-0">
        <div class="p-2 rounded-md bg-blue-50 text-[#0F3D87] shrink-0">
          <i data-lucide="<?php echo $edit_schedule ? 'calendar-clock' : 'calendar-plus'; ?>" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <h2 class="text-xs font-bold text-slate-900 truncate"><?php echo $edit_schedule ? 'Edit Schedule Mode' : 'Create / Import Schedule'; ?></h2>
          <p class="text-[11px] text-slate-500 truncate">Configure window or manage timelines</p>
        </div>
      </div>
      <button type="button" id="toggleMobileSchedBtn" class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-md bg-[#0F3D87] text-white text-xs font-semibold shadow-2xs hover:bg-blue-900 active:scale-[0.98] transition-all min-h-[40px] cursor-pointer">
        <i data-lucide="<?php echo $edit_schedule ? 'edit' : 'plus'; ?>" id="toggleMobileSchedIcon" class="w-4 h-4"></i>
        <span id="toggleMobileSchedText"><?php echo $edit_schedule ? 'Close' : 'New / Tools'; ?></span>
      </button>
    </div>

    <!-- Main Workspace -->
    <div class="grid lg:grid-cols-12 gap-6 items-stretch">
      <!-- Left Column: Form & Tools (5 cols, collapsible on mobile) -->
      <div id="schedFormsPanel" class="<?php echo $edit_schedule ? 'block' : 'hidden'; ?> lg:block lg:col-span-5 space-y-6">
        <!-- Create / Edit Form -->
        <div class="border border-slate-200 rounded-lg bg-white p-5 space-y-4 shadow-xs">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div class="flex items-center gap-2">
              <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
                <i data-lucide="<?php echo $edit_schedule ? 'calendar-clock' : 'calendar-plus'; ?>" class="w-4 h-4"></i>
              </div>
              <h2 class="text-sm font-bold text-slate-900"><?php echo $edit_schedule ? 'Edit Schedule Window' : 'Create Schedule Window'; ?></h2>
            </div>
            <?php if ($edit_schedule): ?>
              <a href="schedules.php" class="text-xs font-medium text-slate-500 hover:text-slate-800 flex items-center gap-1 transition-colors">
                <i data-lucide="x" class="w-3.5 h-3.5"></i> Cancel Edit
              </a>
            <?php endif; ?>
          </div>

          <form method="post" class="space-y-3.5 text-xs">
            <input type="hidden" name="action" value="<?php echo $edit_schedule ? 'edit' : 'create'; ?>" />
            <?php if ($edit_schedule): ?>
              <input type="hidden" name="id" value="<?php echo (int)$edit_schedule['id']; ?>" />
            <?php endif; ?>

            <div>
              <label class="block font-semibold text-slate-700 mb-1">Schedule Title *</label>
              <input name="title" type="text" required placeholder="e.g. Regular Session / Morning Assembly" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none" value="<?php echo $edit_schedule ? h($edit_schedule['title']) : ''; ?>" />
            </div>

            <div>
              <label class="block font-semibold text-slate-700 mb-1">Schedule Date *</label>
              <input name="schedule_date" type="date" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none" value="<?php echo $edit_schedule ? h($edit_schedule['schedule_date']) : ''; ?>" />
            </div>

            <div class="p-3 bg-slate-50 border border-slate-200/80 rounded-md space-y-2.5">
              <div class="flex items-center gap-1.5 text-slate-800 font-semibold text-[11px] uppercase tracking-wider">
                <i data-lucide="log-in" class="w-3.5 h-3.5 text-blue-600"></i>
                <span>Time In Window</span>
              </div>
              <div class="grid grid-cols-2 gap-2.5">
                <div>
                  <label class="block text-slate-600 mb-1 text-[11px]">Start Time</label>
                  <input name="time_in_start" type="time" required class="w-full rounded border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none" value="<?php echo $edit_schedule ? h($edit_schedule['time_in_start']) : ''; ?>" />
                </div>
                <div>
                  <label class="block text-slate-600 mb-1 text-[11px]">End Time</label>
                  <input name="time_in_end" type="time" required class="w-full rounded border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none" value="<?php echo $edit_schedule ? h($edit_schedule['time_in_end']) : ''; ?>" />
                </div>
              </div>
            </div>

            <div class="p-3 bg-slate-50 border border-slate-200/80 rounded-md space-y-2.5">
              <div class="flex items-center gap-1.5 text-slate-800 font-semibold text-[11px] uppercase tracking-wider">
                <i data-lucide="log-out" class="w-3.5 h-3.5 text-amber-600"></i>
                <span>Time Out Window</span>
              </div>
              <div class="grid grid-cols-2 gap-2.5">
                <div>
                  <label class="block text-slate-600 mb-1 text-[11px]">Start Time</label>
                  <input name="time_out_start" type="time" required class="w-full rounded border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none" value="<?php echo $edit_schedule ? h($edit_schedule['time_out_start']) : ''; ?>" />
                </div>
                <div>
                  <label class="block text-slate-600 mb-1 text-[11px]">End Time</label>
                  <input name="time_out_end" type="time" required class="w-full rounded border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none" value="<?php echo $edit_schedule ? h($edit_schedule['time_out_end']) : ''; ?>" />
                </div>
              </div>
            </div>

            <div>
              <label class="block font-semibold text-slate-700 mb-1">Late Grace Period (Minutes)</label>
              <input id="late_minutes_input" name="late_minutes" type="number" min="0" value="<?php echo $edit_schedule ? (int)$edit_schedule['late_minutes'] : '0'; ?>" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none" />
              <p id="late_time_label" class="text-[11px] text-slate-500 mt-1 font-medium"></p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
              <?php if ($edit_schedule): ?>
                <a href="schedules.php" class="px-3.5 py-2 rounded-md border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 font-medium transition-colors">Cancel</a>
              <?php endif; ?>
              <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:bg-blue-900 font-semibold shadow-2xs transition-colors">
                <i data-lucide="<?php echo $edit_schedule ? 'check' : 'plus'; ?>" class="w-4 h-4"></i>
                <span><?php echo $edit_schedule ? 'Update Schedule' : 'Save Schedule'; ?></span>
              </button>
            </div>
          </form>
        </div>

        <!-- Utility Operations (Import / Export / Truncate) -->
        <div class="border border-slate-200 rounded-lg bg-white p-5 space-y-4 shadow-xs">
          <div class="flex items-center gap-2 pb-3 border-b border-slate-100">
            <div class="p-1.5 rounded-md bg-slate-100 text-slate-700">
              <i data-lucide="sliders" class="w-4 h-4"></i>
            </div>
            <h3 class="text-sm font-bold text-slate-900">Schedule Tools & Export</h3>
          </div>

          <div class="space-y-4 text-xs">
            <!-- Export -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
              <div>
                <p class="font-semibold text-slate-800">Export Schedules</p>
                <p class="text-[11px] text-slate-500">Download formatted CSV/Excel roster</p>
              </div>
              <a href="?export=xlsx" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 font-medium transition-colors">
                <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600"></i>
                <span>Export CSV</span>
              </a>
            </div>

            <!-- Import -->
            <div class="pb-3 border-b border-slate-100 space-y-2">
              <p class="font-semibold text-slate-800">Batch Import Schedules</p>
              <form method="post" enctype="multipart/form-data" class="flex items-center gap-2">
                <input type="hidden" name="action" value="import" />
                <input type="file" name="import_file" accept=".csv,.xlsx" required class="flex-1 text-[11px] file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-[11px] file:font-semibold file:bg-blue-50 file:text-[#0F3D87] hover:file:bg-blue-100 border border-slate-200 rounded-md p-1" />
                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md bg-[#0F3D87] text-white hover:bg-blue-900 font-semibold transition-colors">
                  <i data-lucide="upload" class="w-3.5 h-3.5"></i>
                  <span>Import</span>
                </button>
              </form>
            </div>

            <!-- Truncate -->
            <div class="space-y-1.5">
              <p class="font-semibold text-rose-700 flex items-center gap-1">
                <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Danger Zone
              </p>
              <p class="text-[11px] text-slate-500">Permanently clears all schedules and related attendance logs.</p>
              <form method="post" onsubmit="return handleTruncate(event);">
                <input type="hidden" name="action" value="truncate" />
                <button type="button" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-md border border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100 text-xs font-semibold transition-colors" onclick="handleTruncate(event)">
                  <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                  <span>Truncate All Schedules</span>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Schedules List Table (7 cols) -->
      <div class="lg:col-span-7 flex flex-col">
        <div class="border border-slate-200 rounded-lg bg-white px-3 sm:px-5 py-4 shadow-xs flex flex-col justify-between h-full space-y-4">
          <div class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
              <div class="flex items-center gap-2">
                <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
                  <i data-lucide="list" class="w-4 h-4"></i>
                </div>
                <h2 class="text-sm font-bold text-slate-900">Active Schedules</h2>
              </div>
              <div class="relative w-full sm:w-56">
                <i data-lucide="search" class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input id="scheduleSearch" type="text" placeholder="Search schedules..." class="w-full pl-8 pr-3 py-1.5 text-xs rounded-md border border-slate-200 bg-slate-50 focus:bg-white focus:border-[#0F3D87] outline-none transition-colors" />
              </div>
            </div>

            <div class="table-container table-sched-mobile overflow-x-auto">
              <table class="table-modern w-full">
                <thead>
                  <tr class="select-none">
                    <th class="min-w-[170px] text-left">Schedule Title</th>
                    <th class="w-28 text-left">Date</th>
                    <th class="w-36 text-center">Time In Window</th>
                    <th class="w-36 text-center">Time Out Window</th>
                    <th class="w-20 text-center">Grace</th>
                    <th class="w-20 text-center">Actions</th>
                  </tr>
                </thead>
                <tbody id="schedulesBody">
                  <?php if (empty($schedules)): ?>
                    <tr id="noSchedulesRow">
                      <td colspan="6" class="text-center py-8 text-xs text-slate-400">No schedules configured yet.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($schedules as $s): ?>
                      <tr class="schedule-row" data-id="<?php echo (int)$s['id']; ?>">
                        <td class="sc-title font-medium text-slate-900 schedule-title"><?php echo h($s['title']); ?></td>
                        <td class="sc-date text-xs text-slate-600 whitespace-nowrap schedule-date"><?php echo h(date('M j, Y', strtotime($s['schedule_date']))); ?></td>
                        <td class="sc-in text-center whitespace-nowrap">
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-blue-50 text-[#0F3D87] font-mono">
                            <span class="md:hidden font-sans text-[10px] text-blue-700">IN:</span> <?php echo h(date('g:i A', strtotime($s['time_in_start']))); ?> - <?php echo h(date('g:i A', strtotime($s['time_in_end']))); ?>
                          </span>
                        </td>
                        <td class="sc-out text-center whitespace-nowrap">
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-800 font-mono">
                            <span class="md:hidden font-sans text-[10px] text-amber-800">OUT:</span> <?php echo h(date('g:i A', strtotime($s['time_out_start']))); ?> - <?php echo h(date('g:i A', strtotime($s['time_out_end']))); ?>
                          </span>
                        </td>
                        <td class="sc-grace text-xs text-slate-600 whitespace-nowrap text-center font-medium">
                          <span class="md:hidden text-slate-400 font-normal">Grace: </span><?php echo (int)$s['late_minutes']; ?> min
                        </td>
                        <td class="sc-actions text-center">
                          <div class="inline-flex items-center gap-2.5 justify-center">
                            <a href="?edit_id=<?php echo (int)$s['id']; ?>" class="text-blue-600 hover:text-blue-800 transition-colors p-1.5 inline-flex items-center justify-center cursor-pointer" title="Edit Schedule" aria-label="Edit Schedule">
                              <i data-lucide="pencil" class="w-4 h-4"></i>
                            </a>
                            <form method="post" class="inline" onsubmit="return confirm('Delete this schedule?');">
                              <input type="hidden" name="action" value="delete" />
                              <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>" />
                              <button type="submit" class="text-rose-600 hover:text-rose-800 transition-colors p-1.5 inline-flex items-center justify-center cursor-pointer" title="Delete Schedule" aria-label="Delete Schedule">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                              </button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Bottom Pagination Controls -->
          <div id="schedulesPagerWrap" class="flex flex-col sm:flex-row items-center justify-between gap-2 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <div id="schedulesPageInfo">Showing 1 to <?php echo min(13, count($schedules)); ?> of <?php echo count($schedules); ?> schedules</div>
            <div id="schedulesPager" class="inline-flex items-center gap-1"></div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- Modern Modal -->
  <div id="appModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-xs" data-modal-close></div>
    <div id="appModalPanel" class="relative bg-white rounded-lg shadow-xl border border-slate-200 max-w-sm w-full p-5 space-y-4">
      <div class="flex items-center gap-2.5">
        <div id="modalIconBox" class="p-2 rounded-full bg-blue-50 text-[#0F3D87]">
          <i data-lucide="info" class="w-5 h-5"></i>
        </div>
        <h3 id="appModalTitle" class="text-sm font-bold text-slate-900">Notice</h3>
      </div>
      <p id="appModalMsg" class="text-xs text-slate-600 leading-relaxed"></p>
      <div class="flex justify-end pt-2">
        <button type="button" class="px-4 py-2 rounded-md bg-[#0F3D87] text-white text-xs font-semibold hover:bg-blue-900 transition-colors" data-modal-close>OK</button>
      </div>
    </div>
  </div>

  <script>
    function openModal(title, message, type) {
      const overlay = document.getElementById('appModal');
      const ttl = document.getElementById('appModalTitle');
      const msg = document.getElementById('appModalMsg');
      const iconBox = document.getElementById('modalIconBox');
      ttl.textContent = title || 'Notice';
      msg.textContent = message || '';
      if (iconBox) {
        if (type === 'error') {
          iconBox.className = 'p-2 rounded-full bg-rose-50 text-rose-600';
          iconBox.innerHTML = '<i data-lucide="alert-circle" class="w-5 h-5"></i>';
        } else if (type === 'success') {
          iconBox.className = 'p-2 rounded-full bg-emerald-50 text-emerald-600';
          iconBox.innerHTML = '<i data-lucide="check-circle-2" class="w-5 h-5"></i>';
        } else {
          iconBox.className = 'p-2 rounded-full bg-blue-50 text-[#0F3D87]';
          iconBox.innerHTML = '<i data-lucide="info" class="w-5 h-5"></i>';
        }
        if (window.refreshIcons) window.refreshIcons();
      }
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
    document.addEventListener('DOMContentLoaded', function() {
      const startTimeInput = document.querySelector('input[name="time_in_start"]');
      const lateMinutesInput = document.getElementById('late_minutes_input');
      const lateTimeLabel = document.getElementById('late_time_label');

      function calculateLateTime() {
        if (!startTimeInput || !lateMinutesInput || !lateTimeLabel) return;
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

      if (startTimeInput) startTimeInput.addEventListener('input', calculateLateTime);
      if (lateMinutesInput) lateMinutesInput.addEventListener('input', calculateLateTime);
      calculateLateTime();
    });

    // Schedules Client-side Search and Pagination
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('scheduleSearch');
      const tableBody = document.getElementById('schedulesBody');
      if (!tableBody) return;
      const rows = Array.from(tableBody.querySelectorAll('tr.schedule-row'));
      const pageInfo = document.getElementById('schedulesPageInfo');
      const pager = document.getElementById('schedulesPager');
      
      const perPage = 13;
      let currentPage = 1;
      let filteredRows = [...rows];

      function render() {
        const total = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (currentPage > totalPages) currentPage = totalPages;

        rows.forEach(r => r.style.display = 'none');

        if (total === 0) {
          if (!document.getElementById('noMatchRow')) {
            const noMatch = document.createElement('tr');
            noMatch.id = 'noMatchRow';
            noMatch.innerHTML = '<td colspan="6" class="text-center py-8 text-xs text-slate-400">No schedules matching search.</td>';
            tableBody.appendChild(noMatch);
          } else {
            document.getElementById('noMatchRow').style.display = '';
          }
          if (pageInfo) pageInfo.textContent = 'Showing 0 of 0 schedules';
          if (pager) pager.innerHTML = '';
          return;
        }

        const noMatch = document.getElementById('noMatchRow');
        if (noMatch) noMatch.style.display = 'none';

        const start = (currentPage - 1) * perPage;
        const end = Math.min(start + perPage, total);

        for (let i = start; i < end; i++) {
          filteredRows[i].style.display = '';
        }

        if (pageInfo) {
          pageInfo.textContent = `Showing ${start + 1} to ${end} of ${total} schedules`;
        }

        if (pager) {
          pager.innerHTML = '';
          if (totalPages <= 1) return;

          const prevBtn = document.createElement('button');
          prevBtn.type = 'button';
          prevBtn.className = `px-2 py-1 rounded border text-xs transition-colors ${currentPage === 1 ? 'border-slate-200 text-slate-300 cursor-not-allowed' : 'border-slate-200 text-slate-600 hover:bg-slate-100 cursor-pointer'}`;
          prevBtn.innerHTML = '<i data-lucide="chevron-left" class="w-3.5 h-3.5 inline"></i>';
          prevBtn.disabled = currentPage === 1;
          prevBtn.onclick = () => { if (currentPage > 1) { currentPage--; render(); } };
          pager.appendChild(prevBtn);

          for (let p = 1; p <= totalPages; p++) {
            const pageBtn = document.createElement('button');
            pageBtn.type = 'button';
            pageBtn.className = `px-2.5 py-1 rounded border text-xs font-medium transition-colors ${p === currentPage ? 'bg-[#0F3D87] border-[#0F3D87] text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100 cursor-pointer'}`;
            pageBtn.textContent = p;
            pageBtn.onclick = () => { currentPage = p; render(); };
            pager.appendChild(pageBtn);
          }

          const nextBtn = document.createElement('button');
          nextBtn.type = 'button';
          nextBtn.className = `px-2 py-1 rounded border text-xs transition-colors ${currentPage === totalPages ? 'border-slate-200 text-slate-300 cursor-not-allowed' : 'border-slate-200 text-slate-600 hover:bg-slate-100 cursor-pointer'}`;
          nextBtn.innerHTML = '<i data-lucide="chevron-right" class="w-3.5 h-3.5 inline"></i>';
          nextBtn.disabled = currentPage === totalPages;
          nextBtn.onclick = () => { if (currentPage < totalPages) { currentPage++; render(); } };
          pager.appendChild(nextBtn);

          if (window.refreshIcons) window.refreshIcons();
          else if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
        }
      }

      if (searchInput) {
        searchInput.addEventListener('input', function() {
          const q = this.value.toLowerCase().trim();
          filteredRows = rows.filter(r => {
            const title = (r.querySelector('.schedule-title')?.textContent || '').toLowerCase();
            const date = (r.querySelector('.schedule-date')?.textContent || '').toLowerCase();
            return title.includes(q) || date.includes(q);
          });
          currentPage = 1;
          render();
        });
      }

      render();
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
          <path d="M60 15C85 15 100 32 100 55C100 72 92 85 85 92L72 98C72 105 66 112 60 112C54 112 48 105 48 98L35 92C28 85 20 72 20 55C20 32 35 15 60 15Z" fill="#22c55e" stroke="#15803d" stroke-width="2"/>
          <circle cx="45" cy="52" r="12" fill="#000" stroke="#22c55e" stroke-width="1"/>
          <circle cx="45" cy="52" r="7" fill="#4ade80" opacity="0.6"/>
          <circle cx="75" cy="52" r="12" fill="#000" stroke="#22c55e" stroke-width="1"/>
          <circle cx="75" cy="52" r="7" fill="#4ade80" opacity="0.6"/>
          <ellipse cx="60" cy="68" rx="6" ry="10" fill="#000"/>
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

    document.addEventListener('DOMContentLoaded', () => {
      const toggleSchedBtn = document.getElementById('toggleMobileSchedBtn');
      const schedPanel = document.getElementById('schedFormsPanel');
      const toggleSchedText = document.getElementById('toggleMobileSchedText');
      if (toggleSchedBtn && schedPanel) {
        toggleSchedBtn.addEventListener('click', () => {
          const isHidden = schedPanel.classList.contains('hidden');
          if (isHidden) {
            schedPanel.classList.remove('hidden');
            if (toggleSchedText) toggleSchedText.textContent = 'Close';
            schedPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
          } else {
            schedPanel.classList.add('hidden');
            if (toggleSchedText) toggleSchedText.textContent = 'New / Tools';
          }
          if (window.refreshIcons) window.refreshIcons();
        });
      }
    });
  </script>
  <?php include __DIR__ . '/includes/footer.php'; ?>
