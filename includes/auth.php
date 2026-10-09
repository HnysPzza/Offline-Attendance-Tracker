<?php
/**
 * Authentication & Access Control Gate
 */

/** Password required when accessing via remote IP address */
define('IP_ACCESS_PASSWORD', 'Gradcouncil2026');

// IP password gate: requires password before allowing access via IP
if (!is_local_access()) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['ip_access_verified'])) {
        $ip_error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ip_password'])) {
            if ($_POST['ip_password'] === IP_ACCESS_PASSWORD) {
                log_activity('ip_login_success', 'IP access granted from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                $_SESSION['ip_access_verified'] = true;
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            log_activity('ip_login_fail', 'Failed login attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $ip_error = 'Incorrect password. Please try again.';
        }
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $base = $base ?: '';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Required - Attendance Tracker</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-sm bg-white rounded-xl shadow-lg ring-1 ring-gray-200 p-6">
    <div class="text-center mb-6">
      <img src="<?php echo h($base); ?>/assets/logo.jpg" alt="Logo" class="h-16 w-16 rounded-full ring-2 ring-[#D4AF37] mx-auto mb-3 object-cover" onerror="this.style.display='none'">
      <div class="text-[10px] uppercase tracking-widest text-[#D4AF37]">Graduating 2026 Council</div>
      <div class="text-sm font-semibold text-gray-800">CTU-Naga Extension Campus</div>
    </div>
    <h1 class="text-lg font-semibold text-gray-900 mb-2">Access Required</h1>
    <p class="text-sm text-gray-600 mb-4">Enter the password to access the attendance system.</p>
    <?php if ($ip_error): ?>
      <div class="mb-4 p-3 rounded-md bg-red-50 text-red-700 text-sm"><?php echo h($ip_error); ?></div>
    <?php endif; ?>
    <form method="post" class="space-y-4">
      <div>
        <label for="ip_password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <input type="password" id="ip_password" name="ip_password" required autofocus
          class="w-full rounded-md border border-gray-300 px-3 py-2 focus:ring-[#0F3D87] focus:border-[#0F3D87]">
      </div>
      <button type="submit" class="w-full py-2 rounded-md bg-[#0F3D87] text-white font-medium hover:opacity-95">
        Enter
      </button>
    </form>
  </div>
</body>
</html>
        <?php
        exit;
    }
}
