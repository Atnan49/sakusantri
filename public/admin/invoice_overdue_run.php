<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';
$msg=$err=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')) $err='Token tidak valid';
  else {
    $res = invoice_mark_overdue_bulk($conn);
    $msg = 'Invoice overdue ditandai: '.$res['updated'];
  }
}
// Stats kecil
$st=[]; $q=mysqli_query($conn,"SELECT status, COUNT(*) c FROM invoice GROUP BY status"); while($q && $row=mysqli_fetch_assoc($q)) $st[$row['status']]=$row['c'];
require_once BASE_PATH.'/src/includes/header.php';
?>
<main class="container" style="padding-bottom:60px">
  <a href="invoice.php" style="text-decoration:none;font-size:12px;color:#555">&larr; Kembali</a>
  <h1 style="margin:8px 0 20px;font-size:26px">Run Overdue Marker</h1>
  <?php if($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <div class="panel" style="margin-bottom:24px">
    <h2 style="margin:0 0 14px;font-size:18px">Status Ringkas</h2>
    <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:13px">
      <?php foreach(['pending','partial','paid','overdue','canceled'] as $s): ?>
        <div style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fafafa"><b><?= $s ?></b>: <?= (int)($st[$s] ?? 0) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel">
    <h2 style="margin:0 0 14px;font-size:18px">Eksekusi</h2>
    <p style="font-size:13px;line-height:1.5;margin:0 0 14px">Menandai invoice dengan due_date lewat & status pending/partial menjadi <b>overdue</b>. Jalankan harian via cron idealnya.</p>
  <form method="post" data-confirm="Jalankan penandaan overdue sekarang?">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button class="btn-action danger" style="padding:10px 26px">Run Overdue Mark</button>
    </form>
  </div>
</main>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
