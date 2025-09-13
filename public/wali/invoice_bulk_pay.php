<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if($_SERVER['REQUEST_METHOD'] !== 'POST'){ header('Location: invoice.php'); exit; }
if(!verify_csrf_token($_POST['csrf_token'] ?? '')){ header('Location: invoice.php?msg='.urlencode('Sesi berakhir, silakan ulangi.')); exit; }

$ids = $_POST['invoice_ids'] ?? [];
if(!is_array($ids) || !$ids){ header('Location: invoice.php?msg='.urlencode('Tidak ada tagihan dipilih.')); exit; }
// sanitize
$ids = array_values(array_unique(array_map('intval', $ids)));
if(!$ids){ header('Location: invoice.php?msg='.urlencode('Pilihan tidak valid.')); exit; }

// Fetch only invoices owned by user and eligible
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids)+1);
$params = $ids; array_unshift($params, $uid);

$sql = 'SELECT id, amount, paid_amount, status FROM invoice WHERE user_id=? AND id IN ('.$placeholders.')';
$stmt = mysqli_prepare($conn, $sql);
if(!$stmt){ header('Location: invoice.php?msg='.urlencode('Gagal memuat data.')); exit; }
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$rs = mysqli_stmt_get_result($stmt);
$invoices = [];
while($rs && ($row = mysqli_fetch_assoc($rs))){ $invoices[] = $row; }
if(!$invoices){ header('Location: invoice.php?msg='.urlencode('Tagihan tidak ditemukan.')); exit; }

$total = 0; $eligibleIds = [];
foreach($invoices as $inv){
  $remaining = max(0.0, (float)$inv['amount'] - (float)$inv['paid_amount']);
  if($remaining > 0 && !in_array($inv['status'], ['canceled','paid'], true)){
    $total += $remaining; $eligibleIds[] = (int)$inv['id'];
  }
}
if(!$eligibleIds){ header('Location: invoice.php?msg='.urlencode('Tidak ada tagihan yang bisa dibayar.')); exit; }

$balance = wallet_balance($conn, $uid);
if($total > $balance + 0.0001){ header('Location: invoice.php?msg='.urlencode('Saldo wallet tidak cukup (butuh Rp '.number_format($total,0,',','.').').')); exit; }

// Apply wallet payments sequentially (small N) - each atomic
$okCount = 0; $failCount = 0; $messages = [];
foreach($eligibleIds as $iid){
  // recompute each time to respect updated balance
  $q = mysqli_prepare($conn,'SELECT amount, paid_amount, status FROM invoice WHERE id=? AND user_id=? LIMIT 1');
  if(!$q){ $failCount++; continue; }
  mysqli_stmt_bind_param($q,'ii',$iid,$uid); mysqli_stmt_execute($q); $r=mysqli_stmt_get_result($q); $row=$r?mysqli_fetch_assoc($r):null; if(!$row){ $failCount++; continue; }
  $remaining = max(0.0, (float)$row['amount'] - (float)$row['paid_amount']);
  if($remaining <= 0 || in_array($row['status'], ['canceled','paid'], true)) { continue; }
  $curBal = wallet_balance($conn, $uid);
  $amt = min($curBal, $remaining);
  if($amt <= 0){ break; }
  $res = wallet_pay_invoice($conn, $iid, $uid, $amt);
  if($res['ok']){ $okCount++; } else { $failCount++; $messages[] = 'Inv #'.$iid.': '.$res['msg']; }
}

$msg = 'Pembayaran berhasil: '.$okCount.' tagihan';
if($failCount>0){ $msg .= ', gagal: '.$failCount; }
if($messages){ $msg .= ' | '.implode('; ', $messages); }
header('Location: invoice.php?msg='.urlencode($msg));
exit;
?>
