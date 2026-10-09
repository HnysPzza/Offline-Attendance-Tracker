<?php
require_once __DIR__ . '/config.php';
$db = db();
$message = '';
$error = '';
$allowedCourses = ['BSIT','BIT','BEED','BSED','BTLED'];
$coursesByDept = [
    'Education' => ['BEED','BSED','BTLED'],
    'Technology' => ['BSIT','BIT']
];
$allowedGenders = ['Male', 'Female'];

function dept_for_course($course, $coursesByDept) {
    foreach ($coursesByDept as $dept => $courses) {
        if (in_array($course, $courses, true)) {
            return $dept;
        }
    }
    return '';
}

function read_import_row(array $row, array $headerMap) {
    $pick = function($key) use ($row, $headerMap) {
        if (!isset($headerMap[$key])) {
            return '';
        }
        $idx = $headerMap[$key];
        return trim((string)($row[$idx] ?? ''));
    };
    $rawGender = strtoupper($pick('gender'));
    $normalizedGender = $rawGender;
    if ($rawGender === 'M' || $rawGender === 'MALE') {
        $normalizedGender = 'Male';
    } elseif ($rawGender === 'F' || $rawGender === 'FEMALE') {
        $normalizedGender = 'Female';
    } else {
        $normalizedGender = ucfirst(strtolower($pick('gender')));
    }
    return [
        'student_id' => $pick('student id'),
        'name' => $pick('full name (last name, first name, middle initial)'),
        'course' => strtoupper($pick('course')),
        'year_level' => $pick('year'),
        'section' => $pick('section'),
        'gender' => $normalizedGender,
        'department' => $pick('department')
    ];
}

function normalize_import_header($h) {
    $h = (string)$h;
    // Remove UTF-8 BOM if present (common in CSV UTF-8 files).
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    // Normalize non-breaking spaces and underscores.
    $h = str_replace("\xC2\xA0", ' ', $h);
    $h = strtolower(trim($h));
    $h = str_replace('_', ' ', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return trim($h);
}

function xlsx_column_index($cellRef) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
    $num = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $num = ($num * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $num - 1);
}

function read_xlsx_rows($filePath) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP extension is not available for XLSX import. Please save the file as CSV (UTF-8) in Excel, then import the CSV file.');
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Cannot open XLSX file.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $sheetXml = $zip->getFromName('xl/worksheets/sheet.xml');
    }
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Worksheet not found in XLSX.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowNode) {
        $row = [];
        foreach ($rowNode->c as $cell) {
            $ref = (string)$cell['r'];
            $idx = xlsx_column_index($ref);
            $cellType = (string)$cell['t'];
            $value = '';
            if (!isset($cell->v)) {
                $value = '';
            } else {
                $v = (string)$cell->v;
                if ($cellType === 's') {
                    $si = (int)$v;
                    $value = $sharedStrings[$si] ?? '';
                } else {
                    $value = $v;
                }
            }
            $row[$idx] = trim($value);
        }
        if (!empty($row)) {
            ksort($row);
            $rows[] = array_values($row);
        }
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'import') {
        if (!isset($_FILES['import_file']) || ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Please upload a CSV file.';
        } else {
            $tmpFile = $_FILES['import_file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['import_file']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                $error = 'Only CSV or XLSX files are supported for import.';
            } else {
                $rows = [];
                if ($ext === 'csv') {
                    $handle = fopen($tmpFile, 'r');
                    if ($handle === false) {
                        $error = 'Failed to open import file.';
                    } else {
                        while (($line = fgetcsv($handle)) !== false) {
                            $rows[] = $line;
                        }
                        fclose($handle);
                    }
                } else {
                    try {
                        $rows = read_xlsx_rows($tmpFile);
                    } catch (RuntimeException $ex) {
                        $error = $ex->getMessage();
                    }
                }

                if (!$error) {
                    if (count($rows) === 0) {
                        $error = 'Import file is empty.';
                    } else {
                        $header = array_shift($rows);
                        $normalized = array_map('normalize_import_header', $header);
                        $headerMap = [];
                        foreach ($normalized as $idx => $h) {
                            $headerMap[$h] = $idx;
                        }
                        $requiredHeaders = [
                            'student id',
                            'full name (last name, first name, middle initial)',
                            'course',
                            'year',
                            'section',
                            'gender',
                            'department'
                        ];
                        $missing = [];
                        foreach ($requiredHeaders as $rh) {
                            if (!isset($headerMap[$rh])) {
                                $missing[] = $rh;
                            }
                        }
                        if ($missing) {
                            $error = 'Missing column(s): ' . implode(', ', $missing);
                        } else {
                            $imported = 0;
                            $skipped = 0;
                            foreach ($rows as $row) {
                                if (!$row || count(array_filter($row, function($v) { return trim((string)$v) !== ''; })) === 0) {
                                    continue;
                                }
                                $data = read_import_row($row, $headerMap);
                                if (
                                    $data['student_id'] === '' || $data['name'] === '' || $data['course'] === '' ||
                                    $data['year_level'] === '' || $data['section'] === '' ||
                                    $data['gender'] === '' || $data['department'] === ''
                                ) {
                                    $skipped++;
                                    continue;
                                }
                                if (!in_array($data['department'], ['Education','Technology'], true)) {
                                    $skipped++;
                                    continue;
                                }
                                if (!in_array($data['course'], $allowedCourses, true) || !in_array($data['course'], $coursesByDept[$data['department']], true)) {
                                    $skipped++;
                                    continue;
                                }
                                if (!in_array($data['gender'], $allowedGenders, true)) {
                                    $skipped++;
                                    continue;
                                }
                                $stmt = $db->prepare('INSERT INTO students (student_id, name, year_level, section, gender, department, course) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), year_level = VALUES(year_level), section = VALUES(section), gender = VALUES(gender), department = VALUES(department), course = VALUES(course)');
                                $stmt->bind_param('sssssss', $data['student_id'], $data['name'], $data['year_level'], $data['section'], $data['gender'], $data['department'], $data['course']);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) { $imported++; } else { $skipped++; }
                            }
                            if ($imported > 0) {
                                $message = "Import complete. {$imported} row(s) imported/updated, {$skipped} skipped.";
                                log_activity('student_import', "Imported {$imported} student rows, skipped {$skipped}");
                            } else {
                                $error = "No rows imported. {$skipped} row(s) skipped due to invalid data.";
                            }
                        }
                    }
                }
            }
        }
    }
    if ($action === 'create') {
        $student_id = trim($_POST['student_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $gender = ucfirst(strtolower(trim($_POST['gender'] ?? '')));
        $department = $_POST['department'] ?? '';
        $course = strtoupper(trim($_POST['course'] ?? ''));

        if (
            $student_id === '' || $name === '' || $year_level === '' || $section === '' || $gender === '' ||
            $course === '' || !in_array($department, ['Education','Technology'], true)
        ) {
            $error = 'All fields are required.';
        } elseif (!in_array($gender, $allowedGenders, true)) {
            $error = 'Invalid gender.';
        } elseif (!in_array($course, $allowedCourses, true) || !in_array($course, $coursesByDept[$department], true)) {
            $error = 'Invalid course for selected department.';
        } else {
            $stmt = $db->prepare('SELECT id FROM students WHERE student_id = ?');
            $stmt->bind_param('s', $student_id);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists) { $error = 'Student ID already exists.'; }
            else {
                $stmt = $db->prepare('INSERT INTO students (student_id, name, year_level, section, gender, department, course) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssssss', $student_id, $name, $year_level, $section, $gender, $department, $course);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    $message = 'Student added.';
                    log_activity('student_create', $name . ' (' . $student_id . ') - Y' . $year_level . ' ' . $section . ', ' . $course);
                }
                else { $error = 'Failed to add student.'; }
            }
        }
    }
    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $student_id = trim($_POST['student_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $gender = ucfirst(strtolower(trim($_POST['gender'] ?? '')));
        $department = $_POST['department'] ?? '';
        $course = strtoupper(trim($_POST['course'] ?? ''));

        if (
            $id <= 0 || $student_id === '' || $name === '' || $year_level === '' || $section === '' ||
            $gender === '' || $course === '' || !in_array($department, ['Education','Technology'], true)
        ) {
            $error = 'All fields are required.';
        } elseif (!in_array($gender, $allowedGenders, true)) {
            $error = 'Invalid gender.';
        } elseif (!in_array($course, $allowedCourses, true) || !in_array($course, $coursesByDept[$department], true)) {
            $error = 'Invalid course for selected department.';
        } else {
            $stmt = $db->prepare('SELECT id FROM students WHERE student_id = ? AND id <> ?');
            $stmt->bind_param('si', $student_id, $id);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists) { $error = 'Student ID already exists.'; }
            else {
                $stmt = $db->prepare('UPDATE students SET student_id = ?, name = ?, year_level = ?, section = ?, gender = ?, department = ?, course = ? WHERE id = ?');
                $stmt->bind_param('sssssssi', $student_id, $name, $year_level, $section, $gender, $department, $course, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    $message = 'Student updated.';
                    log_activity('student_update', $name . ' (' . $student_id . ')');
                } else { $error = 'Failed to update student.'; }
            }
        }
    }
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('SELECT student_id, name FROM students WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $del = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = $db->prepare('DELETE FROM students WHERE id = ?');
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                $message = 'Student deleted.';
                log_activity('student_delete', $del ? $del['name'] . ' (' . $del['student_id'] . ')' : 'ID ' . $id);
            } else { $error = 'Failed to delete student.'; }
        }
    }
    if ($action === 'truncate') {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $ok1 = $db->query('TRUNCATE TABLE students');
        $ok2 = $db->query('TRUNCATE TABLE attendance');
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        if ($ok1 && $ok2) {
            $message = 'All students and attendance records have been deleted.';
            log_activity('student_truncate', 'All students and attendance truncated');
        } else {
            $error = 'Failed to truncate tables.';
        }
    }
    if ($action === 'delete_section') {
        $course = trim($_POST['course'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $section = trim($_POST['section'] ?? '');
        if ($course === '' || $year_level === '' || $section === '') {
            $error = 'Please select Course, Year, and Section.';
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) c FROM students WHERE course = ? AND year_level = ? AND section = ?');
            $stmt->bind_param('sss', $course, $year_level, $section);
            $stmt->execute();
            $count_affected = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($count_affected === 0) {
                $error = 'No students found for this section.';
            } else {
                $db->query('SET FOREIGN_KEY_CHECKS=0');
                $stmt = $db->prepare('DELETE FROM students WHERE course = ? AND year_level = ? AND section = ?');
                $stmt->bind_param('sss', $course, $year_level, $section);
                $ok = $stmt->execute();
                $stmt->close();
                $db->query('SET FOREIGN_KEY_CHECKS=1');
                if ($ok) {
                    $message = "Deleted {$count_affected} student(s) from {$course} {$year_level}-{$section}.";
                    log_activity('student_delete_section', "{$course} Y{$year_level} {$section} - {$count_affected} students deleted");
                } else {
                    $error = 'Failed to delete section students.';
                }
            }
        }
    }
}

if (isset($_GET['api']) && $_GET['api'] === 'students') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 50; $offset = ($page - 1) * $perPage;
    $sort = $_GET['sort'] ?? 'created_at';
    $dir = strtolower($_GET['dir'] ?? 'desc');
    $allowedSorts = ['student_id','name','course','year_level','section','gender','department','created_at'];
    if (!in_array($sort, $allowedSorts, true)) { $sort = 'created_at'; }
    $dir = $dir === 'asc' ? 'asc' : 'desc';
    if ($q !== '') {
        $like = "%$q%";
        $qCompact = '%' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q)) . '%';
        $cstmt = $db->prepare('SELECT COUNT(*) c FROM students WHERE student_id LIKE ? OR name LIKE ? OR course LIKE ? OR year_level LIKE ? OR section LIKE ? OR gender LIKE ? OR department LIKE ? OR REPLACE(REPLACE(UPPER(CONCAT(course, year_level, section)), "-", ""), " ", "") LIKE ?');
        $cstmt->bind_param('ssssssss', $like, $like, $like, $like, $like, $like, $like, $qCompact);
        $cstmt->execute();
        $count = (int)$cstmt->get_result()->fetch_assoc()['c'];
        $cstmt->close();
        $sql = 'SELECT id, student_id, name, course, year_level, section, gender, department FROM students WHERE student_id LIKE ? OR name LIKE ? OR course LIKE ? OR year_level LIKE ? OR section LIKE ? OR gender LIKE ? OR department LIKE ? OR REPLACE(REPLACE(UPPER(CONCAT(course, year_level, section)), "-", ""), " ", "") LIKE ? ORDER BY ' . $sort . ' ' . $dir . ' LIMIT ? OFFSET ?';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ssssssssii', $like, $like, $like, $like, $like, $like, $like, $qCompact, $perPage, $offset);
    } else {
        $count = (int)$db->query('SELECT COUNT(*) c FROM students')->fetch_assoc()['c'];
        $sql = 'SELECT id, student_id, name, course, year_level, section, gender, department FROM students ORDER BY ' . $sort . ' ' . $dir . ' LIMIT ? OFFSET ?';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $perPage, $offset);
    }
    $totalPages = max(1, (int)ceil($count / $perPage));
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) { $list[] = $row; }
    $stmt->close();
    echo json_encode(['students' => $list, 'page' => $page, 'totalPages' => $totalPages, 'sort' => $sort, 'dir' => $dir]);
    exit;
}

$perPage = 50;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$count = (int)$db->query('SELECT COUNT(*) c FROM students')->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($count / $perPage));
$students = [];
$stmt = $db->prepare('SELECT * FROM students ORDER BY created_at DESC LIMIT ? OFFSET ?');
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $students[] = $row; }
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Students</title>
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

  <main class="max-w-[92rem] mx-auto px-4 sm:px-6 py-6 sm:py-8 grid lg:grid-cols-3 gap-4 sm:gap-6">
    <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 lg:col-span-1">
      <h2 class="text-lg font-semibold mb-4">Add Student</h2>
      <?php if ($error): ?>
        <div class="mb-4 p-3 rounded bg-red-100 text-red-700 text-sm hidden"><?php echo h($error); ?></div>
      <?php elseif ($message): ?>
        <div class="mb-4 p-3 rounded bg-green-100 text-green-700 text-sm hidden"><?php echo h($message); ?></div>
      <?php endif; ?>
      <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="create" />
        <div>
          <label class="block text-sm font-medium text-gray-700">Student ID</label>
          <input name="student_id" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Name</label>
          <input name="name" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Year</label>
          <input name="year_level" type="text" required placeholder="e.g. 3" class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Section</label>
          <input name="section" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Gender</label>
          <select name="gender" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Department</label>
          <select id="departmentSelect" name="department" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select</option>
            <option value="Education">Education</option>
            <option value="Technology">Technology</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Course</label>
          <select id="courseSelect" name="course" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select</option>
            <option value="BSIT">BSIT</option>
            <option value="BIT">BIT</option>
            <option value="BEED">BEED</option>
            <option value="BSED">BSED</option>
            <option value="BTLED">BTLED</option>
          </select>
        </div>
        <div>
          <button class="inline-flex items-center px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:opacity-95">Save</button>
        </div>
      </form>
      <hr class="my-6">
      <h3 class="text-base font-semibold mb-2">Data Management</h3>
      <div class="flex flex-wrap gap-4">
        <div class="flex-1">
          <h4 class="text-base font-semibold mb-2">Import CSV/XLSX</h4>
          <p class="text-xs text-gray-500 mb-3">Required columns: Student ID, Full Name (Last Name, First Name, Middle Initial), Course, Year, Section, Gender, Department</p>
          <form method="post" enctype="multipart/form-data" class="space-y-3">
            <input type="hidden" name="action" value="import" />
            <input type="file" name="import_file" accept=".csv,.xlsx" required class="w-full text-sm" />
            <button class="inline-flex items-center px-4 py-2 rounded-md bg-[#D4AF37] text-[#0F3D87] hover:opacity-95">Import CSV/XLSX</button>
          </form>
        </div>
        <div class="flex-1">
          <h4 class="text-base font-semibold mb-2">Truncate Students</h4>
          <p class="text-xs text-gray-500 mb-3">This will permanently delete all students from the database. This action cannot be undone.</p>
          <form method="post" onsubmit="return handleTruncate(event);">
            <input type="hidden" name="action" value="truncate" />
            <button type="button" class="inline-flex items-center px-4 py-2 rounded-md bg-red-600 text-white hover:bg-red-700" onclick="handleTruncate(event)">Truncate Data</button>
          </form>
        </div>
      </div>
    </div>

    <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 overflow-x-auto lg:col-span-2">
      <h2 class="text-lg font-semibold mb-4">Students List</h2>
      <div class="mb-4 p-3 rounded-md bg-blue-50 border border-blue-200">
        <p class="text-sm font-medium text-blue-900">Total Students: <span class="font-bold text-lg text-blue-600"><?php echo $count; ?></span></p>
      </div>
      <div class="mb-4 p-3 rounded-md bg-red-50 border border-red-200">
        <h4 class="text-sm font-semibold text-red-900 mb-2">Delete Section Students</h4>
        <div class="flex flex-col sm:flex-row gap-2">
          <select id="deleteSectionCourse" class="flex-1 min-w-0 rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" data-placeholder-option>
            <option value="">Select Course</option>
            <option value="BSIT">BSIT</option>
            <option value="BIT">BIT</option>
            <option value="BEED">BEED</option>
            <option value="BSED">BSED</option>
            <option value="BTLED">BTLED</option>
          </select>
          <select id="deleteSectionYear" class="flex-1 min-w-0 rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select Year</option>
            <option value="1">1st Year</option>
            <option value="2">2nd Year</option>
            <option value="3">3rd Year</option>
            <option value="4">4th Year</option>
          </select>
          <select id="deleteSectionSection" class="flex-1 min-w-0 rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select Section</option>
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="A">A</option>
            <option value="B">B</option>
            <option value="C">C</option>
            <option value="D">D</option>
          </select>
          <button id="deleteSectionBtn" type="button" class="px-4 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 touch-target shrink-0 whitespace-nowrap font-medium">Delete Section</button>
        </div>
      </div>
      <div class="mb-4 flex flex-col sm:flex-row gap-2">
        <input id="studentsSearch" type="search" placeholder="Search by ID/Name or combined filter (e.g. BSIT 1-B)" class="flex-1 min-w-0 rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        <button id="studentsSearchBtn" class="px-4 py-2 rounded-md bg-[#D4AF37] text-[#0F3D87] hover:opacity-90 touch-target shrink-0">Search</button>
      </div>
      <p class="text-xs text-gray-500 mb-3">Tip: You can search by a combined class filter like <span class="font-semibold">BSIT 1-B</span>.</p>
      <div class="overflow-x-auto -mx-2 sm:mx-0">
      <table class="table-responsive-cards min-w-full text-sm divide-y divide-gray-200">
        <thead>
          <tr class="text-left bg-gray-50 text-gray-600 text-xs uppercase tracking-wide select-none">
            <th class="py-2 pr-4 font-medium cursor-pointer" data-sort="student_id">Student ID</th>
            <th class="py-2 pr-4 font-medium cursor-pointer" data-sort="name">Name</th>
            <th class="py-2 pr-4 font-medium cursor-pointer" data-sort="course">Course</th>
            <th class="py-2 pr-4 font-medium cursor-pointer" data-sort="year_level">Year</th>
            <th class="py-2 pr-4 font-medium cursor-pointer" data-sort="section">Section</th>
            <th class="py-2 pr-4 font-medium cursor-pointer" data-sort="gender">Gender</th>
            <th class="py-2 pr-4 font-medium cursor-pointer" data-sort="department">Department</th>
            <th class="py-2 pr-4 font-medium min-w-[150px]">Actions</th>
          </tr>
        </thead>
        <tbody id="studentsBody">
          <?php foreach ($students as $s): ?>
            <tr class="border-b last:border-0 hover:bg-gray-50" data-id="<?php echo (int)$s['id']; ?>" data-student_id="<?php echo h($s['student_id']); ?>" data-name="<?php echo h($s['name']); ?>" data-course="<?php echo h($s['course']); ?>" data-year_level="<?php echo h($s['year_level']); ?>" data-section="<?php echo h($s['section']); ?>" data-gender="<?php echo h($s['gender']); ?>" data-department="<?php echo h($s['department']); ?>">
              <td class="py-2 pr-4" data-label="Student ID"><?php echo h($s['student_id']); ?></td>
              <td class="py-2 pr-4" data-label="Name"><?php echo h($s['name']); ?></td>
              <td class="py-2 pr-4" data-label="Course"><?php echo h($s['course']); ?></td>
              <td class="py-2 pr-4" data-label="Year"><?php echo h($s['year_level']); ?></td>
              <td class="py-2 pr-4" data-label="Section"><?php echo h($s['section']); ?></td>
              <td class="py-2 pr-4" data-label="Gender"><?php echo h($s['gender']); ?></td>
              <td class="py-2 pr-4" data-label="Department"><?php echo h($s['department']); ?></td>
              <td class="py-2 pr-4 min-w-[150px]" data-label="Actions">
                <div class="cell-actions inline-flex flex-wrap gap-2">
                <button type="button" class="px-3.5 py-2 sm:py-1.5 rounded-md bg-[#0F3D87] text-white hover:opacity-95 text-xs font-medium touch-target" data-edit>Edit</button>
                <form method="post" class="inline" onsubmit="return confirm('Delete this student?');">
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
      <div class="flex items-center justify-between mt-4 text-sm" id="studentsPagerWrap">
        <div id="studentsPageInfo">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
        <div class="space-x-2" id="studentsPager">
          <?php if ($page > 1): ?>
            <a class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50" href="?page=<?php echo (int)($page-1); ?>">Prev</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50" href="?page=<?php echo (int)($page+1); ?>">Next</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const deptEl = document.getElementById('departmentSelect');
      const courseEl = document.getElementById('courseSelect');
      const all = ['BSIT','BIT','BEED','BSED','BTLED'];
      const byDept = { Education: ['BEED','BSED','BTLED'], Technology: ['BSIT','BIT'] };
      function refreshCourses() {
        const dep = deptEl.value;
        const allowed = byDept[dep] || all;
        const current = courseEl.value;
        courseEl.innerHTML = '<option value="">Select</option>' + allowed.map(c => `<option value="${c}">${c}</option>`).join('');
        if (allowed.indexOf(current) >= 0) courseEl.value = current;
      }
      function deptForCourse(c) {
        if (byDept.Education.indexOf(c) >= 0) return 'Education';
        if (byDept.Technology.indexOf(c) >= 0) return 'Technology';
        return '';
      }
      deptEl.addEventListener('change', refreshCourses);
      courseEl.addEventListener('change', () => {
        const c = courseEl.value;
        const d = deptForCourse(c);
        if (d && deptEl.value !== d) {
          deptEl.value = d;
          const keep = c;
          refreshCourses();
          courseEl.value = keep;
        }
      });
      refreshCourses();
    });
  </script>
  <div id="editStudentModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/50" data-edit-close></div>
    <div class="relative bg-white rounded-xl shadow-xl ring-1 ring-gray-200 max-w-md w-full mx-4">
      <form id="editStudentForm" method="post" class="p-6 space-y-4">
        <input type="hidden" name="action" value="update" />
        <input type="hidden" name="id" value="" />
        <div class="text-base font-semibold text-gray-900">Edit Student</div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Student ID</label>
          <input name="student_id" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Name</label>
          <input name="name" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Year</label>
          <input name="year_level" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Section</label>
          <input name="section" type="text" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Gender</label>
          <select name="gender" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Department</label>
          <select id="editDepartment" name="department" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select</option>
            <option value="Education">Education</option>
            <option value="Technology">Technology</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Course</label>
          <select id="editCourse" name="course" required class="mt-1 w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">Select</option>
            <option value="BSIT">BSIT</option>
            <option value="BIT">BIT</option>
            <option value="BEED">BEED</option>
            <option value="BSED">BSED</option>
            <option value="BTLED">BTLED</option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="px-4 py-2 rounded-md bg-gray-100 hover:bg-gray-200" data-edit-close>Cancel</button>
          <button class="px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:opacity-95">Save</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function(){
      // Client-side search/sort/paginate
      let sPage = 1, sSort = 'created_at', sDir = 'desc', sQ = '';
      const input = document.getElementById('studentsSearch');
      const btn = document.getElementById('studentsSearchBtn');
      const tbody = document.getElementById('studentsBody');
      const pageInfo = document.getElementById('studentsPageInfo');
      const pager = document.getElementById('studentsPager');
      function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m])); }
      function rowHtml(st){
        return `<tr class="border-b last:border-0 hover:bg-gray-50" data-id="${esc(st.id)}" data-student_id="${esc(st.student_id)}" data-name="${esc(st.name)}" data-course="${esc(st.course)}" data-year_level="${esc(st.year_level)}" data-section="${esc(st.section)}" data-gender="${esc(st.gender)}" data-department="${esc(st.department)}">
          <td class="py-2 pr-4" data-label="Student ID">${esc(st.student_id)}</td>
          <td class="py-2 pr-4" data-label="Name">${esc(st.name)}</td>
          <td class="py-2 pr-4" data-label="Course">${esc(st.course)}</td>
          <td class="py-2 pr-4" data-label="Year">${esc(st.year_level)}</td>
          <td class="py-2 pr-4" data-label="Section">${esc(st.section)}</td>
          <td class="py-2 pr-4" data-label="Gender">${esc(st.gender)}</td>
          <td class="py-2 pr-4" data-label="Department">${esc(st.department)}</td>
          <td class="py-2 pr-4 min-w-[150px]" data-label="Actions">
            <div class="cell-actions inline-flex flex-wrap gap-2">
            <button type="button" class="px-3.5 py-2 sm:py-1.5 rounded-md bg-[#0F3D87] text-white hover:opacity-95 text-xs font-medium touch-target" data-edit>Edit</button>
            <form method="post" class="inline" onsubmit="return confirm('Delete this student?');">
              <input type="hidden" name="action" value="delete" />
              <input type="hidden" name="id" value="${esc(st.id)}" />
              <button class="px-3.5 py-2 sm:py-1.5 rounded-md bg-red-600 text-white hover:bg-red-700 text-xs font-medium touch-target">Delete</button>
            </form>
            </div>
          </td>
        </tr>`;
      }
      function render(data){
        tbody.innerHTML = (data.students||[]).map(rowHtml).join('');
        pageInfo.textContent = `Page ${data.page} of ${data.totalPages}`;
        let phtml = '';
        if (data.page > 1) phtml += `<button data-page="${data.page-1}" class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50">Prev</button>`;
        if (data.page < data.totalPages) phtml += `<button data-page="${data.page+1}" class="px-3 py-1.5 rounded-md border bg-white hover:bg-gray-50">Next</button>`;
        pager.innerHTML = phtml;
      }
      let controller=null; function load(page){
        sPage = page||1; sQ = input?input.value.trim():'';
        if (controller) { try{controller.abort();}catch(e){} }
        controller = new AbortController();
        const url = `?api=students&q=${encodeURIComponent(sQ)}&page=${sPage}&sort=${encodeURIComponent(sSort)}&dir=${encodeURIComponent(sDir)}`;
        fetch(url,{signal:controller.signal}).then(r=>r.json()).then(d=>{ render(d); }).catch(()=>{});
      }
      if (input) {
        let t=null; input.addEventListener('input',()=>{ if(t)clearTimeout(t); t=setTimeout(()=>load(1),300); });
      }
      if (btn) btn.addEventListener('click', (e)=>{ e.preventDefault(); load(1); });
      const thead = document.querySelector('thead');
      if (thead) thead.addEventListener('click', (e)=>{
        const th = e.target.closest('[data-sort]'); if(!th) return;
        const key = th.dataset.sort; if (!key) return;
        if (sSort === key) sDir = sDir === 'asc' ? 'desc' : 'asc'; else { sSort = key; sDir = 'asc'; }
        load(1);
      });
      if (pager) pager.addEventListener('click', (e)=>{ const p=e.target?.dataset?.page; if(p){ e.preventDefault(); load(parseInt(p,10)||1);} });
      // Edit modal
      const overlay = document.getElementById('editStudentModal');
      const form = document.getElementById('editStudentForm');
      function closeEdit(){ overlay.classList.add('hidden'); overlay.classList.remove('flex'); }
      function openEditFromRow(tr){
        form.id.value = tr.dataset.id||'';
        form.student_id.value = tr.dataset.student_id||'';
        form.name.value = tr.dataset.name||'';
        form.year_level.value = tr.dataset.year_level||'';
        form.section.value = tr.dataset.section||'';
        form.gender.value = tr.dataset.gender||'';
        document.getElementById('editDepartment').value = tr.dataset.department||'';
        // refresh course options to match department
        (function(){
          const byDept = { Education: ['BEED','BSED','BTLED'], Technology: ['BSIT','BIT'] };
          const all = ['BSIT','BIT','BEED','BSED','BTLED'];
          const dep = document.getElementById('editDepartment').value;
          const allowed = byDept[dep] || all;
          const cEl = document.getElementById('editCourse');
          cEl.innerHTML = '<option value="">Select</option>' + allowed.map(c => `<option value="${c}">${c}</option>`).join('');
          cEl.value = tr.dataset.course||'';
        })();
        overlay.classList.remove('hidden'); overlay.classList.add('flex');
      }
      document.addEventListener('click', (e)=>{
        const btn = e.target.closest('button[data-edit]');
        if (btn) { const tr = btn.closest('tr'); if (tr) openEditFromRow(tr); }
      });
      overlay.querySelectorAll('[data-edit-close]').forEach(el=>{ el.addEventListener('click', closeEdit); });
      document.addEventListener('keydown', function esc(ev){ if(ev.key==='Escape'){ closeEdit(); }});
      const editDep = document.getElementById('editDepartment');
      const editCourse = document.getElementById('editCourse');
      function refreshEditCourses(){
        const byDept = { Education: ['BEED','BSED','BTLED'], Technology: ['BSIT','BIT'] };
        const all = ['BSIT','BIT','BEED','BSED','BTLED'];
        const dep = editDep.value;
        const allowed = byDept[dep] || all;
        const cur = editCourse.value;
        editCourse.innerHTML = '<option value="">Select</option>' + allowed.map(c => `<option value="${c}">${c}</option>`).join('');
        if (allowed.indexOf(cur)>=0) editCourse.value = cur;
      }
      function deptForCourse(c){ if(['BEED','BSED','BTLED'].indexOf(c)>=0) return 'Education'; if(['BSIT','BIT'].indexOf(c)>=0) return 'Technology'; return ''; }
      editDep.addEventListener('change', refreshEditCourses);
      editCourse.addEventListener('change', ()=>{ const d=deptForCourse(editCourse.value); if(d && editDep.value!==d){ editDep.value=d; const keep=editCourse.value; refreshEditCourses(); editCourse.value=keep; }});
      // initial load on page ready (enhancement)
      load(1);
    })();
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

    // Delete Section Functionality
    const deleteSectionBtn = document.getElementById('deleteSectionBtn');
    const deleteSectionCourse = document.getElementById('deleteSectionCourse');
    const deleteSectionYear = document.getElementById('deleteSectionYear');
    const deleteSectionSection = document.getElementById('deleteSectionSection');
    const studentsSearchInput = document.getElementById('studentsSearch');
    
    // Function to auto-filter when dropdowns change
    function autoFilterBySection() {
      const course = deleteSectionCourse.value.trim();
      const year = deleteSectionYear.value.trim();
      const section = deleteSectionSection.value.trim();
      
      if (course && year && section) {
        // Set search input to the combined filter
        if (studentsSearchInput) {
          studentsSearchInput.value = `${course} ${year}-${section}`;
          // Trigger the search
          const event = new Event('input', { bubbles: true });
          studentsSearchInput.dispatchEvent(event);
        }
      }
    }
    
    if (deleteSectionCourse) deleteSectionCourse.addEventListener('change', autoFilterBySection);
    if (deleteSectionYear) deleteSectionYear.addEventListener('change', autoFilterBySection);
    if (deleteSectionSection) deleteSectionSection.addEventListener('change', autoFilterBySection);
    
    if (deleteSectionBtn) {
      deleteSectionBtn.addEventListener('click', function() {
        const course = deleteSectionCourse.value.trim();
        const year = deleteSectionYear.value.trim();
        const section = deleteSectionSection.value.trim();
        
        if (!course || !year || !section) {
          alert('Please select Course, Year, and Section.');
          return;
        }
        
        if (confirm(`Are you sure you want to delete all students from ${course} Year ${year} Section ${section}? This action cannot be undone.`)) {
          const form = document.createElement('form');
          form.method = 'POST';
          form.innerHTML = `
            <input type="hidden" name="action" value="delete_section">
            <input type="hidden" name="course" value="${escapeHtml(course)}">
            <input type="hidden" name="year_level" value="${escapeHtml(year)}">
            <input type="hidden" name="section" value="${escapeHtml(section)}">
          `;
          document.body.appendChild(form);
          form.submit();
        }
      });
    }

    function escapeHtml(text) {
      const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      };
      return text.replace(/[&<>"']/g, m => map[m]);
    }
  </script>
</body>
</html>
