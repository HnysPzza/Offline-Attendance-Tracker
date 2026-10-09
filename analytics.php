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
  <title>Analytics</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        <a href="attendance.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='attendance.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Attendance</a>
        <a href="analytics.php" class="pb-1.5 border-b-2 transition-colors whitespace-nowrap <?php echo basename($_SERVER['PHP_SELF'])==='analytics.php' ? 'border-[#D4AF37] text-white' : 'border-transparent text-blue-100 hover:text-white'; ?>">Analytics</a>
      </nav>
    </div>
    <nav class="nav-mobile" id="navMobile" aria-hidden="true">
      <?php if (is_local_access()): ?><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='dashboard.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Dashboard</a><a href="schedules.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='schedules.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Schedules</a><a href="logs.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='logs.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Logs</a><a href="qr.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='qr.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">QR Access</a><?php endif; ?>
      <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='index.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Time In/Out</a>
      <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='students.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Students</a>
      <a href="attendance.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='attendance.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Attendance</a>
      <a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='analytics.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Analytics</a>
    </nav>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
    <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 mb-6">
      <h1 class="text-2xl font-semibold mb-4">Attendance Analytics</h1>
      <div class="flex flex-col sm:flex-row gap-4 items-end">
        <div class="flex-1">
          <label class="block text-sm font-medium text-gray-700 mb-2">Select Schedule</label>
          <select id="scheduleSelect" class="w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">-- Choose a schedule --</option>
            <?php foreach ($schedules as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['title']); ?> — <?php echo h($s['schedule_date']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Course (Optional)</label>
          <select id="courseFilter" class="w-full rounded-md border border-gray-300 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
            <option value="">-- All Courses --</option>
            <option value="BSIT">BSIT</option>
            <option value="BIT">BIT</option>
            <option value="BEED">BEED</option>
            <option value="BTLED">BTLED</option>
            <option value="BSED">BSED</option>
          </select>
        </div>
        <div class="flex gap-2">
          <button id="exportBtn" class="px-4 py-2 rounded-md bg-[#D4AF37] text-[#0F3D87] hover:opacity-90 font-medium">Export to CSV</button>
          <a href="student_breakdown.php" class="px-4 py-2 rounded-md bg-[#0F3D87] text-white hover:opacity-90 font-medium inline-flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Student Breakdown
          </a>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
      <div class="bg-blue-50 shadow-md ring-1 ring-blue-200 rounded-xl p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-blue-700 font-medium">Total Students</p>
            <p id="totalStudentsCount" class="text-4xl font-bold text-blue-600 mt-2">0</p>
          </div>
          <svg class="w-12 h-12 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10a3 3 0 11-6 0 3 3 0 016 0zM6 20h12a6 6 0 00-6-6 6 6 0 00-6 6z"/></svg>
        </div>
      </div>

      <div class="bg-green-50 shadow-md ring-1 ring-green-200 rounded-xl p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-green-700 font-medium">On Time Students</p>
            <p id="onTimeCount" class="text-4xl font-bold text-green-600 mt-2">0</p>
          </div>
          <svg class="w-12 h-12 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
      </div>

      <div class="bg-red-50 shadow-md ring-1 ring-red-200 rounded-xl p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-red-700 font-medium">Late Students</p>
            <p id="lateCount" class="text-4xl font-bold text-red-600 mt-2">0</p>
          </div>
          <svg class="w-12 h-12 text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
      </div>

      <div class="bg-gray-50 shadow-md ring-1 ring-gray-200 rounded-xl p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-700 font-medium">Absent Students</p>
            <p id="noTimeInCount" class="text-4xl font-bold text-gray-600 mt-2">0</p>
          </div>
          <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" id="chartsContainer">
      <!-- BSIT Chart -->
      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 chart-card" data-course="bsit">
        <h2 class="text-lg font-semibold mb-4">BSIT</h2>
        <canvas id="bsitChart"></canvas>
      </div>

      <!-- BIT Chart -->
      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 chart-card" data-course="bit">
        <h2 class="text-lg font-semibold mb-4">BIT</h2>
        <canvas id="bitChart"></canvas>
      </div>

      <!-- BEED Chart -->
      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 chart-card" data-course="beed">
        <h2 class="text-lg font-semibold mb-4">BEED</h2>
        <canvas id="beedChart"></canvas>
      </div>

      <!-- BTLED Chart -->
      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 chart-card" data-course="btled">
        <h2 class="text-lg font-semibold mb-4">BTLED</h2>
        <canvas id="btledChart"></canvas>
      </div>

      <!-- BSED Chart -->
      <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-4 sm:p-6 chart-card" data-course="bsed">
        <h2 class="text-lg font-semibold mb-4">BSED</h2>
        <canvas id="bsedChart"></canvas>
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
            backgroundColor: ['#10b981', '#ef4444'],
            borderColor: ['#059669', '#dc2626'],
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              display: true,
              position: 'top'
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                stepSize: 1
              }
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
        document.getElementById('onTimeCount').textContent = '0';
        document.getElementById('lateCount').textContent = '0';
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
        // Show only the selected course chart
        chartCards.forEach(card => {
          if (card.dataset.course === selectedCourse.toLowerCase()) {
            card.style.display = 'block';
            // Make it full width when single course is selected
            card.style.gridColumn = 'span 1';
          } else {
            card.style.display = 'none';
          }
        });
        
        // Update statistics to show only selected course data
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
        // Show all course charts
        chartCards.forEach(card => {
          card.style.display = 'block';
          card.style.gridColumn = 'auto';
        });
        
        // Restore total statistics
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

    // Export button functionality
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

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
      courses.forEach(course => {
        createChart(course.toLowerCase(), course);
      });
    });

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
