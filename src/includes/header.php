<?php require_once __DIR__ . "/init.php"; 
// Emit security headers early (idempotent if server also sets)
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
// Prevent caching of dynamic authenticated pages (assets served separately with their own headers)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
// Basic CSP (can be tightened later); allow self + inline styles for existing legacy CSS tweaks
if(!headers_sent()){
  $csp = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; script-src 'self'; font-src 'self' https://fonts.gstatic.com; connect-src 'self'; frame-ancestors 'none'; form-action 'self'";
  header('Content-Security-Policy: '.$csp);
  // Add HSTS only when running over HTTPS to avoid dev confusion
  if(defined('APP_SCHEME') && APP_SCHEME === 'https'){
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <?php
    $rawHost = $_SERVER['HTTP_HOST'] ?? '';
    // strip :port if present
    $rawHost = preg_replace('/:\d+$/', '', (string)$rawHost);
    // drop leading www.
    $hostDisplay = preg_replace('/^www\./i', '', $rawHost);
  ?>
  <?php
  // Branding: pakai logo.png untuk semua (brand + favicon) agar konsisten
  $brandLogoRel = 'assets/img/logo.png';
  $faviconRel = $brandLogoRel; // favicon langsung logo
  $faviconAbs = BASE_PATH.'/public/'.$faviconRel;
  $icoCandidate = 'favicon.ico'; // optional manual placement by user
  $favVersion = file_exists($faviconAbs) ? filemtime($faviconAbs) : time();
    // Generate inline data URI favicon (force override browser cache of localhost default)
    $inlineFavicon = '';
    $logoPath = $faviconAbs;
    if(file_exists($logoPath)){
      if(function_exists('imagecreatefrompng')){
        $src = @imagecreatefrompng($logoPath);
        if($src){
          $tw = 64; $th = 64; $w = imagesx($src); $h = imagesy($src);
          $dst = imagecreatetruecolor($tw,$th);
          imagesavealpha($dst,true); $trans = imagecolorallocatealpha($dst,0,0,0,127); imagefill($dst,0,0,$trans);
            imagecopyresampled($dst,$src,0,0,0,0,$tw,$th,$w,$h);
            ob_start(); imagepng($dst); $pngData = ob_get_clean();
            imagedestroy($dst); imagedestroy($src);
            if($pngData){ $inlineFavicon = 'data:image/png;base64,'.base64_encode($pngData); }
        }
      }
      if(!$inlineFavicon){ // fallback raw file
        $raw = @file_get_contents($logoPath);
        if($raw){ $inlineFavicon = 'data:image/png;base64,'.base64_encode($raw); }
      }
    }
    $baseTitle = 'Saku Santri';
    if(!empty($PAGE_TITLE) && strcasecmp(trim($PAGE_TITLE),$baseTitle)!==0){
      $fullTitle = trim($PAGE_TITLE).' – '.$baseTitle;
    } else { $fullTitle = $baseTitle; }
  ?>
  <title><?= e($fullTitle) ?></title>
  <?php $fv = '?v='.$favVersion; ?>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= url($faviconRel).$fv ?>" />
  <link rel="icon" type="image/png" sizes="16x16" href="<?= url($faviconRel).$fv ?>" />
  <link rel="shortcut icon" href="<?= url($faviconRel).$fv ?>" />
  <link rel="apple-touch-icon" href="<?= url($faviconRel).$fv ?>" />
  <?php if(file_exists(BASE_PATH.'/public/'.$icoCandidate)): ?>
    <link rel="icon" type="image/x-icon" href="<?= url($icoCandidate).$fv ?>" />
  <?php endif; ?>
  <?php if($inlineFavicon): // Inline variant to aggressively override cached default ?>
    <link rel="icon" type="image/png" href="<?= e($inlineFavicon) ?>" />
  <?php endif; ?>
  <meta property="og:image" content="<?= url($brandLogoRel) ?>" />
  <link rel="stylesheet" href="<?php echo url("assets/css/style.css"); ?>?v=20250826a">
  <?php if (empty($_SESSION['role'])): ?>
  <link rel="stylesheet" href="<?php echo url('assets/css/auth.css'); ?>?v=20250825g">
  <link rel="stylesheet" href="<?php echo url('assets/css/mobile-login.css'); ?>?v=20250827a" media="(max-width: 860px)">
  <?php else: ?>
  <link rel="stylesheet" href="<?php echo url('assets/css/mobile-app.css'); ?>?v=20250906b" media="(max-width: 860px)">
  <?php endif; ?>
  <!-- UI overrides loaded last to safely refine base styles -->
  <link rel="stylesheet" href="<?php echo url('assets/css/override.css'); ?>?v=20250906i">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,500,0,0" />
  <meta name="application-name" content="Saku Santri" />
  <meta name="apple-mobile-web-app-title" content="Saku Santri" />
  <meta name="theme-color" content="#809671" />
  <meta name="msapplication-TileColor" content="#809671" />
  <meta name="application-url" content="<?php echo e($hostDisplay); ?>" />
  <meta http-equiv="X-Content-Type-Options" content="nosniff" />
  <meta http-equiv="X-Frame-Options" content="DENY" />
  <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin" />
  <?php
    $reqUri = $_SERVER['REQUEST_URI'] ?? '/';
    $qpos = strpos($reqUri, '?');
    if ($qpos !== false) { $reqUri = substr($reqUri, 0, $qpos); }
    $reqUri = preg_replace('#/+#', '/', $reqUri); // collapse multiple slashes
    $canonical = rtrim(APP_ORIGIN, '/') . canonical_path($reqUri);
  ?>
  <link rel="canonical" href="<?php echo e($canonical); ?>" />
  
</head>
<?php $role = $_SESSION["role"] ?? null; ?>
<?php $authMode = empty($role); ?>
<body class="<?php echo ($role === 'admin' || $role === 'wali_santri') ? 'has-sidebar' : 'auth-mode'; ?>">
<a href="#mainContent" class="skip-link">Lewati ke Konten</a>
<?php if(!$authMode): ?>
<header class="site-header">
  <?php
  // Home route normalization: previously pointed to non-existent admin/home & wali/home
  $home = $role === "admin" ? url("admin/") : ($role === "wali_santri" ? url("wali/") : url("index.php"));
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  $isActive = function(string $needle) use ($currentPath): string {
    if($needle === '') return '';
    if($needle[0] !== '/') $needle = '/'.$needle;
    if($currentPath === $needle) return ' active';
    // Allow prefix match when needle ends with '/' (section) or current path continues with '/'
    if(strpos($currentPath, $needle) === 0) {
      $endsWithSlash = substr($needle,-1) === '/';
      if($endsWithSlash) return ' active';
      if(strlen($currentPath) > strlen($needle) && $currentPath[strlen($needle)] === '/') return ' active';
    }
    return '';
  };
  ?>
  <div class="header-inner">
    <a class="brand" href="<?php echo $home; ?>">Saku Santri</a>
    <?php if (!empty($hostDisplay)): ?>
      <span class="brand-host"><?php echo e($hostDisplay); ?></span>
    <?php endif; ?>
    <?php /* Sidebar selalu tampil untuk admin & wali */ ?>
    <div class="header-right">
      <?php if (isset($_SESSION["nama_wali"])): ?>
        <span class="hello">Halo, <?php echo e($_SESSION["nama_wali"]); ?>!</span>
        <?php if ($role !== "admin"): ?>
          <a class="link-logout" href="<?php echo url("logout"); ?>">Keluar</a>
        <?php endif; ?>
      <?php endif; ?>
        <?php
          // Notification badge
          $unreadNotifCount = 0;
          if(isset($conn) && function_exists('fetch_notifications')){
              $resNotif = mysqli_query($conn, "SELECT COUNT(*) c FROM notifications WHERE read_at IS NULL");
              if($resNotif){ $rowN = mysqli_fetch_assoc($resNotif); $unreadNotifCount = (int)$rowN['c']; }
          }
        ?>
    </div>
  </div>
 </header>
<?php endif; ?>
<?php if ($role === "admin"): ?>
<nav id="mainMenu" class="main-menu">
  <?php
    $adminLabel = 'Admin';
    if(isset($_SESSION['username']) && $_SESSION['username']){ $adminLabel = $_SESSION['username']; }
    $adminInitial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$adminLabel),0,1));
  ?>
  <div class="nav-brand-block">
    <div class="brand-header">
  <div class="brand-logo"><img src="<?php echo url($brandLogoRel); ?>" alt="Logo" /></div>
      <div class="brand-text">SakuSantri</div>
    </div>
    <div class="admin-avatar" aria-label="Avatar Admin">
  <img src="<?php echo url('assets/img/hero.png'); ?>" alt="Avatar" class="js-hide-on-error" />
      <span class="avatar-letter" aria-hidden="true"><?php echo e($adminInitial); ?></span>
    </div>
    <div class="greet-wrap"><span class="hi">Hi, Selamat Datang</span><span class="role">ADMIN</span></div>
  </div>
  <a class="btn-menu<?php echo $isActive('/admin/'); ?>" href="<?php echo url("admin/"); ?>"><span class="mi material-symbols-outlined">home</span> Beranda</a>
  <a class="btn-menu<?php echo $isActive('/admin/invoice.php'); ?>" href="<?php echo url("admin/invoice.php"); ?>"><span class="mi material-symbols-outlined">request_quote</span> Tagihan</a>
  <a class="btn-menu<?php echo $isActive('/admin/generate_spp'); ?>" href="<?php echo url("admin/generate_spp.php"); ?>"><span class="mi material-symbols-outlined">playlist_add</span> Buat SPP</a>
  <a class="btn-menu<?php echo $isActive('/admin/wallet_topups'); ?>" href="<?php echo url("admin/wallet_topups.php"); ?>"><span class="mi material-symbols-outlined">account_balance_wallet</span> Isi Saldo Dompet</a>
  <a class="btn-menu<?php echo $isActive('/admin/pengguna.php'); ?>" href="<?php echo url("admin/pengguna.php"); ?>"><span class="mi material-symbols-outlined">group</span> Pengguna</a>
  <a class="btn-menu<?php echo $isActive('/admin/kelola_user.php'); ?>" href="<?php echo url("admin/kelola_user.php"); ?>"><span class="mi material-symbols-outlined">group_add</span> Kelola Pengguna</a>
  <a class="btn-menu<?php echo $isActive('/kasir/transaksi'); ?>" href="<?php echo url('kasir/transaksi'); ?>"><span class="mi material-symbols-outlined">point_of_sale</span> Kasir Koperasi</a>
  <a class="btn-menu<?php echo $isActive('/admin/notifikasi.php'); ?>" href="<?php echo url('admin/notifikasi.php'); ?>"><span class="mi material-symbols-outlined">notifications</span> Notifikasi<?php if($unreadNotifCount>0){ echo ' <span class="notif-badge">'.$unreadNotifCount.'</span>'; } ?></a>
  <a class="btn-menu<?php echo $isActive('/admin/integrity_report'); ?>" href="<?php echo url('admin/integrity_report.php'); ?>"><span class="mi material-symbols-outlined">inventory</span> Integritas</a>
  <a class="btn-menu btn-danger" href="<?php echo url("logout"); ?>"><span class="mi material-symbols-outlined" style="font-size:18px">logout</span> KELUAR</a>
</nav>
<?php elseif ($role === "wali_santri"): ?>
<?php
  $waliName = $_SESSION['nama_wali'] ?? 'Wali';
  $santriName = $_SESSION['nama_santri'] ?? '';
  $userIdNav = (int)($_SESSION['user_id'] ?? 0);
  // Initial avatar: ambil huruf pertama dari nama santri jika ada, jika tidak pakai nama wali
  $initialSrc = $santriName !== '' ? $santriName : $waliName;
  $waliInitial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$initialSrc),0,1));
  // Unread notifications per user
  $unreadNotifCountWali = 0;
  if(isset($conn)){
      $resNW = mysqli_query($conn, "SELECT COUNT(*) c FROM notifications WHERE user_id=".$userIdNav." AND read_at IS NULL");
      if($resNW){ $unreadNotifCountWali = (int) (mysqli_fetch_assoc($resNW)['c'] ?? 0); }
  }
?>
<nav id="mainMenu" class="main-menu wali-menu">
  <div class="wali-brand-block">
    <div class="wali-logo-row">
  <div class="wali-logo-icon"><img src="<?php echo url($brandLogoRel); ?>" alt="Logo" /></div>
      <div class="wali-logo-text">SakuSantri</div>
    </div>
    <div class="wali-avatar" aria-label="Avatar Wali">
      <span class="avatar-letter" aria-hidden="true"><?php echo e($waliInitial); ?></span>
    </div>
    <div class="wali-greet">
      <span class="hi">Hi, Wali ananda</span>
      <span class="nama"><?php echo e($waliName); ?></span>
      <?php if($santriName): ?><span class="santri">(<?php echo e($santriName); ?>)</span><?php endif; ?>
    </div>
  </div>
  <a class="btn-menu<?php echo $isActive('/wali/'); ?>" href="<?php echo url("wali/"); ?>"><span class="mi material-symbols-outlined">home</span> Beranda</a>
  <a class="btn-menu<?php echo $isActive('/wali/kirim_saku.php'); ?>" href="<?php echo url("wali/kirim_saku.php"); ?>"><span class="mi material-symbols-outlined">account_balance_wallet</span> Isi Saldo</a>
  <a class="btn-menu<?php echo $isActive('/wali/wallet_riwayat.php'); ?>" href="<?php echo url("wali/wallet_riwayat.php"); ?>"><span class="mi material-symbols-outlined">account_balance</span> Riwayat Dompet</a>
  <a class="btn-menu<?php echo $isActive('/wali/invoice.php'); ?>" href="<?php echo url("wali/invoice.php"); ?>"><span class="mi material-symbols-outlined">request_quote</span> Tagihan SPP</a>
  <a class="btn-menu<?php echo $isActive('/wali/notifikasi.php'); ?>" href="<?php echo url("wali/notifikasi.php"); ?>"><span class="mi material-symbols-outlined">notifications</span> Notifikasi<?php if($unreadNotifCountWali>0){ echo ' <span class="notif-badge">'.$unreadNotifCountWali.'</span>'; } ?></a>
  <a class="btn-menu<?php echo $isActive('/wali/ubah_password'); ?>" href="<?php echo url("wali/ubah_password.php"); ?>"><span class="mi material-symbols-outlined">lock_reset</span> Ubah Kata Sandi</a>
  <div class="wali-spacer"></div>
  <a class="btn-menu btn-danger" href="<?php echo url("logout"); ?>"><span class="mi material-symbols-outlined" style="font-size:18px">logout</span> KELUAR</a>
</nav>
<?php endif; ?>
<main id="mainContent" class="container">
<!-- Page content starts -->