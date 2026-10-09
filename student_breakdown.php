<?php
require_once __DIR__ . '/config.php';
$db = db();

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $course = isset($_GET['course']) ? $_GET['course'] : '';
    $gender = isset($_GET['gender']) ? $_GET['gender'] : '';
    
    // Get students by section, sorted alphabetically
    $query = "SELECT name, student_id, course, year_level, section, gender, department 
              FROM students";
    
    $whereConditions = [];
    
    if ($course && in_array($course, ['BEED', 'BSED', 'BTLED', 'BIT', 'BSIT'])) {
        $whereConditions[] = "course = '" . $db->real_escape_string($course) . "'";
    }
    
    if ($gender && in_array($gender, ['Male', 'Female'])) {
        $whereConditions[] = "gender = '" . $db->real_escape_string($gender) . "'";
    }
    
    if (!empty($whereConditions)) {
        $query .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    $query .= " ORDER BY section ASC, name ASC";
    
    $result = $db->query($query);
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    // Generate CSV
    $filename = ($course ? $course . '-' : 'All-Students-');
    if ($gender) {
        $filename .= $gender . '-';
    }
    $filename .= date('Y-m-d-His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    fputcsv($output, ['Name', 'Student ID', 'Course', 'Year Level', 'Section', 'Gender', 'Department']);
    
    foreach ($students as $student) {
        fputcsv($output, [
            $student['name'],
            $student['student_id'],
            $student['course'],
            $student['year_level'],
            $student['section'],
            $student['gender'],
            $student['department']
        ]);
    }
    
    fclose($output);
    exit;
}

// Query to get student count by course
$courses = ['BEED', 'BSED', 'BTLED', 'BIT', 'BSIT'];
$breakdown = [];
$totalStudents = 0;

foreach ($courses as $course) {
    $result = $db->query("SELECT COUNT(*) as count FROM students WHERE course = '" . $db->real_escape_string($course) . "'");
    $row = $result->fetch_assoc();
    $count = $row['count'] ?? 0;
    $breakdown[$course] = $count;
    $totalStudents += $count;
}

// Also get department breakdown
$deptQuery = $db->query("SELECT department, COUNT(*) as count FROM students GROUP BY department");
$deptBreakdown = [];
while ($row = $deptQuery->fetch_assoc()) {
    $deptBreakdown[$row['department']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Enrollment Breakdown - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'analytics.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-[92rem] w-full mx-auto px-4 sm:px-6 py-6 space-y-6 flex-1">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-200">
      <div>
        <div class="flex items-center gap-2 mb-1">
          <a href="analytics.php" class="text-xs text-slate-500 hover:text-[#0F3D87] flex items-center gap-1 font-medium transition-colors">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Analytics
          </a>
        </div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Student Enrollment Breakdown</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Distribution analysis across degree programs, academic departments, and cohorts.</p>
      </div>
      <div class="flex items-center gap-2 self-start sm:self-auto">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 border border-blue-200 text-[#0F3D87]">
          <i data-lucide="users" class="w-3.5 h-3.5"></i>
          <span>Total Students: <strong class="text-[#0F3D87] font-bold"><?php echo $totalStudents; ?></strong></span>
        </span>
      </div>
    </div>

    <!-- Export Actions Toolbar -->
    <div class="border border-slate-200 rounded-lg bg-white p-4 shadow-xs">
      <div class="flex items-center justify-between flex-wrap gap-2 text-xs">
        <div class="flex items-center gap-2">
          <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
            <i data-lucide="download" class="w-4 h-4"></i>
          </div>
          <span class="font-bold text-slate-800">Export Student Data:</span>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <a href="?export=csv" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white font-semibold shadow-2xs transition-colors">
            <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5"></i>
            <span>Export All (CSV)</span>
          </a>
          
          <div class="relative inline-block">
            <button type="button" id="exportCourseBtn" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 font-semibold shadow-2xs transition-colors">
              <i data-lucide="layers" class="w-3.5 h-3.5 text-[#0F3D87]"></i>
              <span>By Course</span>
              <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-400"></i>
            </button>
            <div id="courseDropdown" class="hidden absolute right-0 mt-1.5 w-44 bg-white border border-slate-200 rounded-lg shadow-lg py-1 z-20 text-xs">
              <a href="?export=csv&course=BEED" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BEED Roster</a>
              <a href="?export=csv&course=BSED" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BSED Roster</a>
              <a href="?export=csv&course=BTLED" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BTLED Roster</a>
              <a href="?export=csv&course=BIT" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BIT Roster</a>
              <a href="?export=csv&course=BSIT" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BSIT Roster</a>
            </div>
          </div>

          <div class="relative inline-block">
            <button type="button" id="exportMaleBtn" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 font-semibold shadow-2xs transition-colors">
              <i data-lucide="user" class="w-3.5 h-3.5 text-blue-600"></i>
              <span>Male Students</span>
              <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-400"></i>
            </button>
            <div id="maleDropdown" class="hidden absolute right-0 mt-1.5 w-44 bg-white border border-slate-200 rounded-lg shadow-lg py-1 z-20 text-xs">
              <a href="?export=csv&gender=Male" class="block px-3 py-2 text-slate-700 hover:bg-slate-50 font-semibold">All Courses (Male)</a>
              <a href="?export=csv&course=BEED&gender=Male" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BEED - Male</a>
              <a href="?export=csv&course=BSED&gender=Male" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BSED - Male</a>
              <a href="?export=csv&course=BTLED&gender=Male" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BTLED - Male</a>
              <a href="?export=csv&course=BIT&gender=Male" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BIT - Male</a>
              <a href="?export=csv&course=BSIT&gender=Male" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BSIT - Male</a>
            </div>
          </div>

          <div class="relative inline-block">
            <button type="button" id="exportFemaleBtn" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 font-semibold shadow-2xs transition-colors">
              <i data-lucide="user" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>Female Students</span>
              <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-400"></i>
            </button>
            <div id="femaleDropdown" class="hidden absolute right-0 mt-1.5 w-44 bg-white border border-slate-200 rounded-lg shadow-lg py-1 z-20 text-xs">
              <a href="?export=csv&gender=Female" class="block px-3 py-2 text-slate-700 hover:bg-slate-50 font-semibold">All Courses (Female)</a>
              <a href="?export=csv&course=BEED&gender=Female" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BEED - Female</a>
              <a href="?export=csv&course=BSED&gender=Female" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BSED - Female</a>
              <a href="?export=csv&course=BTLED&gender=Female" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BTLED - Female</a>
              <a href="?export=csv&course=BIT&gender=Female" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BIT - Female</a>
              <a href="?export=csv&course=BSIT&gender=Female" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">BSIT - Female</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Summary Metrics Strip -->
    <div class="border border-slate-200 bg-white rounded-lg shadow-xs overflow-hidden grid grid-cols-2 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-slate-100">
      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Enrolled</span>
          <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1 font-mono"><?php echo $totalStudents; ?></div>
        </div>
        <div class="p-2.5 rounded-full bg-blue-50 text-[#0F3D87]">
          <i data-lucide="users" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Programs</span>
          <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1 font-mono"><?php echo count($courses); ?></div>
        </div>
        <div class="p-2.5 rounded-full bg-indigo-50 text-indigo-700">
          <i data-lucide="book-open" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Departments</span>
          <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1 font-mono"><?php echo count($deptBreakdown); ?></div>
        </div>
        <div class="p-2.5 rounded-full bg-emerald-50 text-emerald-600">
          <i data-lucide="building-2" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Avg per Course</span>
          <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1 font-mono"><?php echo $totalStudents > 0 ? round($totalStudents / count($courses), 1) : 0; ?></div>
        </div>
        <div class="p-2.5 rounded-full bg-amber-50 text-amber-600">
          <i data-lucide="bar-chart-2" class="w-5 h-5"></i>
        </div>
      </div>
    </div>

    <!-- 2-Column Content: Course Breakdown & Visual Bar Chart -->
    <div class="grid lg:grid-cols-12 gap-6 items-start">
      <!-- Left Column: Courses Table & Visual Progress (7 cols) -->
      <div class="lg:col-span-7 space-y-6">
        <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs space-y-4">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div class="flex items-center gap-2">
              <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
                <i data-lucide="graduation-cap" class="w-4 h-4"></i>
              </div>
              <h2 class="text-sm font-bold text-slate-900">Enrollment by Academic Program</h2>
            </div>
          </div>

          <div class="table-container">
            <table class="table-modern">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Degree Program</th>
                  <th class="text-right">Students</th>
                  <th class="text-right">Share</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $courseNames = [
                    'BEED' => 'Bachelor of Elementary Education',
                    'BSED' => 'Bachelor of Secondary Education',
                    'BTLED' => 'Bachelor of Technology & Livelihood Education',
                    'BIT' => 'Bachelor of Industrial Technology',
                    'BSIT' => 'Bachelor of Science in Information Technology'
                ];
                
                foreach ($courses as $course):
                    $count = $breakdown[$course];
                    $percentage = $totalStudents > 0 ? round(($count / $totalStudents) * 100, 1) : 0;
                ?>
                <tr>
                  <td><span class="inline-flex px-1.5 py-0.5 rounded text-[11px] font-bold bg-slate-100 text-slate-800"><?php echo $course; ?></span></td>
                  <td class="text-xs text-slate-700"><?php echo $courseNames[$course]; ?></td>
                  <td class="text-right font-mono text-xs font-semibold text-slate-900"><?php echo $count; ?></td>
                  <td class="text-right font-mono text-xs text-slate-600"><?php echo $percentage; ?>%</td>
                </tr>
                <?php endforeach; ?>
                <tr class="bg-slate-50 font-bold border-t-2 border-slate-200">
                  <td colspan="2" class="text-slate-900">Total Enrolled</td>
                  <td class="text-right font-mono text-slate-900"><?php echo $totalStudents; ?></td>
                  <td class="text-right font-mono text-slate-900">100%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Visual Distribution Bars -->
        <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs space-y-4">
          <div class="flex items-center gap-2 pb-3 border-b border-slate-100">
            <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
              <i data-lucide="bar-chart-3" class="w-4 h-4"></i>
            </div>
            <h3 class="text-sm font-bold text-slate-900">Visual Distribution Comparison</h3>
          </div>

          <div class="space-y-3 pt-1">
            <?php
            $maxCount = max($breakdown) ?: 1;
            foreach ($courses as $course):
                $count = $breakdown[$course];
                $percentOfMax = round(($count / $maxCount) * 100);
                $shareOfTotal = $totalStudents > 0 ? round(($count / $totalStudents) * 100, 1) : 0;
            ?>
            <div class="space-y-1">
              <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-slate-800"><?php echo $course; ?></span>
                <span class="text-slate-500 font-mono"><?php echo $count; ?> students (<?php echo $shareOfTotal; ?>%)</span>
              </div>
              <div class="w-full h-3 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full bg-[#0F3D87] rounded-full transition-all duration-500" style="width: <?php echo max(4, $percentOfMax); ?>%;"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Right Column: Department Breakdown (5 cols) -->
      <div class="lg:col-span-5 space-y-6">
        <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs space-y-4">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div class="flex items-center gap-2">
              <div class="p-1.5 rounded-md bg-blue-50 text-[#0F3D87]">
                <i data-lucide="building-2" class="w-4 h-4"></i>
              </div>
              <h2 class="text-sm font-bold text-slate-900">Enrollment by Department</h2>
            </div>
          </div>

          <div class="table-container">
            <table class="table-modern">
              <thead>
                <tr>
                  <th>Department</th>
                  <th class="text-right">Students</th>
                  <th class="text-right">Share</th>
                </tr>
              </thead>
              <tbody>
                <?php
                foreach ($deptBreakdown as $dept => $count):
                    $percentage = $totalStudents > 0 ? round(($count / $totalStudents) * 100, 1) : 0;
                ?>
                <tr>
                  <td class="font-medium text-slate-800"><?php echo h($dept); ?></td>
                  <td class="text-right font-mono text-xs font-semibold text-slate-900"><?php echo $count; ?></td>
                  <td class="text-right font-mono text-xs text-slate-600"><?php echo $percentage; ?>%</td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    function setupDropdown(btnId, dropdownId) {
      const btn = document.getElementById(btnId);
      const dropdown = document.getElementById(dropdownId);
      if (!btn || !dropdown) return;
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
      });
    }

    setupDropdown('exportCourseBtn', 'courseDropdown');
    setupDropdown('exportMaleBtn', 'maleDropdown');
    setupDropdown('exportFemaleBtn', 'femaleDropdown');

    document.addEventListener('click', (event) => {
      ['courseDropdown', 'maleDropdown', 'femaleDropdown'].forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.classList.contains('hidden')) {
          el.classList.add('hidden');
        }
      });
    });
  </script>
  <?php include __DIR__ . '/includes/footer.php'; ?>
