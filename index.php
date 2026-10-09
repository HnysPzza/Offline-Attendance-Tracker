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
  <title>Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
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

  <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
    <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6">
      <h2 class="text-lg font-semibold mb-4">Quick Time In / Out</h2>
      <?php if ($error): ?>
        <div class="mb-4 p-3 rounded bg-red-100 text-red-700 text-sm hidden"><?php echo h($error); ?></div>
      <?php elseif ($message): ?>
        <div class="mb-4 p-3 rounded bg-green-100 text-green-700 text-sm hidden"><?php echo h($message); ?></div>
      <?php endif; ?>
      <div class="mb-6 flex items-center justify-center"><div id="serverTime" class="text-3xl md:text-5xl font-semibold tracking-tight text-gray-900"><?php echo (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d h:i:s A'); ?></div></div>
      <?php if (!$hasToday): ?>
        <div class="text-sm text-gray-700 mb-4">No schedules for today.<?php if (is_local_access()): ?> Create one in <a class="text-blue-600 underline" href="schedules.php">Schedules</a>.<?php else: ?> Contact the administrator to create schedules.<?php endif; ?></div>
      <?php endif; ?>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700">Schedule</label>
          <select id="quickSchedule" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-blue-500 focus:border-blue-500">
            <option value="">Select schedule</option>
            <?php foreach ($todaySchedules as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" data-date="<?php echo h($s['schedule_date']); ?>"><?php echo h($s['schedule_date']); ?> — <?php echo h($s['title']); ?> — <?php echo h(date('h:i A', strtotime($s['start_time']))); ?> to <?php echo h(date('h:i A', strtotime($s['end_time']))); ?> (late: <?php echo (int)$s['late_minutes']; ?>m)</option>
            <?php endforeach; ?>
          </select>
          <div id="scheduleHint" class="mt-2 text-xs text-gray-500"></div>
        </div>
        <form method="get" class="mb-4 flex gap-2">
          <input id="quickSearch" type="search" name="q" value="<?php echo h($q); ?>" placeholder="Search by Student ID, Name, Course, Year, Section, Gender, Department" class="flex-1 rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" autofocus />
          <button class="px-4 py-2 rounded-md bg-[#D4AF37] text-[#0F3D87] hover:opacity-90 hidden md:inline-flex">Search</button>
          <?php if ($q !== ''): ?>
            <a href="index.php" class="px-4 py-2 rounded-md bg-gray-100 text-gray-800 hover:bg-gray-200">Clear</a>
          <?php endif; ?>
        </form>
        <div class="mb-4 rounded-md border border-gray-200 bg-gray-50 p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-sm">
          <div id="queueStatusText" class="text-gray-700">Queue: 0 pending</div>
          <div class="flex items-center gap-2">
            <button type="button" id="syncQueueBtn" class="px-3 py-1.5 rounded-md bg-[#0F3D87] text-white text-xs hover:opacity-95">Sync now</button>
            <button type="button" id="clearQueueBtn" class="px-3 py-1.5 rounded-md bg-gray-200 text-gray-700 text-xs hover:bg-gray-300">Clear queue</button>
          </div>
        </div>
          <div class="overflow-x-auto -mx-4 sm:mx-0">
            <table class="table-responsive-cards min-w-full text-sm divide-y divide-gray-200">
              <thead>
                <tr class="text-left bg-gray-50 text-gray-600 text-xs uppercase tracking-wide">
                  <th class="py-2 pr-4 font-medium">Student ID</th>
                  <th class="py-2 pr-4 font-medium">Name</th>
                  <th class="py-2 pr-4 font-medium">Course</th>
                  <th class="py-2 pr-4 font-medium">Year</th>
                  <th class="py-2 pr-4 font-medium">Section</th>
                  <th class="py-2 pr-4 font-medium">Gender</th>
                  <th class="py-2 pr-4 font-medium">Department</th>
                  <th class="py-2 pr-4 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody id="studentsBody">
                <?php foreach ($quickStudents as $st): ?>
                  <tr class="border-b last:border-0 hover:bg-gray-50" data-student="<?php echo h($st['student_id']); ?>">
                    <td class="py-2 pr-4" data-label="Student ID"><?php echo h($st['student_id']); ?></td>
                    <td class="py-2 pr-4" data-label="Name"><?php echo h($st['name']); ?></td>
                    <td class="py-2 pr-4" data-label="Course"><?php echo h($st['course']); ?></td>
                    <td class="py-2 pr-4" data-label="Year"><?php echo h($st['year_level']); ?></td>
                    <td class="py-2 pr-4" data-label="Section"><?php echo h($st['section']); ?></td>
                    <td class="py-2 pr-4" data-label="Gender"><?php echo h($st['gender']); ?></td>
                    <td class="py-2 pr-4" data-label="Department"><?php echo h($st['department']); ?></td>
                    <td class="py-2 pr-4" data-label="Actions">
                      <div class="cell-actions inline-flex flex-wrap gap-2">
                      <form method="post" data-quick="1" class="inline">
                        <input type="hidden" name="student_id" value="<?php echo h($st['student_id']); ?>" />
                        <input type="hidden" name="schedule_id" value="" />
                        <input type="hidden" name="q" value="<?php echo h($q); ?>" />
                        <input type="hidden" name="page" value="<?php echo (int)$page; ?>" />
                        <button name="action" value="time_in" class="px-3.5 py-2 sm:py-1.5 rounded-md bg-[#0F3D87] text-white hover:opacity-95 text-xs font-medium touch-target" data-btn="in">Time In</button>
                      </form>
                      <form method="post" data-quick="1" class="inline">
                        <input type="hidden" name="student_id" value="<?php echo h($st['student_id']); ?>" />
                        <input type="hidden" name="schedule_id" value="" />
                        <input type="hidden" name="q" value="<?php echo h($q); ?>" />
                        <input type="hidden" name="page" value="<?php echo (int)$page; ?>" />
                        <button name="action" value="time_out" class="px-3.5 py-2 sm:py-1.5 rounded-md bg-gray-800 text-white hover:bg-gray-900 text-xs font-medium touch-target" data-btn="out">Time Out</button>
                      </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if (count($quickStudents) === 0): ?>
              <div class="text-sm text-gray-600 mt-4">No students found.</div>
            <?php endif; ?>
            <div class="flex items-center justify-between mt-4 text-sm">
              <div id="pageInfo">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
              <div id="pager" class="space-x-2">
                <?php if ($page > 1): ?>
                  <a class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50" href="?q=<?php echo urlencode($q); ?>&page=<?php echo (int)($page-1); ?>">Prev</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                  <a class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50" href="?q=<?php echo urlencode($q); ?>&page=<?php echo (int)($page+1); ?>">Next</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
    </div>
  </main>

  <script>
    const el = document.getElementById('serverTime');
    const start = new Date();
    setInterval(() => {
      const d = new Date();
      const pad = n => String(n).padStart(2,'0');
      const hours = d.getHours();
      const ampm = hours >= 12 ? 'PM' : 'AM';
      const displayHours = hours % 12 || 12;
      const s = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(displayHours)}:${pad(d.getMinutes())}:${pad(d.getSeconds())} ${ampm}`;
      el.textContent = s;
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
          // Reset base state
          btnIn.disabled = false; btnOut.disabled = false;
          btnIn.classList.remove('opacity-50'); btnOut.classList.remove('opacity-50');
          if (s.time_in) { btnIn.disabled = true; btnIn.classList.add('opacity-50'); btnIn.title = 'Already timed in'; }
          if (!s.time_in) { btnOut.disabled = true; btnOut.classList.add('opacity-50'); btnOut.title = 'No time in yet'; }
          if (s.time_out) { btnOut.disabled = true; btnOut.classList.add('opacity-50'); btnOut.title = 'Already timed out'; }
        });
      }).catch(() => {/* ignore */});
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
          if (!sched || !sched.value) { alert('Select a schedule first.'); e.preventDefault(); return; }
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
      const e = s => String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
      let rows = '';
      for (const st of (data.students||[])) {
        rows += `
          <tr class="border-b last:border-0 hover:bg-gray-50" data-student="${e(st.student_id)}">
            <td class="py-2 pr-4" data-label="Student ID">${e(st.student_id)}</td>
            <td class="py-2 pr-4" data-label="Name">${e(st.name)}</td>
            <td class="py-2 pr-4" data-label="Course">${e(st.course)}</td>
            <td class="py-2 pr-4" data-label="Year">${e(st.year_level)}</td>
            <td class="py-2 pr-4" data-label="Section">${e(st.section)}</td>
            <td class="py-2 pr-4" data-label="Gender">${e(st.gender)}</td>
            <td class="py-2 pr-4" data-label="Department">${e(st.department)}</td>
            <td class="py-2 pr-4" data-label="Actions">
              <div class="cell-actions inline-flex flex-wrap gap-2">
              <form method="post" data-quick="1" class="inline">
                <input type="hidden" name="student_id" value="${e(st.student_id)}" />
                <input type="hidden" name="schedule_id" value="" />
                <input type="hidden" name="q" value="${e(document.getElementById('quickSearch').value)}" />
                <input type="hidden" name="page" value="${e(data.page)}" />
                <button name="action" value="time_in" class="px-3.5 py-2 sm:py-1.5 rounded-md bg-[#0F3D87] text-white hover:opacity-95 text-xs font-medium touch-target" data-btn="in">Time In</button>
              </form>
              <form method="post" data-quick="1" class="inline">
                <input type="hidden" name="student_id" value="${e(st.student_id)}" />
                <input type="hidden" name="schedule_id" value="" />
                <input type="hidden" name="q" value="${e(document.getElementById('quickSearch').value)}" />
                <input type="hidden" name="page" value="${e(data.page)}" />
                <button name="action" value="time_out" class="px-3.5 py-2 sm:py-1.5 rounded-md bg-gray-800 text-white hover:bg-gray-900 text-xs font-medium touch-target" data-btn="out">Time Out</button>
              </form>
              </div>
            </td>
          </tr>`;
      }
      tbody.innerHTML = rows;
      pageInfo.textContent = `Page ${data.page} of ${data.totalPages}`;
      let phtml = '';
      if (data.page > 1) phtml += `<button data-page="${data.page-1}" class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50">Prev</button>`;
      if (data.page < data.totalPages) phtml += `<button data-page="${data.page+1}" class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50">Next</button>`;
      pager.innerHTML = phtml;
      bindQuickForms();
      updateButtons();
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

  </script>
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
  </script>
</body>
</html>
