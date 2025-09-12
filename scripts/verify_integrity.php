<?php
// Simple integrity verification script (run via browser or CLI)
require_once __DIR__.'/../src/includes/init.php';

// Simple shared secret (define INTEGRITY_KEY in config.php); if set, require ?key=
if(defined('INTEGRITY_KEY') && constant('INTEGRITY_KEY')){
  $k = $_GET['key'] ?? ($_SERVER['HTTP_X_INTEGRITY_KEY'] ?? '');
  if($k !== constant('INTEGRITY_KEY')){ http_response_code(403); echo 'Forbidden'; exit; }
}

$mode = $_GET['mode'] ?? 'text';
$canSendHeaders = !headers_sent();
if($mode === 'json'){
  if($canSendHeaders){ header('Content-Type: application/json; charset=utf-8'); }
} elseif($mode === 'html') {
  if($canSendHeaders){ header('Content-Type: text/html; charset=utf-8'); }
} else {
  if($canSendHeaders){ header('Content-Type: text/plain; charset=utf-8'); }
}

function report_block($title,$rows){
  echo "==== $title (".count($rows).") ====".PHP_EOL;
  foreach($rows as $r){ echo '- '.$r.PHP_EOL; }
  echo PHP_EOL;
}

$issues=[];
$warn=[];
$info=[];

// 1. invoice paid_amount <= amount
$q = mysqli_query($conn,"SELECT id, amount, paid_amount FROM invoice WHERE paid_amount > amount + 0.01 LIMIT 200");
$over=[]; while($q && $row=mysqli_fetch_assoc($q)){ $over[] = 'Invoice #'.$row['id'].' paid_amount > amount ('.$row['paid_amount'].' > '.$row['amount'].')'; }
if($over) $issues = array_merge($issues,$over);

// 2. Compare sum settled payments vs invoice.paid_amount
$q = mysqli_query($conn,"SELECT i.id, i.paid_amount, COALESCE(SUM(p.amount),0) AS sum_pay FROM invoice i LEFT JOIN payment p ON p.invoice_id=i.id AND p.status='settled' GROUP BY i.id HAVING ABS(paid_amount - sum_pay) > 0.01 LIMIT 300");
$diff=[]; while($q && $row=mysqli_fetch_assoc($q)){ $diff[]='Invoice #'.$row['id'].' mismatch paid_amount='.$row['paid_amount'].' sum_settled='.$row['sum_pay']; }
if($diff) $issues = array_merge($issues,$diff);

// 3. Status consistency
$q = mysqli_query($conn,"SELECT id,status,amount,paid_amount,due_date FROM invoice LIMIT 1000");
$nowDate = date('Y-m-d');
while($q && $row=mysqli_fetch_assoc($q)){
  $id=$row['id']; $st=$row['status']; $amt=(float)$row['amount']; $paid=(float)$row['paid_amount']; $due=$row['due_date'];
  if($st==='paid' && abs($paid-$amt) > 0.01) $issues[]='Invoice #'.$id.' status paid tapi paid_amount != amount';
  if(in_array($st,['pending']) && $paid>0.01) $issues[]='Invoice #'.$id.' status pending tapi paid_amount > 0';
  if($st==='partial' && ($paid<=0.01 || $paid >= $amt-0.01)) $issues[]='Invoice #'.$id.' status partial tapi nilai tidak di tengah';
  if($due && $due < $nowDate && in_array($st,['pending','partial']) === false && $st!=='overdue') $warn[]='Invoice #'.$id.' due lewat tapi status '.$st;
  if($due && $due < $nowDate && in_array($st,['pending','partial']) && $st!=='overdue') $warn[]='Invoice #'.$id.' seharusnya overdue';
}

// 4. Negative wallet (impossible if only credit increases)
$q = mysqli_query($conn,"SELECT user_id, COALESCE(SUM(debit-credit),0) saldo FROM ledger_entries WHERE account='WALLET' GROUP BY user_id HAVING saldo < -0.01 LIMIT 100");
while($q && $row=mysqli_fetch_assoc($q)){ $issues[]='User wallet negatif user_id='.$row['user_id'].' saldo='.$row['saldo']; }

// 5. Settled payments without invoice (topup) - OK; but flag if amount 0
$q = mysqli_query($conn,"SELECT id, amount FROM payment WHERE invoice_id IS NULL AND status='settled' AND amount<=0");
while($q && $row=mysqli_fetch_assoc($q)){ $issues[]='Payment topup #'.$row['id'].' amount <= 0'; }

// 6. Payments settled but invoice still pending with paid_amount 0
$q = mysqli_query($conn,"SELECT p.id pid, i.id iid FROM payment p JOIN invoice i ON p.invoice_id=i.id WHERE p.status='settled' AND i.paid_amount<=0.01 AND i.status IN ('pending') LIMIT 100");
while($q && $row=mysqli_fetch_assoc($q)){ $issues[]='Invoice #'.$row['iid'].' pending padahal payment settled #'.$row['pid']; }

// Summary counts
// Summary counts (tahan fatal jika tabel belum ada di instalasi awal)
function safe_count(mysqli $c, string $sql): int {
  $rs = @mysqli_query($c,$sql);
  if(!$rs){ return 0; }
  $row = mysqli_fetch_row($rs);
  if(!$row){ return 0; }
  return (int)$row[0];
}
$totalInvoices = safe_count($conn,'SELECT COUNT(*) FROM invoice');
$totalPayments = safe_count($conn,'SELECT COUNT(*) FROM payment');
$totalLedger   = safe_count($conn,'SELECT COUNT(*) FROM ledger_entries');
if($totalInvoices===0 && $totalPayments===0 && $totalLedger===0){
  $warn[] = 'Semua hitungan 0. Mungkin tabel belum dimigrasi atau prefix berbeda.';
}

$info[] = 'Total invoice: '.$totalInvoices;
$info[] = 'Total payment: '.$totalPayments;
$info[] = 'Total ledger entries: '.$totalLedger;

if($mode === 'json'){
  echo json_encode([
    'info'=>$info,
    'warn'=>$warn,
    'issues'=>$issues,
    'generated_at'=>date('c')
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} elseif($mode === 'html') {
  // Lightweight HTML rendering (for admin UI iframe or direct view)
  ?>
  <!doctype html><html><head><meta charset="utf-8"><title>Integrity Report</title><style>
  body{font-family:system-ui,Arial,sans-serif;padding:20px;background:#f9fafb;color:#222;}
  h1{margin:0 0 20px;font-size:22px}
  .sec{margin:18px 0;padding:14px 16px;border:1px solid #ddd;border-radius:8px;background:#fff}
  .sec h2{margin:0 0 10px;font-size:15px;letter-spacing:.5px}
  ul{margin:0;padding-left:18px;font-size:13px;line-height:1.5}
  .tag{display:inline-block;padding:2px 6px;font-size:11px;border-radius:4px;background:#eee;margin-left:6px}
  .warn .tag{background:#fef3c7;color:#92400e}
  .issues .tag{background:#fee2e2;color:#991b1b}
  .ok{color:#047857;font-size:13px}
  </style></head><body>
  <h1>Integrity Report <small style="font-size:12px;color:#666;font-weight:400">Generated <?php echo htmlspecialchars(date('c')); ?></small></h1>
  <div class="sec info"><h2>Info <span class="tag"><?php echo count($info); ?></span></h2><ul><?php foreach($info as $i){ echo '<li>'.htmlspecialchars($i).'</li>'; } ?></ul></div>
  <div class="sec warn"><h2>Warn <span class="tag"><?php echo count($warn); ?></span></h2><?php if($warn){ echo '<ul>'; foreach($warn as $w){ echo '<li>'.htmlspecialchars($w).'</li>'; } echo '</ul>'; } else { echo '<div class="ok">Tidak ada peringatan.</div>'; } ?></div>
  <div class="sec issues"><h2>Issues <span class="tag"><?php echo count($issues); ?></span></h2><?php if($issues){ echo '<ul>'; foreach($issues as $is){ echo '<li>'.htmlspecialchars($is).'</li>'; } echo '</ul>'; } else { echo '<div class="ok">Tidak ada isu kritis.</div>'; } ?></div>
  </body></html>
  <?php
} else {
  report_block('INFO', $info);
  report_block('WARN', $warn);
  report_block('ISSUES', $issues);
  echo "Done.".PHP_EOL;
}
