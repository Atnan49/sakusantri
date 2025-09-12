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
    <div class="table-wrap invoice-table-desktop">
      <table class="table table-compact" style="min-width:680px">
        <thead><tr><th>ID</th><th>Jenis</th><th>Periode</th><th>Nominal</th><th>Dibayar</th><th>Status</th><th>Jatuh Tempo</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8" style="text-align:center;font-size:13px;color:#777">Belum ada tagihan.</td></tr>
        <?php else: foreach($rows as $inv): ?>
          <tr>
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
        <div class="invoice-entry-mobile">
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
