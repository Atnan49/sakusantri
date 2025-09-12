<?php
// filepath: c:\xampp\htdocs\saku_santri\public\view_bukti.php
require_once __DIR__ . "/../src/includes/init.php";
require_once __DIR__ . "/includes/session_check.php";

// Default back to a safe internal page
$defaultBack = url("admin/konfirmasi");
$backRaw = $_GET["back"] ?? "";
// Allow only internal paths
if ($backRaw && (strpos($backRaw, 'http://') === 0 || strpos($backRaw, 'https://') === 0)) {
  $back = $defaultBack;
} else {
  $back = $backRaw ? url(ltrim($backRaw, '/')) : $defaultBack;
}

$fn = basename($_GET["f"] ?? "");
require_once __DIR__ . "/../src/includes/header.php";
?>
<main class="container">
  <p><a class="btn-menu" href="<?php echo $back; ?>">Kembali</a></p>
  <?php if ($fn): ?>
  <?php $prettySrc = url('bukti/f/'.rawurlencode($fn)); ?>
  <p><a class="btn-action" target="_blank" rel="noopener" href="<?php echo $prettySrc; ?>">Buka di tab baru</a></p>
  <img src="<?php echo $prettySrc; ?>" alt="Bukti" style="max-width:100%;height:auto"/>
  <?php else: ?>
    <p>Bukti tidak ditemukan.</p>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . "/../src/includes/footer.php"; ?>
