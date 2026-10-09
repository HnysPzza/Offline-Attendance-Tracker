<?php
require_once __DIR__ . '/config.php';

// Only allow local access to this page
if (!is_local_access()) {
    header('Location: index.php');
    exit;
}

// Detect the server's local IP address
function get_local_ip() {
    // Try SERVER_ADDR first (set by Apache/XAMPP)
    $ip = $_SERVER['SERVER_ADDR'] ?? '';

    // If it's localhost/loopback, try resolving the hostname
    if (!$ip || $ip === '127.0.0.1' || $ip === '::1') {
        $ip = gethostbyname(gethostname());
    }

    // Last resort fallback
    if (!$ip || $ip === gethostname()) {
        $ip = '127.0.0.1';
    }

    return $ip;
}

$localIp = get_local_ip();
$port = $_SERVER['SERVER_PORT'] ?? 80;
$portSuffix = ($port == 80 || $port == 443) ? '' : ':' . $port;
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$url = 'http://' . $localIp . $portSuffix . $basePath . '/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QR Access - Attendance Tracker</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body class="bg-gray-50 min-h-screen antialiased">
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
        <a href="dashboard.php" class="pb-1.5 border-b-2 border-transparent text-blue-100 hover:text-white transition-colors">Dashboard</a>
        <a href="index.php" class="pb-1.5 border-b-2 border-transparent text-blue-100 hover:text-white transition-colors">Time In/Out</a>
        <a href="schedules.php" class="pb-1.5 border-b-2 border-transparent text-blue-100 hover:text-white transition-colors">Schedules</a>
        <a href="qr.php" class="pb-1.5 border-b-2 border-[#D4AF37] text-white transition-colors">QR Access</a>
      </nav>
    </div>
  </header>

  <main class="max-w-lg mx-auto px-4 py-10 flex flex-col items-center gap-6">
    <div class="bg-white shadow-md ring-1 ring-gray-100 rounded-xl p-6 sm:p-8 w-full flex flex-col items-center gap-4">
      <h1 class="text-xl font-semibold text-gray-900">Network QR Code</h1>
      <p class="text-sm text-gray-500 text-center">
        Scan this QR code with another device on the same network to open the attendance system — no typing required.
      </p>

      <div id="qrcode" class="p-3 bg-white rounded-lg ring-1 ring-gray-200 shadow-sm"></div>

      <div class="text-xs text-gray-500 text-center break-all">
        <span class="font-medium text-gray-700">URL:</span>
        <a href="<?php echo h($url); ?>" target="_blank" class="text-[#0F3D87] underline ml-1"><?php echo h($url); ?></a>
      </div>

      <div class="w-full rounded-md bg-amber-50 border border-amber-200 text-amber-800 text-xs p-3 text-center">
        Make sure the other device is connected to the <span class="font-semibold">same Wi-Fi or local network</span> as this computer.
      </div>

      <button onclick="window.print()" class="mt-2 px-5 py-2 rounded-md bg-[#0F3D87] text-white text-sm hover:opacity-90">
        Print / Save QR
      </button>
    </div>
  </main>

  <script>
    new QRCode(document.getElementById('qrcode'), {
      text: <?php echo json_encode($url); ?>,
      width: 220,
      height: 220,
      colorDark: '#0F3D87',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.H
    });
  </script>

  <style>
    @media print {
      header, button { display: none !important; }
      body { background: white; }
      main { margin: 0; padding: 20px; }
    }
  </style>
</body>
</html>
