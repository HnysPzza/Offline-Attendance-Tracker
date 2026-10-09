<?php
/**
 * Shared Header & Navigation Component
 *
 * Usage:
 * $activePage = 'dashboard.php'; // or 'index.php', 'students.php', etc.
 * include __DIR__ . '/includes/header.php';
 */

if (!isset($activePage)) {
    $activePage = basename($_SERVER['SCRIPT_NAME'] ?? '');
}

$isLocal = is_local_access();
?>
<header class="sticky top-0 bg-[#0F3D87] text-white shadow-sm z-20 relative">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
    <div class="flex items-center gap-2 sm:gap-3 min-w-0">
      <img src="assets/logo.jpg" alt="Logo" class="h-10 w-10 sm:h-12 sm:w-12 rounded-full ring-2 ring-[#D4AF37] ring-offset-2 ring-offset-[#0F3D87] shadow-md object-cover flex-shrink-0" onerror="this.style.display='none'">
      <div class="leading-tight min-w-0">
        <div class="text-[9px] sm:text-[10px] md:text-xs uppercase tracking-widest text-[#D4AF37] truncate">Graduating 2026 Council</div>
        <div class="text-xs sm:text-sm md:text-base font-semibold truncate">CTU-Naga Extension Campus</div>
      </div>
    </div>
    <nav class="hidden md:flex items-center gap-4 lg:gap-6 text-sm">
      <?php if ($isLocal): ?>
        <a href="dashboard.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'dashboard.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">Dashboard</a>
      <?php endif; ?>
      <a href="index.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'index.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">Time In/Out</a>
      <a href="students.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'students.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">Students</a>
      <a href="attendance.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'attendance.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">Attendance</a>
      <?php if ($isLocal): ?>
        <a href="schedules.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'schedules.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">Schedules</a>
        <a href="analytics.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'analytics.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">Analytics</a>
        <a href="logs.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'logs.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">Logs</a>
        <a href="qr.php" class="pb-1.5 border-b-2 <?php echo $activePage === 'qr.php' ? 'border-[#D4AF37] text-white font-semibold' : 'border-transparent text-blue-100 hover:text-white'; ?> transition-colors">QR Access</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
