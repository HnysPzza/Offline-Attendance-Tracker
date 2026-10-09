<?php
require_once __DIR__ . '/config.php';
$db = db();
$message = '';
$error = '';

$todaySchedules = [];
$res = $db->query("SELECT * FROM schedules WHERE schedule_date >= CURDATE() AND schedule_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY schedule_date, start_time");
while ($row = $res->fetch_assoc()) { $todaySchedules[] = $row; }
$hasToday = false;
foreach ($todaySchedules as $s) { if ($s['schedule_date'] === (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d')) { $hasToday = true; break; } }

$q = trim($_REQUEST['q'] ?? '');
$perPage = 50;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$quickStudents = [];
$totalPages = 1;
{
    if ($q !== '') {
        $like = "%$q%";
        $cstmt = $db->prepare("SELECT COUNT(*) c FROM students WHERE student_id LIKE ? OR name LIKE ? OR course LIKE ? OR year_level LIKE ? OR section LIKE ? OR gender LIKE ? OR department LIKE ?");
        $cstmt->bind_param('sssssss', $like, $like, $like, $like, $like, $like, $like);
        $cstmt->execute();
        $count = (int)$cstmt->get_result()->fetch_assoc()['c'];
        $cstmt->close();

        $stmt = $db->prepare("SELECT * FROM students WHERE student_id LIKE ? OR name LIKE ? OR course LIKE ? OR year_level LIKE ? OR section LIKE ? OR gender LIKE ? OR department LIKE ? ORDER BY name LIMIT ? OFFSET ?");
        $stmt->bind_param('sssssssii', $like, $like, $like, $like, $like, $like, $like, $perPage, $offset);
    } else {
        $count = (int)$db->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
        $stmt = $db->prepare("SELECT * FROM students ORDER BY name LIMIT ? OFFSET ?");
        $stmt->bind_param('ii', $perPage, $offset);
    }
    $totalPages = max(1, (int)ceil($count / $perPage));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $quickStudents[] = $r; }
    $stmt->close();
}

if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');
    $scheduleId = intval($_GET['schedule_id'] ?? 0);
    $codesCsv = trim($_GET['codes'] ?? '');
    if ($scheduleId <= 0 || $codesCsv === '') { echo json_encode([]); exit; }
    $codes = array_values(array_filter(array_map('trim', explode(',', $codesCsv)), function($c){ return $c !== ''; }));
    if (count($codes) === 0) { echo json_encode([]); exit; }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $sql = "SELECT s.student_id AS code, a.time_in, a.time_out
            FROM students s
            LEFT JOIN attendance a ON a.student_id = s.id AND a.schedule_id = ?
            WHERE s.student_id IN ($placeholders)";
    $stmt = $db->prepare($sql);
    $types = 'i' . str_repeat('s', count($codes));
    $params = array_merge([$scheduleId], $codes);
    $refs = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    $stmt->bind_param($types, ...$refs);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[$r['code']] = [
            'time_in' => $r['time_in'],
            'time_out' => $r['time_out']
        ];
    }
    $stmt->close();
    echo json_encode($out);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'students') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;

    if ($q !== '') {
        $like = "%$q%";
        $cstmt = $db->prepare("SELECT COUNT(*) c FROM students WHERE student_id LIKE ? OR name LIKE ? OR course LIKE ? OR year_level LIKE ? OR section LIKE ? OR gender LIKE ? OR department LIKE ?");
        $cstmt->bind_param('sssssss', $like, $like, $like, $like, $like, $like, $like);
        $cstmt->execute();
        $count = (int)$cstmt->get_result()->fetch_assoc()['c'];
        $cstmt->close();

        $stmt = $db->prepare("SELECT student_id, name, course, year_level, section, gender, department FROM students WHERE student_id LIKE ? OR name LIKE ? OR course LIKE ? OR year_level LIKE ? OR section LIKE ? OR gender LIKE ? OR department LIKE ? ORDER BY name LIMIT ? OFFSET ?");
        $stmt->bind_param('sssssssii', $like, $like, $like, $like, $like, $like, $like, $perPage, $offset);
    } else {
        $count = (int)$db->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
        $stmt = $db->prepare("SELECT student_id, name, course, year_level, section, gender, department FROM students ORDER BY name LIMIT ? OFFSET ?");
        $stmt->bind_param('ii', $perPage, $offset);
    }

    $totalPages = max(1, (int)ceil($count / $perPage));
    $stmt->execute();
    $r = $stmt->get_result();
    $students = [];
    while ($row = $r->fetch_assoc()) { $students[] = $row; }
    $stmt->close();

    echo json_encode([
        'students' => $students,
        'page' => $page,
        'totalPages' => $totalPages
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $student_code = trim($_POST['student_id'] ?? '');
    $schedule_id = intval($_POST['schedule_id'] ?? 0);

    if ($student_code === '') { $error = 'Student ID is required.'; }
    if (!$error && $schedule_id <= 0) { $error = 'Select a schedule.'; }

    if (!$error) {
        $stmt = $db->prepare("SELECT * FROM students WHERE student_id = ?");
        $stmt->bind_param('s', $student_code);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$student) { $error = 'Student not found.'; }
    }

    if (!$error) {
        $stmt = $db->prepare("SELECT * FROM schedules WHERE id = ?");
        $stmt->bind_param('i', $schedule_id);
        $stmt->execute();
        $schedule = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$schedule) { $error = 'Schedule not found.'; }
        else {
            $today = (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d');
            if ($schedule['schedule_date'] !== $today) { $error = 'Schedule is not today.'; }
        }
    }

    if (!$error) {
        $now = new DateTime('now', new DateTimeZone(app_timezone()));

        if ($action === 'time_in') {
            $stmt = $db->prepare("SELECT id, time_in FROM attendance WHERE student_id = ? AND schedule_id = ?");
            $sid = intval($student['id']);
            $stmt->bind_param('ii', $sid, $schedule_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing && $existing['time_in']) { $error = 'Already timed in.'; }
            else {
                $start = new DateTime($schedule['schedule_date'] . ' ' . $schedule['start_time'], new DateTimeZone(app_timezone()));

                // Late threshold = later of:
                // 1) start_time + late_minutes, and
                // 2) configured time_in_end (or end_time fallback)
                // This keeps students On time inside the allowed Time In window.
                $lateDeadline = clone $start;
                $lateDeadline->modify('+' . intval($schedule['late_minutes']) . ' minutes');

                $timeInEndValue = trim((string)($schedule['time_in_end'] ?? $schedule['end_time'] ?? ''));
                if ($timeInEndValue !== '') {
                  $timeInEnd = new DateTime($schedule['schedule_date'] . ' ' . $timeInEndValue, new DateTimeZone(app_timezone()));
                  if ($timeInEnd > $lateDeadline) {
                    $lateDeadline = $timeInEnd;
                  }
                }

                // Allow time-in even after deadline, but mark as late.
                $status = ($now > $lateDeadline) ? 'Late' : 'On time';

                // Count only minutes beyond the actual late deadline.
                $minutesFromDeadline = max(0, (int)floor(($now->getTimestamp() - $lateDeadline->getTimestamp()) / 60));
                $minutesLate = ($status === 'Late') ? $minutesFromDeadline : 0;
                $dt = $now->format('Y-m-d H:i:s');

                $stmt = $db->prepare("INSERT INTO attendance (student_id, schedule_id, time_in, status, minutes_late) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE time_in = IF(time_in IS NULL, VALUES(time_in), time_in), status = IF(time_in IS NULL, VALUES(status), status), minutes_late = IF(time_in IS NULL, VALUES(minutes_late), minutes_late)");
                $stmt->bind_param('iissi', $sid, $schedule_id, $dt, $status, $minutesLate);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    $message = 'Time in recorded: ' . h($student['name']) . ' (' . h($student['student_id']) . ')';
                    log_activity('time_in', $student['name'] . ' (' . $student['student_id'] . ') - Schedule ID ' . $schedule_id);
                }
                else { $error = 'Failed to record time in.'; }
            }
        } elseif ($action === 'time_out') {
            $stmt = $db->prepare("SELECT id, time_in, time_out FROM attendance WHERE student_id = ? AND schedule_id = ?");
            $sid = intval($student['id']);
            $stmt->bind_param('ii', $sid, $schedule_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$existing || !$existing['time_in']) { $error = 'No time in found for this schedule.'; }
            elseif ($existing['time_out']) { $error = 'Already timed out.'; }
            else {
                $dt = $now->format('Y-m-d H:i:s');
                $stmt = $db->prepare("UPDATE attendance SET time_out = ? WHERE id = ?");
                $stmt->bind_param('si', $dt, $existing['id']);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    $message = 'Time out recorded: ' . h($student['name']) . ' (' . h($student['student_id']) . ')';
                    log_activity('time_out', $student['name'] . ' (' . $student['student_id'] . ') - Schedule ID ' . $schedule_id);
                }
                else { $error = 'Failed to record time out.'; }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['ajax'] ?? '') === '1' || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => $error === '',
        'message' => $message,
        'error' => $error
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Time In/Out - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo filemtime(__DIR__ . '/assets/css/responsive.css'); ?>">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'index.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-5 flex-1">
    <!-- Top Bar: Clock & Live Status (Replacing Giant Clock Card) -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-200">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Quick Time In / Out</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Rapid attendance logging for scheduled campus sessions.</p>
      </div>
      <div class="flex items-center gap-2 self-start sm:self-auto">
        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-slate-200 shadow-2xs font-mono text-xs sm:text-sm font-semibold text-slate-800">
          <i data-lucide="clock" class="w-4 h-4 text-[#0F3D87]"></i>
          <span id="serverTime"><?php echo (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d h:i:s A'); ?></span>
        </div>
      </div>
    </div>

    <?php if (!$hasToday): ?>
      <div class="flex items-center gap-2.5 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs sm:text-sm">
        <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-600 shrink-0"></i>
        <span>No schedules configured for today. <?php if (is_local_access()): ?>Create one in <a class="font-semibold underline hover:text-amber-900" href="schedules.php">Schedules</a>.<?php else: ?>Contact the administrator to create a schedule.<?php endif; ?></span>
      </div>
    <?php endif; ?>

    <!-- Controls Surface (Flat, No Floating Card Shadow) -->
    <section class="border border-slate-200 rounded-lg bg-white p-4 sm:p-5 space-y-4 shadow-xs">
      <!-- Schedule Selection -->
      <div>
        <label for="quickSchedule" class="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
          Select Active Schedule
        </label>
        <div class="relative">
          <select id="quickSchedule" class="w-full appearance-none rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 pr-10 text-sm text-slate-900 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none transition-colors">
            <option value="">-- Choose a schedule to enable Time In / Out --</option>
            <?php foreach ($todaySchedules as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" data-date="<?php echo h($s['schedule_date']); ?>">
                <?php echo h($s['schedule_date']); ?> &bull; <?php echo h($s['title']); ?> (<?php echo h(date('h:i A', strtotime($s['start_time']))); ?> - <?php echo h(date('h:i A', strtotime($s['end_time']))); ?>) [Late: <?php echo (int)$s['late_minutes']; ?>m]
              </option>
            <?php endforeach; ?>
          </select>
          <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400">
            <i data-lucide="chevron-down" class="w-4 h-4"></i>
          </div>
        </div>
        <div id="scheduleHint" class="mt-1 text-xs text-amber-600 font-medium"></div>
      </div>

      <!-- Search & Filters Toolbar -->
      <div class="flex flex-col sm:flex-row gap-2.5">
        <div class="relative flex-1">
          <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
            <i data-lucide="search" class="w-4 h-4"></i>
          </div>
          <input id="quickSearch" type="search" name="q" value="<?php echo h($q); ?>" placeholder="Search by Student ID, Name, Course, Year, Section..." class="w-full rounded-lg border border-slate-300 bg-white pl-10 pr-3.5 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none transition-colors" autofocus />
        </div>
        <?php if ($q !== ''): ?>
          <a href="index.php" class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 hover:border-rose-300 text-xs font-medium transition-colors shadow-2xs">
            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5 text-rose-600"></i>
            <span>Clear</span>
          </a>
        <?php endif; ?>
      </div>

      <!-- Offline / Sync Queue Bar -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 px-3.5 py-2.5 rounded-lg bg-slate-50 border border-slate-200 text-xs">
        <div class="flex items-center gap-2 text-slate-600 font-medium">
          <i data-lucide="hard-drive" class="w-4 h-4 text-slate-500"></i>
          <span id="queueStatusText">Queue: 0 pending</span>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" id="syncQueueBtn" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-[#0F3D87] text-white text-xs font-medium hover:bg-blue-900 transition-colors shadow-2xs">
            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
            <span>Sync now</span>
          </button>
          <button type="button" id="clearQueueBtn" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md border border-rose-200 bg-rose-50 text-rose-700 text-xs font-medium hover:bg-rose-100 hover:border-rose-300 transition-colors shadow-2xs">
            <i data-lucide="trash-2" class="w-3.5 h-3.5 text-rose-600"></i>
            <span>Clear</span>
          </button>
        </div>
      </div>

      <!-- Modern Flat Students Table -->
      <div class="table-container">
        <table class="table-modern w-full">
          <thead>
            <tr>
              <th class="w-28 text-left">ID</th>
              <th class="min-w-[180px] text-left">Student Name</th>
              <th class="w-24 text-left">Course</th>
              <th class="w-16 text-center">Year</th>
              <th class="w-20 text-center">Section</th>
              <th class="w-24 text-left">Gender</th>
              <th class="w-36 text-left">Department</th>
              <th class="w-64 text-center">Actions</th>
            </tr>
          </thead>
          <tbody id="studentsBody">
            <?php foreach ($quickStudents as $st): ?>
              <tr data-student="<?php echo h($st['student_id']); ?>">
                <td class="font-mono text-xs font-semibold text-slate-600"><?php echo h($st['student_id']); ?></td>
                <td class="font-medium text-slate-900 text-sm"><?php echo h($st['name']); ?></td>
                <td><span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700"><?php echo h($st['course']); ?></span></td>
                <td class="text-center font-medium text-slate-600"><?php echo h($st['year_level']); ?></td>
                <td class="text-center font-semibold text-slate-700"><?php echo h($st['section']); ?></td>
                <td class="text-slate-600 font-medium"><?php echo h($st['gender']); ?></td>
                <td class="text-slate-600 font-medium"><?php echo h($st['department']); ?></td>
                <td class="text-center">
                  <div class="inline-flex items-center gap-2 justify-center flex-nowrap">
                    <form method="post" data-quick="1" class="inline">
                      <input type="hidden" name="student_id" value="<?php echo h($st['student_id']); ?>" />
                      <input type="hidden" name="schedule_id" value="" />
                      <input type="hidden" name="q" value="<?php echo h($q); ?>" />
                      <input type="hidden" name="page" value="<?php echo (int)$page; ?>" />
                      <button name="action" value="time_in" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-300 transition-colors touch-target shadow-2xs" data-btn="in">
                        <i data-lucide="clock" class="w-3.5 h-3.5"></i>
                        <span class="whitespace-nowrap">Time In</span>
                      </button>
                    </form>
                    <form method="post" data-quick="1" class="inline">
                      <input type="hidden" name="student_id" value="<?php echo h($st['student_id']); ?>" />
                      <input type="hidden" name="schedule_id" value="" />
                      <input type="hidden" name="q" value="<?php echo h($q); ?>" />
                      <input type="hidden" name="page" value="<?php echo (int)$page; ?>" />
                      <button name="action" value="time_out" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-300 transition-colors touch-target shadow-2xs" data-btn="out">
                        <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                        <span class="whitespace-nowrap">Time Out</span>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <template id="studentRowTemplate">
          <tr data-student="">
            <td class="font-mono text-xs font-semibold text-slate-600 st-id"></td>
            <td class="font-medium text-slate-900 text-sm st-name"></td>
            <td><span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700 st-course"></span></td>
            <td class="text-center font-medium text-slate-600 st-year"></td>
            <td class="text-center font-semibold text-slate-700 st-sec"></td>
            <td class="text-slate-600 font-medium st-gender"></td>
            <td class="text-slate-600 font-medium st-dept"></td>
            <td class="text-center">
              <div class="inline-flex items-center gap-2 justify-center flex-nowrap">
                <form method="post" data-quick="1" class="inline">
                  <input type="hidden" name="student_id" value="" />
                  <input type="hidden" name="schedule_id" value="" />
                  <input type="hidden" name="q" value="" />
                  <input type="hidden" name="page" value="" />
                  <button name="action" value="time_in" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-300 transition-colors touch-target shadow-2xs" data-btn="in">
                    <i data-lucide="clock" class="w-3.5 h-3.5"></i>
                    <span class="whitespace-nowrap">Time In</span>
                  </button>
                </form>
                <form method="post" data-quick="1" class="inline">
                  <input type="hidden" name="student_id" value="" />
                  <input type="hidden" name="schedule_id" value="" />
                  <input type="hidden" name="q" value="" />
                  <input type="hidden" name="page" value="" />
                  <button name="action" value="time_out" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-300 transition-colors touch-target shadow-2xs" data-btn="out">
                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                    <span class="whitespace-nowrap">Time Out</span>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        </template>
      </div>

      <?php if (count($quickStudents) === 0): ?>
        <div class="py-8 text-center text-sm text-slate-400">No students found matching your criteria.</div>
      <?php endif; ?>

      <!-- Pagination Toolbar -->
      <div class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500">
        <div id="pageInfo">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
        <div id="pager" class="inline-flex items-center gap-1">
          <?php if ($page > 1): ?>
            <a class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors" href="?q=<?php echo urlencode($q); ?>&page=<?php echo (int)($page-1); ?>">Prev</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors" href="?q=<?php echo urlencode($q); ?>&page=<?php echo (int)($page+1); ?>">Next</a>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <!-- Modal Dialog -->
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
    const el = document.getElementById('serverTime');
    setInterval(() => {
      const d = new Date();
      const pad = n => String(n).padStart(2,'0');
      const hours = d.getHours();
      const ampm = hours >= 12 ? 'PM' : 'AM';
      const displayHours = hours % 12 || 12;
      const s = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(displayHours)}:${pad(d.getMinutes())}:${pad(d.getSeconds())} ${ampm}`;
      if (el) el.textContent = s;
    }, 1000);

    function updateButtons() {
      const sched = document.getElementById('quickSchedule');
      const scheduleId = sched && sched.value ? sched.value : '';
      const opt = sched && sched.selectedIndex >= 0 ? sched.options[sched.selectedIndex] : null;
      const selectedDate = opt && opt.dataset ? opt.dataset.date : '';
      const todayStr = (document.getElementById('serverTime')?.textContent || '').slice(0,10);
      const actionable = scheduleId && selectedDate === todayStr;
      const rows = Array.from(document.querySelectorAll('#studentsBody tr[data-student]'));
      rows.forEach(r => {
        const btnIn = r.querySelector('button[data-btn="in"]');
        const btnOut = r.querySelector('button[data-btn="out"]');
        if (!btnIn || !btnOut) return;
        const disableAll = !actionable;
        btnIn.disabled = disableAll;
        btnOut.disabled = disableAll;
        btnIn.classList.toggle('opacity-50', disableAll);
        btnOut.classList.toggle('opacity-50', disableAll);
        if (disableAll) { btnIn.title = 'Select a schedule for today'; btnOut.title = 'Select a schedule for today'; } else { btnIn.removeAttribute('title'); btnOut.removeAttribute('title'); }
      });
      const hint = document.getElementById('scheduleHint');
      if (hint) {
        if (!scheduleId) hint.textContent = '';
        else if (!actionable) hint.textContent = 'Only today\'s schedules can be used to time in/out.';
        else hint.textContent = '';
      }
      if (!actionable || rows.length === 0) return;
      const codes = rows.map(r => r.dataset.student).join(',');
      const url = `?api=status&schedule_id=${encodeURIComponent(scheduleId)}&codes=${encodeURIComponent(codes)}`;
      fetch(url).then(r => r.json()).then(data => {
        rows.forEach(r => {
          const code = r.dataset.student;
          const s = data[code] || {};
          const btnIn = r.querySelector('button[data-btn="in"]');
          const btnOut = r.querySelector('button[data-btn="out"]');
          if (!btnIn || !btnOut) return;
          btnIn.disabled = false; btnOut.disabled = false;
          btnIn.classList.remove('opacity-50'); btnOut.classList.remove('opacity-50');
          if (s.time_in) { btnIn.disabled = true; btnIn.classList.add('opacity-50'); btnIn.title = 'Already timed in'; }
          if (!s.time_in) { btnOut.disabled = true; btnOut.classList.add('opacity-50'); btnOut.title = 'No time in yet'; }
          if (s.time_out) { btnOut.disabled = true; btnOut.classList.add('opacity-50'); btnOut.title = 'Already timed out'; }
        });
      }).catch(() => {});
    }

    const QUEUE_KEY = 'quickAttendanceQueueV1';
    const queueStatusText = document.getElementById('queueStatusText');
    const syncQueueBtn = document.getElementById('syncQueueBtn');
    const clearQueueBtn = document.getElementById('clearQueueBtn');
    let syncingQueue = false;

    function getQueue(){
      try {
        const raw = localStorage.getItem(QUEUE_KEY);
        const arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
      } catch(e) { return []; }
    }

    function setQueue(arr){
      localStorage.setItem(QUEUE_KEY, JSON.stringify(arr || []));
      updateQueueStatus();
    }

    function updateQueueStatus(){
      const q = getQueue();
      const online = navigator.onLine;
      if (queueStatusText) {
        queueStatusText.textContent = `Queue: ${q.length} pending ${online ? '(online)' : '(offline)'}`;
      }
    }

    function enqueueAction(payload){
      const q = getQueue();
      q.push({
        id: `${Date.now()}_${Math.random().toString(36).slice(2,8)}`,
        student_id: payload.student_id,
        schedule_id: payload.schedule_id,
        action: payload.action,
        queued_at: Date.now()
      });
      setQueue(q);
    }

    async function sendQuickAction(payload, silent){
      const body = new URLSearchParams();
      body.set('ajax', '1');
      body.set('student_id', payload.student_id);
      body.set('schedule_id', String(payload.schedule_id || ''));
      body.set('action', payload.action);
      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body
      });
      const data = await res.json();
      if (!data.ok) {
        if (!silent) openModal('Error', data.error || 'Failed to save action.', 'error');
        return { ok: false, permanent: true };
      }
      if (!silent) openModal('Success', data.message || 'Attendance saved.', 'success');
      return { ok: true };
    }

    async function flushQueue(silent){
      if (syncingQueue || !navigator.onLine) return;
      syncingQueue = true;
      let q = getQueue();
      let synced = 0;
      let dropped = 0;
      while (q.length > 0) {
        const item = q[0];
        try {
          const r = await sendQuickAction(item, true);
          if (r.ok || r.permanent) {
            q.shift();
            if (r.ok) synced++; else dropped++;
            setQueue(q);
          } else {
            break;
          }
        } catch(e) {
          break;
        }
      }
      syncingQueue = false;
      updateButtons();
      if (!silent && (synced > 0 || dropped > 0)) {
        const msg = `Synced ${synced} item(s)` + (dropped > 0 ? `, skipped ${dropped} invalid item(s)` : '') + '.';
        openModal('Queue Sync', msg, 'success');
      }
    }

    function bindQuickForms(){
      document.querySelectorAll('form[data-quick="1"]').forEach(f => {
        f.addEventListener('submit', function(e) {
          e.preventDefault();
          const sched = document.getElementById('quickSchedule');
          if (!sched || !sched.value) { alert('Select a schedule first.'); return; }
          const hid = f.querySelector('input[name="schedule_id"]');
          if (hid) hid.value = sched.value;
          const studentId = (f.querySelector('input[name="student_id"]')?.value || '').trim();
          const scheduleId = (hid?.value || '').trim();
          const actionBtn = e.submitter && e.submitter.name === 'action' ? e.submitter : f.querySelector('button[name="action"]');
          const action = actionBtn ? actionBtn.value : '';
          if (!studentId || !scheduleId || !action) return;
          const payload = { student_id: studentId, schedule_id: scheduleId, action };
          if (!navigator.onLine) {
            enqueueAction(payload);
            openModal('Queued', 'Device is offline. Action saved and will auto-sync when online.', 'success');
            return;
          }
          sendQuickAction(payload, false)
            .then(r => {
              if (r.ok) {
                const qs = document.getElementById('quickSearch');
                if (qs && qs.value.trim() !== '') {
                  qs.value = '';
                  loadStudents(1);
                } else {
                  updateButtons();
                }
              }
            })
            .catch(() => {
              enqueueAction(payload);
              openModal('Queued', 'Network error. Action saved to queue and will auto-sync.', 'success');
            });
        });
      });
    }

    function renderStudents(data){
      const tbody = document.getElementById('studentsBody');
      const pageInfo = document.getElementById('pageInfo');
      const pager = document.getElementById('pager');
      const tpl = document.getElementById('studentRowTemplate');
      const searchVal = document.getElementById('quickSearch')?.value || '';
      tbody.innerHTML = '';
      if (tpl && tpl.content) {
        const frag = document.createDocumentFragment();
        for (const st of (data.students || [])) {
          const row = tpl.content.cloneNode(true).firstElementChild;
          row.dataset.student = st.student_id;
          row.querySelector('.st-id').textContent = st.student_id;
          row.querySelector('.st-name').textContent = st.name;
          row.querySelector('.st-course').textContent = st.course;
          row.querySelector('.st-year').textContent = st.year_level;
          row.querySelector('.st-sec').textContent = st.section;
          row.querySelector('.st-gender').textContent = st.gender;
          row.querySelector('.st-dept').textContent = st.department;
          row.querySelectorAll('input[name="student_id"]').forEach(i => { i.value = st.student_id; });
          row.querySelectorAll('input[name="q"]').forEach(i => { i.value = searchVal; });
          row.querySelectorAll('input[name="page"]').forEach(i => { i.value = String(data.page || 1); });
          frag.appendChild(row);
        }
        tbody.appendChild(frag);
      }
      pageInfo.textContent = `Page ${data.page} of ${data.totalPages}`;
      let phtml = '';
      if (data.page > 1) phtml += `<button data-page="${data.page-1}" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors">Prev</button>`;
      if (data.page < data.totalPages) phtml += `<button data-page="${data.page+1}" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors">Next</button>`;
      pager.innerHTML = phtml;
      bindQuickForms();
      updateButtons();
      if (window.refreshIcons) window.refreshIcons();
    }

    let studentsFetchController = null;
    function loadStudents(page=1){
      const input = document.getElementById('quickSearch');
      const q = input ? input.value.trim() : '';
      const u = new URL(window.location.href);
      if (q) u.searchParams.set('q', q); else u.searchParams.delete('q');
      u.searchParams.set('page', page);
      history.replaceState({}, '', u.toString());
      if (studentsFetchController) { try { studentsFetchController.abort(); } catch(e) {} }
      studentsFetchController = new AbortController();
      fetch(`?api=students&q=${encodeURIComponent(q)}&page=${page}`, { signal: studentsFetchController.signal })
        .then(r => r.json())
        .then(renderStudents)
        .catch(() => {});
    }

    document.addEventListener('DOMContentLoaded', () => {
      const sched = document.getElementById('quickSchedule');
      if (sched) {
        const saved = localStorage.getItem('quickScheduleId');
        if (saved) sched.value = saved;
        sched.addEventListener('change', () => {
          if (sched.value) localStorage.setItem('quickScheduleId', sched.value);
          else localStorage.removeItem('quickScheduleId');
          updateButtons();
        });
      }
      updateButtons();
      updateQueueStatus();
      bindQuickForms();
      if (syncQueueBtn) syncQueueBtn.addEventListener('click', () => { flushQueue(false); });
      if (clearQueueBtn) clearQueueBtn.addEventListener('click', () => {
        setQueue([]);
        openModal('Queue Cleared', 'Pending offline actions were cleared.', 'success');
      });
      window.addEventListener('online', () => {
        updateQueueStatus();
        flushQueue(true);
      });
      window.addEventListener('offline', updateQueueStatus);
      const qs = document.getElementById('quickSearch');
      if (qs) {
        let t = null;
        qs.addEventListener('input', () => {
          if (t) clearTimeout(t);
          t = setTimeout(() => loadStudents(1), 250);
        });
      }
      const pager = document.getElementById('pager');
      if (pager) {
        pager.addEventListener('click', (ev) => {
          const p = ev.target && ev.target.dataset ? ev.target.dataset.page : '';
          if (p) { ev.preventDefault(); loadStudents(parseInt(p,10) || 1); }
        });
      }
      if (navigator.onLine) flushQueue(true);
    });

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
</body>
</html>
