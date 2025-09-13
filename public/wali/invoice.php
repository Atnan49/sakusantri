<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
// Ambil daftar periode unik untuk dropdown

$bulan = $_GET['bulan'] ?? '';
$tahun = $_GET['tahun'] ?? '';
// Daftar tahun unik (semua jenis tagihan)
$years = [];
$q = mysqli_query($conn, "SELECT DISTINCT LEFT(period,4) y FROM invoice WHERE user_id=$uid ORDER BY y DESC");
while($q && ($row = mysqli_fetch_row($q))) $years[] = $row[0];

// Bangun kondisi dinamis: tampilkan SEMUA tagihan (semua type & status)
$conds = ['user_id=?']; $params=[$uid]; $types='i';
if($tahun !== '' && preg_match('/^[0-9]{4}$/',$tahun)) { $conds[]='LEFT(period,4)=?'; $params[]=$tahun; $types.='s'; }
if($bulan !== '' && ctype_digit($bulan)) {
  $bulanDig = str_pad((int)$bulan,2,'0',STR_PAD_LEFT);
  // bulan hanya relevan utk period format YYYYMM (panjang 6)
  $conds[] = '(CHAR_LENGTH(period)=6 AND SUBSTR(period,5,2)=?)';
  $params[] = $bulanDig; $types.='s';
}
$where = implode(' AND ',$conds);
$page = max(1,(int)($_GET['page'] ?? 1)); $perPage=100; $offset=($page-1)*$perPage; if($offset>5000) $offset=5000;
$order = 'ORDER BY id DESC';
$sql = "SELECT * FROM invoice WHERE $where $order LIMIT $perPage OFFSET $offset";
$total=0; $csql="SELECT COUNT(*) c FROM invoice WHERE $where"; if($cstmt=mysqli_prepare($conn,$csql)){ mysqli_stmt_bind_param($cstmt,$types,...$params); mysqli_stmt_execute($cstmt); $cr=mysqli_stmt_get_result($cstmt); if($cr && ($crow=mysqli_fetch_assoc($cr))) $total=(int)$crow['c']; }
$totalPages = max(1,(int)ceil($total/$perPage)); if($page>$totalPages) $page=$totalPages;
$rows=[]; if($stmt=mysqli_prepare($conn,$sql)){ mysqli_stmt_bind_param($stmt,$types,...$params); mysqli_stmt_execute($stmt); $r=mysqli_stmt_get_result($stmt); while($r && $row=mysqli_fetch_assoc($r)) $rows[]=$row; }
require_once BASE_PATH.'/src/includes/header.php';
?>
<div class="page-shell">
  <div class="content-header">
  <h1>Semua Tagihan</h1>
    <div class="actions"><a href="kirim_saku.php" class="btn-action outline" style="text-decoration:none">Top-Up Wallet</a></div>
  </div>
  <?php if(isset($_GET['msg']) && $_GET['msg']!==''): ?>
    <div class="panel section" style="background:#f0f7f0;border-color:#dfe6db"><div class="alert success" style="margin:0"><?= e($_GET['msg']) ?></div></div>
  <?php endif; ?>
  <div class="panel section">
    <h2>Filter</h2>
    <form method="get" class="filter-inline">
      <div class="field">
        <label>Bulan</label>
        <select name="bulan" style="min-width:80px">
          <option value="">Bulan</option>
          <?php for($b=1;$b<=12;$b++): ?>
            <option value="<?= $b ?>"<?= ($bulan==$b)?' selected':'' ?>><?= str_pad($b,2,'0',STR_PAD_LEFT) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field">
        <label>Tahun</label>
        <select name="tahun" style="min-width:90px">
          <option value="">Tahun</option>
          <?php foreach($years as $y): ?>
            <option value="<?= e($y) ?>"<?= ($tahun==$y)?' selected':'' ?>><?= e($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn-action outline">Terapkan</button>
    </form>
  </div>
  <div class="panel section">
    <h2>Daftar Tagihan</h2>
    <form id="bulkForm" method="post" action="invoice_bulk_upload.php">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
      <div class="table-wrap invoice-table-desktop">
        <table class="table table-compact" style="min-width:760px">
          <thead>
            <tr>
              <th class="bulk-col" style="display:none;width:36px"><input type="checkbox" id="chkAll" aria-label="Pilih semua" /></th>
              <th>ID</th><th>Jenis</th><th>Periode</th><th>Nominal</th><th>Dibayar</th><th>Status</th><th>Jatuh Tempo</th><th>Aksi</th>
            </tr>
          </thead>
          <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="9" style="text-align:center;font-size:13px;color:#777">Belum ada tagihan.</td></tr>
        <?php else: foreach($rows as $inv): ?>
          <?php 
            $remaining = max(0, (float)$inv['amount'] - (float)$inv['paid_amount']);
            $eligible = ($remaining > 0) && !in_array($inv['status'], ['canceled','paid'], true);
          ?>
          <tr>
            <td class="bulk-col" style="display:none;text-align:center">
              <input type="checkbox" class="chkOne" name="invoice_ids[]" value="<?= (int)$inv['id'] ?>" data-remaining="<?= (int)$remaining ?>" <?= $eligible? '' : 'disabled' ?> />
            </td>
            <td>#<?= (int)$inv['id'] ?></td>
            <td><?= e(strtoupper(str_replace('_',' ',$inv['type']))) ?></td>
            <td><?= e($inv['period']) ?></td>
            <td>Rp <?= number_format($inv['amount'],0,',','.') ?></td>
            <td>Rp <?= number_format($inv['paid_amount'],0,',','.') ?></td>
            <td><span class="status-<?= e(str_replace('_','-',$inv['status'])) ?><?= $inv['status']==='overdue'?' warn':'' ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
            <td><?= e($inv['due_date']) ?></td>
            <td>
                <a href="invoice_detail.php?id=<?= (int)$inv['id'] ?>" class="btn-detail">Bayar</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

    <!-- Mobile Card List -->
    <div class="invoice-mobile-list">
      <?php if(!$rows): ?>
        <div class="invoice-entry-mobile" style="text-align:center;color:#777;font-size:12px">Belum ada tagihan.</div>
      <?php else: foreach($rows as $inv): ?>
        <?php 
          $remaining = max(0, (float)$inv['amount'] - (float)$inv['paid_amount']);
          $eligible = ($remaining > 0) && !in_array($inv['status'], ['canceled','paid'], true);
        ?>
        <div class="invoice-entry-mobile">
          <div class="im-bulk" style="display:none">
            <label style="display:flex;gap:8px;align-items:center;font-size:13px">
              <input type="checkbox" class="chkOne" name="invoice_ids[]" value="<?= (int)$inv['id'] ?>" data-remaining="<?= (int)$remaining ?>" <?= $eligible? '' : 'disabled' ?> />
              <span>Pilih tagihan ini</span>
            </label>
          </div>
          <div class="im-id">#<?= (int)$inv['id'] ?></div>
          <div class="im-row">
            <span class="im-label">Jenis</span>
            <span class="im-value"><?= e(strtoupper(str_replace('_',' ',$inv['type']))) ?></span>
          </div>
          <div class="im-row">
            <span class="im-label">Status</span>
            <span class="im-status status-<?= e(str_replace('_','-',$inv['status'])) ?><?= $inv['status']==='overdue'?' warn':'' ?>"><?= e(ucfirst($inv['status'])) ?></span>
          </div>
          <div class="im-row">
            <span class="im-label">Nominal</span>
            <span class="im-value">Rp <?= number_format($inv['amount'],0,',','.') ?></span>
          </div>
          <div class="im-row">
            <span class="im-label">Dibayar</span>
            <span class="im-value">Rp <?= number_format($inv['paid_amount'],0,',','.') ?></span>
          </div>
          <div class="im-row">
            <span class="im-label">Periode</span>
            <span class="im-value"><?= e($inv['period']) ?></span>
          </div>
          <div class="im-row">
            <span class="im-label">Jatuh Tempo</span>
            <span class="im-value"><?= e($inv['due_date']) ?></span>
          </div>
          <div class="im-action">
              <a href="invoice_detail.php?id=<?= (int)$inv['id'] ?>" class="btn-detail">Bayar Tagihan</a>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    
    <!-- Bulk action bar / controls -->
    <div class="bulk-controls" style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
      <div>
        <button type="button" id="btnBulkToggle" class="btn-action outline">Bayar Sekaligus</button>
      </div>
      <div id="bulkBar" style="display:none;position:sticky;bottom:8px;background:#f6f8f6;border:1px solid #dfe6db;border-radius:10px;padding:10px 12px;box-shadow:0 1px 2px rgba(0,0,0,.04)">
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
          <div style="font-size:13px;color:#333">
            Dipilih: <b><span id="bulkCount">0</span></b> tagihan · Total: <b>Rp <span id="bulkTotal">0</span></b>
          </div>
          <div style="display:flex;gap:8px">
            <button type="button" id="btnBulkCancel" class="btn-action">Batal</button>
            <button type="submit" id="btnBulkPay" class="btn-action primary" disabled>Bayar</button>
          </div>
        </div>
      </div>
    </div>
    </form>
    <?php if($totalPages>1): ?>
      <nav class="page-nav" aria-label="Navigasi halaman">
        <span class="current">Hal <?= $page ?>/<?= $totalPages ?></span>
        <?php if($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" aria-label="Halaman sebelumnya">&larr;</a><?php endif; ?>
        <?php if($page<$totalPages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" aria-label="Halaman berikutnya">&rarr;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</div>
<script src="../assets/js/invoice_bulk.js?v=20250913a" defer></script>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
