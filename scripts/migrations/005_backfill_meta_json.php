<?php
// Migration 005: Backfill meta_json & source for existing invoice & payment rows.
// Jalankan sekali via CLI atau browser (lalu hapus / protect).
// Menambahkan metadata dasar tanpa mengubah nilai finansial.
require_once __DIR__.'/../../src/includes/init.php';
header('Content-Type: text/plain; charset=utf-8');

// Optional simple auth via ?key= if BACKFILL_SECRET defined
if(defined('BACKFILL_SECRET') && constant('BACKFILL_SECRET')){
  $k = $_GET['key'] ?? '';
  if($k !== constant('BACKFILL_SECRET')){ http_response_code(403); echo "Forbidden"; exit; }
}

function column_exists(mysqli $c,string $table,string $col): bool {
  $tableSafe = mysqli_real_escape_string($c,$table);
  $colSafe = mysqli_real_escape_string($c,$col);
  $rs = mysqli_query($c,"SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tableSafe' AND COLUMN_NAME='$colSafe' LIMIT 1");
  return $rs && mysqli_fetch_row($rs) ? true:false;
}

$invHasMeta = column_exists($conn,'invoice','meta_json');
$invHasSource = column_exists($conn,'invoice','source');
$payHasMeta = column_exists($conn,'payment','meta_json');
$payHasSource = column_exists($conn,'payment','source');

$stats = ['invoice_updated'=>0,'payment_updated'=>0];

if($invHasMeta){
  $q = mysqli_query($conn,"SELECT id,type,period,created_at,source FROM invoice WHERE meta_json IS NULL OR meta_json='' LIMIT 8000");
  while($q && $row=mysqli_fetch_assoc($q)){
    $meta = [
      'backfill'=>true,
      'created_at'=>$row['created_at'],
      'type'=>$row['type'],
      'period'=>$row['period'],
      'source'=>$row['source'] ?: 'legacy'
    ];
    $metaJson = mysqli_real_escape_string($conn,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $src = $row['source'] ?: 'legacy';
    if($invHasSource && !$row['source']){
      mysqli_query($conn,"UPDATE invoice SET meta_json='$metaJson', source='$src' WHERE id=".(int)$row['id']);
    } else {
      mysqli_query($conn,"UPDATE invoice SET meta_json='$metaJson' WHERE id=".(int)$row['id']);
    }
    if(mysqli_affected_rows($conn)>0) $stats['invoice_updated']++;
  }
}

if($payHasMeta){
  $q = mysqli_query($conn,"SELECT id,invoice_id,method,created_at,source FROM payment WHERE meta_json IS NULL OR meta_json='' LIMIT 12000");
  while($q && $row=mysqli_fetch_assoc($q)){
    $meta = [
      'backfill'=>true,
      'created_at'=>$row['created_at'],
      'invoice_id'=>$row['invoice_id'],
      'method'=>$row['method'],
      'source'=>$row['source'] ?: 'legacy'
    ];
    $metaJson = mysqli_real_escape_string($conn,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $src = $row['source'] ?: 'legacy';
    if($payHasSource && !$row['source']){
      mysqli_query($conn,"UPDATE payment SET meta_json='$metaJson', source='$src' WHERE id=".(int)$row['id']);
    } else {
      mysqli_query($conn,"UPDATE payment SET meta_json='$metaJson' WHERE id=".(int)$row['id']);
    }
    if(mysqli_affected_rows($conn)>0) $stats['payment_updated']++;
  }
}

// Summary
print_r($stats);
echo "Done.".PHP_EOL;
