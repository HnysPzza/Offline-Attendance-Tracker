<?php
require_once __DIR__ . '/config.php';
if (!can_access_page(__FILE__)) {
    header('Location: index.php');
    exit;
}
$db = db();

// Handle API requests
if (isset($_GET['api']) && $_GET['api'] === 'analytics') {
    header('Content-Type: application/json');
    $schedule_id = intval($_GET['schedule_id'] ?? 0);
    $course_filter = trim($_GET['course'] ?? '');
    
    if ($schedule_id <= 0) {
        echo json_encode([]);
        exit;
    }

    $courses = ['BSIT', 'BIT', 'BEED', 'BTLED', 'BSED'];
    $result = [];
    $totalOnTime = 0;
    $totalLate = 0;
    $totalNoTimeIn = 0;
    $totalAllStudents = 0;

    foreach ($courses as $course) {
        $stmt = $db->prepare('
            SELECT 
                COUNT(CASE WHEN a.status = "On time" THEN 1 END) as on_time,
                COUNT(CASE WHEN a.status = "Late" THEN 1 END) as late
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.schedule_id = ? AND s.course = ?
        ');
        $stmt->bind_param('is', $schedule_id, $course);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $onTime = (int)($row['on_time'] ?? 0);
        $late = (int)($row['late'] ?? 0);
        
        // Get count of students enrolled in this course for this schedule
        $totalStmt = $db->prepare('
            SELECT COUNT(DISTINCT s.id) as total_students
            FROM students s
            WHERE s.course = ?
        ');
        $totalStmt->bind_param('s', $course);
        $totalStmt->execute();
        $totalRow = $totalStmt->get_result()->fetch_assoc();
        $totalStmt->close();
        $totalStudents = (int)($totalRow['total_students'] ?? 0);
        
        // Count students who did not time in
        $noTimeIn = $totalStudents - ($onTime + $late);
        $noTimeIn = max(0, $noTimeIn);
        
        $result[$course] = [
            'on_time' => $onTime,
            'late' => $late,
            'no_time_in' => $noTimeIn,
            'total_students' => $totalStudents
        ];
        
        $totalOnTime += $onTime;
        $totalLate += $late;
        $totalNoTimeIn += $noTimeIn;
        $totalAllStudents += $totalStudents;
    }

    $result['total'] = [
        'on_time' => $totalOnTime,
        'late' => $totalLate,
        'no_time_in' => $totalNoTimeIn,
        'total_students' => $totalAllStudents
    ];
    
    if ($course_filter && in_array($course_filter, $courses)) {
        $result['filtered'] = $result[$course_filter];
    }

    echo json_encode($result);
    exit;
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $schedule_id = intval($_GET['schedule_id'] ?? 0);
    $course_filter = trim($_GET['course'] ?? '');
    
    if ($schedule_id > 0) {
        // Get schedule title and date for filename
        $scheduleStmt = $db->prepare('SELECT title, schedule_date FROM schedules WHERE id = ?');
        $scheduleStmt->bind_param('i', $schedule_id);
        $scheduleStmt->execute();
        $scheduleRow = $scheduleStmt->get_result()->fetch_assoc();
        $scheduleStmt->close();
        
        $scheduleTitle = $scheduleRow['title'] ?? 'Schedule';
        $scheduleDate = $scheduleRow['schedule_date'] ?? date('Y-m-d');
        $courseName = $course_filter && in_array($course_filter, ['BSIT', 'BIT', 'BEED', 'BTLED', 'BSED']) ? $course_filter : 'All';
        
        // Get all students with their attendance status
        if ($course_filter && in_array($course_filter, ['BSIT', 'BIT', 'BEED', 'BTLED', 'BSED'])) {
            $stmt = $db->prepare('
                SELECT 
                    s.student_id,
                    s.name,
                    s.course,
                    s.year_level,
                    s.section,
                    sch.title as schedule_title,
                    sch.schedule_date,
                    a.time_in,
                    a.time_out,
                    a.status,
                    a.minutes_late,
                    (SELECT COUNT(*) FROM attendance a2 WHERE a2.student_id = s.id AND a2.status = "Late") as total_lates
                FROM students s
                LEFT JOIN attendance a ON a.student_id = s.id AND a.schedule_id = ?
                LEFT JOIN schedules sch ON a.schedule_id = sch.id
                WHERE s.course = ?
                ORDER BY s.name
            ');
            $stmt->bind_param('is', $schedule_id, $course_filter);
        } else {
            $stmt = $db->prepare('
                SELECT 
                    s.student_id,
                    s.name,
                    s.course,
                    s.year_level,
                    s.section,
                    sch.title as schedule_title,
                    sch.schedule_date,
                    a.time_in,
                    a.time_out,
                    a.status,
                    a.minutes_late,
                    (SELECT COUNT(*) FROM attendance a2 WHERE a2.student_id = s.id AND a2.status = "Late") as total_lates
                FROM students s
                LEFT JOIN attendance a ON a.student_id = s.id AND a.schedule_id = ?
                LEFT JOIN schedules sch ON a.schedule_id = sch.id
                ORDER BY s.course, s.name
            ');
            $stmt->bind_param('i', $schedule_id);
        }
        
        $stmt->execute();
        $res = $stmt->get_result();
        
        $filename = $scheduleTitle . '-' . $courseName . '-' . $scheduleDate . '-Analytics.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Student ID', 'Name', 'Course', 'Year', 'Section', 'Schedule', 'Date', 'Time In', 'Time Out', 'Status', 'Minutes Late', 'Total Lates', 'Sanction Status']);
        
        while ($row = $res->fetch_assoc()) {
            $status = $row['status'] ?: 'Absent';
            $sanctionStatus = (int)$row['total_lates'] >= 3 ? 'SANCTION' : 'NONE';
            fputcsv($output, [
                $row['student_id'],
                $row['name'],
                $row['course'],
                $row['year_level'],
                $row['section'],
                $row['schedule_title'] ?: $scheduleTitle,
                $row['schedule_date'] ?: $scheduleDate,
                $row['time_in'] ?: '',
                $row['time_out'] ?: '',
                $status,
                $row['minutes_late'] ?: '',
                $row['total_lates'] ?: 0,
                $sanctionStatus
            ]);
        }
        
        fclose($output);
        $stmt->close();
        exit;
    }
}

// Get all schedules
$selected_schedule_id = intval($_GET['schedule_id'] ?? 0);
$schedules = [];
$res = $db->query('SELECT id, title, schedule_date FROM schedules ORDER BY schedule_date DESC');
while ($row = $res->fetch_assoc()) {
    $schedules[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo time(); ?>">
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
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Attendance Analytics</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Session turnout, punctuality breakdown, and course-by-course performance charts.</p>
      </div>
      <div class="flex items-center gap-2 self-start sm:self-auto w-full sm:w-auto">
        <a href="student_breakdown.php" class="w-full sm:w-auto justify-center inline-flex items-center gap-1.5 px-3 py-2 sm:py-1.5 rounded-md bg-[#0F3D87] hover:bg-blue-900 text-white text-xs font-semibold shadow-2xs transition-colors min-h-[42px] sm:min-h-0">
          <i data-lucide="users" class="w-3.5 h-3.5"></i>
          <span>Student Breakdown</span>
        </a>
      </div>
    </div>

    <!-- Controls & Filters Surface -->
    <div class="border border-slate-200 rounded-lg bg-white p-4 shadow-xs">
      <div class="flex flex-col md:flex-row md:items-end gap-3 text-xs">
        <div class="flex-1 w-full">
          <label class="block font-semibold text-slate-700 mb-1">Select Schedule *</label>
          <select id="scheduleSelect" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="">-- Choose a schedule session --</option>
            <?php foreach ($schedules as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo $selected_schedule_id === (int)$s['id'] ? 'selected' : ''; ?>><?php echo h($s['title']); ?> — <?php echo h(date('M j, Y', strtotime($s['schedule_date']))); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-full md:w-56">
          <label class="block font-semibold text-slate-700 mb-1">Course Filter</label>
          <select id="courseFilter" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-[#0F3D87] outline-none">
            <option value="">All Courses</option>
            <option value="BSIT">BSIT</option>
            <option value="BIT">BIT</option>
            <option value="BEED">BEED</option>
            <option value="BTLED">BTLED</option>
            <option value="BSED">BSED</option>
          </select>
        </div>
        <div class="w-full md:w-auto flex items-center gap-2 self-stretch md:self-end">
          <button type="button" id="exportBtn" class="w-full md:w-auto justify-center inline-flex items-center gap-1.5 px-3.5 py-2 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold shadow-2xs transition-colors min-h-[42px] md:min-h-0">
            <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600"></i>
            <span>Export CSV</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Unified 4-Metric Toolbar (Matching Dashboard) -->
    <div class="border border-slate-200 bg-white rounded-lg shadow-xs overflow-hidden grid grid-cols-2 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-slate-100">
      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Enrolled</span>
          <div id="totalStudentsCount" class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1 font-mono">0</div>
        </div>
        <div class="p-2.5 rounded-full bg-blue-50 text-[#0F3D87]">
          <i data-lucide="users" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">On Time</span>
          <div id="onTimeCount" class="text-2xl sm:text-3xl font-extrabold text-emerald-600 mt-1 font-mono">0</div>
        </div>
        <div class="p-2.5 rounded-full bg-emerald-50 text-emerald-600">
          <i data-lucide="user-check" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Late Attendance</span>
          <div id="lateCount" class="text-2xl sm:text-3xl font-extrabold text-amber-600 mt-1 font-mono">0</div>
        </div>
        <div class="p-2.5 rounded-full bg-amber-50 text-amber-600">
          <i data-lucide="clock" class="w-5 h-5"></i>
        </div>
      </div>

      <div class="p-4 sm:p-5 flex items-center justify-between">
        <div>
          <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Absent / No Time In</span>
          <div id="noTimeInCount" class="text-2xl sm:text-3xl font-extrabold text-rose-600 mt-1 font-mono">0</div>
        </div>
        <div class="p-2.5 rounded-full bg-rose-50 text-rose-600">
          <i data-lucide="user-x" class="w-5 h-5"></i>
        </div>
      </div>
    </div>

    <!-- Charts Workspace (Clean border containers) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5" id="chartsContainer">
      <!-- BSIT Chart -->
      <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs chart-card" data-course="bsit">
        <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-50 text-[#0F3D87]">BSIT</span>
            <span class="text-xs text-slate-500">Information Technology</span>
          </div>
        </div>
        <canvas id="bsitChart" class="max-h-72"></canvas>
      </div>

      <!-- BIT Chart -->
      <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs chart-card" data-course="bit">
        <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-50 text-[#0F3D87]">BIT</span>
            <span class="text-xs text-slate-500">Industrial Technology</span>
          </div>
        </div>
        <canvas id="bitChart" class="max-h-72"></canvas>
      </div>

      <!-- BEED Chart -->
      <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs chart-card" data-course="beed">
        <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-indigo-50 text-indigo-700">BEED</span>
            <span class="text-xs text-slate-500">Elementary Education</span>
          </div>
        </div>
        <canvas id="beedChart" class="max-h-72"></canvas>
      </div>

      <!-- BTLED Chart -->
      <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs chart-card" data-course="btled">
        <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-indigo-50 text-indigo-700">BTLED</span>
            <span class="text-xs text-slate-500">Tech & Livelihood Education</span>
          </div>
        </div>
        <canvas id="btledChart" class="max-h-72"></canvas>
      </div>

      <!-- BSED Chart -->
      <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs chart-card" data-course="bsed">
        <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-indigo-50 text-indigo-700">BSED</span>
            <span class="text-xs text-slate-500">Secondary Education</span>
          </div>
        </div>
        <canvas id="bsedChart" class="max-h-72"></canvas>
      </div>
    </div>
  </main>

  <script>
    const chartInstances = {};
    const courses = ['BSIT', 'BIT', 'BEED', 'BTLED', 'BSED'];

    function createChart(courseId, courseLabel) {
      const ctx = document.getElementById(courseId + 'Chart').getContext('2d');
      
      if (chartInstances[courseId]) {
        chartInstances[courseId].destroy();
      }

      chartInstances[courseId] = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: ['On Time', 'Late'],
          datasets: [{
            label: courseLabel,
            data: [0, 0],
            backgroundColor: ['#10b981', '#f59e0b'],
            borderColor: ['#059669', '#d97706'],
            borderWidth: 1,
            borderRadius: 4
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                stepSize: 1,
                font: { family: 'ui-sans-serif, system-ui' }
              },
              grid: { color: '#f1f5f9' }
            },
            x: {
              ticks: { font: { family: 'ui-sans-serif, system-ui' } },
              grid: { display: false }
            }
          }
        }
      });
    }

    function loadAnalytics() {
      const scheduleId = document.getElementById('scheduleSelect').value;
      
      if (!scheduleId) {
        courses.forEach(course => {
          const courseId = course.toLowerCase();
          createChart(courseId, course);
        });
        document.getElementById('totalStudentsCount').textContent = '0';
        document.getElementById('onTimeCount').textContent = '0';
        document.getElementById('lateCount').textContent = '0';
        document.getElementById('noTimeInCount').textContent = '0';
        return;
      }

      fetch(`?api=analytics&schedule_id=${encodeURIComponent(scheduleId)}`)
        .then(r => r.json())
        .then(data => {
          courses.forEach(course => {
            const courseId = course.toLowerCase();
            const courseData = data[course] || { on_time: 0, late: 0 };
            
            createChart(courseId, course);
            chartInstances[courseId].data.datasets[0].data = [
              courseData.on_time,
              courseData.late
            ];
            chartInstances[courseId].update();
          });
          
          const totals = data.total || { on_time: 0, late: 0, no_time_in: 0, total_students: 0 };
          document.getElementById('totalStudentsCount').textContent = totals.total_students;
          document.getElementById('onTimeCount').textContent = totals.on_time;
          document.getElementById('lateCount').textContent = totals.late;
          document.getElementById('noTimeInCount').textContent = totals.no_time_in;
        })
        .catch(err => console.error('Error loading analytics:', err));
    }

    document.getElementById('scheduleSelect').addEventListener('change', loadAnalytics);

    document.getElementById('courseFilter').addEventListener('change', function() {
      const selectedCourse = this.value;
      const chartCards = document.querySelectorAll('.chart-card');
      
      if (selectedCourse) {
        chartCards.forEach(card => {
          if (card.dataset.course === selectedCourse.toLowerCase()) {
            card.style.display = 'block';
            card.style.gridColumn = 'span 2';
          } else {
            card.style.display = 'none';
          }
        });
        
        const scheduleId = document.getElementById('scheduleSelect').value;
        if (scheduleId) {
          fetch(`?api=analytics&schedule_id=${encodeURIComponent(scheduleId)}&course=${encodeURIComponent(selectedCourse)}`)
            .then(r => r.json())
            .then(data => {
              if (data.filtered) {
                document.getElementById('totalStudentsCount').textContent = data.filtered.total_students;
                document.getElementById('onTimeCount').textContent = data.filtered.on_time;
                document.getElementById('lateCount').textContent = data.filtered.late;
                document.getElementById('noTimeInCount').textContent = data.filtered.no_time_in;
              }
            })
            .catch(err => console.error('Error:', err));
        }
      } else {
        chartCards.forEach(card => {
          card.style.display = 'block';
          card.style.gridColumn = 'auto';
        });
        
        const scheduleId = document.getElementById('scheduleSelect').value;
        if (scheduleId) {
          fetch(`?api=analytics&schedule_id=${encodeURIComponent(scheduleId)}`)
            .then(r => r.json())
            .then(data => {
              const totals = data.total || { on_time: 0, late: 0, no_time_in: 0, total_students: 0 };
              document.getElementById('totalStudentsCount').textContent = totals.total_students;
              document.getElementById('onTimeCount').textContent = totals.on_time;
              document.getElementById('lateCount').textContent = totals.late;
              document.getElementById('noTimeInCount').textContent = totals.no_time_in;
            })
            .catch(err => console.error('Error:', err));
        }
      }
    });

    document.getElementById('exportBtn').addEventListener('click', function() {
      const scheduleId = document.getElementById('scheduleSelect').value;
      const selectedCourse = document.getElementById('courseFilter').value;
      
      if (!scheduleId) {
        alert('Please select a schedule first');
        return;
      }
      
      let url = `?export=csv&schedule_id=${encodeURIComponent(scheduleId)}`;
      if (selectedCourse) {
        url += `&course=${encodeURIComponent(selectedCourse)}`;
      }
      
      window.location.href = url;
    });

    document.addEventListener('DOMContentLoaded', function() {
      courses.forEach(course => {
        createChart(course.toLowerCase(), course);
      });
      if (document.getElementById('scheduleSelect').value) {
        loadAnalytics();
      }
    });
  </script>
  <?php include __DIR__ . '/includes/footer.php'; ?>
