<?php
require_once __DIR__ . "/../../src/includes/init.php";
require_once __DIR__ . '/../includes/session_check.php';
require_role("admin");
// Re-sync wallet cached saldo with ledger (lightweight)
require_once __DIR__ . '/../../src/includes/wallet_sync.php';
$walletSync = wallet_recalc_all($conn);
// Gunakan analytics helper agar query konsisten & reusable
$metrics = analytics_dashboard_core($conn);
$total_wali = $metrics['total_wali'];
$total_saldo = $metrics['total_saldo'];
$invoice_paid = $metrics['invoice']['paid'];
$invoice_overdue = $metrics['invoice']['overdue'];
$invoice_unpaid = $metrics['invoice']['pending'] + $metrics['invoice']['partial'];
// Outstanding lama tetap tersedia jika ingin dipakai di tempat lain
$outstanding_amount = $metrics['outstanding'];
// Total nominal seluruh tagihan SPP (semua status) untuk badge kecil
$invoice_total_amount = $metrics['invoice_total_amount'] ?? 0;
$pending_invoice = $metrics['payments']['pending_invoice'];
$pending_topup = $metrics['payments']['pending_topup'];
$pending_total = $metrics['payments']['pending_total'];
$recent_invoice_payments = analytics_recent_pending_payments($conn,5,true);
$recent_wallet_topup = analytics_recent_pending_payments($conn,5,false);
require_once __DIR__ . "/../../src/includes/header.php";
?>
<?php
    // Dynamic brand assets & favicon (YouTube-like tab icon behavior)
    // 1. Primary brand image detection order
    $brandLogoRel = 'assets/img/logo.png';
    foreach(['brand_logo.png','brand_logo.webp','brand_logo.svg'] as $cand){
      if(is_file(PUBLIC_PATH.'/assets/img/'.$cand)){ $brandLogoRel = 'assets/img/'.$cand; break; }
    }
    // 2. Favicon detection order; falls back to brand logo
    $faviconRel = $brandLogoRel; // start with brand
    foreach(['favicon.ico','favicon.png','favicon.svg','favicon-32.png','favicon-16.png'] as $fav){
      if(is_file(PUBLIC_PATH.'/assets/img/'.$fav)){ $faviconRel = 'assets/img/'.$fav; break; }
    }
    // 3. Page title: allow pages to set $PAGE_TITLE before including header
    $baseTitle = 'Saku Santri';
    if(!empty($PAGE_TITLE)){
      $pageTitle = trim($PAGE_TITLE);
      if(strcasecmp($pageTitle,$baseTitle)!==0){
        $fullTitle = $pageTitle.' – '.$baseTitle; // Similar pattern to YouTube's "Video Title - YouTube"
      } else { $fullTitle = $baseTitle; }
    } else {
      $fullTitle = $baseTitle; }
  ?>
<div class="page-shell">
  <?php $belum = $invoice_unpaid + $invoice_overdue; ?>
  <div class="content-header">
    <h1>BERANDA ADMIN</h1>
    <div class="quick-actions-inline" aria-label="Tindakan cepat">
      <a class="qa-btn" href="<?php echo url('admin/invoice.php'); ?>">Kelola Tagihan</a>
  <!-- Link Generate SPP dihapus -->
      <a class="qa-btn" href="<?php echo url('admin/pengguna.php'); ?>">Data Santri</a>
    </div>
  </div>
  <div class="stat-row" aria-label="Ringkasan utama">
    <div class="stat-box wallet" role="group" aria-label="Total Tabungan Santri">
      <h3>Tabungan Santri</h3>
      <div class="amount" aria-live="polite">Rp <?php echo number_format($total_saldo,0,',','.'); ?></div>
      <button class="mini-act" data-href="<?php echo url('admin/wallet_topups.php'); ?>" title="Top-Up Wallet">Top-Up</button>
    </div>
    <div class="stat-box invoice" role="group" aria-label="Status Tagihan SPP">
      <h3>Tagihan SPP</h3>
      <div class="amount secondary" aria-live="polite"><?php echo number_format($belum); ?> belum / overdue</div>
      <div class="mini-act second" title="Total Nominal Semua Tagihan SPP"><?php echo format_rp($invoice_total_amount); ?></div>
    </div>
  </div>
  <div class="mini-stats" aria-label="Statistik detail">
    <div class="mini-stat" title="Jumlah Wali Terdaftar"><span class="lbl">Wali</span><span class="val"><?php echo number_format($total_wali); ?></span></div>
    <div class="mini-stat" title="Tagihan Lunas"><span class="lbl">Lunas</span><span class="val ok"><?php echo number_format($invoice_paid); ?></span></div>
    <div class="mini-stat" title="Tagihan Belum Dibayar"><span class="lbl">Belum</span><span class="val warn"><?php echo number_format($invoice_unpaid); ?></span></div>
    <div class="mini-stat" title="Tagihan Terlambat"><span class="lbl">Terlambat</span><span class="val bad"><?php echo number_format($invoice_overdue); ?></span></div>
  <div class="mini-stat" title="Pembayaran Tagihan Menunggu"><span class="lbl">Tagihan</span><span class="val info"><?php echo number_format($pending_invoice); ?></span></div>
  <div class="mini-stat" title="Top-Up Menunggu"><span class="lbl">Top-Up</span><span class="val info"><?php echo number_format($pending_topup); ?></span></div>
  <div class="mini-stat" title="Total Menunggu (tagihan + top-up)"><span class="lbl">Total</span><span class="val info"><?php echo number_format($pending_total); ?></span></div>
  </div>
  <div class="section-line-title"><span>Konfirmasi Pembayaran<?php if($pending_total>0) echo ' ('.$pending_total.' menunggu)'; ?></span></div>
  <div class="confirm-wrapper">
    <div class="c-col">
      <div class="c-head">Konfirmasi TOP-UP</div>
      <?php if(empty($recent_wallet_topup)){ echo '<p class=\"empty\">Tidak ada.</p>'; } else { $grp2=[]; foreach($recent_wallet_topup as $rp){ $d=date('d M Y', strtotime($rp['created_at'])); $grp2[$d][]=$rp; } foreach($grp2 as $date=>$items): ?>
        <div class="c-date-block">
          <div class="c-date-line"><?php echo $date; ?></div>
          <?php foreach($items as $it): ?>
            <div class="c-item">
              <span class="dot" aria-hidden="true"></span>
              <div class="c-meta">
                <div class="c-name"><?php echo htmlspecialchars($it['nama_wali']??'Wali',ENT_QUOTES,'UTF-8'); ?> <span class="t"><?php echo date('H:i', strtotime($it['created_at'])); ?></span></div>
                <div class="c-amt">+ Rp <?php echo number_format($it['amount'],0,',','.'); ?></div>
              </div>
              <span class="badge-wait">Menunggu</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; } ?>
    </div>
    <div class="c-divider" aria-hidden="true"></div>
    <div class="c-col">
      <div class="c-head">Konfirmasi Pembayaran SPP</div>
      <?php if(empty($recent_invoice_payments)){ echo '<p class=\"empty\">Tidak ada.</p>'; } else { $grp=[]; foreach($recent_invoice_payments as $rp){ $d=date('d M Y', strtotime($rp['created_at'])); $grp[$d][]=$rp; } foreach($grp as $date=>$items): ?>
        <div class="c-date-block">
          <div class="c-date-line"><?php echo $date; ?></div>
          <?php foreach($items as $it): ?>
            <div class="c-item">
              <span class="dot" aria-hidden="true"></span>
              <div class="c-meta">
                <div class="c-name"><?php echo htmlspecialchars($it['nama_wali'],ENT_QUOTES,'UTF-8'); ?> <span class="t"><?php echo date('H:i', strtotime($it['created_at'])); ?></span></div>
                <div class="c-amt">+ Rp <?php echo number_format($it['amount'],0,',','.'); ?></div>
              </div>
              <span class="badge-wait">Menunggu</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; } ?>
    </div>
  </div>
  <!-- Queue panels legacy removed -->
</div>
<?php require_once __DIR__ . "/../../src/includes/footer.php"; ?>
