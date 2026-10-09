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

$isLocal = function_exists('is_local_access') ? is_local_access() : true;
?>
<header class="sticky top-0 bg-[#0F3D87] text-white border-b border-blue-900/40 shadow-sm z-30">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2.5 flex items-center justify-between">
    <!-- Brand / Logo -->
    <a href="index.php" class="flex items-center gap-2.5 min-w-0 group">
      <img src="assets/logo.jpg" alt="Logo" class="h-9 w-9 rounded-full ring-2 ring-[#D4AF37]/80 ring-offset-2 ring-offset-[#0F3D87] object-cover flex-shrink-0 transition-transform group-hover:scale-105" onerror="this.style.display='none'">
      <div class="leading-tight min-w-0">
        <div class="text-[9px] sm:text-[10px] uppercase font-bold tracking-wider text-[#D4AF37] truncate">Graduating 2026 Council</div>
        <div class="text-xs sm:text-sm font-semibold truncate tracking-tight text-white/95">CTU-Naga Extension Campus</div>
      </div>
    </a>

    <!-- Mobile Hamburger Toggle -->
    <button type="button" class="nav-mobile-toggle md:hidden" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">
      <i data-lucide="menu" class="w-5 h-5"></i>
    </button>

    <!-- Desktop Navigation -->
    <nav class="nav-desktop hidden md:flex items-center gap-1 lg:gap-2 text-xs font-medium">
      <?php if ($isLocal): ?>
        <a href="dashboard.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'dashboard.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
          <i data-lucide="layout-dashboard" class="w-3.5 h-3.5 <?php echo $activePage === 'dashboard.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
          <span>Dashboard</span>
        </a>
      <?php endif; ?>

      <a href="index.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'index.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
        <i data-lucide="clock" class="w-3.5 h-3.5 <?php echo $activePage === 'index.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
        <span>Time In/Out</span>
      </a>

      <a href="students.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'students.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
        <i data-lucide="users" class="w-3.5 h-3.5 <?php echo $activePage === 'students.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
        <span>Students</span>
      </a>

      <a href="attendance.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'attendance.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
        <i data-lucide="file-check-2" class="w-3.5 h-3.5 <?php echo $activePage === 'attendance.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
        <span>Attendance</span>
      </a>

      <?php if ($isLocal): ?>
        <a href="schedules.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'schedules.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
          <i data-lucide="calendar" class="w-3.5 h-3.5 <?php echo $activePage === 'schedules.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
          <span>Schedules</span>
        </a>

        <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'analytics.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
          <i data-lucide="bar-chart-3" class="w-3.5 h-3.5 <?php echo $activePage === 'analytics.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
          <span>Analytics</span>
        </a>

        <a href="logs.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'logs.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
          <i data-lucide="history" class="w-3.5 h-3.5 <?php echo $activePage === 'logs.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
          <span>Logs</span>
        </a>

        <a href="qr.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors <?php echo $activePage === 'qr.php' ? 'bg-white/15 text-white font-semibold ring-1 ring-white/20' : 'text-blue-100/90 hover:text-white hover:bg-white/10'; ?>">
          <i data-lucide="qr-code" class="w-3.5 h-3.5 <?php echo $activePage === 'qr.php' ? 'text-[#D4AF37]' : ''; ?>"></i>
          <span>QR Access</span>
        </a>
      <?php endif; ?>
    </nav>
  </div>

  <!-- Mobile Dropdown Navigation -->
  <nav class="nav-mobile" id="navMobile" aria-hidden="true">
    <?php if ($isLocal): ?>
      <a href="dashboard.php" class="<?php echo $activePage === 'dashboard.php' ? 'active' : ''; ?>">
        <i data-lucide="layout-dashboard" class="w-4 h-4 text-[#D4AF37]"></i>
        <span>Dashboard</span>
      </a>
    <?php endif; ?>
    <a href="index.php" class="<?php echo $activePage === 'index.php' ? 'active' : ''; ?>">
      <i data-lucide="clock" class="w-4 h-4 text-[#D4AF37]"></i>
      <span>Time In/Out</span>
    </a>
    <a href="students.php" class="<?php echo $activePage === 'students.php' ? 'active' : ''; ?>">
      <i data-lucide="users" class="w-4 h-4 text-[#D4AF37]"></i>
      <span>Students</span>
    </a>
    <a href="attendance.php" class="<?php echo $activePage === 'attendance.php' ? 'active' : ''; ?>">
      <i data-lucide="file-check-2" class="w-4 h-4 text-[#D4AF37]"></i>
      <span>Attendance</span>
    </a>
    <?php if ($isLocal): ?>
      <a href="schedules.php" class="<?php echo $activePage === 'schedules.php' ? 'active' : ''; ?>">
        <i data-lucide="calendar" class="w-4 h-4 text-[#D4AF37]"></i>
        <span>Schedules</span>
      </a>
      <a href="analytics.php" class="<?php echo $activePage === 'analytics.php' ? 'active' : ''; ?>">
        <i data-lucide="bar-chart-3" class="w-4 h-4 text-[#D4AF37]"></i>
        <span>Analytics</span>
      </a>
      <a href="logs.php" class="<?php echo $activePage === 'logs.php' ? 'active' : ''; ?>">
        <i data-lucide="history" class="w-4 h-4 text-[#D4AF37]"></i>
        <span>Logs</span>
      </a>
      <a href="qr.php" class="<?php echo $activePage === 'qr.php' ? 'active' : ''; ?>">
        <i data-lucide="qr-code" class="w-4 h-4 text-[#D4AF37]"></i>
        <span>QR Access</span>
      </a>
    <?php endif; ?>
  </nav>
</header>
<div id="navBackdrop" class="nav-backdrop" aria-hidden="true"></div>
