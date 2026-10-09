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
  <title>Dashboard - Attendance Tracker</title>
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'dashboard.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6 flex-1">
    <!-- Page Header (Flat, Clean) -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-slate-200">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Attendance Dashboard</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Real-time attendance metrics, trends, and late monitoring.</p>
      </div>
      <div class="flex items-center gap-2 self-start sm:self-auto">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-slate-100 border border-slate-200 text-slate-700">
          <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-500"></i>
          <span>Today: <strong class="text-slate-900"><?php echo h($today); ?></strong></span>
        </span>
      </div>
    </div>

    <!-- Integrated Metric Toolbar (Replaces 4 Heavy Cards) -->
    <section class="border border-slate-200 rounded-lg bg-white overflow-hidden shadow-xs">
      <div class="grid grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        <!-- Metric 1: Schedules -->
        <div class="p-4 sm:p-5 flex items-start justify-between">
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Schedules Today</div>
            <div class="mt-1 text-2xl sm:text-3xl font-bold tracking-tight text-slate-900"><?php echo (int)$schedulesToday; ?></div>
            <div class="mt-1 text-[11px] text-slate-400">Scheduled campus sessions</div>
          </div>
          <div class="p-2 rounded-md bg-blue-50 text-[#0F3D87]">
            <i data-lucide="calendar-days" class="w-5 h-5"></i>
          </div>
        </div>

        <!-- Metric 2: Time Ins -->
        <div class="p-4 sm:p-5 flex items-start justify-between">
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Time Ins Today</div>
            <div class="mt-1 text-2xl sm:text-3xl font-bold tracking-tight text-emerald-600"><?php echo (int)$presentToday; ?></div>
            <div class="mt-1 text-[11px] text-slate-400">Recorded student arrivals</div>
          </div>
          <div class="p-2 rounded-md bg-emerald-50 text-emerald-600">
            <i data-lucide="user-check" class="w-5 h-5"></i>
          </div>
        </div>

        <!-- Metric 3: Late Today -->
        <div class="p-4 sm:p-5 flex items-start justify-between">
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Late Today</div>
            <div class="mt-1 text-2xl sm:text-3xl font-bold tracking-tight text-amber-600"><?php echo (int)$lateToday; ?></div>
            <div class="mt-1 text-[11px] text-slate-400">Past schedule window</div>
          </div>
          <div class="p-2 rounded-md bg-amber-50 text-amber-600">
            <i data-lucide="clock-alert" class="w-5 h-5"></i>
          </div>
        </div>

        <!-- Metric 4: Time Outs -->
        <div class="p-4 sm:p-5 flex items-start justify-between">
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Time Outs Today</div>
            <div class="mt-1 text-2xl sm:text-3xl font-bold tracking-tight text-slate-700"><?php echo (int)$timeoutToday; ?></div>
            <div class="mt-1 text-[11px] text-slate-400">Completed departures</div>
          </div>
          <div class="p-2 rounded-md bg-slate-100 text-slate-600">
            <i data-lucide="user-minus" class="w-5 h-5"></i>
          </div>
        </div>
      </div>
    </section>

    <!-- Main Content: Charts & Rankings -->
    <section class="grid lg:grid-cols-3 gap-6">
      <!-- 7-Day Trend Chart -->
      <div class="border border-slate-200 rounded-lg bg-white p-5 lg:col-span-2 shadow-xs">
        <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
          <div>
            <h2 class="text-sm font-semibold text-slate-900">Attendance Trend</h2>
            <p class="text-xs text-slate-400">Daily time-ins and late arrivals for the past 7 days</p>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span class="inline-flex items-center gap-1.5 text-slate-600 font-medium">
              <span class="w-2.5 h-2.5 rounded-full bg-[#0F3D87]"></span> Present
            </span>
            <span class="inline-flex items-center gap-1.5 text-slate-600 font-medium">
              <span class="w-2.5 h-2.5 rounded-full bg-[#D4AF37]"></span> Late
            </span>
          </div>
        </div>
        <div class="h-64 sm:h-72 w-full">
          <canvas id="trendChart"></canvas>
        </div>
      </div>

      <!-- Top Late Students List -->
      <div class="border border-slate-200 rounded-lg bg-white p-5 shadow-xs flex flex-col">
        <div class="pb-3 mb-3 border-b border-slate-100">
          <h2 class="text-sm font-semibold text-slate-900">Top Late Students</h2>
          <p class="text-xs text-slate-400">Ranked by total late minutes (last 30 days)</p>
        </div>

        <div class="overflow-y-auto max-h-72 -mx-2 px-2 flex-1">
          <table class="table-modern w-full">
            <thead>
              <tr>
                <th class="py-2 text-left">Student</th>
                <th class="py-2 text-right">Minutes</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($topLate as $idx => $row): ?>
              <tr>
                <td class="py-2">
                  <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 shrink-0">
                      <?php echo (int)$idx + 1; ?>
                    </span>
                    <div class="min-w-0">
                      <div class="text-xs font-medium text-slate-900 truncate"><?php echo h($row['name']); ?></div>
                      <div class="text-[11px] text-slate-400 font-mono"><?php echo h($row['student_id']); ?></div>
                    </div>
                  </div>
                </td>
                <td class="py-2 text-right">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-50 text-amber-700">
                    <?php echo (int)$row['minutes']; ?>m
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($topLate) === 0): ?>
              <tr><td class="py-6 text-center text-slate-400 text-xs" colspan="2">No late records in the last 30 days.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const ctx = document.getElementById('trendChart');
      if (!ctx) return;
      const labels = <?php echo json_encode($labels); ?>;
      const present = <?php echo json_encode($present); ?>;
      const late = <?php echo json_encode($late); ?>;
      new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [
            {
              label: 'Present',
              data: present,
              borderColor: '#0F3D87',
              backgroundColor: 'rgba(15,61,135,0.06)',
              borderWidth: 2,
              fill: true,
              tension: 0.3,
              pointRadius: 3,
              pointBackgroundColor: '#0F3D87'
            },
            {
              label: 'Late',
              data: late,
              borderColor: '#D4AF37',
              backgroundColor: 'rgba(212,175,55,0.08)',
              borderWidth: 2,
              fill: true,
              tension: 0.3,
              pointRadius: 3,
              pointBackgroundColor: '#D4AF37'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { 
              beginAtZero: true, 
              ticks: { precision:0, font: { size: 11 } },
              grid: { color: '#f1f5f9' }
            },
            x: {
              ticks: { font: { size: 10 } },
              grid: { display: false }
            }
          },
          plugins: {
            legend: { display: false }
          }
        }
      });
    });
  </script>
</body>
</html>

