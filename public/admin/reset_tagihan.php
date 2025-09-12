<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('admin');
require_once __DIR__.'/../../src/includes/header.php';

$msg=$err=null; $detailMsg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $tok=$_POST['csrf_token']??''; $confirm=trim($_POST['confirm']??''); $wipeNotif=isset($_POST['wipe_notif']);
  if(!verify_csrf_token($tok)){
    $err='Token tidak valid';
  } elseif(strtoupper($confirm)!=='RESET TAGIHAN'){
    $err='Ketik persis: RESET TAGIHAN';
  } else {
    $invCount=0; $payCount=0; $notifCount=0; $errors=[]; $ok=true;
    if($rs=mysqli_query($conn,'SELECT COUNT(*) c FROM invoice')){ $invCount=(int)(mysqli_fetch_assoc($rs)['c']??0); }
    if($rs=mysqli_query($conn,'SELECT COUNT(*) c FROM payment')){ $payCount=(int)(mysqli_fetch_assoc($rs)['c']??0); }
    // Nonaktif FK sementara agar TRUNCATE bisa jalan walau ada relasi
    @mysqli_query($conn,'SET FOREIGN_KEY_CHECKS=0');
    // Coba TRUNCATE dulu (lebih cepat), fallback ke DELETE jika gagal
    if(!@mysqli_query($conn,'TRUNCATE TABLE payment')){
      $errors[]='TRUNCATE payment: '.mysqli_error($conn); if(!@mysqli_query($conn,'DELETE FROM payment')){ $errors[]='DELETE payment: '.mysqli_error($conn); $ok=false; }
    }
    if(!@mysqli_query($conn,'TRUNCATE TABLE invoice')){
      $errors[]='TRUNCATE invoice: '.mysqli_error($conn); if(!@mysqli_query($conn,'DELETE FROM invoice')){ $errors[]='DELETE invoice: '.mysqli_error($conn); $ok=false; }
    }
    if($wipeNotif){
      if(!@mysqli_query($conn,"DELETE FROM notifications WHERE type LIKE 'invoice_%' OR type LIKE 'payment_%'")){
        $errors[]='DELETE notifications: '.mysqli_error($conn); $ok=false;
      } else { $notifCount = mysqli_affected_rows($conn); }
    }
    // Reset AI best effort
    @mysqli_query($conn,'ALTER TABLE payment AUTO_INCREMENT=1');
    @mysqli_query($conn,'ALTER TABLE invoice AUTO_INCREMENT=1');
    @mysqli_query($conn,'SET FOREIGN_KEY_CHECKS=1');
    if($ok){
      $msg='Reset tagihan selesai.';
      $detailMsg="Invoice sebelumnya: $invCount, Payment sebelumnya: $payCount".($wipeNotif?", Notif dihapus: $notifCount":'');
      if($errors){ $detailMsg.=' (Catatan: '.implode(' | ',$errors).')'; }
    } else {
      $err='Gagal reset (query error).'; if($errors){ $err.=' Detail: '.htmlspecialchars(implode(' | ',$errors)); }
    }
  }
}

// Ambil ringkas sebelum/ sesudah
$summary=[];
foreach([
  'invoice_total'=>'SELECT COUNT(*) c FROM invoice',
  'invoice_pending'=>"SELECT COUNT(*) c FROM invoice WHERE status='pending'",
  'payment_total'=>'SELECT COUNT(*) c FROM payment'
] as $k=>$sql){
  $v=0; if($rs=@mysqli_query($conn,$sql)){ $v=(int)(mysqli_fetch_assoc($rs)['c']??0); } $summary[$k]=$v; }
?>
<main class="container" style="padding-bottom:60px">
  <h1 style="margin:0 0 18px;font-size:24px">Reset Data Tagihan</h1>
  <div style="font-size:12px;margin:0 0 16px;color:#555">Halaman ini menghapus <strong>semua</strong> data invoice & payment sehingga generasi tagihan bisa dimulai dari nol. Gunakan dengan sangat hati-hati. Tidak dapat dibatalkan.</div>
  <?php if($msg): ?><div class="alert success" role="alert"><?= e($msg) ?><?php if($detailMsg): ?><div style="font-size:11px;margin-top:4px;opacity:.8"><?= e($detailMsg) ?></div><?php endif; ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>
  <div class="panel" style="padding:16px;border:1px solid #d1d5db;background:#fff;border-radius:8px;max-width:640px">
    <h2 style="margin:0 0 12px;font-size:18px">Ringkasan Saat Ini</h2>
    <ul style="margin:0 0 18px 18px;font-size:13px;line-height:1.5">
      <li>Total Invoice: <strong><?= number_format($summary['invoice_total']) ?></strong></li>
      <li>Invoice Pending: <strong><?= number_format($summary['invoice_pending']) ?></strong></li>
      <li>Total Payment: <strong><?= number_format($summary['payment_total']) ?></strong></li>
    </ul>
    <form method="POST" action="" onsubmit="return confirm('Yakin hapus SEMUA data tagihan & pembayaran? Tindakan ini tidak bisa dibatalkan.');">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Ketik: <code>RESET TAGIHAN</code></label>
      <input type="text" name="confirm" placeholder="RESET TAGIHAN" style="width:100%;padding:8px 10px;font-size:14px;margin:0 0 10px;border:1px solid #ccc;border-radius:6px" required />
      <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin:0 0 14px"><input type="checkbox" name="wipe_notif" value="1" /> Hapus notifikasi terkait invoice/payment</label>
      <button type="submit" class="btn-action danger" style="background:#b91c1c;color:#fff;padding:10px 18px;border:0;border-radius:6px;font-weight:600;cursor:pointer">HAPUS SEMUA TAGIHAN</button>
      <a href="generate_spp.php" class="btn-action" style="margin-left:12px">Kembali</a>
    </form>
    <div style="font-size:11px;color:#666;margin-top:14px;line-height:1.4">Catatan: Tidak menghapus histori wallet / ledger_entries. Jika ingin benar-benar bersih, hapus manual tabel ledger_entries & notifications lain. Pastikan backup sebelum eksekusi.</div>
  </div>
</main>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
