<?php
/**
 * Shared Footer Component
 */
$isLocalFooter = function_exists('is_local_access') ? is_local_access() : true;
$curPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!-- Mobile Bottom Navigation Bar (< 768px) -->
<nav class="mobile-bottom-nav md:hidden" aria-label="Mobile Navigation">
  <a href="index.php" class="<?php echo $curPage === 'index.php' ? 'active' : ''; ?>">
    <i data-lucide="clock" class="w-5 h-5"></i>
    <span>Time In</span>
  </a>
  <a href="students.php" class="<?php echo $curPage === 'students.php' ? 'active' : ''; ?>">
    <i data-lucide="users" class="w-5 h-5"></i>
    <span>Students</span>
  </a>
  <a href="attendance.php" class="<?php echo $curPage === 'attendance.php' ? 'active' : ''; ?>">
    <i data-lucide="file-check-2" class="w-5 h-5"></i>
    <span>Records</span>
  </a>
  <?php if ($isLocalFooter): ?>
    <a href="dashboard.php" class="<?php echo $curPage === 'dashboard.php' ? 'active' : ''; ?>">
      <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
      <span>Dashboard</span>
    </a>
    <button type="button" id="mobileMoreBtn" aria-label="More navigation links">
      <i data-lucide="menu" class="w-5 h-5"></i>
      <span>More</span>
    </button>
  <?php endif; ?>
</nav>

<footer class="mt-auto py-6 text-center text-xs text-slate-500 border-t border-slate-200/60 bg-white/50 mb-14 md:mb-0">
  <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
    <p>&copy; <?php echo date('Y'); ?> Graduating 2026 Council &bull; CTU-Naga Extension Campus</p>
    <div class="flex items-center gap-1.5 text-[11px] text-slate-400">
      <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
      <span>System Active</span>
    </div>
  </div>
</footer>

<script src="assets/js/lucide.min.js"></script>
<script>
  // Safe area helper class on body
  document.body.classList.add('has-mobile-nav');

  // Global icon initializer
  window.refreshIcons = function() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  };
  document.addEventListener('DOMContentLoaded', window.refreshIcons);
  if (document.readyState === 'interactive' || document.readyState === 'complete') {
    window.refreshIcons();
  }

  // Mobile nav toggle handler with backdrop
  const navToggle = document.getElementById('navToggle');
  const navMobile = document.getElementById('navMobile');
  const navBackdrop = document.getElementById('navBackdrop');
  const mobileMoreBtn = document.getElementById('mobileMoreBtn');

  function toggleMobileNav(e) {
    if (e) e.stopPropagation();
    if (!navMobile) return;
    const willOpen = !navMobile.classList.contains('open');
    navMobile.classList.toggle('open', willOpen);
    if (navBackdrop) navBackdrop.classList.toggle('open', willOpen);
    if (navToggle) navToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  }

  function closeMobileNav() {
    if (navMobile) navMobile.classList.remove('open');
    if (navBackdrop) navBackdrop.classList.remove('open');
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
  }

  if (navToggle) navToggle.addEventListener('click', toggleMobileNav);
  if (mobileMoreBtn) mobileMoreBtn.addEventListener('click', toggleMobileNav);
  if (navBackdrop) navBackdrop.addEventListener('click', closeMobileNav);
</script>
</body>
</html>
