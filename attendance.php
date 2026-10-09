<?php
require_once __DIR__ . '/config.php';
$db = db();

$today = (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d');
$date = isset($_GET['date']) ? trim($_GET['date']) : '';
$schedule_id = intval($_GET['schedule_id'] ?? 0);

// Default to today only if neither date nor schedule_id were provided
if (!isset($_GET['date']) && $schedule_id === 0) {
    $date = $today;
}

$department = trim($_GET['department'] ?? '');
$section = trim($_GET['section'] ?? '');
$course = trim($_GET['course'] ?? '');
$year_level = trim($_GET['year_level'] ?? '');
$status = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$student_code = trim($_GET['student_id'] ?? '');
$student_name = trim($_GET['name'] ?? '');
$view = ($_GET['view'] ?? 'schedule') === 'student' ? 'student' : 'schedule';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Top summary counters
$statTotal = (int)($db->query('SELECT COUNT(*) c FROM attendance')->fetch_assoc()['c'] ?? 0);
$statTodayStmt = $db->prepare('SELECT COUNT(*) c FROM attendance a JOIN schedules sc ON a.schedule_id = sc.id WHERE sc.schedule_date = ?');
$statToday = 0;
if ($statTodayStmt) {
    $statTodayStmt->bind_param('s', $today);
    $statTodayStmt->execute();
    $statToday = (int)($statTodayStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $statTodayStmt->close();
}
$statOnTime = (int)($db->query("SELECT COUNT(*) c FROM attendance WHERE status = 'On time' OR status = 'On Time'")->fetch_assoc()['c'] ?? 0);
$statLate = (int)($db->query("SELECT COUNT(*) c FROM attendance WHERE status = 'Late'")->fetch_assoc()['c'] ?? 0);

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

// Build query conditions
$where = ['1=1'];
$params = [];
$types = '';

if ($date !== '') {
    $where[] = 'sc.schedule_date = ?';
    $params[] = $date;
    $types .= 's';
}
if ($schedule_id > 0) {
    $where[] = 'a.schedule_id = ?';
    $params[] = $schedule_id;
    $types .= 'i';
}
if ($department !== '' && in_array($department, ['Education','Technology'], true)) {
    $where[] = 's.department = ?';
    $params[] = $department;
    $types .= 's';
}
if ($section !== '') {
    $where[] = 's.section LIKE ?';
    $params[] = "%$section%";
    $types .= 's';
}
if ($course !== '') {
    $where[] = 's.course = ?';
    $params[] = $course;
    $types .= 's';
}
if ($year_level !== '') {
    $where[] = 's.year_level = ?';
    $params[] = $year_level;
    $types .= 's';
}
if ($status !== '') {
    if (strtolower($status) === 'on time') {
        $where[] = "(a.status = 'On time' OR a.status = 'On Time')";
    } else {
        $where[] = 'a.status = ?';
        $params[] = $status;
        $types .= 's';
    }
}
if ($q !== '') {
    $like = "%$q%";
    $where[] = '(s.student_id LIKE ? OR s.name LIKE ? OR s.section LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($student_code !== '') {
    $where[] = 's.student_id LIKE ?';
    $params[] = "%$student_code%";
    $types .= 's';
}
if ($student_name !== '') {
    $where[] = 's.name LIKE ?';
    $params[] = "%$student_name%";
    $types .= 's';
}

function render_attendance_row(array $r) {
    $statusLower = strtolower((string)$r['status']);
    $statusClass = ($statusLower === 'on time') 
        ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' 
        : ($statusLower === 'late' 
            ? 'bg-amber-50 text-amber-700 border border-amber-200' 
            : 'bg-slate-100 text-slate-600 border border-slate-200');
    $lateMin = (int)$r['minutes_late'];
    $lateText = ($lateMin > 0 ? $lateMin . 'm' : '');
    
    $timeInFormatted = $r['time_in'] ? date('h:i:s A', strtotime($r['time_in'])) : '—';
    $timeOutFormatted = $r['time_out'] ? date('h:i:s A', strtotime($r['time_out'])) : '—';
    $schedDateFormatted = !empty($r['schedule_date']) ? date('M j, Y', strtotime($r['schedule_date'])) : '—';

    return '<tr class="hover:bg-slate-50 transition-colors">'
          . '<td class="att-hide whitespace-nowrap text-slate-600 font-mono text-xs">' . h($r['schedule_date']) . '</td>'
          . '<td class="att-sched font-medium text-slate-900 text-xs">' . h($r['title']) . '<span class="md:hidden text-xs text-slate-400 font-normal ml-1.5">• ' . h($schedDateFormatted) . '</span></td>'
          . '<td class="att-id font-mono text-xs font-semibold text-slate-600">' . h($r['sid']) . '<span class="md:hidden font-sans font-medium text-slate-500 ml-1.5">• ' . h($r['course']) . ' ' . h($r['year_level']) . '-' . h($r['section']) . '</span></td>'
          . '<td class="att-name font-medium text-slate-900 text-sm whitespace-nowrap">' . h($r['name']) . '</td>'
          . '<td class="att-hide"><span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700">' . h($r['course']) . '</span></td>'
          . '<td class="att-hide text-center font-medium text-slate-600">' . h($r['year_level']) . '</td>'
          . '<td class="att-hide text-center font-semibold text-slate-700">' . h($r['section']) . '</td>'
          . '<td class="att-hide text-slate-600 font-medium hidden lg:table-cell">' . h($r['gender']) . '</td>'
          . '<td class="att-hide text-xs text-slate-500 hidden xl:table-cell">' . h($r['department']) . '</td>'
          . '<td class="att-timein font-mono text-xs text-emerald-700 font-semibold whitespace-nowrap" title="' . h($r['time_in']) . '">' . h($timeInFormatted) . '</td>'
          . '<td class="att-timeout font-mono text-xs text-slate-700 whitespace-nowrap" title="' . h($r['time_out']) . '">' . h($timeOutFormatted) . '</td>'
          . '<td class="att-status text-center"><span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold ' . $statusClass . '">' . h($r['status'] ?: '—') . '</span></td>'
          . '<td class="att-hide text-center text-xs text-slate-600 font-medium">' . ($lateText ?: '—') . '</td>'
          . '</tr>';
}

$api = $_GET['api'] ?? '';

// API: By Student Attendance History
if ($api === 'students_attendance') {
    header('Content-Type: application/json');
    $sq = trim($_GET['q'] ?? '');
    $spage = max(1, intval($_GET['page'] ?? 1));
    $sperPage = 25;
    $soffset = ($spage - 1) * $sperPage;
    $swhere = ['1=1'];
    $sparams = [];
    $stypes = '';
    if ($sq !== '') {
        $slike = "%$sq%";
        $swhere[] = '(s.student_id LIKE ? OR s.name LIKE ? OR s.section LIKE ? OR s.department LIKE ? OR s.course LIKE ?)';
        $sparams = array_fill(0, 5, $slike);
        $stypes = 'sssss';
    }
    $countSql = "SELECT COUNT(*) c FROM students s WHERE " . implode(' AND ', $swhere);
    if (count($sparams) > 0) {
        $cstmt = $db->prepare($countSql);
        $crefs = [];
        foreach ($sparams as $k => $v) { $crefs[$k] = &$sparams[$k]; }
        $cstmt->bind_param($stypes, ...$crefs);
        $cstmt->execute();
        $scount = (int)($cstmt->get_result()->fetch_assoc()['c'] ?? 0);
        $cstmt->close();
    } else {
        $scount = (int)($db->query($countSql)->fetch_assoc()['c'] ?? 0);
    }
    $stotalPages = max(1, (int)ceil($scount / $sperPage));
    
    $sparams[] = $sperPage;
    $sparams[] = $soffset;
    $stypes .= 'ii';
    $sql = "SELECT s.id, s.student_id, s.name, s.year_level, s.section, s.department, s.course FROM students s WHERE " . implode(' AND ', $swhere) . " ORDER BY s.name LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    $refs = [];
    foreach ($sparams as $k => $v) { $refs[$k] = &$sparams[$k]; }
    $stmt->bind_param($stypes, ...$refs);
    $stmt->execute();
    $students = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['id'];
        $attRes = $db->prepare("SELECT sc.title, sc.schedule_date, sc.time_in_start, sc.time_in_end, sc.time_out_start, sc.time_out_end, a.time_in, a.time_out, a.status, a.minutes_late
            FROM attendance a JOIN schedules sc ON sc.id = a.schedule_id
            WHERE a.student_id = ? ORDER BY sc.schedule_date DESC, a.time_in DESC");
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
    echo json_encode(['students' => $students, 'page' => $spage, 'totalPages' => $stotalPages, 'count' => $scount]);
    exit;
}

// API: Schedules List
if ($api === 'schedules') {
    header('Content-Type: application/json');
    $arr = [];
    $rs = $db->query('SELECT id, title, schedule_date, start_time, time_in_start, time_in_end FROM schedules ORDER BY schedule_date DESC, start_time ASC');
    if ($rs) {
        while ($row = $rs->fetch_assoc()) { $arr[] = $row; }
        $rs->close();
    }
    echo json_encode($arr);
    exit;
}

// API: By Schedule Attendance Records (Live AJAX)
if ($api === 'attendance') {
    header('Content-Type: application/json');
    $countSql = "SELECT COUNT(*) c FROM attendance a JOIN students s ON a.student_id = s.id JOIN schedules sc ON a.schedule_id = sc.id WHERE " . implode(' AND ', $where);
    $cstmt = $db->prepare($countSql);
    if (count($params) > 0) {
        $crefs = [];
        foreach ($params as $k => $v) { $crefs[$k] = &$params[$k]; }
        $cstmt->bind_param($types, ...$crefs);
    }
    $cstmt->execute();
    $matchedCount = (int)($cstmt->get_result()->fetch_assoc()['c'] ?? 0);
    $cstmt->close();

    $apiTotalPages = max(1, (int)ceil($matchedCount / $perPage));

    $sql = "SELECT a.*, s.student_id AS sid, s.name, s.year_level, s.section, s.gender, s.department, s.course, sc.title, sc.schedule_date, sc.start_time, sc.end_time
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            JOIN schedules sc ON a.schedule_id = sc.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sc.schedule_date DESC, sc.start_time ASC, s.name ASC
            LIMIT ? OFFSET ?";
    $apiParams = $params;
    $apiTypes = $types . 'ii';
    $apiParams[] = $perPage;
    $apiParams[] = $offset;

    $ps = $db->prepare($sql);
    $refs = [];
    foreach ($apiParams as $k => $v) { $refs[$k] = &$apiParams[$k]; }
    $ps->bind_param($apiTypes, ...$refs);
    $ps->execute();
    $res2 = $ps->get_result();
    $html = '';
    while ($row = $res2->fetch_assoc()) {
        $html .= render_attendance_row($row);
    }
    $ps->close();

    echo json_encode([
        'html' => $html,
        'count' => $matchedCount,
        'page' => $page,
        'totalPages' => $apiTotalPages
    ]);
    exit;
}

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === '1') {
    log_activity('attendance_export', 'Date: ' . $date . ', Schedule: ' . $schedule_id . ', Department: ' . $department . ', Section: ' . $section . ', Course: ' . $course . ', Year: ' . $year_level);
    $slug = function($v) {
        $v = strtolower(trim((string)$v));
        $v = preg_replace('/\s+/', '-', $v);
        $v = preg_replace('/[^a-z0-9\-_]/', '', $v);
        return trim($v, '-_');
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
    if ($date !== '') {
        $nameParts[] = $slug($date);
    }
    $filename = implode('_', array_filter($nameParts));
    if ($filename === '') { $filename = 'attendance_records'; }
    
    // Fetch all records for export without limit
    $exportSql = "SELECT a.*, s.student_id AS sid, s.name, s.year_level, s.section, s.gender, s.department, s.course, sc.title, sc.schedule_date, sc.start_time, sc.end_time
                  FROM attendance a
                  JOIN students s ON a.student_id = s.id
                  JOIN schedules sc ON a.schedule_id = sc.id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY sc.schedule_date DESC, sc.start_time ASC, s.name ASC";
    $exStmt = $db->prepare($exportSql);
    if (count($params) > 0) {
        $exRefs = [];
        foreach ($params as $k => $v) { $exRefs[$k] = &$params[$k]; }
        $exStmt->bind_param($types, ...$exRefs);
    }
    $exStmt->execute();
    $exResult = $exStmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Schedule','Student ID','Name','Course','Year','Section','Gender','Department','Time In','Time Out','Status','Minutes Late']);
    while ($erow = $exResult->fetch_assoc()) {
        fputcsv($out, [
            "'" . (string)$erow['schedule_date'],
            $erow['title'],
            $erow['sid'],
            $erow['name'],
            $erow['course'],
            $erow['year_level'],
            $erow['section'],
            $erow['gender'],
            $erow['department'],
            $erow['time_in'] ? ("'" . (string)$erow['time_in']) : '',
            $erow['time_out'] ? ("'" . (string)$erow['time_out']) : '',
            $erow['status'],
            $erow['minutes_late'],
        ]);
    }
    fclose($out);
    $exStmt->close();
    exit;
}

// Initial Server Load for By Schedule view
$countSql = "SELECT COUNT(*) c FROM attendance a JOIN students s ON a.student_id = s.id JOIN schedules sc ON a.schedule_id = sc.id WHERE " . implode(' AND ', $where);
$countStmt = $db->prepare($countSql);
if (count($params) > 0) {
    $crefs = [];
    foreach ($params as $k => $v) { $crefs[$k] = &$params[$k]; }
    $countStmt->bind_param($types, ...$crefs);
}
$countStmt->execute();
$totalRows = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$countStmt->close();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT a.*, s.student_id AS sid, s.name, s.year_level, s.section, s.gender, s.department, s.course, sc.title, sc.schedule_date, sc.start_time, sc.end_time
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        JOIN schedules sc ON a.schedule_id = sc.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sc.schedule_date DESC, sc.start_time ASC, s.name ASC
        LIMIT ? OFFSET ?";
$pageParams = $params;
$pageTypes = $types . 'ii';
$pageParams[] = $perPage;
$pageParams[] = $offset;

$stmt = $db->prepare($sql);
$refs = [];
foreach ($pageParams as $k => $v) { $refs[$k] = &$pageParams[$k]; }
$stmt->bind_param($pageTypes, ...$refs);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) { $rows[] = $row; }
$stmt->close();
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
      <div class="flex items-center gap-2 self-start sm:self-auto">
        <div class="inline-flex p-1 rounded-lg bg-slate-200/70 border border-slate-200 text-xs font-semibold">
          <a href="?view=schedule&<?php echo http_build_query(array_filter(['date'=>$date,'schedule_id'=>$schedule_id,'department'=>$department,'section'=>$section,'course'=>$course,'year_level'=>$year_level,'status'=>$status,'q'=>$q])); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $view==='schedule' ? 'bg-white text-[#0F3D87] shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
            <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
            <span>By Schedule</span>
          </a>
          <a href="?view=student" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $view==='student' ? 'bg-white text-[#0F3D87] shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
            <i data-lucide="users" class="w-3.5 h-3.5"></i>
            <span>By Student</span>
          </a>
        </div>
      </div>
    </div>

    <?php if ($view === 'schedule'): ?>
    <!-- Top Summary Stat Metric Badges -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
      <div class="p-3.5 sm:p-4 rounded-lg bg-white border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Check-Ins</div>
          <div class="text-xl sm:text-2xl font-black text-slate-900 mt-0.5"><?php echo $statTotal; ?></div>
        </div>
        <div class="p-2 sm:p-2.5 rounded-md bg-blue-50 text-[#0F3D87]">
          <i data-lucide="clipboard-check" class="w-4 h-4"></i>
        </div>
      </div>
      <div class="p-3.5 sm:p-4 rounded-lg bg-white border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Today's Check-Ins</div>
          <div class="text-xl sm:text-2xl font-black text-emerald-600 mt-0.5"><?php echo $statToday; ?></div>
        </div>
        <div class="p-2 sm:p-2.5 rounded-md bg-emerald-50 text-emerald-600">
          <i data-lucide="calendar" class="w-4 h-4"></i>
        </div>
      </div>
      <div class="p-3.5 sm:p-4 rounded-lg bg-white border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">On Time</div>
          <div class="text-xl sm:text-2xl font-black text-emerald-700 mt-0.5"><?php echo $statOnTime; ?></div>
        </div>
        <div class="p-2 sm:p-2.5 rounded-md bg-emerald-50 text-emerald-700">
          <i data-lucide="check-circle-2" class="w-4 h-4"></i>
        </div>
      </div>
      <div class="p-3.5 sm:p-4 rounded-lg bg-white border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Late Arrivals</div>
          <div class="text-xl sm:text-2xl font-black text-amber-600 mt-0.5"><?php echo $statLate; ?></div>
        </div>
        <div class="p-2 sm:p-2.5 rounded-md bg-amber-50 text-amber-600">
          <i data-lucide="alert-circle" class="w-4 h-4"></i>
        </div>
      </div>
    </div>

    <!-- Attendance Table Card Surface -->
    <div class="border border-slate-200 rounded-lg bg-white p-4 sm:p-5 shadow-xs space-y-4">
      <!-- Card Header: Title, Total Found, and Export Button -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
        <div class="flex items-center gap-2">
          <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
            <i data-lucide="calendar-check" class="w-4 h-4"></i>
          </div>
          <div>
            <h2 class="text-sm font-bold text-slate-900">Schedule Attendance Roster</h2>
            <p class="text-[11px] text-slate-500">Found <strong class="text-slate-800 font-semibold" id="attHeaderCount"><?php echo $totalRows; ?></strong> verified record(s)</p>
          </div>
        </div>
        <div class="flex items-center gap-2 self-end sm:self-auto">
          <a id="exportCsv" href="?view=schedule&<?php echo http_build_query(array_filter(['date'=>$date,'schedule_id'=>$schedule_id,'department'=>$department,'section'=>$section,'course'=>$course,'year_level'=>$year_level,'status'=>$status,'q'=>$q,'export'=>1])); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold shadow-2xs transition-colors h-[36px]">
            <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600"></i>
            <span>Export CSV</span>
          </a>
        </div>
      </div>

      <!-- Integrated Search & Filter Controls (Matching students.php / logs.php) -->
      <form id="attFilters" method="get" class="space-y-3">
        <input type="hidden" name="view" value="schedule" />
        <input type="hidden" name="page" id="attPageInput" value="<?php echo $page; ?>" />

        <div class="flex flex-col lg:flex-row lg:items-center gap-2.5">
          <!-- Search input -->
          <div class="relative flex-1 min-w-[200px]">
            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input type="text" name="q" id="attSearch" value="<?php echo h($q); ?>" placeholder="Search student name, ID, or section..." class="w-full pl-9 pr-3 py-1.5 text-xs rounded-md border border-slate-200 bg-slate-50 focus:bg-white focus:border-[#0F3D87] outline-none transition-colors h-[36px]" />
          </div>

          <!-- Select Filters Row -->
          <div class="flex flex-wrap sm:flex-nowrap items-center gap-2 overflow-x-auto pb-1 sm:pb-0">
            <!-- Schedule select -->
            <select name="schedule_id" id="schedSelect" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px] min-w-[150px] max-w-[230px]">
              <option value="0">All Schedules</option>
              <?php foreach ($schedulesForDate as $sc): ?>
                <option value="<?php echo (int)$sc['id']; ?>" <?php echo $schedule_id === (int)$sc['id'] ? 'selected' : ''; ?>>
                  <?php echo h($sc['title']); ?> (<?php echo h(date('M j', strtotime($sc['schedule_date']))); ?>)
                </option>
              <?php endforeach; ?>
            </select>

            <!-- Date picker -->
            <input type="date" name="date" id="dateInput" value="<?php echo h($date); ?>" title="Filter by date (leave blank for all)" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px] min-w-[130px]" />

            <!-- Course select -->
            <select name="course" id="courseSelect" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px]">
              <option value="">Course</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?php echo h($c); ?>" <?php echo $course === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
              <?php endforeach; ?>
            </select>

            <!-- Year select -->
            <select name="year_level" id="yearSelect" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px]">
              <option value="">Year</option>
              <?php foreach ($years as $y): ?>
                <option value="<?php echo h($y); ?>" <?php echo $year_level === $y ? 'selected' : ''; ?>><?php echo h($y); ?></option>
              <?php endforeach; ?>
            </select>

            <!-- Section select -->
            <select name="section" id="sectionSelect" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px]">
              <option value="">Sec</option>
              <?php foreach ($sections as $sec): ?>
                <option value="<?php echo h($sec); ?>" <?php echo $section === $sec ? 'selected' : ''; ?>><?php echo h($sec); ?></option>
              <?php endforeach; ?>
            </select>

            <!-- Status select -->
            <select name="status" id="statusSelect" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px]">
              <option value="">Status</option>
              <option value="On time" <?php echo strtolower($status) === 'on time' ? 'selected' : ''; ?>>On Time</option>
              <option value="Late" <?php echo strtolower($status) === 'late' ? 'selected' : ''; ?>>Late</option>
            </select>

            <!-- Clear filters button -->
            <button type="button" id="clearFilters" class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-md border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-600 text-xs font-medium transition-colors h-[36px] shrink-0" title="Clear Filters">
              <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
              <span>Clear</span>
            </button>
          </div>
        </div>
      </form>

      <!-- Table Container -->
      <div class="table-container table-attendance-mobile overflow-x-auto">
        <table class="table-modern w-full">
          <thead>
            <tr class="select-none">
              <th class="w-24 text-left">Date</th>
              <th class="min-w-[150px] text-left">Schedule</th>
              <th class="w-24 text-left">ID</th>
              <th class="min-w-[180px] text-left">Student Name</th>
              <th class="w-20 text-left">Course</th>
              <th class="w-14 text-center">Year</th>
              <th class="w-14 text-center">Sec</th>
              <th class="w-20 text-left hidden lg:table-cell">Gender</th>
              <th class="w-28 text-left hidden xl:table-cell">Dept</th>
              <th class="w-28 text-left">Time In</th>
              <th class="w-28 text-left">Time Out</th>
              <th class="w-24 text-center">Status</th>
              <th class="w-16 text-center">Late</th>
            </tr>
          </thead>
          <tbody id="attendanceBody">
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="13" class="text-center py-8 text-xs text-slate-400">No attendance records found matching filters.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php echo render_attendance_row($r); ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pager Toolbar (Matching students.php / logs.php) -->
      <div class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500" id="attPagerWrap">
        <div id="attPageInfo">
          Page <strong class="text-slate-800 font-semibold" id="currPageNum"><?php echo $page; ?></strong> of <strong class="text-slate-800 font-semibold" id="totalPageNum"><?php echo $totalPages; ?></strong> (<span id="totalRecNum"><?php echo $totalRows; ?></span> records)
        </div>
        <div class="inline-flex items-center gap-1" id="attPagerBtns">
          <button type="button" id="attPrevBtn" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed" <?php echo $page <= 1 ? 'disabled' : ''; ?>>Prev</button>
          <button type="button" id="attNextBtn" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>Next</button>
        </div>
      </div>
    </div>
    <?php else: ?>
    <!-- By Student View (Completely Aligned Grid Layout) -->
    <div class="border border-slate-200 rounded-lg bg-white p-4 sm:p-5 shadow-xs space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-100">
        <div class="flex items-center gap-2">
          <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
            <i data-lucide="user-check" class="w-4 h-4"></i>
          </div>
          <div>
            <h2 class="text-sm font-bold text-slate-900">Student Attendance History</h2>
            <p class="text-[11px] text-slate-500">Click any student row to inspect verified check-in history across schedules.</p>
          </div>
        </div>
      </div>

      <!-- Search Bar -->
      <div class="flex flex-col sm:flex-row gap-2.5">
        <div class="relative flex-1">
          <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
          <input type="search" id="studentSearch" value="<?php echo h($view==='student' ? ($_GET['q'] ?? '') : ''); ?>" placeholder="Search by Student ID, Name, Section, Course..." class="w-full pl-9 pr-3 py-2 text-xs rounded-md border border-slate-300 bg-white focus:border-[#0F3D87] outline-none" />
        </div>
        <button type="button" id="studentSearchBtn" class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:bg-blue-900 text-xs font-semibold shadow-2xs transition-colors">
          <i data-lucide="search" class="w-3.5 h-3.5"></i>
          <span>Search</span>
        </button>
      </div>

      <!-- Fixed Column Header for Rigid Vertical Alignment -->
      <div class="hidden sm:grid grid-cols-12 gap-4 px-4 py-2.5 bg-slate-100/80 border border-slate-200 rounded-lg text-[11px] font-bold text-slate-500 uppercase tracking-wider">
        <div class="col-span-5">Student Name & ID</div>
        <div class="col-span-4">Course & Section</div>
        <div class="col-span-3 text-right">Attendance History</div>
      </div>

      <!-- Student Records Accordion List -->
      <div id="studentsList" class="space-y-2 pt-1"></div>

      <!-- Student Pagination Toolbar -->
      <div id="studentsPager" class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500"></div>
    </div>
    <?php endif; ?>
  </main>

  <script>
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    function formatTime12(t) {
      if (!t) return '—';
      const parts = t.split(':');
      if (parts.length < 2) return t;
      let h = parseInt(parts[0], 10);
      const m = parts[1];
      const ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      return h + ':' + m + ' ' + ampm;
    }

    function formatDateTime(dt) {
      if (!dt) return '—';
      const parts = dt.split(' ');
      const t = parts[1] || dt;
      const tParts = t.split(':');
      if (tParts.length >= 2) {
        let h = parseInt(tParts[0], 10);
        const m = tParts[1];
        const s = tParts[2] ? ':' + tParts[2] : '';
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + s + ' ' + ampm;
      }
      return dt;
    }

    document.addEventListener('DOMContentLoaded', () => {
      // ---------------------------------------------------------------
      // VIEW: BY SCHEDULE LOGIC
      // ---------------------------------------------------------------
      const f = document.getElementById('attFilters');
      if (f) {
        const qInput = document.getElementById('attSearch');
        const schedSelect = document.getElementById('schedSelect');
        const dateInput = document.getElementById('dateInput');
        const courseSelect = document.getElementById('courseSelect');
        const yearSelect = document.getElementById('yearSelect');
        const sectionSelect = document.getElementById('sectionSelect');
        const statusSelect = document.getElementById('statusSelect');
        const pageInput = document.getElementById('attPageInput');
        const bodyEl = document.getElementById('attendanceBody');
        const exportEl = document.getElementById('exportCsv');
        const clearBtn = document.getElementById('clearFilters');
        const headerCountEl = document.getElementById('attHeaderCount');
        const currPageEl = document.getElementById('currPageNum');
        const totalPageEl = document.getElementById('totalPageNum');
        const totalRecEl = document.getElementById('totalRecNum');
        const prevBtn = document.getElementById('attPrevBtn');
        const nextBtn = document.getElementById('attNextBtn');

        let currentPage = parseInt(pageInput ? pageInput.value || '1' : '1', 10);
        let maxPages = <?php echo (int)$totalPages; ?>;

        function buildQueryString(targetPage) {
          const p = new URLSearchParams();
          p.set('view', 'schedule');
          if (qInput && qInput.value.trim()) p.set('q', qInput.value.trim());
          if (schedSelect && parseInt(schedSelect.value || '0', 10) > 0) p.set('schedule_id', schedSelect.value);
          if (dateInput && dateInput.value) p.set('date', dateInput.value);
          if (courseSelect && courseSelect.value) p.set('course', courseSelect.value);
          if (yearSelect && yearSelect.value) p.set('year_level', yearSelect.value);
          if (sectionSelect && sectionSelect.value) p.set('section', sectionSelect.value);
          if (statusSelect && statusSelect.value) p.set('status', statusSelect.value);
          p.set('page', String(targetPage || 1));
          return p.toString();
        }

        let fetchController = null;
        function loadAttendanceRows(targetPage = 1) {
          currentPage = targetPage;
          if (pageInput) pageInput.value = targetPage;
          const qs = buildQueryString(targetPage);

          // Sync URL & Export Link
          const u = new URL(window.location.href);
          u.search = qs;
          history.replaceState({}, '', u.toString());
          if (exportEl) exportEl.href = '?' + qs + '&export=1';

          if (fetchController) { try { fetchController.abort(); } catch(e) {} }
          fetchController = new AbortController();

          fetch('?api=attendance&' + qs, { signal: fetchController.signal })
            .then(r => r.json())
            .then(d => {
              if (bodyEl) {
                bodyEl.innerHTML = d.html || '<tr><td colspan="13" class="text-center py-8 text-xs text-slate-400">No attendance records found matching filters.</td></tr>';
              }
              maxPages = d.totalPages || 1;
              if (headerCountEl) headerCountEl.textContent = d.count ?? 0;
              if (currPageEl) currPageEl.textContent = d.page ?? 1;
              if (totalPageEl) totalPageEl.textContent = maxPages;
              if (totalRecEl) totalRecEl.textContent = d.count ?? 0;

              if (prevBtn) prevBtn.disabled = (currentPage <= 1);
              if (nextBtn) nextBtn.disabled = (currentPage >= maxPages);

              if (window.refreshIcons) window.refreshIcons();
            })
            .catch(() => {});
        }

        let debounceTimer = null;
        function triggerFilter() {
          if (debounceTimer) clearTimeout(debounceTimer);
          debounceTimer = setTimeout(() => loadAttendanceRows(1), 300);
        }

        if (qInput) qInput.addEventListener('input', triggerFilter);
        if (schedSelect) schedSelect.addEventListener('change', () => loadAttendanceRows(1));
        if (dateInput) dateInput.addEventListener('change', () => loadAttendanceRows(1));
        if (courseSelect) courseSelect.addEventListener('change', () => loadAttendanceRows(1));
        if (yearSelect) yearSelect.addEventListener('change', () => loadAttendanceRows(1));
        if (sectionSelect) sectionSelect.addEventListener('change', () => loadAttendanceRows(1));
        if (statusSelect) statusSelect.addEventListener('change', () => loadAttendanceRows(1));

        if (prevBtn) {
          prevBtn.addEventListener('click', () => {
            if (currentPage > 1) loadAttendanceRows(currentPage - 1);
          });
        }
        if (nextBtn) {
          nextBtn.addEventListener('click', () => {
            if (currentPage < maxPages) loadAttendanceRows(currentPage + 1);
          });
        }

        if (clearBtn) {
          clearBtn.addEventListener('click', () => {
            if (qInput) qInput.value = '';
            if (schedSelect) schedSelect.value = '0';
            if (dateInput) dateInput.value = '';
            if (courseSelect) courseSelect.value = '';
            if (yearSelect) yearSelect.value = '';
            if (sectionSelect) sectionSelect.value = '';
            if (statusSelect) statusSelect.value = '';
            loadAttendanceRows(1);
          });
        }
      }

      // ---------------------------------------------------------------
      // VIEW: BY STUDENT LOGIC (Strict Column Alignment)
      // ---------------------------------------------------------------
      const studentSearch = document.getElementById('studentSearch');
      const studentSearchBtn = document.getElementById('studentSearchBtn');
      const studentsList = document.getElementById('studentsList');
      const studentsPager = document.getElementById('studentsPager');

      if (studentsList) {
        function loadStudents(pg = 1) {
          const q = studentSearch ? studentSearch.value.trim() : '';
          fetch('?api=students_attendance&q=' + encodeURIComponent(q) + '&page=' + pg)
            .then(r => r.json())
            .then(d => {
              let html = '';
              for (const s of (d.students || [])) {
                const att = s.attendance || [];
                const attHtml = att.length === 0 
                  ? '<tr><td colspan="7" class="text-center py-6 text-xs text-slate-400">No attendance records found for this student.</td></tr>' 
                  : att.map(a => {
                      const inWindow = (a.time_in_start && a.time_in_end) 
                        ? formatTime12(a.time_in_start) + ' - ' + formatTime12(a.time_in_end) 
                        : '—';
                      const stLower = (a.status || '').toLowerCase();
                      const statusBadge = stLower === 'on time' 
                        ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">On Time</span>'
                        : (stLower === 'late' 
                            ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">Late</span>'
                            : '<span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">' + esc(a.status || 'Pending') + '</span>');
                      
                      const lateMin = parseInt(a.minutes_late || '0', 10);
                      return '<tr class="hover:bg-slate-50 transition-colors">'
                        + '<td class="whitespace-nowrap font-medium text-slate-700 text-xs">' + esc(a.schedule_date) + '</td>'
                        + '<td class="font-medium text-slate-900 text-xs">' + esc(a.title) + '</td>'
                        + '<td class="font-mono text-xs text-slate-600 whitespace-nowrap">' + esc(inWindow) + '</td>'
                        + '<td class="font-mono text-xs text-emerald-700 font-semibold whitespace-nowrap">' + esc(a.time_in ? formatDateTime(a.time_in) : '—') + '</td>'
                        + '<td class="font-mono text-xs text-slate-700 whitespace-nowrap">' + esc(a.time_out ? formatDateTime(a.time_out) : '—') + '</td>'
                        + '<td class="text-center">' + statusBadge + '</td>'
                        + '<td class="text-center text-xs text-slate-600 font-medium">' + (lateMin > 0 ? lateMin + 'm' : '—') + '</td>'
                        + '</tr>';
                    }).join('');

                const firstLetter = (s.name || '?').charAt(0).toUpperCase();
                html += '<div class="border border-slate-200 rounded-lg overflow-hidden bg-white shadow-2xs">'
                      + '<div class="grid grid-cols-1 sm:grid-cols-12 items-center gap-2 sm:gap-4 p-3.5 bg-slate-50/80 hover:bg-slate-100/80 cursor-pointer transition-colors select-none" data-toggle>'
                      + '  <!-- Col 1: Student Name & ID -->'
                      + '  <div class="sm:col-span-5 flex items-center gap-2.5 min-w-0">'
                      + '    <div class="w-8 h-8 rounded-full bg-blue-100 text-[#0F3D87] font-bold text-xs flex items-center justify-center shrink-0">' + esc(firstLetter) + '</div>'
                      + '    <div class="min-w-0 flex-1">'
                      + '      <div class="font-semibold text-xs text-slate-900 truncate leading-tight">' + esc(s.name) + '</div>'
                      + '      <div class="font-mono text-[11px] text-slate-500 truncate">' + esc(s.student_id) + '</div>'
                      + '    </div>'
                      + '  </div>'
                      + '  <!-- Col 2: Course & Section - STRICTLY LOCKED TO SAME COLUMN LINE -->'
                      + '  <div class="sm:col-span-4 flex items-center gap-2 text-xs text-slate-700 min-w-0">'
                      + '    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700 shrink-0">' + esc(s.course) + '</span>'
                      + '    <span class="font-semibold text-slate-800 shrink-0">Sec ' + esc(s.section) + '</span>'
                      + (s.year_level ? '    <span class="text-slate-400 text-[11px]">• Year ' + esc(s.year_level) + '</span>' : '')
                      + '  </div>'
                      + '  <!-- Col 3: Records Count & Chevron -->'
                      + '  <div class="sm:col-span-3 flex items-center justify-between sm:justify-end gap-3">'
                      + '    <span class="text-[11px] font-semibold text-[#0F3D87] bg-blue-50 border border-blue-200/80 px-2.5 py-1 rounded-full shrink-0">' + att.length + ' record(s)</span>'
                      + '    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 transition-transform duration-200 shrink-0 toggle-chevron"></i>'
                      + '  </div>'
                      + '</div>'
                      + '<div class="att-detail hidden p-3 sm:p-4 border-t border-slate-200/80 bg-white">'
                      + '  <div class="table-container overflow-x-auto">'
                      + '    <table class="table-modern w-full text-xs">'
                      + '      <thead>'
                      + '        <tr>'
                      + '          <th class="w-28 text-left">Date</th>'
                      + '          <th class="min-w-[150px] text-left">Schedule</th>'
                      + '          <th class="w-36 text-left">In Window</th>'
                      + '          <th class="w-32 text-left">Time In</th>'
                      + '          <th class="w-32 text-left">Time Out</th>'
                      + '          <th class="w-24 text-center">Status</th>'
                      + '          <th class="w-16 text-center">Late</th>'
                      + '        </tr>'
                      + '      </thead>'
                      + '      <tbody>' + attHtml + '</tbody>'
                      + '    </table>'
                      + '  </div>'
                      + '</div>'
                      + '</div>';
              }

              studentsList.innerHTML = html || '<div class="text-center py-8 text-xs text-slate-400">No students found matching query.</div>';

              studentsList.querySelectorAll('[data-toggle]').forEach(el => {
                el.addEventListener('click', () => {
                  el.classList.toggle('is-open');
                  el.nextElementSibling.classList.toggle('hidden');
                });
              });

              if (studentsPager) {
                const qParam = (studentSearch ? studentSearch.value : '').trim();
                const qStr = qParam ? '&q=' + encodeURIComponent(qParam) : '';
                if (d.totalPages > 1) {
                  studentsPager.innerHTML = '<div>Page ' + d.page + ' of ' + d.totalPages + ' (' + (d.count || 0) + ' students)</div>'
                    + '<div class="flex gap-1">'
                    + (d.page > 1 ? '<button type="button" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700 transition-colors" data-page="' + (d.page - 1) + '">Prev</button>' : '')
                    + (d.page < d.totalPages ? '<button type="button" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700 transition-colors" data-page="' + (d.page + 1) + '">Next</button>' : '')
                    + '</div>';
                  studentsPager.querySelectorAll('[data-page]').forEach(b => {
                    b.addEventListener('click', () => loadStudents(parseInt(b.dataset.page, 10)));
                  });
                } else {
                  studentsPager.innerHTML = d.count ? 'Total: ' + d.count + ' student(s)' : '';
                }
              }

              if (window.refreshIcons) window.refreshIcons();
            })
            .catch(() => {
              studentsList.innerHTML = '<div class="text-rose-500 text-xs py-4">Failed to load student data.</div>';
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        loadStudents(parseInt(urlParams.get('page') || '1', 10));

        if (studentSearchBtn) {
          studentSearchBtn.addEventListener('click', () => loadStudents(1));
        }
        if (studentSearch) {
          let sTimer = null;
          studentSearch.addEventListener('input', () => {
            if (sTimer) clearTimeout(sTimer);
            sTimer = setTimeout(() => loadStudents(1), 350);
          });
        }
      }
    });
  </script>
  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
