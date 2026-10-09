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
        $html .= '<tr class="border-b border-gray-200 divide-x divide-gray-100 hover:bg-gray-50">'
              . '<td class="py-2 pr-4" data-label="Date">' . h($row['schedule_date']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Schedule">' . h($row['title']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Student ID">' . h($row['sid']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Name">' . h($row['name']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Course">' . h($row['course']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Year">' . h($row['year_level']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Section">' . h($row['section']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Gender">' . h($row['gender']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Department">' . h($row['department']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Time In">' . h($row['time_in']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Time Out">' . h($row['time_out']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Status">' . h($row['status']) . '</td>'
              . '<td class="py-2 pr-4" data-label="Late (min)">' . (int)$row['minutes_late'] . '</td>'
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
  <title>Attendance</title>
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
    <div class="flex gap-2 mb-4 flex-wrap">
      <a href="?view=schedule&<?php echo http_build_query(array_filter(['date'=>$date,'schedule_id'=>$schedule_id,'department'=>$department,'section'=>$section,'course'=>$course,'year_level'=>$year_level,'student_id'=>$student_code,'name'=>$student_name])); ?>" class="px-4 py-2 rounded-md font-medium <?php echo $view==='schedule' ? 'bg-[#0F3D87] text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">By Schedule</a>
      <a href="?view=student" class="px-4 py-2 rounded-md font-medium <?php echo $view==='student' ? 'bg-[#0F3D87] text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">By Student</a>
    </div>

    <?php if ($view === 'schedule'): ?>
    <div class="bg-white shadow-md ring-1 ring-gray-200 rounded-xl p-4 sm:p-6 mb-4 sm:mb-6">
      <form id="attFilters" method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-8 gap-4 items-end">
        <input type="hidden" name="view" value="schedule" />
        <div>
          <label class="block text-sm font-medium text-gray-700">Date</label>
          <input type="date" name="date" value="<?php echo h($date); ?>" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Schedule</label>
          <select name="schedule_id" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="0">All</option>
            <?php foreach ($schedulesForDate as $sc): ?>
              <option value="<?php echo (int)$sc['id']; ?>" <?php echo $schedule_id === (int)$sc['id'] ? 'selected' : ''; ?>><?php echo h($sc['title']); ?> — <?php echo h($sc['schedule_date'] ?? ''); ?> (<?php echo h(substr($sc['start_time'] ?? '', 0, 5)); ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Department</label>
          <select name="department" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">All</option>
            <option value="Education" <?php echo $department==='Education'?'selected':''; ?>>Education</option>
            <option value="Technology" <?php echo $department==='Technology'?'selected':''; ?>>Technology</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Course</label>
          <select name="course" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">All</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?php echo h($c); ?>" <?php echo $course === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Year</label>
          <select name="year_level" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">All</option>
            <?php foreach ($years as $y): ?>
              <option value="<?php echo h($y); ?>" <?php echo $year_level === $y ? 'selected' : ''; ?>><?php echo h($y); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Section</label>
          <select name="section" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">All</option>
            <?php foreach ($sections as $sec): ?>
              <option value="<?php echo h($sec); ?>" <?php echo $section === $sec ? 'selected' : ''; ?>><?php echo h($sec); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Student ID</label>
          <input type="text" name="student_id" value="<?php echo h($student_code); ?>" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" placeholder="Search by ID" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Name</label>
          <input type="text" name="name" value="<?php echo h($student_name); ?>" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" placeholder="Search by name" />
        </div>
        <div class="sm:col-span-2 lg:col-span-8 flex gap-2">
          <button class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700 hidden">Filter</button>
          <a id="exportCsv" href="?date=<?php echo urlencode($date); ?>&schedule_id=<?php echo (int)$schedule_id; ?>&department=<?php echo urlencode($department); ?>&section=<?php echo urlencode($section); ?>&course=<?php echo urlencode($course); ?>&year_level=<?php echo urlencode($year_level); ?>&student_id=<?php echo urlencode($student_code); ?>&name=<?php echo urlencode($student_name); ?>&export=1" class="inline-flex items-center px-4 py-2 rounded-md bg-[#D4AF37] text-[#0F3D87] hover:opacity-90 touch-target">Export Excel (CSV)</a>
          <button type="button" id="clearFilters" class="inline-flex items-center px-4 py-2 rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300 touch-target">Clear</button>
        </div>
      </form>
    </div>

    <div class="bg-white shadow-md ring-1 ring-gray-200 rounded-xl p-4 sm:p-6 overflow-x-auto">
      <div class="rounded-lg border border-gray-200 overflow-hidden overflow-x-auto -mx-2 sm:mx-0">
      <table class="table-responsive-cards min-w-full text-sm">
        <thead>
          <tr class="text-left bg-gray-50 text-gray-600 text-xs uppercase tracking-wide divide-x divide-gray-200">
            <th class="py-2 pr-4 font-medium">Date</th>
            <th class="py-2 pr-4 font-medium">Schedule</th>
            <th class="py-2 pr-4 font-medium">Student ID</th>
            <th class="py-2 pr-4 font-medium">Name</th>
            <th class="py-2 pr-4 font-medium">Course</th>
            <th class="py-2 pr-4 font-medium">Year</th>
            <th class="py-2 pr-4 font-medium">Section</th>
            <th class="py-2 pr-4 font-medium">Gender</th>
            <th class="py-2 pr-4 font-medium">Department</th>
            <th class="py-2 pr-4 font-medium">Time In</th>
            <th class="py-2 pr-4 font-medium">Time Out</th>
            <th class="py-2 pr-4 font-medium">Status</th>
            <th class="py-2 pr-4 font-medium">Late (min)</th>
          </tr>
        </thead>
        <tbody id="attendanceBody">
          <?php foreach ($rows as $r): ?>
            <tr class="border-b border-gray-200 divide-x divide-gray-100 hover:bg-gray-50">
              <td class="py-2 pr-4" data-label="Date"><?php echo h($r['schedule_date']); ?></td>
              <td class="py-2 pr-4" data-label="Schedule"><?php echo h($r['title']); ?></td>
              <td class="py-2 pr-4" data-label="Student ID"><?php echo h($r['sid']); ?></td>
              <td class="py-2 pr-4" data-label="Name"><?php echo h($r['name']); ?></td>
              <td class="py-2 pr-4" data-label="Course"><?php echo h($r['course']); ?></td>
              <td class="py-2 pr-4" data-label="Year"><?php echo h($r['year_level']); ?></td>
              <td class="py-2 pr-4" data-label="Section"><?php echo h($r['section']); ?></td>
              <td class="py-2 pr-4" data-label="Gender"><?php echo h($r['gender']); ?></td>
              <td class="py-2 pr-4" data-label="Department"><?php echo h($r['department']); ?></td>
              <td class="py-2 pr-4" data-label="Time In"><?php echo h($r['time_in']); ?></td>
              <td class="py-2 pr-4" data-label="Time Out"><?php echo h($r['time_out']); ?></td>
              <td class="py-2 pr-4" data-label="Status"><?php echo h($r['status']); ?></td>
              <td class="py-2 pr-4" data-label="Late (min)"><?php echo (int)$r['minutes_late']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if (count($rows) === 0): ?>
        <div class="text-sm text-gray-600 mt-4">No records found for selected filters.</div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="bg-white shadow-md ring-1 ring-gray-200 rounded-xl p-4 sm:p-6 mb-4 sm:mb-6">
      <h2 class="text-lg font-semibold mb-4">All Students – Attendance History</h2>
      <p class="text-sm text-gray-600 mb-4">View each student's attendance across all schedules.</p>
      <div class="flex flex-col sm:flex-row gap-3 mb-4">
        <input type="search" id="studentSearch" value="<?php echo h($view==='student' ? ($_GET['q'] ?? '') : ''); ?>" placeholder="Search by Student ID, Name, Section, Department, Course..." class="flex-1 min-w-0 rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        <button type="button" id="studentSearchBtn" class="px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:opacity-95 touch-target">Search</button>
      </div>
      <div id="studentsList"></div>
      <div id="studentsPager" class="flex items-center justify-between mt-4 text-sm"></div>
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
      exportEl.href = '?' + p.toString() + '&export=1';
    }

    let fetchController = null;
    function loadRows() {
      syncUrlAndExport();
      if (fetchController) { try { fetchController.abort(); } catch(e) {} }
      fetchController = new AbortController();
      fetch('?api=attendance&' + qs(), { signal: fetchController.signal })
        .then(r => r.json())
        .then(d => { bodyEl.innerHTML = d.html || ''; })
        .catch(() => {});
    }

    function reloadSchedules() {
      const selected = schedEl.value;
      fetch('?api=schedules&date=' + encodeURIComponent(dateEl.value || ''))
        .then(r => r.json())
        .then(arr => {
          const opts = ['<option value="0">All</option>'].concat(arr.map(s => `<option value="${s.id}">${s.title || ''} — ${s.schedule_date || ''} (${(s.start_time || '').substring(0,5)})</option>`));
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
        loadRows();
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
                const attHtml = att.length === 0 ? '<p class="text-sm text-gray-500 py-2">No attendance records yet.</p>' : att.map(a => 
                  '<tr class="border-b border-gray-100 hover:bg-gray-50"><td class="py-2 pr-4">' + esc(a.schedule_date) + '</td><td class="py-2 pr-4">' + esc(a.title) + '</td><td class="py-2 pr-4">' + esc(a.start_time || '') + '-' + esc(a.end_time || '') + '</td><td class="py-2 pr-4">' + esc(a.time_in || '-') + '</td><td class="py-2 pr-4">' + esc(a.time_out || '-') + '</td><td class="py-2 pr-4">' + esc(a.status || '-') + '</td><td class="py-2 pr-4">' + (a.minutes_late || 0) + '</td></tr>'
                ).join('');
                html += '<div class="border border-gray-200 rounded-lg mb-3 overflow-hidden"><div class="flex flex-wrap items-center justify-between gap-2 p-3 bg-gray-50 cursor-pointer hover:bg-gray-100" data-toggle><div class="font-medium">' + esc(s.name) + ' <span class="text-gray-500 font-normal">(' + esc(s.student_id) + ')</span></div><div class="text-sm text-gray-600">' + esc(s.section) + ' · ' + esc(s.course) + '</div><div class="text-xs text-gray-500">' + att.length + ' attendance record(s)</div></div><div class="att-detail hidden"><table class="min-w-full text-sm"><thead><tr class="bg-gray-100 text-left text-xs text-gray-600"><th class="py-2 pr-4">Date</th><th class="py-2 pr-4">Schedule</th><th class="py-2 pr-4">Time</th><th class="py-2 pr-4">Time In</th><th class="py-2 pr-4">Time Out</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Late</th></tr></thead><tbody>' + attHtml + '</tbody></table></div></div>';
              }
              studentsList.innerHTML = html || '<p class="text-gray-500 py-4">No students found.</p>';
              studentsList.querySelectorAll('[data-toggle]').forEach(el => {
                el.addEventListener('click', () => { el.nextElementSibling.classList.toggle('hidden'); });
              });
              if (studentsPager) {
                const qParam = (studentSearch ? studentSearch.value : '').trim();
                const qStr = qParam ? '&q=' + encodeURIComponent(qParam) : '';
                if (d.totalPages > 1) {
                  studentsPager.innerHTML = '<div>Page ' + d.page + ' of ' + d.totalPages + ' (' + (d.count || 0) + ' students)</div><div class="flex gap-2">' + (d.page > 1 ? '<a href="?view=student' + qStr + '&page=' + (d.page-1) + '" class="px-3 py-1.5 rounded border hover:bg-gray-50">Prev</a>' : '') + (d.page < d.totalPages ? '<a href="?view=student' + qStr + '&page=' + (d.page+1) + '" class="px-3 py-1.5 rounded border hover:bg-gray-50">Next</a>' : '') + '</div>';
                } else {
                  studentsPager.innerHTML = d.count ? 'Total: ' + d.count + ' student(s)' : '';
                }
              }
            }).catch(() => { studentsList.innerHTML = '<p class="text-red-500 py-4">Failed to load.</p>'; });
        }
        function esc(s) { return String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
        const urlParams = new URLSearchParams(window.location.search);
        loadStudents(parseInt(urlParams.get('page') || '1', 10));
        if (studentSearchBtn) studentSearchBtn.addEventListener('click', () => { const q = new URLSearchParams(); q.set('view', 'student'); q.set('q', studentSearch.value.trim()); q.set('page', '1'); window.location.href = '?' + q.toString(); });
        if (studentSearch) { let t; studentSearch.addEventListener('input', () => { if (t) clearTimeout(t); t = setTimeout(() => loadStudents(1), 400); }); }
      }
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
