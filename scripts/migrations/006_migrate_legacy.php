<?php
// Migration 006: migrate legacy table `transaksi` into new schema (invoice, payment, ledger_entries).
// SAFE TO RUN MULTIPLE TIMES (idempotent) – it skips rows already migrated (identified by notes 'LEGACY TX #<id>' or payment note).
// Usage (browser): /scripts/migrations/006_migrate_legacy.php?dry=1  (dry run)
//        (execute): /scripts/migrations/006_migrate_legacy.php
// You can also add ?limit=100 to process only first 100 legacy rows (for batching).
// Optional auth: define('MIGRATE_SECRET','yourkey') in config.php then supply ?key=yourkey

require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../../src/includes/payments.php';

header('Content-Type: text/plain; charset=utf-8');

if(defined('MIGRATE_SECRET') && constant('MIGRATE_SECRET')){
  $k = $_GET['key'] ?? '';
  if($k !== constant('MIGRATE_SECRET')){ http_response_code(403); echo "Forbidden (secret)"; exit; }
}

$dry = isset($_GET['dry']);
$limit = isset($_GET['limit']) ? max(1,(int)$_GET['limit']) : 5000;

function legacy_rows(mysqli $conn,int $limit): array {
  $rows=[];
  $q = mysqli_query($conn,"SELECT * FROM transaksi WHERE deleted_at IS NULL ORDER BY id ASC LIMIT ".$limit);
  while($q && $r=mysqli_fetch_assoc($q)) $rows[]=$r;
  return $rows;
}

function already_migrated_invoice(mysqli $conn,int $legacyId): bool {
  $note = 'LEGACY TX #'.$legacyId;
  $stmt = mysqli_prepare($conn,'SELECT id FROM invoice WHERE notes=? LIMIT 1');
  if(!$stmt) return false; mysqli_stmt_bind_param($stmt,'s',$note); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); return $res && mysqli_fetch_row($res) ? true:false;
}
function already_migrated_payment(mysqli $conn,int $legacyId): bool {
  $note = 'LEGACY TX #'.$legacyId;
  $stmt = mysqli_prepare($conn,'SELECT id FROM payment WHERE note=? LIMIT 1');
  if(!$stmt) return false; mysqli_stmt_bind_param($stmt,'s',$note); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); return $res && mysqli_fetch_row($res) ? true:false;
}

$rows = legacy_rows($conn,$limit);

$stats = [
  'legacy_total'=>count($rows),
  'invoice_created'=>0,
  'payment_created'=>0,
  'invoice_skipped'=>0,
  'payment_skipped'=>0,
  'wallet_topup_settled'=>0,
  'wallet_topup_pending'=>0,
  'spp_paid'=>0,
  'spp_pending'=>0
];

if($dry) echo "DRY RUN (tidak commit perubahan).\n";

if(!$rows){ echo "Tidak ada baris legacy ditemukan.\n"; exit; }

if(!$dry) mysqli_begin_transaction($conn);

foreach($rows as $r){
  $legacyId = (int)$r['id'];
  $uid = (int)$r['user_id'];
  $jenis = $r['jenis_transaksi'];
  $status = $r['status'];
  $amount = (float)$r['jumlah'];
  $proof = $r['bukti_pembayaran'];
  $uploadedAt = $r['tanggal_upload'];
  $noteTag = 'LEGACY TX #'.$legacyId;

  if($jenis === 'spp'){
    if(already_migrated_invoice($conn,$legacyId)) { $stats['invoice_skipped']++; continue; }
    // Create invoice
    $invId = invoice_create($conn,$uid,'spp',null,$amount,null,$noteTag);
    if(!$invId){ $stats['invoice_skipped']++; continue; }
    // Map status
    if($status === 'lunas'){
      // Create payment and settle
      $pid = payment_initiate($conn,$uid,$invId,'manual_transfer',$amount,'','LEGACY TX #'.$legacyId);
      if($pid){
        // If there was a proof we can set to awaiting_confirmation then settle to record both states
        if($proof){ payment_update_status($conn,$pid,'awaiting_confirmation',null,'legacy imported'); }
        payment_update_status($conn,$pid,'settled',null,'legacy paid');
        $stats['payment_created']++; $stats['spp_paid']++;
      }
    } elseif($status === 'menunggu_konfirmasi'){
      $pid = payment_initiate($conn,$uid,$invId,'manual_transfer',$amount,'','LEGACY TX #'.$legacyId);
      if($pid){
        payment_update_status($conn,$pid,'awaiting_confirmation',null,'legacy awaiting confirm');
        if($proof){ // attach proof file reference (not moving file automatically)
          $stmtU = mysqli_prepare($conn,'UPDATE payment SET proof_file=? WHERE id=?');
          if($stmtU){ mysqli_stmt_bind_param($stmtU,'si',$proof,$pid); mysqli_stmt_execute($stmtU);} }
        $stats['payment_created']++; $stats['spp_pending']++;
      }
    } elseif($status === 'menunggu_pembayaran'){
      // Just leave invoice pending
      $stats['spp_pending']++;
    } elseif($status === 'ditolak') {
      // Mark invoice canceled
      $stmtC = mysqli_prepare($conn,'UPDATE invoice SET status="canceled", updated_at=NOW() WHERE id=?');
      if($stmtC){ mysqli_stmt_bind_param($stmtC,'i',$invId); mysqli_stmt_execute($stmtC);}    
    }
    $stats['invoice_created']++;
  } else { // uang_saku -> wallet topup
    if(already_migrated_payment($conn,$legacyId)) { $stats['payment_skipped']++; continue; }
    // invoice_id null payment
    $pid = payment_initiate($conn,$uid,null,'manual_transfer',$amount,'','LEGACY TX #'.$legacyId);
    if(!$pid){ $stats['payment_skipped']++; continue; }
    if($status === 'lunas'){
      if($proof){ payment_update_status($conn,$pid,'awaiting_confirmation',null,'legacy proof'); }
      payment_update_status($conn,$pid,'settled',null,'legacy topup');
      $stats['wallet_topup_settled']++; $stats['payment_created']++;
    } elseif($status === 'menunggu_konfirmasi'){
      payment_update_status($conn,$pid,'awaiting_confirmation',null,'legacy awaiting confirm');
      if($proof){ $stmtU = mysqli_prepare($conn,'UPDATE payment SET proof_file=? WHERE id=?'); if($stmtU){ mysqli_stmt_bind_param($stmtU,'si',$proof,$pid); mysqli_stmt_execute($stmtU);} }
      $stats['wallet_topup_pending']++; $stats['payment_created']++;
    } elseif($status === 'menunggu_pembayaran'){
      // leave initiated or move to awaiting_proof if proof exists
      if($proof){ payment_update_status($conn,$pid,'awaiting_confirmation',null,'legacy proof present'); }
      $stats['wallet_topup_pending']++; $stats['payment_created']++;
    } elseif($status === 'ditolak') {
      payment_update_status($conn,$pid,'failed',null,'legacy rejected');
      $stats['payment_created']++;
    }
  }
}

if($dry){
  echo "Dry run selesai (ROLLBACK). Statistik:\n";
  print_r($stats);
  if(!$dry) mysqli_rollback($conn); // not executed, just clarity
} else {
  mysqli_commit($conn);
  echo "Migrasi selesai. Statistik:\n"; print_r($stats);
}

echo "Done.".PHP_EOL;
