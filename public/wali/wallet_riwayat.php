<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('wali_santri');
$userId = (int)($_SESSION['user_id'] ?? 0);

// Ambil ledger WALLET (batasi 300 terbaru) + hitung running balance dari terbaru ke bawah (atau sebaliknya?)
// Kita urutkan ascending agar running balance mudah dibaca kronologis.
// Pagination
$perPage = 100; $page = max(1,(int)($_GET['p'] ?? 1));
$offset = ($page-1)*$perPage;
$rows=[];
// Ambil subset page (kronologis ASC)
$sql = "SELECT id, debit, credit, note, created_at, ref_type, ref_id FROM ledger_entries WHERE user_id=$userId AND account='WALLET' ORDER BY id ASC LIMIT $perPage OFFSET $offset";
$res = mysqli_query($conn,$sql); while($res && $r=mysqli_fetch_assoc($res)){ $rows[]=$r; }
// Opening balance = sum sebelum id pertama di subset ini (jika page > 1)
$opening = 0.0;
if($offset>0 && $rows){
  $firstId = (int)$rows[0]['id'];
  $rsOpen = mysqli_query($conn,"SELECT COALESCE(SUM(debit-credit),0) FROM ledger_entries WHERE user_id=$userId AND account='WALLET' AND id < $firstId");
  if($rsOpen){ $opening = (float)(mysqli_fetch_row($rsOpen)[0] ?? 0); }
}
// Running balance
$running = $opening; foreach($rows as &$r){ $running += ((float)$r['debit'] - (float)$r['credit']); $r['running'] = $running; }
unset($r);

// Total count for pagination nav
$totalRows = 0; $rsCnt = mysqli_query($conn,"SELECT COUNT(*) FROM ledger_entries WHERE user_id=$userId AND account='WALLET'"); if($rsCnt){ $totalRows = (int)(mysqli_fetch_row($rsCnt)[0] ?? 0); }
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$currentSaldo = wallet_balance($conn,$userId);

require_once __DIR__.'/../../src/includes/header.php';
?>
<div class="wallet-page">
  <a class="back-link" href="invoice.php">&larr; Kembali</a>
  <h1 class="wallet-title">Riwayat Wallet</h1>
  <div class="panel wallet-balance">
    <h2>Saldo Saat Ini</h2>
    <div class="wallet-balance-amount">Rp <?= number_format($currentSaldo,0,',','.') ?></div>
    <div class="wallet-balance-meta">Halaman <?php echo $page; ?> / <?php echo $totalPages; ?>, <?php echo $perPage; ?> entri per halaman. Opening balance halaman ini: Rp <?php echo number_format($opening,0,',','.'); ?>.</div>
  </div>
  <div class="panel wallet-history">
    <h2>Riwayat Wallet</h2>
    <div class="table-wrap wallet-table-desktop">
      <table class="table table-compact">
        <thead><tr><th>ID</th><th>Tanggal</th><th>Debit</th><th>Credit</th><th>Saldo</th><th>Referensi</th><th>Catatan</th></tr></thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="7" style="text-align:center;font-size:12px;color:#777">Belum ada entri wallet.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars(date('d M Y H:i',strtotime((string)$r['created_at'])),ENT_QUOTES,'UTF-8') ?></td>
            <td><?php if($r['debit']>0): ?>Rp <?= number_format((float)$r['debit'],0,',','.') ?><?php else: ?>-<?php endif; ?></td>
            <td><?php if($r['credit']>0): ?>Rp <?= number_format((float)$r['credit'],0,',','.') ?><?php else: ?>-<?php endif; ?></td>
            <td>Rp <?= number_format((float)$r['running'],0,',','.') ?></td>
            <td style="font-size:11px">
              <?php if($r['ref_type']==='payment'): ?>Payment #<?= (int)$r['ref_id'] ?><?php elseif($r['ref_type']): ?><?= htmlspecialchars($r['ref_type'],ENT_QUOTES,'UTF-8') ?><?php else: ?>-<?php endif; ?>
            </td>
            <td style="font-size:11px;max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars((string)$r['note'],ENT_QUOTES,'UTF-8') ?>">
              <?= htmlspecialchars((string)$r['note'],ENT_QUOTES,'UTF-8') ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <!-- Mobile Card List -->
  <div class="wallet-mobile-list">
      <?php if(!$rows): ?>
        <div class="wallet-entry-mobile" style="text-align:center;color:#777;font-size:12px">Belum ada entri wallet.</div>
      <?php else: foreach($rows as $r): ?>
        <div class="wallet-entry-mobile">
          <div class="wm-id">#<?= (int)$r['id'] ?></div>
          <div class="wm-row"><span class="wm-label">Tanggal</span><span class="wm-value"><?= htmlspecialchars(date('d M Y H:i',strtotime((string)$r['created_at'])),ENT_QUOTES,'UTF-8') ?></span></div>
          <div class="wm-row"><span class="wm-label">Debit</span><span class="wm-value"><?php if($r['debit']>0): ?>Rp <?= number_format((float)$r['debit'],0,',','.') ?><?php else: ?>-<?php endif; ?></span></div>
          <div class="wm-row"><span class="wm-label">Kredit</span><span class="wm-value"><?php if($r['credit']>0): ?>Rp <?= number_format((float)$r['credit'],0,',','.') ?><?php else: ?>-<?php endif; ?></span></div>
          <div class="wm-row"><span class="wm-label">Saldo</span><span class="wm-value">Rp <?= number_format((float)$r['running'],0,',','.') ?></span></div>
          <div class="wm-ref">
            <?php if($r['ref_type']==='payment'): ?>Payment #<?= (int)$r['ref_id'] ?><?php elseif($r['ref_type']): ?><?= htmlspecialchars($r['ref_type'],ENT_QUOTES,'UTF-8') ?><?php else: ?>-<?php endif; ?>
          </div>
          <?php if(!empty($r['note'])): ?>
            <div class="wm-note" title="<?= htmlspecialchars((string)$r['note'],ENT_QUOTES,'UTF-8') ?>">
              <?= htmlspecialchars((string)$r['note'],ENT_QUOTES,'UTF-8') ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php if($totalPages>1): ?>
    <div class="page-nav" style="margin-top:14px">
      <?php if($page>1): ?><a class="btn-action outline" href="?p=<?php echo $page-1; ?>">&larr; Prev</a><?php endif; ?>
      <?php if($page < $totalPages): ?><a class="btn-action outline" href="?p=<?php echo $page+1; ?>">Next &rarr;</a><?php endif; ?>
      <span style="padding:6px 8px;color:#555">Goto:
        <?php for($i=max(1,$page-3);$i<=min($totalPages,$page+3);$i++): ?>
          <?php if($i===$page): ?><b><?php echo $i; ?></b><?php else: ?><a href="?p=<?php echo $i; ?>" style="text-decoration:none;margin:0 4px"><?php echo $i; ?></a><?php endif; ?>
        <?php endfor; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  const uid = <?php echo (int)$userId; ?>;
  function fmt(n){ return 'Rp '+Number(n||0).toLocaleString('id-ID'); }
  function applySaldo(v){
    const box = document.querySelector('h2 + div[style*="font-size:30px"]');
    if(box){ box.textContent = fmt(v); }
  }
  window.addEventListener('storage', function(ev){
    if(ev.key==='wallet_update' && ev.newValue){
      try{ const d=JSON.parse(ev.newValue); if(String(d.uid)===String(uid)){ applySaldo(d.saldo); } }catch(e){}
    }
  });
})();
</script>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
