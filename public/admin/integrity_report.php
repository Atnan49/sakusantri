<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('admin');
require_once __DIR__.'/../../src/includes/header.php';

// Jalankan skrip integrity langsung (tanpa iframe) agar tidak tergantung path web
// Tangkap output HTML / TEXT dari scripts/verify_integrity.php (paksa mode=html)
ob_start();
$_GET['mode'] = 'html';
// Bypass key check dengan set INTEGRITY_KEY sama jika didefinisikan
if(defined('INTEGRITY_KEY') && constant('INTEGRITY_KEY')){
  $_GET['key'] = constant('INTEGRITY_KEY');
}
// Include file; jika file gagal ditemukan tampilkan pesan
$integrityFile = BASE_PATH.'/scripts/verify_integrity.php';
if(is_file($integrityFile)){
  include $integrityFile; // file sudah menulis output sendiri sesuai mode
  $reportHtml = ob_get_clean();
} else {
  $reportHtml = '<div class="alert error" style="padding:14px;border:1px solid #dc2626;background:#fee2e2;color:#b91c1c;border-radius:6px">File integrity script tidak ditemukan: '.e($integrityFile).'</div>';
  ob_end_clean();
}
?>
<main class="container" style="padding-bottom:60px">
  <h1 style="margin:0 0 18px;font-size:24px">Integrity Report</h1>
  <div style="font-size:12px;margin:0 0 12px;color:#555">Memeriksa konsistensi invoice, payment, ledger. Refresh halaman untuk memperbarui.</div>
  <div style="border:1px solid #ccc;background:#fff;border-radius:8px;padding:12px;min-height:300px;overflow:auto">
    <?php echo $reportHtml; ?>
  </div>
  <div style="font-size:11px;color:#666;margin-top:10px">Dihasilkan oleh skrip: scripts/verify_integrity.php (embedded).</div>
</main>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
