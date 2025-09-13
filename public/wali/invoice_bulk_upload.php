<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';
require_once BASE_PATH.'/src/includes/upload_helper.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
$step = $_POST['step'] ?? 'select';

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')){
  header('Location: invoice.php?msg='.urlencode('Sesi berakhir, silakan ulangi.'));
  exit;
}

$ids = $_POST['invoice_ids'] ?? [];
if(!is_array($ids) || !$ids){ header('Location: invoice.php?msg='.urlencode('Tidak ada tagihan dipilih.')); exit; }
$ids = array_values(array_unique(array_map('intval',$ids)));
if(!$ids){ header('Location: invoice.php?msg='.urlencode('Pilihan tidak valid.')); exit; }

// Load selected invoices (owned by user)
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids)+1);
$params = $ids; array_unshift($params, $uid);
$sql = 'SELECT id, type, period, amount, paid_amount, status, due_date FROM invoice WHERE user_id=? AND id IN ('.$placeholders.') ORDER BY id DESC';
$stmt = mysqli_prepare($conn,$sql);
if(!$stmt){ header('Location: invoice.php?msg='.urlencode('Gagal memuat data.')); exit; }
mysqli_stmt_bind_param($stmt,$types,...$params);
mysqli_stmt_execute($stmt); $rs=mysqli_stmt_get_result($stmt);
$invoices=[]; while($rs && ($row=mysqli_fetch_assoc($rs))) $invoices[]=$row;
if(!$invoices){ header('Location: invoice.php?msg='.urlencode('Tagihan tidak ditemukan.')); exit; }

// Filter eligible
$eligible=[]; $total=0; foreach($invoices as $inv){
  $remaining = max(0.0,(float)$inv['amount'] - (float)$inv['paid_amount']);
  if($remaining > 0 && !in_array($inv['status'],['paid','canceled'],true)){
    $inv['remaining']=$remaining; $eligible[]=$inv; $total += $remaining;
  }
}
if(!$eligible){ header('Location: invoice.php?msg='.urlencode('Tidak ada tagihan yang bisa dibayar.')); exit; }

$msg=null; $err=null;
if(($step === 'upload') && $_SERVER['REQUEST_METHOD']==='POST'){
  // Handle proof upload and create payments per invoice with shared proof
  $up = handle_payment_proof_upload('proof');
  if(!$up['ok']){ $err = $up['error'] ?: 'Upload gagal'; }
  else {
    $proofFile = $up['file'];
    $okCount=0; $failCount=0;
    foreach($eligible as $inv){
      $amount = (float)$inv['remaining'];
      $pid = payment_initiate($conn,$uid,(int)$inv['id'],'manual_transfer',$amount,'','bulk proof');
      if($pid){
        // Attach proof and move to awaiting_confirmation
        $upd = mysqli_prepare($conn,'UPDATE payment SET proof_file=?, status="awaiting_confirmation", updated_at=NOW() WHERE id=?');
        if($upd){ mysqli_stmt_bind_param($upd,'si',$proofFile,$pid); mysqli_stmt_execute($upd); }
        payment_history_add($conn,$pid,'initiated','awaiting_confirmation',$uid,'bulk upload proof');
        $okCount++;
      } else { $failCount++; }
    }
    $msg = 'Dikirim: '.$okCount.' pembayaran'; if($failCount>0){ $msg .= ', gagal: '.$failCount; }
    header('Location: invoice.php?msg='.urlencode($msg));
    exit;
  }
}

// Render summary + upload form
$PAGE_TITLE = 'Pembayaran Sekaligus';
require_once BASE_PATH.'/src/includes/header.php';
?>
<div class="page-shell">
  <div class="content-header">
    <h1>Pembayaran Sekaligus</h1>
    <div class="actions"><a href="invoice.php" class="btn-action outline" style="text-decoration:none">Kembali</a></div>
  </div>
  <?php if($err): ?><div class="panel section"><div class="alert error" style="margin:0"><?= e($err) ?></div></div><?php endif; ?>
  <div class="panel section">
    <h2>Ringkasan Tagihan</h2>
    <div class="table-wrap">
      <table class="table table-compact" style="min-width:640px">
        <thead><tr><th>ID</th><th>Jenis</th><th>Periode</th><th>Nominal</th><th>Dibayar</th><th>Sisa</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach($eligible as $inv): ?>
            <tr>
              <td>#<?= (int)$inv['id'] ?></td>
              <td><?= e(strtoupper(str_replace('_',' ',$inv['type']))) ?></td>
              <td><?= e($inv['period']) ?></td>
              <td>Rp <?= number_format($inv['amount'],0,',','.') ?></td>
              <td>Rp <?= number_format($inv['paid_amount'],0,',','.') ?></td>
              <td><b>Rp <?= number_format($inv['remaining'],0,',','.') ?></b></td>
              <td><span class="status-<?= e(str_replace('_','-',$inv['status'])) ?><?= $inv['status']==='overdue'?' warn':'' ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th colspan="5" style="text-align:right">Total</th><th colspan="2">Rp <?= number_format($total,0,',','.') ?></th></tr>
        </tfoot>
      </table>
    </div>
  </div>
  <div class="panel section">
    <h2>Upload Satu Bukti Pembayaran</h2>
    <div class="bank-label" style="margin-top:4px"><b>Transfer ke Rekening Pondok:</b></div>
    <div class="bank-info" style="margin-bottom:6px"><b>BSI 664701012881537 a.n. RUMAH TAHFIDZ BAITUL</b></div>
    <p style="margin-top:-2px;color:#555">Silakan transfer total sebesar <b>Rp <?= number_format($total,0,',','.') ?></b> ke rekening di atas, lalu upload satu bukti transfer. Admin akan memverifikasi dan melunasi tagihan terpilih.</p>
    <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
      <?php foreach($ids as $id): ?>
        <input type="hidden" name="invoice_ids[]" value="<?= (int)$id ?>" />
      <?php endforeach; ?>
      <input type="hidden" name="step" value="upload" />
      <div style="display:flex;flex-direction:column;gap:6px">
        <label style="font-size:12px;font-weight:600">Bukti Transfer (jpg/png/pdf, maks 2MB)</label>
        <input type="file" name="proof" accept="image/*,.pdf" required style="padding:8px 10px;background:#fff;border:1px solid #ccc;border-radius:8px" />
      </div>
      <button class="btn-action primary" style="height:40px;padding:0 22px">Kirim Bukti</button>
    </form>
    <div style="font-size:12px;color:#666;margin-top:6px">Setelah upload, admin akan memverifikasi pembayaran Anda dan menandai tagihan menjadi lunas sesuai nominal total.</div>
  </div>
</div>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
