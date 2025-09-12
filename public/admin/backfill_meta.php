<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('admin');
header('Content-Type: text/html; charset=utf-8');

function column_exists(mysqli $c,string $table,string $col): bool {
  $tableSafe = mysqli_real_escape_string($c,$table);
  $colSafe = mysqli_real_escape_string($c,$col);
  $rs = mysqli_query($c,"SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tableSafe' AND COLUMN_NAME='$colSafe' LIMIT 1");
  return $rs && mysqli_fetch_row($rs) ? true:false;
}

$ran = false; $stats = ['invoice_updated'=>0,'payment_updated'=>0,'invoice_skipped'=>0,'payment_skipped'=>0]; $err=null; $msg=null; $dry=false;

// Pre-calc column existence & candidate counts for diagnostics
$invHasMeta = column_exists($conn,'invoice','meta_json');
$invHasSource = column_exists($conn,'invoice','source');
$payHasMeta = column_exists($conn,'payment','meta_json');
$payHasSource = column_exists($conn,'payment','source');
$invCandidates = $invHasMeta ? (int)(mysqli_fetch_row(mysqli_query($conn,"SELECT COUNT(*) FROM invoice WHERE meta_json IS NULL OR meta_json=''"))[0] ?? 0) : -1;
$payCandidates = $payHasMeta ? (int)(mysqli_fetch_row(mysqli_query($conn,"SELECT COUNT(*) FROM payment WHERE meta_json IS NULL OR meta_json=''"))[0] ?? 0) : -1;

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')){ $err='Token tidak valid'; }
  else {
    $action = $_POST['act'] ?? 'run';
    if($action==='dry') { $dry=true; $msg='Dry run: tidak ada update dilakukan.'; }
    else {
      if($invHasMeta && $invCandidates>0){
        $q = mysqli_query($conn,"SELECT id,type,period,created_at,source FROM invoice WHERE (meta_json IS NULL OR meta_json='') LIMIT 20000");
        while($q && $row=mysqli_fetch_assoc($q)){
          $meta = [ 'backfill'=>true,'created_at'=>$row['created_at'],'type'=>$row['type'],'period'=>$row['period'],'source'=>$row['source'] ?: 'legacy'];
          $metaJson = mysqli_real_escape_string($conn,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
          $src = $row['source'] ?: 'legacy';
          if($invHasSource && !$row['source']){
            mysqli_query($conn,"UPDATE invoice SET meta_json='$metaJson', source='$src' WHERE id=".(int)$row['id']);
          } else {
            mysqli_query($conn,"UPDATE invoice SET meta_json='$metaJson' WHERE id=".(int)$row['id']);
          }
          if(mysqli_affected_rows($conn)>0) $stats['invoice_updated']++; else $stats['invoice_skipped']++;
        }
      }
      if($payHasMeta && $payCandidates>0){
        $q = mysqli_query($conn,"SELECT id,invoice_id,method,created_at,source FROM payment WHERE (meta_json IS NULL OR meta_json='') LIMIT 30000");
        while($q && $row=mysqli_fetch_assoc($q)){
          $meta = [ 'backfill'=>true,'created_at'=>$row['created_at'],'invoice_id'=>$row['invoice_id'],'method'=>$row['method'],'source'=>$row['source'] ?: 'legacy'];
          $metaJson = mysqli_real_escape_string($conn,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
          $src = $row['source'] ?: 'legacy';
          if($payHasSource && !$row['source']){
            mysqli_query($conn,"UPDATE payment SET meta_json='$metaJson', source='$src' WHERE id=".(int)$row['id']);
          } else {
            mysqli_query($conn,"UPDATE payment SET meta_json='$metaJson' WHERE id=".(int)$row['id']);
          }
          if(mysqli_affected_rows($conn)>0) $stats['payment_updated']++; else $stats['payment_skipped']++;
        }
      }
      $msg='Backfill selesai';
    }
    $ran = true;
  }
  // Refresh candidate counts after run
  if($invHasMeta) $invCandidates = (int)(mysqli_fetch_row(mysqli_query($conn,"SELECT COUNT(*) FROM invoice WHERE meta_json IS NULL OR meta_json=''"))[0] ?? 0);
  if($payHasMeta) $payCandidates = (int)(mysqli_fetch_row(mysqli_query($conn,"SELECT COUNT(*) FROM payment WHERE meta_json IS NULL OR meta_json=''"))[0] ?? 0);
}
require_once __DIR__.'/../../src/includes/header.php';
?>
<main class="container" style="padding-bottom:60px">
  <a href="invoice.php" style="text-decoration:none;font-size:12px;color:#555">&larr; Kembali</a>
  <h1 style="margin:10px 0 20px;font-size:26px">Backfill meta_json</h1>
  <?php if($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <div class="panel" style="margin-bottom:24px">
    <h2 style="margin:0 0 14px;font-size:18px">Jalankan</h2>
    <p style="font-size:13px;line-height:1.5">Menambahkan meta_json & source (jika kosong) untuk data lama. Idempotent: baris yang sudah terisi meta_json akan dilewati.</p>
    <div style="font-size:12px;padding:6px 10px;margin:0 0 14px;background:#f6f7f9;border:1px solid #e1e5e9;border-radius:6px;display:inline-block">
      Invoice: <?php echo $invHasMeta? 'kolom meta_json ADA':'kolom meta_json TIDAK ADA'; ?>,
      Payment: <?php echo $payHasMeta? 'kolom meta_json ADA':'kolom meta_json TIDAK ADA'; ?>
      <br/>Candidate invoice kosong: <?php echo $invHasMeta? $invCandidates:'-'; ?>,
      candidate payment kosong: <?php echo $payHasMeta? $payCandidates:'-'; ?>
    </div>
    <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
      <input type="hidden" name="act" value="run" />
      <button class="btn-action primary" style="padding:10px 24px" onclick="return confirm('Jalankan backfill sekarang?')">Run Backfill</button>
    </form>
    <form method="post" style="margin-top:10px">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
      <input type="hidden" name="act" value="dry" />
      <button class="btn-action outline" style="padding:8px 18px">Dry Run (Hitung Saja)</button>
    </form>
  </div>
  <?php if($ran): ?>
  <div class="panel">
    <h2 style="margin:0 0 14px;font-size:18px">Hasil</h2>
    <ul style="font-size:13px;line-height:1.5;margin:0;padding-left:18px">
      <li>Invoice updated: <?= (int)$stats['invoice_updated'] ?></li>
      <li>Payment updated: <?= (int)$stats['payment_updated'] ?></li>
      <li>Invoice skipped: <?= (int)$stats['invoice_skipped'] ?></li>
      <li>Payment skipped: <?= (int)$stats['payment_skipped'] ?></li>
      <li>Invoice masih perlu backfill: <?= $invHasMeta? $invCandidates:'-' ?></li>
      <li>Payment masih perlu backfill: <?= $payHasMeta? $payCandidates:'-' ?></li>
      <li>Mode: <?= $dry? 'DRY RUN (tidak update)':'EXECUTE' ?></li>
    </ul>
  </div>
  <?php endif; ?>
</main>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
