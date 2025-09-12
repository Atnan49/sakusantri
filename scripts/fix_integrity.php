<?php
// scripts/fix_integrity.php
// Jalankan manual setelah verify_integrity.php untuk memperbaiki anomali utama
require_once __DIR__.'/../src/includes/init.php';
if(defined('INTEGRITY_KEY') && constant('INTEGRITY_KEY')){
  $k = $_GET['key'] ?? ($_SERVER['HTTP_X_INTEGRITY_KEY'] ?? '');
  if($k !== constant('INTEGRITY_KEY')){ http_response_code(403); echo 'Forbidden'; exit; }
}

$fixed = [];
$now = date('Y-m-d');

// 1. Perbaiki invoice.paid_amount > amount
echo "Memperbaiki invoice.paid_amount > amount...\n";
$q = mysqli_query($conn,"SELECT id, amount, paid_amount FROM invoice WHERE paid_amount > amount + 0.01");
while($q && $row=mysqli_fetch_assoc($q)){
  $id = (int)$row['id'];
  $amt = (float)$row['amount'];
  mysqli_query($conn, "UPDATE invoice SET paid_amount=$amt WHERE id=$id");
  $fixed[] = "Invoice #$id paid_amount dikoreksi ke $amt";
}

// 2. Samakan paid_amount dengan sum pembayaran settled
echo "Menyesuaikan paid_amount dengan sum settled payments...\n";
$q = mysqli_query($conn,"SELECT i.id, COALESCE(SUM(p.amount),0) AS sum_pay FROM invoice i LEFT JOIN payment p ON p.invoice_id=i.id AND p.status='settled' GROUP BY i.id");
while($q && $row=mysqli_fetch_assoc($q)){
  $id = (int)$row['id'];
  $sum = (float)$row['sum_pay'];
  mysqli_query($conn, "UPDATE invoice SET paid_amount=$sum WHERE id=$id");
}

// 3. Perbaiki status invoice jika tidak konsisten
echo "Memperbaiki status invoice...\n";
$q = mysqli_query($conn,"SELECT id, amount, paid_amount, status, due_date FROM invoice");
while($q && $row=mysqli_fetch_assoc($q)){
  $id = (int)$row['id'];
  $amt = (float)$row['amount'];
  $paid = (float)$row['paid_amount'];
  $st = $row['status'];
  $due = $row['due_date'];
  $newStatus = $st;
  if(abs($paid-$amt)<0.01 && $amt>0) $newStatus = 'paid';
  elseif($paid>0.01 && $paid<$amt-0.01) $newStatus = 'partial';
  elseif($paid<=0.01) $newStatus = 'pending';
  if($due && $due<$now && in_array($newStatus,['pending','partial'])) $newStatus = 'overdue';
  if($newStatus!==$st){
    mysqli_query($conn, "UPDATE invoice SET status='$newStatus' WHERE id=$id");
    $fixed[] = "Invoice #$id status dikoreksi ke $newStatus";
  }
}
echo "Selesai.\n";
if($fixed){
  echo "Perbaikan:\n";
  foreach($fixed as $f) echo "- $f\n";
} else {
  echo "Tidak ada perubahan signifikan.\n";
}
