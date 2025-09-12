<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';
require_once BASE_PATH.'/src/includes/upload_helper.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
$msg=$err=null; $do=$_POST['do'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')) $err='Token tidak valid';
  else if($do==='start_topup'){
  $amt = normalize_amount($_POST['amount'] ?? 0);
    if($amt < 10000) $err='Minimal top-up 10000'; else {
  // Pastikan argumen invoice_id benar-benar null untuk top up wallet
  $pid = payment_initiate($conn,$uid,null,'manual_transfer',$amt,'','topup start');
      if($pid){ payment_update_status($conn,$pid,'awaiting_proof',$uid,'topup awaiting proof'); $msg='Top-up dibuat (#'.$pid.'). Upload bukti.'; }
      else $err='Gagal membuat top-up';
    }
  } else if($do==='upload_proof'){
    $pid = (int)($_POST['payment_id'] ?? 0);
    if(!$pid) $err='Payment tidak valid'; else {
      $pr = mysqli_query($conn,'SELECT * FROM payment WHERE id='.(int)$pid.' AND user_id='.(int)$uid.' AND invoice_id IS NULL LIMIT 1');
      $prow = $pr?mysqli_fetch_assoc($pr):null;
      if(!$prow) $err='Top-up tidak ditemukan';
      else if(!in_array($prow['status'],['initiated','awaiting_proof'])) $err='Status tidak bisa upload';
      else {
        $upRes = handle_payment_proof_upload('proof');
        if(!$upRes['ok']) $err=$upRes['error']; else {
          $newName = $upRes['file'];
          $upd = mysqli_prepare($conn,'UPDATE payment SET proof_file=?, status=?, updated_at=NOW() WHERE id=?');
          $newStatus='awaiting_confirmation';
          if($upd){ mysqli_stmt_bind_param($upd,'ssi',$newName,$newStatus,$pid); mysqli_stmt_execute($upd); }
          payment_history_add($conn,$pid,$prow['status'],'awaiting_confirmation',$uid,'upload proof topup');
          $msg='Bukti diupload.';
        }
      }
    }
  }
  if($msg && !$err){ header('Location: wallet_topup.php?msg='.urlencode($msg)); exit; }
}
if(isset($_GET['msg'])) $msg=$_GET['msg'];
$saldo = wallet_balance($conn,$uid);
$topups=[]; $r = mysqli_query($conn,'SELECT * FROM payment WHERE user_id='.(int)$uid.' AND invoice_id IS NULL ORDER BY id DESC LIMIT 50'); while($r && $row=mysqli_fetch_assoc($r)) $topups[]=$row;
require_once BASE_PATH.'/src/includes/header.php';
?>
<main class="container" style="padding-bottom:60px">
  <a href="invoice.php" style="text-decoration:none;font-size:12px;color:#555">&larr; Kembali</a>
  <h1 style="margin:8px 0 20px;font-size:26px">Top-up Wallet</h1>
  <?php if($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <div class="panel" style="margin-bottom:28px">
    <h2 style="margin:0 0 14px;font-size:18px">Saldo Saat Ini: Rp <?= number_format($saldo,0,',','.') ?></h2>
    <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="start_topup">
      <div style="display:flex;flex-direction:column;gap:4px">
        <label style="font-size:11px;font-weight:600">Nominal Top-up</label>
        <input type="number" name="amount" min="10000" step="1000" required style="padding:8px 10px;width:180px">
      </div>
      <button class="btn-action primary" style="height:44px;padding:0 26px">Buat Top-up</button>
    </form>
    <div style="font-size:11px;color:#666;margin-top:8px">Setelah buat, upload bukti transfer untuk diverifikasi admin.</div>
  </div>
  <div class="panel">
    <h2 style="margin:0 0 14px;font-size:18px">Riwayat Top-up</h2>
    <div class="table-wrap topup-table-desktop" style="overflow-x:auto">
      <table class="table" style="min-width:680px">
        <thead><tr><th>ID</th><th>Nominal</th><th>Status</th><th>Bukti</th><th>Dibuat</th><th>Aksi</th></tr></thead>
        <tbody>
          <?php if(!$topups): ?><tr><td colspan="6" style="text-align:center;font-size:12px;color:#777">Belum ada.</td></tr><?php else: foreach($topups as $t): ?>
            <tr>
              <td>#<?= (int)$t['id'] ?></td>
              <td>Rp <?= number_format($t['amount'],0,',','.') ?></td>
              <td><span class="status-<?= e($t['status']) ?>"><?= e($t['status']) ?></span></td>
              <td style="font-size:11px">
                <?php if(!empty($t['proof_file'])): ?><a href="../uploads/payment_proof/<?= e($t['proof_file']) ?>" target="_blank">Lihat</a><?php else: ?>-<?php endif; ?>
              </td>
              <td><?= e($t['created_at']) ?></td>
              <td style="font-size:11px">
                <?php if(in_array($t['status'],['initiated','awaiting_proof']) && empty($t['proof_file'])): ?>
                  <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="do" value="upload_proof">
                    <input type="hidden" name="payment_id" value="<?= (int)$t['id'] ?>">
                    <input type="file" name="proof" required accept="image/*,.pdf" style="padding:4px 6px;font-size:11px">
                    <button style="padding:6px 10px;font-size:11px">Upload</button>
                  </form>
                <?php else: ?>-
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card List -->
    <div class="topup-mobile-list">
      <?php if(!$topups): ?>
        <div class="topup-entry-mobile" style="text-align:center;color:#777;font-size:12px">Belum ada.</div>
      <?php else: foreach($topups as $t): ?>
        <div class="topup-entry-mobile">
          <div class="tm-id">#<?= (int)$t['id'] ?></div>
          <div class="tm-row"><span class="tm-label">Nominal</span><span class="tm-value">Rp <?= number_format($t['amount'],0,',','.') ?></span></div>
          <div class="tm-row"><span class="tm-label">Status</span><span class="tm-status status-<?= e($t['status']) ?>"><?= e($t['status']) ?></span></div>
          <div class="tm-row"><span class="tm-label">Bukti</span><span class="tm-proof"><?php if(!empty($t['proof_file'])): ?><a href="../uploads/payment_proof/<?= e($t['proof_file']) ?>" target="_blank">Lihat</a><?php else: ?>-<?php endif; ?></span></div>
          <div class="tm-row"><span class="tm-label">Dibuat</span><span class="tm-value"><?= e($t['created_at']) ?></span></div>
          <?php if(in_array($t['status'],['initiated','awaiting_proof']) && empty($t['proof_file'])): ?>
            <form class="tm-upload-form" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="do" value="upload_proof">
              <input type="hidden" name="payment_id" value="<?= (int)$t['id'] ?>">
              <input type="file" name="proof" required accept="image/*,.pdf" style="padding:4px 6px;font-size:11px">
              <button style="padding:6px 10px;font-size:11px">Upload</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</main>
<script>
(function(){
  const uid = <?php echo (int)$uid; ?>;
  const headerSaldo = document.querySelector('h2');
  function updateSaldo(val){ if(headerSaldo){ headerSaldo.innerHTML = 'Saldo Saat Ini: '+ 'Rp '+Number(val||0).toLocaleString('id-ID'); } }
  window.addEventListener('storage', function(ev){ if(ev.key==='wallet_update' && ev.newValue){ try{ const d=JSON.parse(ev.newValue); if(String(d.uid)===String(uid)){ updateSaldo(d.saldo); } }catch(e){} } });
})();
</script>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
