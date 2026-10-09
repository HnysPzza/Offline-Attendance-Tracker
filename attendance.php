<?php
require_once __DIR__ . '/config.php';
$db = db();

$date = $_GET['date'] ?? (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d');
$department = $_GET['department'] ?? '';
$section = trim($_GET['section'] ?? '');
$course = trim($_GET['course'] ?? '');
$year_level = trim($_GET['year_level'] ?? '');
$student_code = trim($_GET['student_id'] ?? '');
$student_name = trim($_GET['name'] ?? '');
$schedule_id = intval($_GET['schedule_id'] ?? 0);
$view = ($_GET['view'] ?? 'schedule') === 'student' ? 'student' : 'schedule';

$schedulesForDate = [];
$stmt = $db->query('SELECT * FROM schedules ORDER BY schedule_date DESC, start_time ASC');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) { $schedulesForDate[] = $row; }
    $stmt->close();
}

$sections = [];
$secRes = $db->query('SELECT DISTINCT section FROM students WHERE section <> "" ORDER BY section ASC');
if ($secRes) {
    while ($r = $secRes->fetch_assoc()) { $sections[] = $r['section']; }
    $secRes->close();
}

$courses = [];
$courseRes = $db->query('SELECT DISTINCT course FROM students WHERE course <> "" ORDER BY course ASC');
if ($courseRes) {
    while ($r = $courseRes->fetch_assoc()) { $courses[] = $r['course']; }
    $courseRes->close();
}

$years = [];
$yearRes = $db->query('SELECT DISTINCT year_level FROM students WHERE year_level <> "" ORDER BY CAST(year_level AS UNSIGNED), year_level ASC');
if ($yearRes) {
    while ($r = $yearRes->fetch_assoc()) { $years[] = $r['year_level']; }
    $yearRes->close();
}

$where = [];
$params = [];
$types = '';
$where[] = 'sc.schedule_date = ?';
$params[] = $date;
$types .= 's';
if ($schedule_id > 0) { $where[] = 'a.schedule_id = ?'; $params[] = $schedule_id; $types .= 'i'; }
if ($department !== '' && in_array($department, ['Education','Technology'])) { $where[] = 's.department = ?'; $params[] = $department; $types .= 's'; }
if ($section !== '') { $where[] = 's.section LIKE ?'; $params[] = "%$section%"; $types .= 's'; }
if ($course !== '') { $where[] = 's.course = ?'; $params[] = $course; $types .= 's'; }
if ($year_level !== '') { $where[] = 's.year_level = ?'; $params[] = $year_level; $types .= 's'; }
if ($student_code !== '') { $where[] = 's.student_id LIKE ?'; $params[] = "%$student_code%"; $types .= 's'; }
if ($student_name !== '') { $where[] = 's.name LIKE ?'; $params[] = "%$student_name%"; $types .= 's'; }

$sql = "SELECT a.*, s.student_id AS sid, s.name, s.year_level, s.section, s.gender, s.department, s.course, sc.title, sc.schedule_date, sc.start_time, sc.end_time
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        JOIN schedules sc ON a.schedule_id = sc.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sc.schedule_date DESC, sc.start_time ASC, s.name ASC";

$stmt = $db->prepare($sql);
if (count($params) > 0) {
    $refs = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    $stmt->bind_param($types, ...$refs);
}
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) { $rows[] = $row; }
$stmt->close();

$api = $_GET['api'] ?? '';
if ($api === 'students_attendance') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 30;
    $offset = ($page - 1) * $perPage;
    $where = ['1=1'];
    $params = [];
    $types = '';
    if ($q !== '') {
        $like = "%$q%";
        $where[] = '(s.student_id LIKE ? OR s.name LIKE ? OR s.section LIKE ? OR s.department LIKE ? OR s.course LIKE ?)';
        $params = array_fill(0, 5, $like);
        $types = 'sssss';
    }
    $sql = "SELECT s.id, s.student_id, s.name, s.section, s.department, s.course FROM students s WHERE " . implode(' AND ', $where) . " ORDER BY s.name LIMIT ? OFFSET ?";
    $countSql = "SELECT COUNT(*) c FROM students s WHERE " . implode(' AND ', $where);
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
    $stmt = $db->prepare($sql);
    $refs = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    $stmt->bind_param($types, ...$refs);
    $stmt->execute();
    $students = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['id'];
        $attRes = $db->prepare("SELECT sc.title, sc.schedule_date, sc.start_time, sc.end_time, a.time_in, a.time_out, a.status, a.minutes_late
            FROM attendance a JOIN schedules sc ON sc.id = a.schedule_id
            WHERE a.student_id = ? ORDER BY sc.schedule_date DESC, sc.start_time ASC");
        $attRes->bind_param('i', $sid);
        $attRes->execute();
        $att = [];
        $ar = $attRes->get_result();
        while ($arow = $ar->fetch_assoc()) { $att[] = $arow; }
        $attRes->close();
        $row['attendance'] = $att;
        $students[] = $row;
    }
    $stmt->close();
    echo json_encode(['students' => $students, 'page' => $page, 'totalPages' => $totalPages, 'count' => $count]);
    exit;
}

if ($api === 'schedules') {
    header('Content-Type: application/json');
    $arr = [];
    $rs = $db->query('SELECT id, title, schedule_date, start_time FROM schedules ORDER BY schedule_date DESC, start_time ASC');
    if ($rs) {
        while ($row = $rs->fetch_assoc()) { $arr[] = $row; }
        $rs->close();
    }
    echo json_encode($arr);
    exit;
}

if ($api === 'attendance') {
    header('Content-Type: application/json');
    $d = $_GET['date'] ?? $date;
    $dep = $_GET['department'] ?? '';
    $sec = trim($_GET['section'] ?? '');
    $crs = trim($_GET['course'] ?? '');
    $yr = trim($_GET['year_level'] ?? '');
    $sid = trim($_GET['student_id'] ?? '');
    $scid = intval($_GET['schedule_id'] ?? 0);
    $where = [];
    $params = [];
    $types = '';
    $where[] = 'sc.schedule_date = ?';
    $params[] = $d; $types .= 's';
    if ($scid > 0) { $where[] = 'a.schedule_id = ?'; $params[] = $scid; $types .= 'i'; }
    if ($dep !== '' && in_array($dep, ['Education','Technology'])) { $where[] = 's.department = ?'; $params[] = $dep; $types .= 's'; }
    if ($sec !== '') { $where[] = 's.section LIKE ?'; $params[] = "%$sec%"; $types .= 's'; }
    if ($crs !== '') { $where[] = 's.course = ?'; $params[] = $crs; $types .= 's'; }
    if ($yr !== '') { $where[] = 's.year_level = ?'; $params[] = $yr; $types .= 's'; }
    if ($sid !== '') { $where[] = 's.student_id LIKE ?'; $params[] = "%$sid%"; $types .= 's'; }
    $sname = trim($_GET['name'] ?? '');
    if ($sname !== '') { $where[] = 's.name LIKE ?'; $params[] = "%$sname%"; $types .= 's'; }
    $sql = "SELECT a.*, s.student_id AS sid, s.name, s.year_level, s.section, s.gender, s.department, s.course, sc.title, sc.schedule_date, sc.start_time, sc.end_time
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            JOIN schedules sc ON a.schedule_id = sc.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sc.schedule_date DESC, sc.start_time ASC, s.name ASC";
    $ps = $db->prepare($sql);
    if (count($params) > 0) { $refs = []; foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; } $ps->bind_param($types, ...$refs); }
    $ps->execute();
    $res2 = $ps->get_result();
    $html = '';
    while ($row = $res2->fetch_assoc()) {
        $statusClass = $row['status'] === 'On Time' ? 'bg-emerald-50 text-emerald-700' : ($row['status'] === 'Late' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600');
        $html .= '<tr class="hover:bg-slate-50 transition-colors">'
              . '<td class="whitespace-nowrap text-slate-600">' . h($row['schedule_date']) . '</td>'
              . '<td class="font-medium text-slate-900">' . h($row['title']) . '</td>'
              . '<td class="font-mono text-xs text-slate-600">' . h($row['sid']) . '</td>'
              . '<td class="font-medium text-slate-900">' . h($row['name']) . '</td>'
              . '<td><span class="inline-flex px-1.5 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700">' . h($row['course']) . '</span></td>'
              . '<td>' . h($row['year_level']) . '</td>'
              . '<td>' . h($row['section']) . '</td>'
              . '<td>' . h($row['gender']) . '</td>'
              . '<td class="text-xs text-slate-500">' . h($row['department']) . '</td>'
              . '<td class="font-mono text-xs text-slate-700">' . h($row['time_in'] ?: '—') . '</td>'
              . '<td class="font-mono text-xs text-slate-700">' . h($row['time_out'] ?: '—') . '</td>'
              . '<td><span class="inline-flex px-1.5 py-0.5 rounded text-[11px] font-semibold ' . $statusClass . '">' . h($row['status'] ?: '—') . '</span></td>'
              . '<td class="text-xs text-slate-600">' . ((int)$row['minutes_late'] > 0 ? (int)$row['minutes_late'] . 'm' : '—') . '</td>'
              . '</tr>';
    }
    $ps->close();
    echo json_encode([ 'html' => $html ]);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === '1') {
    log_activity('attendance_export', 'Date: ' . $date . ', Schedule: ' . $schedule_id . ', Department: ' . $department . ', Section: ' . $section . ', Course: ' . $course . ', Year: ' . $year_level);
    $slug = function($v) {
        $v = strtolower(trim((string)$v));
        $v = preg_replace('/\s+/', '-', $v);
        $v = preg_replace('/[^a-z0-9\-_]/', '', $v);
        $v = trim($v, '-_');
        return $v;
    };
    $nameParts = [];
    if ($department !== '') { $nameParts[] = $slug($department); }
    if ($course !== '') { $nameParts[] = $slug($course); }
    if ($year_level !== '') { $nameParts[] = $slug($year_level); }
    if ($section !== '') { $nameParts[] = $slug($section); }
    if ($schedule_id > 0) {
        foreach ($schedulesForDate as $sc) {
            if ((int)$sc['id'] === $schedule_id) {
                $nameParts[] = $slug($sc['title'] ?? '');
                break;
            }
        }
    }
    if (count($nameParts) === 0) {
        $nameParts[] = 'attendance';
    }
    $nameParts[] = $slug($date);
    $filename = implode('_', array_filter($nameParts));
    if ($filename === '') { $filename = 'attendance_' . $date; }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Schedule','Student ID','Name','Course','Year','Section','Gender','Department','Time In','Time Out','Status','Minutes Late']);
    foreach ($rows as $row) {
        $dateText = "'" . (string)$row['schedule_date'];
        $timeInText = $row['time_in'] ? ("'" . (string)$row['time_in']) : '';
        $timeOutText = $row['time_out'] ? ("'" . (string)$row['time_out']) : '';
        fputcsv($out, [
            $dateText,
            $row['title'],
            $row['sid'],
            $row['name'],
            $row['course'],
            $row['year_level'],
            $row['section'],
            $row['gender'],
            $row['department'],
            $timeInText,
            $timeOutText,
            $row['status'],
            $row['minutes_late'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance Records - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'attendance.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-[92rem] w-full mx-auto px-4 sm:px-6 py-6 space-y-6 flex-1">
    <!-- Page Header & View Toggle -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-200">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Attendance Records</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Filter, inspect real-time session logs, and export records.</p>
      </div>
      <div class="inline-flex p-1 rounded-lg bg-slate-200/70 border border-slate-200 text-xs font-semibold self-start sm:self-auto">
        <a href="?view=schedule&<?php echo http_build_query(array_filter(['date'=>$date,'schedule_id'=>$schedule_id,'department'=>$department,'section'=>$section,'course'=>$course,'year_level'=>$year_level,'student_id'=>$student_code,'name'=>$student_name])); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $view==='schedule' ? 'bg-white text-[#0F3D87] shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
          <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
          <span>By Schedule</span>
        </a>
        <a href="?view=student" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $view==='student' ? 'bg-white text-[#0F3D87] shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
          <i data-lucide="users" class="w-3.5 h-3.5"></i>
          <span>By Student</span>
        </a>
      </div>
    </div>

    <?php if ($view === 'schedule'): ?>
    <!-- Filter Toolbar Surface -->
    <div class="border border-slate-200 rounded-lg bg-white p-4 shadow-xs space-y-4">
      <form id="attFilters" method="get" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 items-end text-xs">
        <input type="hidden" name="view" value="schedule" />
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Date</label>
          <input type="date" name="date" value="<?php echo h($date); ?>" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none" />
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Schedule</label>
          <select name="schedule_id" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="0">All Schedules</option>
            <?php foreach ($schedulesForDate as $sc): ?>
              <option value="<?php echo (int)$sc['id']; ?>" <?php echo $schedule_id === (int)$sc['id'] ? 'selected' : ''; ?>><?php echo h($sc['title']); ?> — <?php echo h($sc['schedule_date'] ?? ''); ?> (<?php echo h(substr($sc['start_time'] ?? '', 0, 5)); ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Department</label>
          <select name="department" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="">All Departments</option>
            <option value="Education" <?php echo $department==='Education'?'selected':''; ?>>Education</option>
            <option value="Technology" <?php echo $department==='Technology'?'selected':''; ?>>Technology</option>
          </select>
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Course</label>
          <select name="course" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="">All Courses</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?php echo h($c); ?>" <?php echo $course === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Year</label>
          <select name="year_level" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?>
              <option value="<?php echo h($y); ?>" <?php echo $year_level === $y ? 'selected' : ''; ?>><?php echo h($y); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Section</label>
          <select name="section" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="">All Sections</option>
            <?php foreach ($sections as $sec): ?>
              <option value="<?php echo h($sec); ?>" <?php echo $section === $sec ? 'selected' : ''; ?>><?php echo h($sec); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Student ID</label>
          <input type="text" name="student_id" value="<?php echo h($student_code); ?>" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] outline-none" placeholder="Search ID..." />
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Name</label>
          <input type="text" name="name" value="<?php echo h($student_name); ?>" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] outline-none" placeholder="Search name..." />
        </div>

        <div class="sm:col-span-2 md:col-span-4 lg:col-span-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 pt-2.5 border-t border-slate-100">
          <div class="flex items-center gap-2">
            <a id="exportCsv" href="?date=<?php echo urlencode($date); ?>&schedule_id=<?php echo (int)$schedule_id; ?>&department=<?php echo urlencode($department); ?>&section=<?php echo urlencode($section); ?>&course=<?php echo urlencode($course); ?>&year_level=<?php echo urlencode($year_level); ?>&student_id=<?php echo urlencode($student_code); ?>&name=<?php echo urlencode($student_name); ?>&export=1" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold shadow-2xs transition-colors">
              <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600"></i>
              <span>Export CSV</span>
            </a>
            <button type="button" id="clearFilters" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-600 text-xs font-medium transition-colors">
              <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
              <span>Clear Filters</span>
            </button>
          </div>
          <div class="text-xs text-slate-500 font-medium">
            Found <strong class="text-slate-900 font-bold"><?php echo count($rows); ?></strong> record(s)
          </div>
        </div>
      </form>
    </div>

    <!-- Attendance Table Surface -->
    <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs space-y-4">
      <div class="table-container">
        <table class="table-modern">
          <thead>
            <tr>
              <th>Date</th>
              <th>Schedule</th>
              <th>Student ID</th>
              <th>Name</th>
              <th>Course</th>
              <th>Year</th>
              <th>Sec</th>
              <th>Gender</th>
              <th>Dept</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Status</th>
              <th>Late</th>
            </tr>
          </thead>
          <tbody id="attendanceBody">
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="13" class="text-center py-8 text-xs text-slate-400">No attendance records found for selected filters.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php $statusClass = $r['status'] === 'On Time' ? 'bg-emerald-50 text-emerald-700' : ($r['status'] === 'Late' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'); ?>
                <tr class="hover:bg-slate-50 transition-colors">
                  <td class="whitespace-nowrap text-slate-600"><?php echo h($r['schedule_date']); ?></td>
                  <td class="font-medium text-slate-900"><?php echo h($r['title']); ?></td>
                  <td class="font-mono text-xs text-slate-600"><?php echo h($r['sid']); ?></td>
                  <td class="font-medium text-slate-900"><?php echo h($r['name']); ?></td>
                  <td><span class="inline-flex px-1.5 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700"><?php echo h($r['course']); ?></span></td>
                  <td><?php echo h($r['year_level']); ?></td>
                  <td><?php echo h($r['section']); ?></td>
                  <td><?php echo h($r['gender']); ?></td>
                  <td class="text-xs text-slate-500"><?php echo h($r['department']); ?></td>
                  <td class="font-mono text-xs text-slate-700"><?php echo h($r['time_in'] ?: '—'); ?></td>
                  <td class="font-mono text-xs text-slate-700"><?php echo h($r['time_out'] ?: '—'); ?></td>
                  <td><span class="inline-flex px-1.5 py-0.5 rounded text-[11px] font-semibold <?php echo $statusClass; ?>"><?php echo h($r['status'] ?: '—'); ?></span></td>
                  <td class="text-xs text-slate-600"><?php echo ((int)$r['minutes_late'] > 0 ? (int)$r['minutes_late'] . 'm' : '—'); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <!-- By Student View -->
    <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-100">
        <div>
          <h2 class="text-sm font-bold text-slate-900">Student Attendance History</h2>
          <p class="text-xs text-slate-500">Click a student row to inspect all recorded session logs.</p>
        </div>
      </div>
      <div class="flex flex-col sm:flex-row gap-2.5">
        <div class="relative flex-1">
          <input type="search" id="studentSearch" value="<?php echo h($view==='student' ? ($_GET['q'] ?? '') : ''); ?>" placeholder="Search by Student ID, Name, Section, Department, Course..." class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] outline-none" />
        </div>
        <button type="button" id="studentSearchBtn" class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:bg-blue-900 text-xs font-semibold shadow-2xs transition-colors">
          <i data-lucide="search" class="w-3.5 h-3.5"></i>
          <span>Search</span>
        </button>
      </div>
      <div id="studentsList" class="space-y-2 pt-2"></div>
      <div id="studentsPager" class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500"></div>
    </div>
    <?php endif; ?>
  </main>

  <script>
    const f = document.getElementById('attFilters');
    const dateEl = f ? f.querySelector('input[name="date"]') : null;
    const schedEl = f ? f.querySelector('select[name="schedule_id"]') : null;
    const deptEl = f ? f.querySelector('select[name="department"]') : null;
    const secEl = f ? f.querySelector('select[name="section"]') : null;
    const courseEl = f ? f.querySelector('select[name="course"]') : null;
    const yearEl = f ? f.querySelector('select[name="year_level"]') : null;
    const sidEl = f ? f.querySelector('input[name="student_id"]') : null;
    const nameEl = f ? f.querySelector('input[name="name"]') : null;
    const bodyEl = document.getElementById('attendanceBody');
    const exportEl = document.getElementById('exportCsv');
    const clearEl = document.getElementById('clearFilters');

    function qs() {
      if (!f) return '';
      const p = new URLSearchParams();
      if (dateEl && dateEl.value) p.set('date', dateEl.value);
      const sv = schedEl ? parseInt(schedEl.value || '0', 10) || 0 : 0; p.set('schedule_id', String(sv));
      if (deptEl && deptEl.value) p.set('department', deptEl.value);
      if (secEl && secEl.value.trim()) p.set('section', secEl.value.trim());
      if (courseEl && courseEl.value.trim()) p.set('course', courseEl.value.trim());
      if (yearEl && yearEl.value.trim()) p.set('year_level', yearEl.value.trim());
      if (sidEl && sidEl.value.trim()) p.set('student_id', sidEl.value.trim());
      if (nameEl && nameEl.value.trim()) p.set('name', nameEl.value.trim());
      return p.toString();
    }

    function syncUrlAndExport() {
      const u = new URL(window.location.href);
      const p = new URLSearchParams(qs());
      u.search = p.toString();
      history.replaceState({}, '', u.toString());
      if (exportEl) exportEl.href = '?' + p.toString() + '&export=1';
    }

    let fetchController = null;
    function loadRows() {
      syncUrlAndExport();
      if (fetchController) { try { fetchController.abort(); } catch(e) {} }
      fetchController = new AbortController();
      fetch('?api=attendance&' + qs(), { signal: fetchController.signal })
        .then(r => r.json())
        .then(d => { 
          if (bodyEl) {
            bodyEl.innerHTML = d.html || '<tr><td colspan="13" class="text-center py-8 text-xs text-slate-400">No records found for selected filters.</td></tr>'; 
          }
        })
        .catch(() => {});
    }

    function reloadSchedules() {
      if (!schedEl) return;
      const selected = schedEl.value;
      fetch('?api=schedules&date=' + encodeURIComponent(dateEl ? dateEl.value : ''))
        .then(r => r.json())
        .then(arr => {
          const opts = ['<option value="0">All Schedules</option>'].concat(arr.map(s => `<option value="${s.id}">${s.title || ''} — ${s.schedule_date || ''} (${(s.start_time || '').substring(0,5)})</option>`));
          schedEl.innerHTML = opts.join('');
          if (selected) schedEl.value = selected;
          loadRows();
        }).catch(() => { loadRows(); });
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (f) {
        let t1 = null, t2 = null, t3 = null;
        if (dateEl) dateEl.addEventListener('change', reloadSchedules);
        if (schedEl) schedEl.addEventListener('change', loadRows);
        if (deptEl) deptEl.addEventListener('change', loadRows);
        if (courseEl) courseEl.addEventListener('change', loadRows);
        if (yearEl) yearEl.addEventListener('change', loadRows);
        if (secEl) secEl.addEventListener('input', () => { if (t1) clearTimeout(t1); t1 = setTimeout(loadRows, 300); });
        if (sidEl) sidEl.addEventListener('input', () => { if (t2) clearTimeout(t2); t2 = setTimeout(loadRows, 300); });
        if (nameEl) nameEl.addEventListener('input', () => { if (t3) clearTimeout(t3); t3 = setTimeout(loadRows, 300); });
        if (clearEl) clearEl.addEventListener('click', () => {
          if (schedEl) schedEl.value = '0';
          if (deptEl) deptEl.value = '';
          if (secEl) secEl.value = '';
          if (courseEl) courseEl.value = '';
          if (yearEl) yearEl.value = '';
          if (sidEl) sidEl.value = '';
          if (nameEl) nameEl.value = '';
          loadRows();
        });
      }
      // By Student view
      const studentSearch = document.getElementById('studentSearch');
      const studentSearchBtn = document.getElementById('studentSearchBtn');
      const studentsList = document.getElementById('studentsList');
      const studentsPager = document.getElementById('studentsPager');
      if (studentSearch && studentsList) {
        function loadStudents(pg = 1) {
          const q = studentSearch ? studentSearch.value.trim() : '';
          fetch('?api=students_attendance&q=' + encodeURIComponent(q) + '&page=' + pg)
            .then(r => r.json())
            .then(d => {
              let html = '';
              for (const s of (d.students || [])) {
                const att = s.attendance || [];
                const attHtml = att.length === 0 ? '<tr><td colspan="7" class="text-center py-4 text-xs text-slate-400">No attendance records yet.</td></tr>' : att.map(a => 
                  '<tr class="hover:bg-slate-50 transition-colors"><td class="whitespace-nowrap font-medium text-slate-700">' + esc(a.schedule_date) + '</td><td>' + esc(a.title) + '</td><td class="font-mono text-xs">' + esc(a.start_time || '') + ' - ' + esc(a.end_time || '') + '</td><td class="font-mono text-xs text-slate-700">' + esc(a.time_in || '—') + '</td><td class="font-mono text-xs text-slate-700">' + esc(a.time_out || '—') + '</td><td><span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold ' + (a.status === 'On Time' ? 'bg-emerald-50 text-emerald-700' : (a.status === 'Late' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600')) + '">' + esc(a.status || '—') + '</span></td><td class="text-xs text-slate-600">' + (a.minutes_late ? a.minutes_late + 'm' : '—') + '</td></tr>'
                ).join('');
                html += '<div class="border border-slate-200 rounded-lg overflow-hidden bg-white"><div class="flex flex-wrap items-center justify-between gap-2 p-3 bg-slate-50 hover:bg-slate-100 cursor-pointer transition-colors" data-toggle><div class="flex items-center gap-2"><span class="font-semibold text-xs text-slate-900">' + esc(s.name) + '</span><span class="font-mono text-xs text-slate-500">(' + esc(s.student_id) + ')</span></div><div class="text-xs text-slate-600 font-medium">' + esc(s.section) + ' · ' + esc(s.course) + '</div><div class="text-[11px] font-semibold text-[#0F3D87] bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-full">' + att.length + ' record(s)</div></div><div class="att-detail hidden p-3 border-t border-slate-100 bg-white"><div class="table-container"><table class="table-modern w-full text-xs"><thead><tr><th>Date</th><th>Schedule</th><th>Window</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Late</th></tr></thead><tbody>' + attHtml + '</tbody></table></div></div></div>';
              }
              studentsList.innerHTML = html || '<div class="text-center py-8 text-xs text-slate-400">No students found.</div>';
              studentsList.querySelectorAll('[data-toggle]').forEach(el => {
                el.addEventListener('click', () => { el.nextElementSibling.classList.toggle('hidden'); });
              });
              if (studentsPager) {
                const qParam = (studentSearch ? studentSearch.value : '').trim();
                const qStr = qParam ? '&q=' + encodeURIComponent(qParam) : '';
                if (d.totalPages > 1) {
                  studentsPager.innerHTML = '<div>Page ' + d.page + ' of ' + d.totalPages + ' (' + (d.count || 0) + ' students)</div><div class="flex gap-1">' + (d.page > 1 ? '<a href="?view=student' + qStr + '&page=' + (d.page-1) + '" class="px-2.5 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700">Prev</a>' : '') + (d.page < d.totalPages ? '<a href="?view=student' + qStr + '&page=' + (d.page+1) + '" class="px-2.5 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700">Next</a>' : '') + '</div>';
                } else {
                  studentsPager.innerHTML = d.count ? 'Total: ' + d.count + ' student(s)' : '';
                }
              }
              if (window.refreshIcons) window.refreshIcons();
            }).catch(() => { studentsList.innerHTML = '<div class="text-rose-500 text-xs py-4">Failed to load student data.</div>'; });
        }
        function esc(s) { return String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
        const urlParams = new URLSearchParams(window.location.search);
        loadStudents(parseInt(urlParams.get('page') || '1', 10));
        if (studentSearchBtn) studentSearchBtn.addEventListener('click', () => { const q = new URLSearchParams(); q.set('view', 'student'); q.set('q', studentSearch.value.trim()); q.set('page', '1'); window.location.href = '?' + q.toString(); });
        if (studentSearch) { let t; studentSearch.addEventListener('input', () => { if (t) clearTimeout(t); t = setTimeout(() => loadStudents(1), 400); }); }
      }
    });
  </script>
  <?php include __DIR__ . '/includes/footer.php'; ?>
