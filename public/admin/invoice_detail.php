<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';
require_once BASE_PATH.'/src/includes/status_helpers.php';
$iid = (int)($_GET['id'] ?? 0);
if(!$iid){ header('Location: invoice.php'); exit; }
// Fetch invoice with user
$stmt = mysqli_prepare($conn,'SELECT i.*, u.nama_santri, u.nama_wali FROM invoice i JOIN users u ON i.user_id=u.id WHERE i.id=? LIMIT 1');
if(!$stmt){ die('DB error'); }
mysqli_stmt_bind_param($stmt,'i',$iid); mysqli_stmt_execute($stmt); $r = mysqli_stmt_get_result($stmt); $inv = $r?mysqli_fetch_assoc($r):null;
if(!$inv){ require_once BASE_PATH.'/src/includes/header.php'; echo '<main class="container"><div class="alert error">Invoice tidak ditemukan.</div></main>'; require_once BASE_PATH.'/src/includes/footer.php'; exit; }

// Load payment list
$payments=[]; $pr = mysqli_query($conn,'SELECT * FROM payment WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($pr && $row=mysqli_fetch_assoc($pr)) $payments[]=$row;
// Histories
$hist_inv=[]; $hr = mysqli_query($conn,'SELECT * FROM invoice_history WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($hr && $row=mysqli_fetch_assoc($hr)) $hist_inv[]=$row;
$hist_pay=[];
if($payments){
  $ids = implode(',',array_map('intval', array_column($payments,'id')));
  $hpr = mysqli_query($conn,'SELECT * FROM payment_history WHERE payment_id IN ('.$ids.') ORDER BY id DESC');
  while($hpr && $row=mysqli_fetch_assoc($hpr)) $hist_pay[]=$row;
}

// Admin actions: mark payment settled / failed / reverse; create manual payment record
$msg=$err=null; $do = $_POST['do'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')) $err='Token tidak valid';
  else if($do==='new_manual_payment'){
  $amt = normalize_amount($_POST['amount'] ?? 0);
  if($amt<=0) $err='Nominal harus > 0'; else {
      $pid = payment_initiate($conn,(int)$inv['user_id'],$inv['id'],'manual_transfer',$amt,'','manual add');
      if($pid){
        // langsung ubah status ke awaiting_confirmation
        payment_update_status($conn,$pid,'awaiting_confirmation',(int)($_SESSION['user_id']??null),'upload proof bypass');
        $msg='Payment draft dibuat (#'.$pid.')';
      } else $err='Gagal buat payment';
    }
  } else if($do==='set_status'){
    $pid = (int)($_POST['payment_id'] ?? 0); $to = $_POST['to'] ?? '';
    if(!$pid || !$to) $err='Data kurang';
    else if(!payment_update_status($conn,$pid,$to,(int)($_SESSION['user_id']??null),'admin manual')) $err='Gagal update status';
    else $msg='Status payment #'.$pid.' diubah ke '.$to;
  } else if($do==='reverse_payment'){
    $pid = (int)($_POST['payment_id'] ?? 0);
    if(!$pid) $err='Payment tidak valid'; else {
      $res = payment_reversal($conn,$pid,(int)($_SESSION['user_id']??null),'admin reversal');
      if(!$res['ok']) $err=$res['msg']; else $msg=$res['msg'];
    }
  } else if($do==='confirm_payment'){
    $pid = (int)($_POST['payment_id'] ?? 0);
    if(!$pid) $err='Payment tidak valid'; else {
      $res = payment_confirm($conn,$pid,(int)($_SESSION['user_id']??null),'admin confirm');
      if(!$res['ok']) $err=$res['msg']; else $msg=$res['msg'];
    }
  } else if($do==='reject_payment'){
    $pid = (int)($_POST['payment_id'] ?? 0);
    if(!$pid) $err='Payment tidak valid'; else {
      $res = payment_update_status($conn,$pid,'rejected',(int)($_SESSION['user_id']??null),'admin reject');
      if(!$res) $err='Gagal menolak pembayaran'; else $msg='Pembayaran ditolak';
    }
  }
  // reload after post
  if($msg && !$err){ header('Location: invoice_detail.php?id='.$iid.'&msg='.urlencode($msg)); exit; }
}
if(isset($_GET['msg'])) $msg = $_GET['msg'];

require_once BASE_PATH.'/src/includes/header.php';
?>
<main class="alternative-layout">
  <aside class="sidebar">
    <!-- Sidebar content remains unchanged -->
  </aside>
  <div class="invoice-content">
    <header class="invoice-header">
      <a href="invoice.php" class="back-link">&larr; Kembali</a>
  <h1>Tagihan #<?= (int)$inv['id'] ?></h1>
    </header>

    <?php if($msg): ?><div class="alert success">✅ <?= e($msg) ?></div><?php endif; ?>
    <?php if($err): ?><div class="alert error">❌ <?= e($err) ?></div><?php endif; ?>

    <!-- Display error message if exists -->
    <?php if (!empty($err)): ?>
      <div class="error-message"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <!-- Display success message if exists -->
    <?php if (!empty($msg)): ?>
      <div class="success-message"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <section class="invoice-summary">
      <div class="summary-card">
  <h2>Data Tagihan</h2>
        <ul>
          <li><strong>Santri:</strong> <?= e($inv['nama_santri']) ?></li>
          <li><strong>Wali:</strong> <?= e($inv['nama_wali']) ?></li>
          <li><strong>Periode:</strong> <?= e($inv['period']) ?></li>
          <li><strong>Nominal:</strong> Rp <?= number_format($inv['amount'],0,',','.') ?></li>
          <li><strong>Status:</strong> <span class="status-<?= e($inv['status']) ?>"><?= e(t_status_invoice($inv['status'])) ?></span></li>
        </ul>
      </div>
    </section>

    <section class="payment-table">
      <h2>Daftar Pembayaran</h2>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Metode</th>
              <th>Nominal</th>
              <th>Status</th>
              <th>Tanggal</th>
              <th>Bukti</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$payments): ?>
              <tr><td colspan="7" class="no-data">Belum ada pembayaran.</td></tr>
            <?php else: foreach($payments as $p): ?>
              <tr>
                <td>#<?= (int)$p['id'] ?></td>
                <td><?= e($p['method']) ?></td>
                <td>Rp <?= number_format($p['amount'],0,',','.') ?></td>
                <td><span class="status-<?= e($p['status']) ?>"><?= e(t_status_payment($p['status'])) ?></span></td>
                <td><?= e($p['created_at']) ?></td>
                <td>
                  <?php if(!empty($p['proof_file'])): ?>
                    <a href="../uploads/payment_proof/<?= e($p['proof_file']) ?>" target="_blank">Lihat</a>
                  <?php else: ?>-
                  <?php endif; ?>
                </td>
                <td>
                  <?php if($p['status'] === 'awaiting_confirmation'): ?>
                    <form method="post" class="action-form" style="display:inline-block;">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="do" value="confirm_payment">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                      <button class="btn-confirm">Konfirmasi</button>
                    </form>
                    <form method="post" class="action-form" style="display:inline-block;">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="do" value="reject_payment">
                      <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                      <button class="btn-reject">Tolak</button>
                    </form>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</main>

<style>
  .alternative-layout {
    display: flex;
    gap: 20px;
  }
  .invoice-content {
    flex: 1;
    margin-left: 20px;
    padding: 20px;
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  }
  .invoice-header {
    margin-bottom: 20px;
  }
  .invoice-header h1 {
    font-size: 28px;
    color: #333;
  }
  .summary-card {
    background: #f7f7f7;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
  }
  .summary-card ul {
    list-style: none;
    padding: 0;
  }
  .summary-card li {
    margin-bottom: 10px;
    font-size: 16px;
    color: #555;
  }
  .summary-card strong {
    color: #222;
  }
  .payment-table {
    margin-top: 20px;
  }
  .table-container {
    overflow-x: auto;
  }
  .payment-table table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
  }
  .payment-table th, .payment-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
  }
  .payment-table th {
    background: #f4f4f4;
    font-weight: bold;
  }
  .payment-table .no-data {
    text-align: center;
    color: #999;
  }
  .status-awaiting_confirmation {
    color: #ff9800;
  }
  .status-settled {
    color: #4caf50;
  }
  .status-failed {
    color: #f44336;
  }
  .status-reversed {
    color: #2196f3;
  }
  .btn-confirm {
    padding: 8px 12px;
    font-size: 14px;
    color: #fff;
    background: #4caf50;
    border: none;
    border-radius: 4px;
    cursor: pointer;
  }
  .btn-confirm:hover {
    background: #45a049;
  }
  .btn-reject {
    padding: 8px 12px;
    font-size: 14px;
    color: #fff;
    background: #f44336;
    border: none;
    border-radius: 4px;
    cursor: pointer;
  }
  .btn-reject:hover {
    background: #e53935;
  }
</style>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
