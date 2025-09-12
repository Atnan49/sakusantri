<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';

$msg=$err=null; $filter_type = $_GET['type'] ?? 'all';
$allowedTypes=['spp','daftar_ulang','all']; if(!in_array($filter_type,$allowedTypes,true)) $filter_type='all';
// Default period kosong (tahun & bulan '--') untuk menampilkan semua
$period = array_key_exists('period',$_GET)? $_GET['period'] : '';
$filter_status = $_GET['status'] ?? '';
// Normalisasi period sesuai jenis
if($filter_type==='daftar_ulang'){
  // Hanya gunakan 4 digit tahun; selain itu kosongkan supaya tampil semua daftar ulang
  if(!preg_match('/^[0-9]{4}$/',$period)) $period='';
} elseif($filter_type==='spp'){
  // Pastikan format YYYYMM atau kosong
  if($period!=='' && !preg_match('/^[0-9]{6}$/',$period)) $period='';
} else { // all
  // Terima YYYYMM (SPP) atau kosong; jika user kirim YYYY treat sebagai tahun untuk daftar ulang+SPP nanti logic conds handle
  if($period!=='' && !preg_match('/^[0-9]{4}([0-9]{2})?$/',$period)) $period='';
}
// Override: jika datang dari link lama (period=current month) & type=all, jadikan kosong agar tampil semua
if($filter_type==='all' && isset($_GET['period']) && $_GET['period']===date('Ym') && !isset($_GET['force_period'])){
  $period='';
}
// Fitur generate SPP langsung dari halaman invoice telah dihapus.

// Ambil daftar invoice (support filter jenis)
$params=[]; $types=''; $conds=[];
$year = strlen($period)>=4 ? substr($period,0,4):'';
if($filter_type==='spp'){
  $conds[]="i.type='spp'"; if($period && strlen($period)==6){ $conds[]='i.period=?'; $params[]=$period; $types.='s'; }
} elseif($filter_type==='daftar_ulang'){
  $conds[]="i.type='daftar_ulang'"; if($year){ $conds[]='i.period=?'; $params[]=$year; $types.='s'; }
} else { // all
  if($period && strlen($period)==6){
    // Kedua jenis sekarang memakai format YYYYMM yang sama
    $conds[] = "((i.type='spp' AND i.period=?) OR (i.type='daftar_ulang' AND i.period=?))"; $params[]=$period; $types.='s'; $params[]=$period; $types.='s';
  }
}
if($filter_status){ $conds[]='i.status=?'; $params[]=$filter_status; $types.='s'; }
$where = $conds? implode(' AND ',$conds):'1=1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100; $offset = ($page-1)*$perPage; if($offset>5000) $offset=5000; // hard cap
$sql = "SELECT i.*, u.nama_santri FROM invoice i JOIN users u ON i.user_id=u.id WHERE $where ORDER BY i.id DESC LIMIT $perPage OFFSET $offset";
$total = 0; $countSql = "SELECT COUNT(*) c FROM invoice i WHERE $where"; if($cstmt = mysqli_prepare($conn,$countSql)){ if($params){ mysqli_stmt_bind_param($cstmt,$types,...$params); } mysqli_stmt_execute($cstmt); $cr = mysqli_stmt_get_result($cstmt); if($cr && ($crow=mysqli_fetch_assoc($cr))) $total=(int)$crow['c']; }
$totalPages = max(1, (int)ceil($total / $perPage)); if($page>$totalPages) $page=$totalPages;
$rows=[];
if($stmt = mysqli_prepare($conn,$sql)){
  if($params){ mysqli_stmt_bind_param($stmt,$types,...$params); }
  mysqli_stmt_execute($stmt); $r = mysqli_stmt_get_result($stmt); while($r && $row=mysqli_fetch_assoc($r)){ $rows[]=$row; }
}

// Status distribution (sesuaikan scope)
$distCounts = ['pending'=>0,'partial'=>0,'paid'=>0,'overdue'=>0,'canceled'=>0];
$outstandingTotal = 0; $baseParams=[]; $baseTypes=''; $baseConds=[];
if($filter_type==='spp'){
  $baseConds[]="type='spp'"; if($period && strlen($period)==6){ $baseConds[]='period=?'; $baseParams[]=$period; $baseTypes.='s'; }
} elseif($filter_type==='daftar_ulang'){
  $baseConds[]="type='daftar_ulang'"; if($year){ $baseConds[]='period=?'; $baseParams[]=$year; $baseTypes.='s'; }
} else {
  if($period && strlen($period)==6){ $baseConds[]="((type='spp' AND period=?) OR (type='daftar_ulang' AND period=?))"; $baseParams[]=$period; $baseTypes.='s'; $baseParams[]=$period; $baseTypes.='s'; }
}
$baseWhere = $baseConds? implode(' AND ',$baseConds):'1=1';
$sqlDist = "SELECT status, COUNT(*) c, SUM(amount-paid_amount) os FROM invoice WHERE $baseWhere GROUP BY status";
if($dstmt = mysqli_prepare($conn,$sqlDist)){
  if($baseParams){ mysqli_stmt_bind_param($dstmt,$baseTypes,...$baseParams); }
  mysqli_stmt_execute($dstmt); $dr = mysqli_stmt_get_result($dstmt);
  while($dr && $drow = mysqli_fetch_assoc($dr)){
    $st = $drow['status']; if(isset($distCounts[$st])){ $distCounts[$st] = (int)$drow['c']; }
    if(in_array($st,['pending','partial','overdue'])){ $outstandingTotal += (float)($drow['os'] ?? 0); }
  }
}
$totalAll = array_sum($distCounts) ?: 1;
// Diagnostic: count invoices per type to aid troubleshooting filter
$typeCounts=['spp'=>0,'daftar_ulang'=>0];
$resType = mysqli_query($conn,"SELECT type, COUNT(*) c FROM invoice GROUP BY type");
while($resType && ($tr=mysqli_fetch_assoc($resType))){ if(isset($typeCounts[$tr['type']])) $typeCounts[$tr['type']] = (int)$tr['c']; }
require_once BASE_PATH.'/src/includes/status_helpers.php';
require_once BASE_PATH.'/src/includes/header.php';
?>
<div class="page-shell invoice-page">
  <div class="content-header">
  <h1>Tagihan</h1>
  <div class="quick-actions-inline">
    <a class="qa-btn" href="invoice_overdue_run.php" title="Tandai Terlambat">Tandai Terlambat</a>
  <a class="qa-btn" href="generate_spp.php" title="Buat Tagihan">Buat Tagihan</a>
    </div>
  </div>
  <?php if($msg): ?><div class="alert success" role="alert"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>
  <div class="small muted" style="margin:-8px 0 10px 0; font-size:12px">Diag: SPP <?= $typeCounts['spp'] ?> | Daftar Ulang <?= $typeCounts['daftar_ulang'] ?></div>

  <div class="inv-chips" aria-label="Ringkasan status periode">
    <div class="inv-chip warn" title="Belum Dibayar (Pending + Partial)"><span class="k">Belum</span><span class="v"><?= number_format($distCounts['pending'] + $distCounts['partial']); ?></span></div>
  <div class="inv-chip danger" title="Terlambat"><span class="k">Terlambat</span><span class="v"><?= number_format($distCounts['overdue']); ?></span></div>
    <div class="inv-chip ok" title="Lunas"><span class="k">Lunas</span><span class="v"><?= number_format($distCounts['paid']); ?></span></div>
    <div class="inv-chip mute" title="Batal"><span class="k">Batal</span><span class="v"><?= number_format($distCounts['canceled']); ?></span></div>
  <div class="inv-chip info" title="Tunggakan"><span class="k">Tunggakan</span><span class="v">Rp <?= number_format($outstandingTotal,0,',','.'); ?></span></div>
    <!-- Tombol Generate SPP dihapus -->
  </div>

  <!-- Modal generate SPP dihapus -->

  <div class="panel section invoice-list">
  <div class="panel-header"><h2>Daftar Tagihan</h2></div>
    <?php 
      $fYear = substr($period,0,4); $fMonth = substr($period,4,2); 
      // Ambil distinct tahun dari invoice agar dropdown ringkas
      $yearOptions=[]; $yrRes=mysqli_query($conn,"SELECT DISTINCT LEFT(period,4) y FROM invoice WHERE period IS NOT NULL AND period<>'' ORDER BY y DESC LIMIT 20");
      while($yrRes && ($yr=mysqli_fetch_row($yrRes))) { if(preg_match('/^[0-9]{4}$/',$yr[0])) $yearOptions[]=$yr[0]; }
      if(!$yearOptions){ // fallback jika belum ada data
        $nowYear=(int)date('Y'); for($y=$nowYear-1;$y<=$nowYear+3;$y++){ $yearOptions[]=$y; }
        rsort($yearOptions); // terbaru dulu
      }
    ?>
  <form method="get" class="inv-filter" autocomplete="off" id="filterInvoiceForm">
      <div class="grp period">
        <label>Periode</label>
        <div class="period-dual small">
          <select id="fYear" aria-label="Tahun">
            <option value="">--</option>
            <?php foreach($yearOptions as $y): ?>
              <option value="<?= e($y) ?>" <?php if($fYear===(string)$y) echo 'selected'; ?>><?= e($y) ?></option>
            <?php endforeach; ?>
          </select>
      <select id="fMonth" aria-label="Bulan" data-role="month-select">
            <option value="">--</option>
            <?php for($m=1;$m<=12;$m++): $mm=str_pad((string)$m,2,'0',STR_PAD_LEFT); ?>
              <option value="<?= $mm ?>" <?php if($mm===$fMonth) echo 'selected'; ?>><?= $mm ?></option>
            <?php endfor; ?>
          </select>
        </div>
  <input type="hidden" id="fPeriodHidden" name="period" value="<?= e($period) ?>">
      </div>
      <div class="grp type">
        <label for="fType">Jenis</label>
        <select id="fType" name="type">
          <option value="spp" <?= $filter_type==='spp'?'selected':''; ?>>SPP</option>
          <option value="daftar_ulang" <?= $filter_type==='daftar_ulang'?'selected':''; ?>>Daftar Ulang</option>
          <option value="all" <?= $filter_type==='all'?'selected':''; ?>>Semua</option>
        </select>
      </div>
      <div class="grp status">
        <label for="fStatus">Status</label>
        <select id="fStatus" name="status">
          <option value="">Semua</option>
          <?php foreach(['pending','partial','paid','overdue','canceled'] as $st): ?>
            <option value="<?= $st ?>" <?php if($filter_status===$st) echo 'selected'; ?>><?= e(t_status_invoice($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grp actions">
  <button class="btn-action outline">Saring</button>
  <a href="?type=<?= urlencode($filter_type) ?>" class="btn-action" title="Reset">Atur Ulang</a>
      </div>
    </form>
    <div class="table-wrap">
  <table class="table table-compact invoice-table slim" style="min-width:780px" aria-describedby="invCaption">
        <caption id="invCaption" style="position:absolute;left:-9999px;top:-9999px;">Daftar invoice</caption>
        <thead><tr>
          <th>ID</th>
          <th>Santri</th>
          <th>Jenis</th>
          <th>Periode</th>
          <th class="num">Nominal</th>
            <th class="num">Dibayar</th>
          <th>Status</th>
          <th>Jatuh Tempo</th>
          <th>Aksi</th>
        </tr></thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="9" style="text-align:center;font-size:13px;color:#777">
            <?php if($filter_type==='daftar_ulang' && $typeCounts['daftar_ulang']===0): ?>
              Belum ada invoice Daftar Ulang. Gunakan tombol Generate Tagihan & pilih Daftar Ulang.
            <?php else: ?>Belum ada invoice.<?php endif; ?>
          </td></tr>
        <?php else: foreach($rows as $inv): ?>
          <?php $amt=(float)$inv['amount']; $paid=(float)$inv['paid_amount']; $ratio=$amt>0?min(1,$paid/$amt):0; $pct=round($ratio*100,1); ?>
          <tr class="st-<?= e(str_replace('_','-',$inv['status'])) ?>">
            <td>#<?= (int)$inv['id'] ?></td>
            <td><?= e($inv['nama_santri']) ?></td>
            <td><?= e(strtoupper(str_replace('_',' ',$inv['type']))) ?></td>
            <td><?= e($inv['period']) ?></td>
            <td class="num">Rp <?= number_format($amt,0,',','.') ?></td>
            <td class="num">Rp <?= number_format($paid,0,',','.') ?><?php if($paid>0 && $paid<$amt) echo ' <span class="pct">('.$pct.'%)</span>'; ?></td>
            <td><span class="status-<?= e(str_replace('_','-',$inv['status'])) ?>"><?= e(t_status_invoice($inv['status'])) ?></span></td>
            <td><?= $inv['due_date'] ?></td>
            <td><a class="btn-detail" href="invoice_detail.php?id=<?= (int)$inv['id'] ?>">Detail</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($totalPages>1): ?>
      <nav class="page-nav" aria-label="Navigasi halaman">
        <span class="current">Hal <?= $page ?>/<?= $totalPages ?></span>
        <?php if($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" aria-label="Halaman sebelumnya">&larr;</a><?php endif; ?>
        <?php if($page<$totalPages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" aria-label="Halaman berikutnya">&rarr;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</div>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
(function(){
  // Hanya logika filter periode (fitur generate SPP dihapus)
  const filterForm=document.getElementById('filterInvoiceForm'); if(filterForm){
    const fy=document.getElementById('fYear'); const fm=document.getElementById('fMonth'); const hidden=document.getElementById('fPeriodHidden');
    const fType=document.getElementById('fType');
    function rebuild(){
      const y=(fy.value||'').trim(); const m=(fm.value||'').trim(); const t=fType?fType.value:'spp';
      if(t==='daftar_ulang'){
        hidden.value = y || '';
      } else if(t==='spp'){
        hidden.value = (y && m)? (y+m): '';
      } else { // all
        hidden.value = (y && m)? (y+m): '';
      }
      toggleMonth();
    }
    function toggleMonth(){ const t=fType?fType.value:'spp'; if(t==='daftar_ulang'){ fm.parentElement.style.display='none'; } else { fm.parentElement.style.display=''; } }
    fy?.addEventListener('change',rebuild); fm?.addEventListener('change',rebuild); fType?.addEventListener('change',rebuild);
    rebuild();
  }
})();
</script>
