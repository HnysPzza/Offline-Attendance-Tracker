<?php
require_once __DIR__ . '/config.php';
if (!can_access_page(__FILE__)) {
    header('Location: index.php');
    exit;
}
$db = db();

$today = (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d');

$schedulesToday = (int)$db->query("SELECT COUNT(*) c FROM schedules WHERE schedule_date = CURDATE()")->fetch_assoc()['c'];
$presentToday = (int)$db->query("SELECT COUNT(*) c FROM attendance a JOIN schedules sc ON sc.id = a.schedule_id WHERE sc.schedule_date = CURDATE() AND a.time_in IS NOT NULL")->fetch_assoc()['c'];
$lateToday = (int)$db->query("SELECT COUNT(*) c FROM attendance a JOIN schedules sc ON sc.id = a.schedule_id WHERE sc.schedule_date = CURDATE() AND a.status = 'Late'")->fetch_assoc()['c'];
$timeoutToday = (int)$db->query("SELECT COUNT(*) c FROM attendance a JOIN schedules sc ON sc.id = a.schedule_id WHERE sc.schedule_date = CURDATE() AND a.time_out IS NOT NULL")->fetch_assoc()['c'];

$start = (new DateTime('now', new DateTimeZone(app_timezone())))->modify('-6 days')->format('Y-m-d');
$trend = [];
$stmt = $db->prepare("SELECT sc.schedule_date d,
  SUM(CASE WHEN a.time_in IS NOT NULL THEN 1 ELSE 0 END) present,
  SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) late
  FROM schedules sc
  LEFT JOIN attendance a ON a.schedule_id = sc.id
  WHERE sc.schedule_date BETWEEN ? AND ?
  GROUP BY sc.schedule_date
  ORDER BY sc.schedule_date");
$stmt->bind_param('ss', $start, $today);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $trend[$row['d']] = ['present'=>(int)$row['present'], 'late'=>(int)$row['late']]; }
$stmt->close();

$labels = [];$present = [];$late = [];
$cur = new DateTime($start);
$end = new DateTime($today);
while ($cur <= $end) {
  $d = $cur->format('Y-m-d');
  $labels[] = $d;
  $present[] = isset($trend[$d]) ? (int)$trend[$d]['present'] : 0;
  $late[] = isset($trend[$d]) ? (int)$trend[$d]['late'] : 0;
  $cur->modify('+1 day');
}

$topLate = [];
$stmt = $db->prepare("SELECT s.student_id, s.name, SUM(COALESCE(a.minutes_late,0)) minutes
  FROM attendance a
  JOIN students s ON s.id = a.student_id
  JOIN schedules sc ON sc.id = a.schedule_id
  WHERE sc.schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY a.student_id
  ORDER BY minutes DESC
  LIMIT 10");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $topLate[] = $row; }
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard</title>
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
      </nav>
    </div>
    <nav class="nav-mobile" id="navMobile" aria-hidden="true">
      <?php if (is_local_access()): ?><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='dashboard.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Dashboard</a><a href="schedules.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='schedules.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Schedules</a><a href="logs.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='logs.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Logs</a><a href="qr.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='qr.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">QR Access</a><?php endif; ?>
      <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='index.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Time In/Out</a>
      <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='students.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Students</a>
      <a href="attendance.php" class="<?php echo basename($_SERVER['PHP_SELF'])==='attendance.php' ? 'bg-[#D4AF37]/20 text-white font-medium' : 'text-blue-100 hover:bg-white/10'; ?>">Attendance</a>
    </nav>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-6">
    <section class="rounded-2xl bg-gradient-to-r from-[#0F3D87] to-[#1F5BB5] text-white p-5 sm:p-6 shadow-lg">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight">Attendance Dashboard</h1>
          <p class="text-blue-100 text-sm mt-1">Monitor daily attendance performance and late trends at a glance.</p>
        </div>
        <div class="text-sm text-blue-100">
          <div>Today: <span class="font-semibold text-white"><?php echo h($today); ?></span></div>
          <div class="mt-1">Last 30-day late ranking is updated automatically.</div>
        </div>
      </div>
    </section>

    <section class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Schedules Today</div>
        <div class="mt-2 text-3xl font-semibold text-gray-900"><?php echo (int)$schedulesToday; ?></div>
      </div>
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Time Ins Today</div>
        <div class="mt-2 text-3xl font-semibold text-gray-900"><?php echo (int)$presentToday; ?></div>
      </div>
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Late Today</div>
        <div class="mt-2 text-3xl font-semibold text-amber-600"><?php echo (int)$lateToday; ?></div>
      </div>
      <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Time Outs Today</div>
        <div class="mt-2 text-3xl font-semibold text-gray-900"><?php echo (int)$timeoutToday; ?></div>
      </div>
    </section>

    <section class="grid lg:grid-cols-3 gap-4 sm:gap-6">
      <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4 sm:p-6 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">Attendance Trend</h2>
            <p class="text-xs text-gray-500 mt-1">Daily time-ins and late counts for the last 7 days</p>
          </div>
        </div>
        <div class="h-72">
          <canvas id="trendChart"></canvas>
        </div>
      </div>
      <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-1">Top Late Students</h2>
        <p class="text-xs text-gray-500 mb-4">Ranked by total minutes late in the last 30 days</p>
        <div class="overflow-x-auto -mx-2 sm:mx-0">
          <table class="table-responsive-cards min-w-full text-sm divide-y divide-gray-200">
            <thead>
              <tr class="text-left bg-gray-50 text-gray-600 text-xs uppercase tracking-wide">
                <th class="py-2 pr-4 font-medium">Rank / Student</th>
                <th class="py-2 pr-4 font-medium text-right">Minutes Late</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($topLate as $idx => $row): ?>
              <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2 pr-4" data-label="Student">
                  <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-xs font-semibold text-gray-700 mr-2"><?php echo (int)$idx + 1; ?></span>
                  <?php echo h($row['name']); ?>
                  <div class="text-xs text-gray-500 ml-8"><?php echo h($row['student_id']); ?></div>
                </td>
                <td class="py-2 pr-4 text-right font-medium text-gray-800" data-label="Minutes Late"><?php echo (int)$row['minutes']; ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($topLate) === 0): ?>
              <tr><td class="py-4 text-gray-500 text-sm" colspan="2">No late records in the last 30 days.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const ctx = document.getElementById('trendChart');
      const labels = <?php echo json_encode($labels); ?>;
      const present = <?php echo json_encode($present); ?>;
      const late = <?php echo json_encode($late); ?>;
      new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [
            {
              label: 'Present (time in)',
              data: present,
              borderColor: '#0F3D87',
              backgroundColor: 'rgba(15,61,135,0.1)',
              tension: 0.25,
            },
            {
              label: 'Late',
              data: late,
              borderColor: '#D4AF37',
              backgroundColor: 'rgba(212,175,55,0.15)',
              tension: 0.25,
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: true, ticks: { precision:0 } }
          },
          plugins: {
            legend: { display: true }
          }
        }
      });
    });
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
