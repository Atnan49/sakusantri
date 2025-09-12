<?php
// ajax_kasir_beli.php - proses transaksi kasir via AJAX dan kembalikan saldo terbaru
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
header('Content-Type: application/json; charset=utf-8');
// session_check.php sudah memastikan user_id ada; batasi role admin
require_role('admin');

require_once BASE_PATH.'/src/includes/payments.php';
require_once BASE_PATH.'/src/includes/wallet_sync.php';

if($_SERVER['REQUEST_METHOD']!=='POST'){
    echo json_encode(['ok'=>false,'msg'=>'Method not allowed']); exit;
}

$token = $_POST['csrf_token'] ?? '';
if(!verify_csrf_token($token)){
    echo json_encode(['ok'=>false,'msg'=>'Token tidak valid']); exit;
}

$uid = (int)($_POST['user_id'] ?? 0);
$nominal = (int)($_POST['nominal'] ?? 0);
$catatan = trim($_POST['catatan'] ?? 'Belanja koperasi');
if($uid<=0 || $nominal<=0){ echo json_encode(['ok'=>false,'msg'=>'Data tidak lengkap']); exit; }

@mysqli_begin_transaction($conn);
$lock = mysqli_prepare($conn,'SELECT saldo FROM users WHERE id=? FOR UPDATE');
if($lock){ mysqli_stmt_bind_param($lock,'i',$uid); mysqli_stmt_execute($lock); $rs= mysqli_stmt_get_result($lock); $row=$rs?mysqli_fetch_assoc($rs):null; }
if(empty($row)){ @mysqli_rollback($conn); echo json_encode(['ok'=>false,'msg'=>'Pengguna tidak ditemukan']); exit; }
if((int)$row['saldo'] < $nominal){ @mysqli_rollback($conn); echo json_encode(['ok'=>false,'msg'=>'Saldo tidak cukup']); exit; }

// Catat ledger (wallet keluar). Bisa diberi akun lawan di masa depan.
if(!ledger_post($conn,$uid,'WALLET',0,(float)$nominal,'purchase',null,$catatan)){
    @mysqli_rollback($conn); echo json_encode(['ok'=>false,'msg'=>'Ledger gagal']); exit; }

// Kompatibilitas legacy wallet_ledger
$ins = mysqli_prepare($conn,'INSERT INTO wallet_ledger (user_id,direction,amount,ref_type,ref_id,note) VALUES (? ,"debit", ?, "purchase", NULL, ?)');
if($ins){ $nF=(float)$nominal; mysqli_stmt_bind_param($ins,'ids',$uid,$nF,$catatan); mysqli_stmt_execute($ins); }

add_notification($conn,$uid,'purchase','Belanja koperasi Rp '.number_format($nominal,0,',','.'));
@mysqli_commit($conn);

// Recalc single (best effort)
@wallet_recalc_user($conn,$uid);

// Ambil saldo terbaru & 5 transaksi purchase terakhir
$saldoNow = 0; if($st=mysqli_prepare($conn,'SELECT saldo FROM users WHERE id=?')){ mysqli_stmt_bind_param($st,'i',$uid); mysqli_stmt_execute($st); $r=mysqli_stmt_get_result($st); if($r && ($rw=mysqli_fetch_assoc($r))) $saldoNow=(float)$rw['saldo']; }
$recent=[]; if($rsR=mysqli_query($conn,'SELECT l.id,l.amount,l.created_at FROM wallet_ledger l WHERE l.user_id='.(int)$uid.' AND l.ref_type="purchase" ORDER BY l.id DESC LIMIT 5')){ while($r=mysqli_fetch_assoc($rsR)) $recent[]=$r; }

echo json_encode(['ok'=>true,'msg'=>'Transaksi diproses','saldo'=>$saldoNow,'recent'=>$recent]);
exit;
?>