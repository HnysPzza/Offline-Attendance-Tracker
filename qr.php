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
  <link rel="icon" type="image/jpg" href="assets/logo.jpg"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/responsive.css">
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
  <style>
    @media print {
      header, footer, button, .no-print { display: none !important; }
      body { background: white !important; }
      main { margin: 0 !important; padding: 20px !important; max-width: 100% !important; }
      .print-box { border: 2px solid #0F3D87 !important; }
    }
  </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col antialiased text-slate-900">
  <?php 
  $activePage = 'qr.php';
  include __DIR__ . '/includes/header.php'; 
  ?>

  <main class="max-w-2xl w-full mx-auto px-4 sm:px-6 py-6 space-y-6 flex-1">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-200">
      <div>
        <h1 class="text-lg sm:text-xl font-bold tracking-tight text-slate-900 flex items-center gap-2">
          <i data-lucide="qr-code" class="w-5 h-5 text-[#0F3D87]"></i>
          <span>Network QR Access</span>
        </h1>
        <p class="text-xs text-slate-500 mt-0.5">Scan to access the CTU-Naga Attendance portal from local mobile devices.</p>
      </div>

      <div class="flex items-center gap-2 no-print">
        <button onclick="copyQrUrl()" id="copyBtn" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold shadow-sm transition-colors">
          <i data-lucide="copy" class="w-3.5 h-3.5" id="copyIcon"></i>
          <span id="copyText">Copy Link</span>
        </button>
        <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-[#0F3D87] hover:bg-[#0c316d] text-white text-xs font-semibold shadow-sm transition-colors">
          <i data-lucide="printer" class="w-3.5 h-3.5"></i>
          <span>Print QR</span>
        </button>
      </div>
    </div>

    <!-- Main QR Card (Flat Institutional Bordered Panel) -->
    <div class="print-box bg-white border border-slate-200 rounded-lg p-6 sm:p-8 flex flex-col items-center gap-6">
      <div class="text-center space-y-1">
        <div class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">CTU-Naga Extension Campus</div>
        <h2 class="text-base font-bold text-slate-900">Instant Student Self Time In / Out</h2>
        <p class="text-xs text-slate-500 max-w-md">
          Point phone camera at this QR code to quickly log attendance on the campus network.
        </p>
      </div>

      <!-- QR Display -->
      <div id="qrcode" class="p-4 bg-white rounded-lg border border-slate-200 shadow-sm flex items-center justify-center"></div>

      <!-- Live Network Target URL -->
      <div class="w-full max-w-md bg-slate-50 border border-slate-200 rounded-md p-3 flex items-center justify-between gap-3 text-xs">
        <div class="min-w-0 flex-1">
          <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Access Endpoint</div>
          <a href="<?php echo h($url); ?>" target="_blank" class="text-blue-700 font-mono font-medium hover:underline truncate block">
            <?php echo h($url); ?>
          </a>
        </div>
        <a href="<?php echo h($url); ?>" target="_blank" class="text-slate-400 hover:text-slate-600 no-print flex-shrink-0" title="Open in new tab">
          <i data-lucide="external-link" class="w-4 h-4"></i>
        </a>
      </div>

      <!-- Instructions & Local Network Requirement Notice -->
      <div class="w-full max-w-md border-l-4 border-amber-500 bg-amber-50/70 p-3.5 rounded-r-md text-amber-900 text-xs space-y-1">
        <div class="font-semibold flex items-center gap-1.5">
          <i data-lucide="wifi" class="w-3.5 h-3.5 text-amber-600"></i>
          <span>Same Network Required</span>
        </div>
        <p class="text-amber-800/90 leading-relaxed text-[11px]">
          Mobile devices must be connected to the same Wi-Fi or LAN subnet (<span class="font-mono font-medium"><?php echo h($localIp); ?></span>) to communicate with this server.
        </p>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    new QRCode(document.getElementById('qrcode'), {
      text: <?php echo json_encode($url); ?>,
      width: 220,
      height: 220,
      colorDark: '#0F3D87',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.H
    });

    function copyQrUrl() {
      const url = <?php echo json_encode($url); ?>;
      navigator.clipboard.writeText(url).then(() => {
        const btn = document.getElementById('copyBtn');
        const text = document.getElementById('copyText');
        const icon = document.getElementById('copyIcon');
        text.innerText = 'Copied!';
        btn.classList.add('border-emerald-500', 'text-emerald-700', 'bg-emerald-50');
        setTimeout(() => {
          text.innerText = 'Copy Link';
          btn.classList.remove('border-emerald-500', 'text-emerald-700', 'bg-emerald-50');
        }, 2000);
      }).catch(err => {
        alert('Could not copy link: ' + url);
      });
    }
  </script>
</body>
</html>
