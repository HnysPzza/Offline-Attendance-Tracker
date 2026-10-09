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
            if ($cellType === 'inlineStr' && isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            } elseif (!isset($cell->v)) {
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
    $perPage = 13; $offset = ($page - 1) * $perPage;
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

$perPage = 13;
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
  <title>Students Roster - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo filemtime(__DIR__ . '/assets/css/responsive.css'); ?>">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'students.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-[92rem] w-full mx-auto px-4 sm:px-6 py-6 space-y-6 flex-1">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-200">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Student Directory & Roster</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Manage student records, batch imports, sections, and roster details.</p>
      </div>
      <div class="flex items-center gap-2 self-start sm:self-auto">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 border border-blue-200 text-[#0F3D87]">
          <i data-lucide="users" class="w-3.5 h-3.5"></i>
          <span>Total Students: <strong class="text-[#0F3D87] font-bold"><?php echo $count; ?></strong></span>
        </span>
      </div>
    </div>

    <!-- Mobile-Only Add / Import Toggle Banner -->
    <div class="lg:hidden flex items-center justify-between gap-3 bg-white p-3.5 rounded-lg border border-slate-200 shadow-xs">
      <div class="flex items-center gap-2.5 min-w-0">
        <div class="p-2 rounded-md bg-blue-50 text-[#0F3D87] shrink-0">
          <i data-lucide="user-plus" class="w-4 h-4"></i>
        </div>
        <div class="truncate">
          <h2 class="text-xs font-bold text-slate-900 truncate">Add or Import Records</h2>
          <p class="text-[11px] text-slate-500 truncate">Create student or batch upload file</p>
        </div>
      </div>
      <button type="button" id="toggleMobileFormsBtn" class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-md bg-[#0F3D87] text-white text-xs font-semibold shadow-2xs hover:bg-blue-900 active:scale-[0.98] transition-all min-h-[40px] cursor-pointer">
        <i data-lucide="plus" id="toggleMobileFormsIcon" class="w-4 h-4"></i>
        <span id="toggleMobileFormsText">Add / Import</span>
      </button>
    </div>

    <!-- Main Workspace (Clean 2-Column Grid) -->
    <div class="grid lg:grid-cols-12 gap-6 items-stretch">
      <!-- Left Column: Add Student & Data Operations (Collapsible on mobile) -->
      <div id="studentFormsPanel" class="hidden lg:block lg:col-span-4 space-y-6">
        <!-- Add Student Form Surface -->
        <div class="border border-slate-200 rounded-lg bg-white p-5 space-y-4 shadow-xs">
          <div class="flex items-center gap-2 pb-3 border-b border-slate-100">
            <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
              <i data-lucide="user-plus" class="w-4 h-4"></i>
            </div>
            <h2 class="text-sm font-bold text-slate-900">Add Student Record</h2>
          </div>

          <form method="post" class="space-y-3.5 text-xs">
            <input type="hidden" name="action" value="create" />
            <div>
              <label class="block font-semibold text-slate-700 mb-1">Student ID *</label>
              <input name="student_id" type="text" required placeholder="e.g. 1350879 or 4A-01" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none" />
            </div>
            <div>
              <label class="block font-semibold text-slate-700 mb-1">Full Name *</label>
              <input name="name" type="text" required placeholder="Last Name, First Name M.I." class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none" />
            </div>
            <div class="grid grid-cols-2 gap-2.5">
              <div>
                <label class="block font-semibold text-slate-700 mb-1">Year Level *</label>
                <input name="year_level" type="text" required placeholder="e.g. 4" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none" />
              </div>
              <div>
                <label class="block font-semibold text-slate-700 mb-1">Section *</label>
                <input name="section" type="text" required placeholder="e.g. A" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none" />
              </div>
            </div>
            <div>
              <label class="block font-semibold text-slate-700 mb-1">Gender *</label>
              <select name="gender" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none">
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-2.5">
              <div>
                <label class="block font-semibold text-slate-700 mb-1">Department *</label>
                <select id="departmentSelect" name="department" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none">
                  <option value="">Select</option>
                  <option value="Education">Education</option>
                  <option value="Technology">Technology</option>
                </select>
              </div>
              <div>
                <label class="block font-semibold text-slate-700 mb-1">Course *</label>
                <select id="courseSelect" name="course" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none">
                  <option value="">Select</option>
                  <option value="BSIT">BSIT</option>
                  <option value="BIT">BIT</option>
                  <option value="BEED">BEED</option>
                  <option value="BSED">BSED</option>
                  <option value="BTLED">BTLED</option>
                </select>
              </div>
            </div>
            <button type="submit" class="w-full mt-2 inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-md bg-[#0F3D87] text-white font-semibold text-xs hover:bg-blue-900 transition-colors shadow-2xs">
              <i data-lucide="check" class="w-4 h-4"></i>
              <span>Save Student</span>
            </button>
          </form>
        </div>

        <!-- Batch Data Management Surface -->
        <div class="border border-slate-200 rounded-lg bg-white p-5 space-y-4 shadow-xs">
          <div class="flex items-center gap-2 pb-3 border-b border-slate-100">
            <div class="p-1.5 rounded-md bg-amber-50 text-amber-700">
              <i data-lucide="file-up" class="w-4 h-4"></i>
            </div>
            <h2 class="text-sm font-bold text-slate-900">Batch Import (CSV / XLSX)</h2>
          </div>

          <form method="post" enctype="multipart/form-data" class="space-y-3 text-xs">
            <input type="hidden" name="action" value="import" />
            <p class="text-[11px] text-slate-500 leading-normal">
              Required headers: Student ID, Full Name, Course, Year, Section, Gender, Department.
            </p>
            <div class="relative border-2 border-dashed border-slate-200 rounded-lg p-3 hover:border-slate-300 bg-slate-50/50 transition-colors text-center">
              <input type="file" name="import_file" accept=".csv,.xlsx" required class="w-full text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-white file:text-slate-700 file:shadow-2xs file:border file:border-slate-300 hover:file:bg-slate-50 cursor-pointer" />
            </div>
            <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-800 font-semibold text-xs transition-colors">
              <i data-lucide="upload" class="w-3.5 h-3.5 text-emerald-600"></i>
              <span>Upload & Import</span>
            </button>
          </form>

          <hr class="border-slate-100 my-3">

          <!-- Truncate Danger Zone -->
          <div class="space-y-2 text-xs">
            <div class="flex items-center justify-between">
              <span class="font-semibold text-slate-700 text-[11px] uppercase tracking-wider">Danger Zone</span>
            </div>
            <form method="post" onsubmit="return handleTruncate(event);">
              <input type="hidden" name="action" value="truncate" />
              <button type="button" onclick="handleTruncate(event)" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 transition-colors">
                <i data-lucide="trash-2" class="w-3.5 h-3.5 text-rose-600"></i>
                <span>Truncate All Students</span>
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Right Column: Student Directory Table & Filters (8 Cols) -->
      <div class="lg:col-span-8 border border-slate-200 rounded-lg bg-white px-3 sm:px-5 py-4 shadow-xs flex flex-col justify-between h-full">
        <!-- Top Compact Toolbar (Search + Filters + Delete) -->
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-3">
          <!-- Live Search Bar -->
          <div class="relative flex-1 min-w-[180px]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
              <i data-lucide="search" class="w-4 h-4"></i>
            </div>
            <input id="studentsSearch" type="search" placeholder="Search ID, Name, or class (e.g. BSIT 1-B)..." class="w-full rounded-md border border-slate-300 bg-white pl-9 pr-3 py-2 text-xs text-slate-900 placeholder:text-slate-400 focus:border-[#0F3D87] focus:ring-1 focus:ring-[#0F3D87] outline-none transition-colors" />
            <button id="studentsSearchBtn" class="hidden">Search</button>
          </div>

          <!-- Compact Filter Controls -->
          <div class="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0 shrink-0">
            <select id="deleteSectionCourse" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px]">
              <option value="">Course</option>
              <option value="BSIT">BSIT</option>
              <option value="BIT">BIT</option>
              <option value="BEED">BEED</option>
              <option value="BSED">BSED</option>
              <option value="BTLED">BTLED</option>
            </select>
            <select id="deleteSectionYear" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px]">
              <option value="">Year</option>
              <option value="1">1st</option>
              <option value="2">2nd</option>
              <option value="3">3rd</option>
              <option value="4">4th</option>
            </select>
            <select id="deleteSectionSection" class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 outline-none focus:border-[#0F3D87] h-[36px]">
              <option value="">Sec</option>
              <option value="1">1</option>
              <option value="2">2</option>
              <option value="A">A</option>
              <option value="B">B</option>
              <option value="C">C</option>
              <option value="D">D</option>
            </select>
            <button id="deleteSectionBtn" type="button" title="Delete all students in selected section" class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-md text-xs font-semibold text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 transition-colors shrink-0 h-[36px] active:scale-[0.98]">
              <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
              <span>Delete</span>
            </button>
          </div>
        </div>

        <!-- Modern Flat Student Table Container (Fitted with mobile card layout) -->
        <div class="table-container table-card-mobile overflow-x-auto">
          <table class="table-modern w-full">
            <thead>
              <tr class="select-none">
                <th class="cursor-pointer w-24 text-left" data-sort="student_id">ID</th>
                <th class="cursor-pointer min-w-[180px] text-left" data-sort="name">Student Name</th>
                <th class="cursor-pointer w-20 text-left" data-sort="course">Course</th>
                <th class="cursor-pointer w-16 text-center" data-sort="year_level">Year</th>
                <th class="cursor-pointer w-16 text-center" data-sort="section">Section</th>
                <th class="cursor-pointer w-20 text-left" data-sort="gender">Gender</th>
                <th class="cursor-pointer w-28 text-left" data-sort="department">Department</th>
                <th class="w-24 text-center">Actions</th>
              </tr>
            </thead>
            <tbody id="studentsBody">
              <?php foreach ($students as $s): ?>
                <tr data-id="<?php echo (int)$s['id']; ?>" data-student_id="<?php echo h($s['student_id']); ?>" data-name="<?php echo h($s['name']); ?>" data-course="<?php echo h($s['course']); ?>" data-year_level="<?php echo h($s['year_level']); ?>" data-section="<?php echo h($s['section']); ?>" data-gender="<?php echo h($s['gender']); ?>" data-department="<?php echo h($s['department']); ?>">
                  <td class="cell-id font-mono text-xs font-semibold text-slate-600"><?php echo h($s['student_id']); ?></td>
                  <td class="cell-name font-medium text-slate-900 text-sm"><?php echo h($s['name']); ?></td>
                  <td class="cell-course">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700">
                      <span><?php echo h($s['course']); ?></span>
                      <span class="md:hidden ml-1 font-bold text-slate-800"><?php echo h($s['year_level']); ?>-<?php echo h($s['section']); ?></span>
                    </span>
                  </td>
                  <td class="cell-year text-center font-medium text-slate-600"><?php echo h($s['year_level']); ?></td>
                  <td class="cell-sec text-center font-semibold text-slate-700"><?php echo h($s['section']); ?></td>
                  <td class="st-gender text-slate-600 font-medium"><?php echo h($s['gender']); ?></td>
                  <td class="st-dept text-slate-600 font-medium"><?php echo h($s['department']); ?></td>
                  <td class="mobile-actions text-center">
                    <div class="inline-flex items-center gap-2 justify-center w-full">
                      <button type="button" class="btn-icon-action btn-edit flex-1 md:flex-initial gap-1.5 px-3 py-2 md:p-0.5 rounded-md text-xs font-semibold cursor-pointer" title="Edit Student" aria-label="Edit Student" data-edit>
                        <i data-lucide="pencil" class="w-4 h-4"></i>
                        <span class="md:hidden">Edit</span>
                      </button>
                      <form method="post" class="inline flex-1 md:flex-initial" onsubmit="return confirm('Delete this student?');">
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>" />
                        <button type="submit" class="btn-icon-action btn-delete w-full gap-1.5 px-3 py-2 md:p-0.5 rounded-md text-xs font-semibold cursor-pointer" title="Delete Student" aria-label="Delete Student">
                          <i data-lucide="trash-2" class="w-4 h-4"></i>
                          <span class="md:hidden">Delete</span>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pager Toolbar -->
        <div class="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-500" id="studentsPagerWrap">
          <div id="studentsPageInfo">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
          <div class="inline-flex items-center gap-1" id="studentsPager">
            <?php if ($page > 1): ?>
              <a class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors" href="?page=<?php echo (int)($page-1); ?>">Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors" href="?page=<?php echo (int)($page+1); ?>">Next</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- Edit Student Modal (Modern Flat Dialog) -->
  <div id="editStudentModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-xs" data-edit-close></div>
    <div class="relative bg-white rounded-lg shadow-xl border border-slate-200 max-w-md w-full p-5 space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <div class="flex items-center gap-2">
          <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
            <i data-lucide="pencil" class="w-4 h-4"></i>
          </div>
          <h3 class="text-sm font-bold text-slate-900">Edit Student Record</h3>
        </div>
        <button type="button" class="text-slate-400 hover:text-slate-600 p-1" data-edit-close>
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <form id="editStudentForm" method="post" class="space-y-3 text-xs">
        <input type="hidden" name="action" value="update" />
        <input type="hidden" name="id" value="" />
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Student ID</label>
          <input name="student_id" type="text" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none" />
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Full Name</label>
          <input name="name" type="text" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Year Level</label>
            <input name="year_level" type="text" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none" />
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Section</label>
            <input name="section" type="text" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none" />
          </div>
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Gender</label>
          <select name="gender" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Department</label>
            <select id="editDepartment" name="department" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none">
              <option value="Education">Education</option>
              <option value="Technology">Technology</option>
            </select>
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Course</label>
            <select id="editCourse" name="course" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none">
              <option value="BSIT">BSIT</option>
              <option value="BIT">BIT</option>
              <option value="BEED">BEED</option>
              <option value="BSED">BSED</option>
              <option value="BTLED">BTLED</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
          <button type="button" class="px-3 py-1.5 rounded-md border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 font-medium" data-edit-close>Cancel</button>
          <button type="submit" class="px-4 py-1.5 rounded-md bg-[#0F3D87] text-white hover:bg-blue-900 font-semibold shadow-2xs">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const deptEl = document.getElementById('departmentSelect');
      const courseEl = document.getElementById('courseSelect');
      const all = ['BSIT','BIT','BEED','BSED','BTLED'];
      const byDept = { Education: ['BEED','BSED','BTLED'], Technology: ['BSIT','BIT'] };
      function refreshCourses() {
        if (!deptEl || !courseEl) return;
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
      if (deptEl) deptEl.addEventListener('change', refreshCourses);
      if (courseEl) {
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
      }
    });
  </script>

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
        return `<tr data-id="${esc(st.id)}" data-student_id="${esc(st.student_id)}" data-name="${esc(st.name)}" data-course="${esc(st.course)}" data-year_level="${esc(st.year_level)}" data-section="${esc(st.section)}" data-gender="${esc(st.gender)}" data-department="${esc(st.department)}">
          <td class="cell-id font-mono text-xs font-semibold text-slate-600">${esc(st.student_id)}</td>
          <td class="cell-name font-medium text-slate-900 text-sm">${esc(st.name)}</td>
          <td class="cell-course">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700">
              <span>${esc(st.course)}</span>
              <span class="md:hidden ml-1 font-bold text-slate-800">${esc(st.year_level)}-${esc(st.section)}</span>
            </span>
          </td>
          <td class="cell-year text-center font-medium text-slate-600">${esc(st.year_level)}</td>
          <td class="cell-sec text-center font-semibold text-slate-700">${esc(st.section)}</td>
          <td class="st-gender text-slate-600 font-medium">${esc(st.gender)}</td>
          <td class="st-dept text-slate-600 font-medium">${esc(st.department)}</td>
          <td class="mobile-actions text-center">
            <div class="inline-flex items-center gap-2 justify-center w-full">
              <button type="button" class="btn-icon-action btn-edit flex-1 md:flex-initial gap-1.5 px-3 py-2 md:p-0.5 rounded-md text-xs font-semibold cursor-pointer" title="Edit Student" aria-label="Edit Student" data-edit>
                <i data-lucide="pencil" class="w-4 h-4"></i>
                <span class="md:hidden">Edit</span>
              </button>
              <form method="post" class="inline flex-1 md:flex-initial" onsubmit="return confirm('Delete this student?');">
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="${esc(st.id)}" />
                <button type="submit" class="btn-icon-action btn-delete w-full gap-1.5 px-3 py-2 md:p-0.5 rounded-md text-xs font-semibold cursor-pointer" title="Delete Student" aria-label="Delete Student">
                  <i data-lucide="trash-2" class="w-4 h-4"></i>
                  <span class="md:hidden">Delete</span>
                </button>
              </form>
            </div>
          </td>
        </tr>`;
      }
      function render(data){
        tbody.innerHTML = (data.students||[]).map(rowHtml).join('');
        pageInfo.textContent = `Page ${data.page} of ${data.totalPages}`;
        let phtml = '';
        if (data.page > 1) phtml += `<button data-page="${data.page-1}" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors">Prev</button>`;
        if (data.page < data.totalPages) phtml += `<button data-page="${data.page+1}" class="px-2.5 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-700 transition-colors">Next</button>`;
        pager.innerHTML = phtml;
        if (window.refreshIcons) window.refreshIcons();
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
        if (window.refreshIcons) window.refreshIcons();
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
      if (editDep) editDep.addEventListener('change', refreshEditCourses);
      if (editCourse) editCourse.addEventListener('change', ()=>{ const d=deptForCourse(editCourse.value); if(d && editDep.value!==d){ editDep.value=d; const keep=editCourse.value; refreshEditCourses(); editCourse.value=keep; }});
      load(1);
    })();

    // Mobile forms accordion toggle
    document.addEventListener('DOMContentLoaded', () => {
      const toggleBtn = document.getElementById('toggleMobileFormsBtn');
      const formsPanel = document.getElementById('studentFormsPanel');
      const toggleIcon = document.getElementById('toggleMobileFormsIcon');
      const toggleText = document.getElementById('toggleMobileFormsText');
      if (toggleBtn && formsPanel) {
        toggleBtn.addEventListener('click', () => {
          const isHidden = formsPanel.classList.contains('hidden');
          if (isHidden) {
            formsPanel.classList.remove('hidden');
            if (toggleText) toggleText.textContent = 'Hide Forms';
            if (toggleIcon) toggleIcon.setAttribute('data-lucide', 'chevron-up');
            formsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
          } else {
            formsPanel.classList.add('hidden');
            if (toggleText) toggleText.textContent = 'Add / Import';
            if (toggleIcon) toggleIcon.setAttribute('data-lucide', 'plus');
          }
          if (window.refreshIcons) window.refreshIcons();
        });
      }
    });
  </script>

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
  <?php include __DIR__ . '/includes/footer.php'; ?>
