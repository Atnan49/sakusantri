<?php
// scripts/cron_overdue.php
// Script untuk dijalankan via cron untuk update status invoice overdue otomatis
define('CRON', true);
require_once __DIR__.'/../src/includes/init.php';
if(defined('CRON_SECRET') && constant('CRON_SECRET')){
  $k = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
  if($k !== constant('CRON_SECRET')){ http_response_code(403); echo 'Forbidden'; exit; }
}
$now = date('Y-m-d');
$count = 0;
// Update semua invoice yang due_date < hari ini, status pending/partial, menjadi overdue
echo "Menandai invoice overdue...\n";
$q = mysqli_query($conn, "SELECT id FROM invoice WHERE due_date < '$now' AND status IN ('pending','partial')");
while($q && $row=mysqli_fetch_assoc($q)){
  $id = (int)$row['id'];
  mysqli_query($conn, "UPDATE invoice SET status='overdue', updated_at=NOW() WHERE id=$id");
  $count++;
}
echo "Total invoice yang ditandai overdue: $count\n";
