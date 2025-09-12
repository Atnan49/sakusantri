<?php
// Helper translate status to label for UI (Menunggu / Berhasil / Ditolak)
function topup_label($status){
    switch($status){
        case 'awaiting_proof': return 'Upload Bukti';
        case 'awaiting_confirmation': return 'Menunggu';
        case 'settled': return 'Berhasil';
        case 'failed': return 'Gagal';
        default: return human_status($status);
    }
}
function status_class($status){
    switch($status){
        case 'awaiting_confirmation': return 'tp-stat tp-wait';
        case 'settled': return 'tp-stat tp-ok';
        case 'failed': return 'tp-stat tp-bad';
        default: return 'tp-stat';
    }
}
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');

$user_id = (int)($_SESSION['user_id'] ?? 0);
require_once BASE_PATH.'/src/includes/payments.php';

// Data profil singkat (saldo dihitung dari ledger WALLET)
$nama_santri='';
if($stmt=mysqli_prepare($conn,"SELECT nama_santri FROM users WHERE id=?")){
    mysqli_stmt_bind_param($stmt,'i',$user_id); mysqli_stmt_execute($stmt); $r=mysqli_stmt_get_result($stmt); if($row=mysqli_fetch_assoc($r)){ $nama_santri=$row['nama_santri']; } mysqli_stmt_close($stmt);
}
$saldo = wallet_balance($conn,$user_id);

// Jumlah invoice SPP belum lunas (pending/partial/overdue)
$tagihan_spp = 0;
if($st = mysqli_prepare($conn, "SELECT COUNT(id) c FROM invoice WHERE user_id=? AND type='spp' AND status IN ('pending','partial','overdue')")){
    mysqli_stmt_bind_param($st,'i',$user_id);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $tagihan_spp = (int) (mysqli_fetch_assoc($rs)['c'] ?? 0);
    mysqli_stmt_close($st);
}

// Recent top-up payments (tanpa invoice) & invoices terbaru
$recent_topup = [];
if($st = mysqli_prepare($conn, "SELECT id, amount, status, created_at FROM payment WHERE user_id=? AND invoice_id IS NULL ORDER BY id DESC LIMIT 10")){
    mysqli_stmt_bind_param($st,'i',$user_id); mysqli_stmt_execute($st); $rs = mysqli_stmt_get_result($st); while($row=mysqli_fetch_assoc($rs)){ $recent_topup[]=$row; } mysqli_stmt_close($st);
}
$recent_invoices = [];
if($st = mysqli_prepare($conn, "SELECT id, period, amount, paid_amount, status, created_at FROM invoice WHERE user_id=? AND type='spp' ORDER BY id DESC LIMIT 10")){
    mysqli_stmt_bind_param($st,'i',$user_id); mysqli_stmt_execute($st); $rs = mysqli_stmt_get_result($st); while($row=mysqli_fetch_assoc($rs)){ $recent_invoices[]=$row; } mysqli_stmt_close($st);
}

// Kelompokkan per tanggal (d M Y)
function group_by_date_created($rows,$field){ $g=[]; foreach($rows as $r){ $ts = $r[$field] ?? null; $d = $ts ? date('d M Y', strtotime($ts)) : 'Tidak diketahui'; $g[$d][]=$r; } return $g; }
$group_topup = group_by_date_created($recent_topup,'created_at');
$group_inv   = group_by_date_created($recent_invoices,'created_at');

require_once __DIR__ . '/../../src/includes/status_helpers.php';
require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="wali-dashboard-shell">
    <div class="wali-page-title">Beranda Wali
        <span class="wali-page-sub">Ringkasan saldo & tagihan terbaru</span>
    </div>
    <div class="wali-cards">
        <div class="wali-card">
            <div class="wali-card-head">Saldo Wallet</div>
            <div class="wali-card-value">Rp <?php echo number_format($saldo,0,',','.'); ?></div>
            <div class="wali-card-sub">Tabungan Santri</div>
        </div>
    <div class="wali-card spp-card" data-href="<?php echo url('wali/invoice'); ?>" style="cursor:pointer">
            <div class="wali-card-head">Tagihan SPP</div>
            <div class="wali-card-value" style="color:#d97706"><?php echo $tagihan_spp; ?></div>
            <div class="wali-card-sub">Belum Dibayar</div>
        </div>
    </div>
    <div class="wali-section-bar">
    <span>Top-Up Dompet Terbaru</span>
        <span class="line"></span>
    <a class="btn-action primary" href="<?php echo url('wali/kirim_saku.php'); ?>">Isi Saldo</a>
        <a class="btn-action" href="<?php echo url('wali/invoice.php'); ?>">Lihat Tagihan</a>
    </div>
    <?php
        $unreadCount = 0;
        if(isset($conn)){
            $res = mysqli_query($conn, "SELECT COUNT(*) c FROM notifications WHERE user_id=".$user_id." AND read_at IS NULL");
            if($res){ $unreadCount = (int) (mysqli_fetch_assoc($res)['c'] ?? 0); }
        }
        if($unreadCount > 0): ?>
            <div class="alert info" style="margin-bottom:16px;max-width:420px">Anda memiliki <strong><?php echo $unreadCount; ?></strong> notifikasi belum dibaca. <a href="<?php echo url('wali/notifikasi'); ?>">Lihat</a></div>
    <?php endif; ?>
    <div class="topup-queue-wrapper">
        <div class="topup-queue-grid">
            <?php if(empty($topup_groups)): ?>
                <p class="text-muted" style="margin:0">Belum ada top-up.</p>
            <?php else: ?>
                <div class="queue-column">
                    <?php foreach($col1 as $grp): ?>
                        <div class="queue-date-group">
                            <div class="queue-date-head"><?php echo e($grp['date']); ?></div>
                            <?php foreach($grp['rows'] as $it): $status=$it['status']; ?>
                                <div class="queue-row">
                                    <div class="queue-left">
                                        <span class="qi-icon" aria-hidden="true">⤴</span>
                                        <span class="qi-amount">+ Rp <?php echo number_format($it['amount'],0,',','.'); ?></span>
                                    </div>
                                    <div class="queue-right">
                                        <span class="<?php echo status_class($status); ?>"><?php echo e(topup_label($status)); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="queue-column">
                    <?php foreach($col2 as $grp): ?>
                        <div class="queue-date-group">
                            <div class="queue-date-head"><?php echo e($grp['date']); ?></div>
                            <?php foreach($grp['rows'] as $it): $status=$it['status']; ?>
                                <div class="queue-row">
                                    <div class="queue-left">
                                        <span class="qi-icon" aria-hidden="true">⤴</span>
                                        <span class="qi-amount">+ Rp <?php echo number_format($it['amount'],0,',','.'); ?></span>
                                    </div>
                                    <div class="queue-right">
                                        <span class="<?php echo status_class($status); ?>"><?php echo e(topup_label($status)); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="wali-section-bar">
    <span>Tagihan Terbaru</span>
        <span class="line"></span>
        <span class="wali-section-sub">(10 terakhir)</span>
    </div>
    <div class="topup-queue-wrapper">
        <div class="topup-queue-grid">
            <?php if(empty($group_inv)): ?>
                <p class="text-muted" style="margin:0">Belum ada invoice.</p>
            <?php else: ?>
                <?php $inv_groups=[]; foreach($group_inv as $d=>$rows){ $inv_groups[]=['date'=>$d,'rows'=>$rows]; } foreach($inv_groups as $grp): ?>
                    <div class="queue-column">
                        <div class="queue-date-group">
                            <div class="queue-date-head"><?php echo e($grp['date']); ?></div>
                            <?php foreach($grp['rows'] as $it): $status=$it['status']; ?>
                                <div class="queue-row" style="align-items:center">
                                    <div class="queue-left">
                                        <span class="qi-icon" aria-hidden="true">#</span>
                                        <span class="qi-amount">Periode <?php echo e($it['period']); ?> • Rp <?php echo number_format($it['amount'],0,',','.'); ?></span>
                                    </div>
                                    <div class="queue-right">
                                        <span class="<?php echo status_class($status); ?>"><?php echo e(t_status_invoice($status)); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// Dengarkan broadcast saldo dari kasir (storage event)
(function(){
    const uid = <?php echo (int)$user_id; ?>;
    function updateSaldo(s){
        const el = document.querySelector('.wali-card .wali-card-value');
        if(el){ el.textContent = 'Rp '+Number(s).toLocaleString('id-ID'); }
    }
    window.addEventListener('storage', function(ev){
        if(ev.key==='wallet_update' && ev.newValue){
            try{ const data = JSON.parse(ev.newValue); if(String(data.uid)===String(uid) && typeof data.saldo!== 'undefined'){ updateSaldo(data.saldo); } }catch(e){}
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>




