<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

$years=[]; $yrRes=mysqli_query($conn,"SELECT DISTINCT LEFT(period,4) y FROM invoice WHERE period IS NOT NULL AND period<>'' ORDER BY y DESC LIMIT 20");
while($yrRes && ($yr=mysqli_fetch_row($yrRes))){ if(preg_match('/^[0-9]{4}$/',$yr[0])) $years[]=$yr[0]; }
if(!$years){ $nowYear=(int)date('Y'); for($y=$nowYear-1;$y<=$nowYear+3;$y++){ $years[]=$y; } rsort($years); }

$scope = $_GET['scope'] ?? 'monthly';
$type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? '';
$yearSel = $_GET['year'] ?? (string)date('Y');
$monthSel = $_GET['month'] ?? (string)date('m');

require_once BASE_PATH.'/src/includes/header.php';
?>
<div class="page-shell">
  <div class="content-header">
    <h1>Laporan Tagihan (PDF)</h1>
  </div>
  <div class="panel">
    <div class="panel-header"><h2>Pilih Periode</h2></div>
    <form method="get" action="report_download.php" class="inv-filter" autocomplete="off">
      <div class="grp">
        <label for="scope">Jenis Periode</label>
        <select id="scope" name="scope">
          <option value="monthly" <?= $scope==='monthly'?'selected':''; ?>>Bulanan</option>
          <option value="yearly" <?= $scope==='yearly'?'selected':''; ?>>Tahunan</option>
        </select>
      </div>
      <div class="grp">
        <label for="year">Tahun</label>
        <select id="year" name="year">
          <?php foreach($years as $y): ?>
            <option value="<?= e($y) ?>" <?= ((string)$y===(string)$yearSel)?'selected':''; ?>><?= e($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grp" id="monthWrap">
        <label for="month">Bulan</label>
        <select id="month" name="month">
          <?php for($m=1;$m<=12;$m++): $mm=str_pad((string)$m,2,'0',STR_PAD_LEFT); ?>
            <option value="<?= $mm ?>" <?= ($mm===(string)$monthSel)?'selected':''; ?>><?= $mm ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="grp">
        <label for="type">Jenis Tagihan</label>
        <select id="type" name="type">
          <option value="all" <?= $type==='all'?'selected':''; ?>>Semua</option>
          <option value="spp" <?= $type==='spp'?'selected':''; ?>>SPP</option>
          <option value="daftar_ulang" <?= $type==='daftar_ulang'?'selected':''; ?>>Daftar Ulang</option>
        </select>
      </div>
      <div class="grp">
        <label for="status">Status</label>
        <select id="status" name="status">
          <option value="" <?= $status===''?'selected':''; ?>>Semua</option>
          <?php foreach(['pending','partial','paid','overdue','canceled'] as $st): ?>
            <option value="<?= $st ?>" <?= $status===$st?'selected':''; ?>><?= e($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grp actions">
        <button class="btn-action primary" type="submit">Unduh PDF</button>
      </div>
    </form>
  </div>
</div>
<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
(function(){
  const scope=document.getElementById('scope');
  const monthWrap=document.getElementById('monthWrap');
  function toggle(){ monthWrap.style.display = scope.value==='monthly' ? '' : 'none'; }
  scope?.addEventListener('change',toggle); toggle();
})();
</script>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
