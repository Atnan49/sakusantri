<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';
require_once BASE_PATH.'/src/includes/upload_helper.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
$iid = (int)($_GET['id'] ?? 0);
if(!$iid){ header('Location: invoice.php'); exit; }
$stmt = mysqli_prepare($conn,'SELECT * FROM invoice WHERE id=? AND user_id=? LIMIT 1');
if(!$stmt){ die('DB error'); }
mysqli_stmt_bind_param($stmt,'ii',$iid,$uid); mysqli_stmt_execute($stmt); $r=mysqli_stmt_get_result($stmt); $inv=$r?mysqli_fetch_assoc($r):null;
if(!$inv){ require_once BASE_PATH.'/src/includes/header.php'; echo '<main class="container"><div class="alert error">Invoice tidak ditemukan.</div></main>'; require_once BASE_PATH.'/src/includes/footer.php'; exit; }

// Ambil payments invoice ini
$payments=[]; $pr = mysqli_query($conn,'SELECT * FROM payment WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($pr && $row=mysqli_fetch_assoc($pr)) $payments[]=$row;
$hist_inv=[]; $hr = mysqli_query($conn,'SELECT * FROM invoice_history WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($hr && $row=mysqli_fetch_assoc($hr)) $hist_inv[]=$row;

$msg=$err=null; $do=$_POST['do'] ?? '';
// Debug log helper
function debug_log($msg) {
  file_put_contents(__DIR__.'/debug_invoice.log', date('Y-m-d H:i:s')." | ".$msg."\n", FILE_APPEND);
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  debug_log('POST diterima, do=' . ($_POST['do'] ?? '')); 
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')) { $err='Token tidak valid'; debug_log('Token tidak valid'); }
  else if($do==='bayar_spp_upload'){
    debug_log('Proses bayar_spp_upload');
    if(!isset($_FILES['bukti_bayar']) || $_FILES['bukti_bayar']['error']!=0){
      debug_log('File tidak ada atau error: '.($_FILES['bukti_bayar']['error'] ?? 'no file'));
      if(isset($_FILES['bukti_bayar']['error']) && $_FILES['bukti_bayar']['error']==UPLOAD_ERR_INI_SIZE)
        $err='Ukuran file terlalu besar. Maksimal '.ini_get('upload_max_filesize');
      else
        $err='File bukti tidak valid atau gagal diupload.';
    } else {
      $tmp = $_FILES['bukti_bayar']['tmp_name'];
      $ext = strtolower(pathinfo($_FILES['bukti_bayar']['name'], PATHINFO_EXTENSION));
      $allowed = ['jpg','jpeg','png','pdf'];
      if(!in_array($ext,$allowed)) { $err='Format file tidak didukung'; debug_log('Format file tidak didukung: '.$ext); }
      else {
        $data = file_get_contents($tmp);
        $invoiceId = (int)$inv['id'] > 0 ? (int)$inv['id'] : null;
        $amt = normalize_amount($_POST['amount'] ?? 0);
        $remaining = (float)$inv['amount'] - (float)$inv['paid_amount'];
        if($amt<=0 || $amt>$remaining) { $err='Nominal tidak valid'; debug_log('Nominal tidak valid'); }
        else {
          $pid = payment_initiate($conn,$uid,$invoiceId,'manual_transfer',$amt,'','wali bayar bukti');
          debug_log('payment_initiate result: '.($pid?$pid:'FAILED'));
          if($pid){
            $bukti_name = uniqid('proof_').'.'.$ext;
            $bukti_path = BASE_PATH.'/public/uploads/payment_proof/'.$bukti_name;
            $w = file_put_contents($bukti_path, $data);
            debug_log('file_put_contents: '.$bukti_path.' result='.$w);
            $upd = mysqli_prepare($conn,'UPDATE payment SET proof_file=?, status=? , updated_at=NOW() WHERE id=?');
            $newStatus = 'awaiting_confirmation';
            if($upd){ mysqli_stmt_bind_param($upd,'ssi',$bukti_name,$newStatus,$pid); mysqli_stmt_execute($upd); debug_log('UPDATE payment success'); }
            payment_history_add($conn,$pid,'initiated','awaiting_confirmation',$uid,'upload proof');
            $msg='Pembayaran dibuat (#'.$pid.') dan bukti dikirim.';
          } else {
            $err='Gagal membuat payment'; debug_log('Gagal membuat payment');
          }
        }
      }
    }
  }
  if($msg && !$err){ header('Location: invoice_detail.php?id='.$iid.'&msg='.urlencode($msg)); exit; }
}
if(isset($_GET['msg'])) $msg=$_GET['msg'];

$progress = $inv['amount']>0 ? min(100, round(($inv['paid_amount']/$inv['amount'])*100)) : 0;
function human_status_local($s){
  switch($s){
    case 'pending': return 'Belum Dibayar';
    case 'partial': return 'Sebagian';
    case 'paid': return 'Lunas';
    case 'overdue': return 'Terlambat';
    case 'canceled': return 'Dibatalkan';
    default: return ucfirst($s);
  }
}
require_once BASE_PATH.'/src/includes/header.php';
?>
<main class="container invoice-detail-page" style="padding-bottom:60px">
  <a href="invoice.php" style="text-decoration:none;font-size:12px;color:#555">&larr; Kembali</a>
  <h1 style="margin:8px 0 18px;font-size:26px">Invoice #<?= (int)$inv['id'] ?></h1>
  <?php if($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <div class="tx-panel invoice-detail-card" style="margin-bottom:24px">
    <div class="inv-flex">
      <div class="inv-section detail">
        <h3 class="sec-title">Detail</h3>
        <div class="meta-line"><span>Periode</span><b><?= e($inv['period']) ?></b></div>
        <div class="meta-line"><span>Nominal</span><b>Rp <?= number_format($inv['amount'],0,',','.') ?></b></div>
        <div class="meta-line"><span>Dibayar</span><b>Rp <?= number_format($inv['paid_amount'],0,',','.') ?></b></div>
        <div class="meta-line"><span>Status</span><b><span class="status-<?= e($inv['status']) ?>"><?= e(human_status_local($inv['status'])) ?></span></b></div>
        <div class="meta-line"><span>Jatuh Tempo</span><b><?= e($inv['due_date']) ?></b></div>
        <div class="meta-line"><span>Dibuat</span><b><?= e($inv['created_at']) ?></b></div>
      </div>
      <div class="inv-section progress">
        <h3 class="sec-title">Progress</h3>
        <div class="progress-bar"><div class="bar-fill" style="width:<?= $progress ?>%"></div></div>
        <div class="progress-info"><?= $progress ?>% terbayar (Rp <?= number_format($inv['paid_amount'],0,',','.') ?> dari Rp <?= number_format($inv['amount'],0,',','.') ?>)</div>
        <?php if(in_array($inv['status'],['pending','partial'])): ?>
        <div class="pay-instr panel-lite">
          <h3 class="instr-title">Instruksi Pembayaran SPP</h3>
          <div class="bank-label"><b>Transfer ke Rekening Pondok:</b></div>
          <div class="bank-info"><b>Bank BSI 1234567890 a.n. Pondok Pesantren Contoh</b></div>
          <div class="instr-text">Silakan transfer sesuai nominal tagihan ke rekening di atas. Setelah transfer, upload bukti pembayaran di bawah ini.</div>
          <ul class="instr-list">
            <li>Pastikan nominal transfer sesuai tagihan.</li>
            <li>Upload bukti transfer yang jelas (jpg/png/pdf).</li>
            <li>Admin akan memverifikasi pembayaran Anda.</li>
          </ul>
          <?php if(!$payments): ?>
            <form method="post" enctype="multipart/form-data" class="pay-form" id="bayarForm">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="do" value="bayar_spp_upload">
              <input type="hidden" name="amount" value="<?= (int)$inv['amount'] ?>">
              <div class="file-field">
                <label>Upload Bukti Transfer (jpg/png/pdf)</label>
                <input type="file" name="bukti_bayar" id="buktiInput" accept="image/*,.pdf" required>
              </div>
              <button class="btn-action primary" id="btnBayarSpp" disabled>Bayar SPP</button>
            </form>
            <script src="../assets/js/invoice_detail.js" defer></script>
          <?php endif; ?>
        </div>
        <?php else: ?>
          <div class="paid-note">Invoice sudah <?= e($inv['status']) ?>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="panel" style="margin-bottom:28px">
  <h2 style="margin:0 0 14px;font-size:18px">Payments</h2>
    <div class="table-wrap" style="overflow-x:auto">
      <table class="table" style="min-width:620px">
  <thead><tr><th>ID</th><th>Method</th><th>Amount</th><th>Status</th><th>Dibuat</th><th>Bukti</th></tr></thead>
        <tbody>
          <?php if(!$payments): ?><tr><td colspan="5" style="text-align:center;font-size:12px;color:#777">Belum ada payment.</td></tr><?php else: foreach($payments as $p): ?>
            <tr>
              <td>#<?= (int)$p['id'] ?></td>
              <td><?= e($p['method']) ?></td>
              <td>Rp <?= number_format($p['amount'],0,',','.') ?></td>
              <td><span class="status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
              <td><?= e($p['created_at']) ?></td>
              <td style="font-size:11px">
                <?php if(!empty($p['proof_file'])): ?>
                  <a href="../uploads/payment_proof/<?= e($p['proof_file']) ?>" target="_blank">Lihat</a>
                <?php else: ?>-
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php 
      $canUpload = null; 
  foreach($payments as $pp){ if(in_array($pp['status'],['initiated','awaiting_proof']) && empty($pp['proof_file'])){ $canUpload=$pp; break; } }
      if($canUpload): ?>
      <div style="margin-top:16px">
        <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="upload_proof">
            <input type="hidden" name="payment_id" value="<?= (int)$canUpload['id'] ?>">
            <div style="display:flex;flex-direction:column;gap:4px">
              <label style="font-size:11px;font-weight:600">Upload Bukti (jpg/png/pdf)</label>
              <input type="file" name="proof" accept="image/*,.pdf" required style="padding:6px 8px;background:#fff;border:1px solid #ccc;border-radius:6px">
            </div>
            <button class="btn-action primary" style="height:40px;padding:0 26px">Kirim Bukti</button>
        </form>
        <div style="font-size:11px;color:#666;margin-top:6px">Setelah upload, admin akan verifikasi.</div>
      </div>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h2 style="margin:0 0 14px;font-size:18px">Riwayat Invoice</h2>
    <ul style="list-style:none;padding:0;margin:0;max-height:260px;overflow:auto">
      <?php if(!$hist_inv): ?><li style="font-size:12px;color:#777">Belum ada.</li><?php else: foreach($hist_inv as $h): ?>
        <li style="padding:6px 4px;border-bottom:1px solid #eee;font-size:12px">
          <b><?= e($h['from_status'] ?: '-') ?> &rarr; <?= e($h['to_status']) ?></b> <span style="color:#666">(<?= e($h['created_at']) ?>)</span>
          <?php if($h['note']): ?><i style="color:#999"> - <?= e($h['note']) ?></i><?php endif; ?>
        </li>
      <?php endforeach; endif; ?>
    </ul>
  </div>
</main>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
