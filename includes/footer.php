<?php
/**
 * Shared Footer Component
 */
?>
<footer class="mt-auto py-6 text-center text-xs text-slate-500 border-t border-slate-200/60 bg-white/50">
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
  // Global icon initializer
  window.refreshIcons = function() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  };
  document.addEventListener('DOMContentLoaded', window.refreshIcons);
  // Also run immediately if DOM is already ready
  if (document.readyState === 'interactive' || document.readyState === 'complete') {
    window.refreshIcons();
  }

  // Mobile nav toggle handler
  const navToggle = document.getElementById('navToggle');
  const navMobile = document.getElementById('navMobile');
  if (navToggle && navMobile) {
    navToggle.addEventListener('click', function() {
      navMobile.classList.toggle('open');
      const isOpen = navMobile.classList.contains('open');
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }
</script>
</body>
</html>
