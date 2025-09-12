<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

// ====== Deteksi / fallback kolom saldo (instalasi lama) ======
$hasSaldo=false; if($cS=mysqli_query($conn,"SHOW COLUMNS FROM users LIKE 'saldo'")){ if(mysqli_fetch_assoc($cS)) $hasSaldo=true; }
if(!$hasSaldo){
  @mysqli_query($conn,"ALTER TABLE users ADD COLUMN saldo DECIMAL(12,2) NOT NULL DEFAULT 0");
  if($cS2=mysqli_query($conn,"SHOW COLUMNS FROM users LIKE 'saldo'")){ if(mysqli_fetch_assoc($cS2)) $hasSaldo=true; }
}
// ekspresi saldo untuk SELECT
$saldoSelect = $hasSaldo ? 'u.saldo' : '0 AS saldo';

// Detect optional soft-delete column on transaksi
$hasSoftDelete = false;
if($colChk = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='transaksi' AND COLUMN_NAME='deleted_at' LIMIT 1")){
  if(mysqli_fetch_row($colChk)) $hasSoftDelete = true;
}
$softCond      = $hasSoftDelete ? " AND t.deleted_at IS NULL" : ""; // for main alias t
$softCond_t2   = $hasSoftDelete ? " AND t2.deleted_at IS NULL" : ""; // for alias t2

// ========= Filter & Pagination =========
$q        = trim($_GET['q'] ?? '');
$only_due = isset($_GET['due']) && $_GET['due'] === '1';
$page     = max(1, (int)($_GET['p'] ?? 1));
$perPage  = 50; // page size
$offset   = ($page-1)*$perPage;

// Build dynamic WHERE
$whereParts = ["u.role='wali_santri'"];
if ($q !== '') {
    $safe = '%'.mysqli_real_escape_string($conn,$q).'%';
    $whereParts[] = "(u.nama_santri LIKE '$safe' OR u.nama_wali LIKE '$safe')";
}
if ($only_due) {
  // Due jika ada legacy transaksi SPP pending ATAU invoice spp/daftar_ulang status pending/partial/overdue
  $whereParts[] = "(EXISTS (SELECT 1 FROM transaksi t2 WHERE t2.user_id=u.id AND t2.jenis_transaksi='spp' AND t2.status='menunggu_pembayaran' $softCond_t2) OR EXISTS (SELECT 1 FROM invoice i2 WHERE i2.user_id=u.id AND i2.type IN ('spp','daftar_ulang') AND i2.status IN ('pending','partial','overdue')))";
}
$whereSql = implode(' AND ',$whereParts);

// ========= Summary Metrics (respect current filters except due toggle for fairness) =========
$rowMeta = ['total'=>0,'sum_saldo'=>0.0,'due'=>0];
// Total semua wali (tanpa filter) untuk membantu debug jika kosong
$totalAllWali = 0; if($rAll = mysqli_query($conn,"SELECT COUNT(*) c FROM users u WHERE u.role='wali_santri'")){ $ra=mysqli_fetch_assoc($rAll); if($ra) $totalAllWali=(int)$ra['c']; }
if ($metaRes = mysqli_query($conn, "SELECT COUNT(*) total, COALESCE(SUM(".($hasSaldo? 'u.saldo':'0')."),0) sum_saldo FROM users u WHERE $whereSql")) {
    $rowMeta = array_merge($rowMeta, mysqli_fetch_assoc($metaRes) ?: []);
}
// Hitung jumlah user yang punya tagihan (SPP legacy atau invoice spp / daftar_ulang) belum bayar
$sqlDueUsers = "SELECT COUNT(DISTINCT x.uid) due FROM (".
  "SELECT t.user_id uid FROM transaksi t JOIN users u ON t.user_id=u.id WHERE t.jenis_transaksi='spp' AND t.status='menunggu_pembayaran' $softCond AND ".str_replace('u.', 'u.', $whereSql)." ".
  "UNION ALL ".
  "SELECT i.user_id uid FROM invoice i JOIN users u ON i.user_id=u.id WHERE i.type IN ('spp','daftar_ulang') AND i.status IN ('pending','partial','overdue') AND ".str_replace('u.', 'u.', $whereSql).
") x";
if($dueRes = mysqli_query($conn, $sqlDueUsers)){
  if($rDue = mysqli_fetch_assoc($dueRes)) $rowMeta['due'] = (int)$rDue['due'];
}
$totalUsersFiltered = (int)$rowMeta['total'];
$sumSaldo           = (float)$rowMeta['sum_saldo'];
$avgSaldo           = $totalUsersFiltered ? ($sumSaldo / $totalUsersFiltered) : 0;

// ========= Data Query =========
$items = [];
$sql = "SELECT u.id,u.nama_wali,u.nama_santri,u.nisn,$saldoSelect, (".
  "COALESCE((SELECT COUNT(*) FROM transaksi t WHERE t.user_id=u.id AND t.jenis_transaksi='spp' AND t.status='menunggu_pembayaran' $softCond),0) + ".
  "COALESCE((SELECT COUNT(*) FROM invoice inv WHERE inv.user_id=u.id AND inv.type IN ('spp','daftar_ulang') AND inv.status IN ('pending','partial','overdue')),0)".
  ") AS tagihan_belum\n        FROM users u\n        WHERE $whereSql\n        ORDER BY tagihan_belum DESC, u.nama_santri ASC\n        LIMIT $offset,$perPage";
$res = mysqli_query($conn,$sql);
$sqlError = null;
if($res===false){
  $sqlError = mysqli_error($conn);
} else {
  while($r=mysqli_fetch_assoc($res)) $items[]=$r;
}

// Total pages
$totalPages = max(1, (int)ceil($totalUsersFiltered / $perPage));

require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell pengguna-table-page">
  <div class="content-header">
    <h1>Pengguna</h1>
    <div class="quick-actions-inline">
      <a class="qa-btn" href="<?= url('admin/kelola-user'); ?>">+Tambah</a>
    </div>
  </div>

  <div class="inv-chips user-chips compact" aria-label="Ringkasan pengguna (berdasar filter)">
    <div class="inv-chip info"><span class="k">Total</span><span class="v"><?= number_format($totalUsersFiltered) ?></span></div>
    <div class="inv-chip ok"><span class="k">Saldo</span><span class="v">Rp <?= number_format($sumSaldo,0,',','.') ?></span></div>
    <div class="inv-chip warn"><span class="k">Tagihan Belum</span><span class="v"><?= number_format($rowMeta['due']) ?></span></div>
    <div class="inv-chip"><span class="k">Rata2 Saldo</span><span class="v">Rp <?= number_format($avgSaldo,0,',','.') ?></span></div>
    <a class="btn-action small" href="?<?= http_build_query(array_merge($_GET,['due'=>$only_due?0:1,'p'=>1])) ?>">Due: <?= $only_due? 'ON':'OFF' ?></a>
  </div>

  <form method="get" class="user-filter table-style" autocomplete="off">
    <div class="grp">
      <label for="fQ">Cari</label>
      <input type="text" id="fQ" name="q" value="<?= e($q) ?>" placeholder="Nama wali / santri" />
    </div>
    <?php if($only_due): ?><input type="hidden" name="due" value="1"><?php endif; ?>
    <div class="grp actions"><button class="btn-action primary">Cari</button><a class="btn-action" href="pengguna.php">Reset</a></div>
  </form>

  <div class="table-scroll-wrap">
    <table class="pengguna-table" aria-describedby="descPengguna">
      <thead>
        <tr>
          <th>#</th>
          <th>Santri & Wali</th>
          <th>NIS</th>
          <th>Saldo</th>
          <th>Tagihan</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if(isset($sqlError) && $sqlError): ?>
          <tr><td colspan="6" class="empty error">Query error: <?= e($sqlError) ?><div class="sql">SQL: <code><?= e($sql) ?></code></div></td></tr>
        <?php elseif(empty($items)): ?>
          <tr class="no-data"><td colspan="6"><div class="no-data-box">Belum ada pengguna yang cocok.</div></td></tr>
  <?php else: $rowNum = $offset + 1; foreach($items as $it): $due = (int)$it['tagihan_belum']; $saldo=(float)$it['saldo']; ?>
          <tr class="user-row <?= $due>0? 'has-due':'' ?>">
            <td data-th="#" class="row-num"><?= $rowNum++ ?></td>
            <td data-th="Santri & Wali" class="col-santri">
              <div class="sn-main"><a class="row-link" href="<?= url('admin/pengguna-detail?id='.(int)$it['id']); ?>" title="Detail pengguna"><?= e($it['nama_santri']); ?></a><?php if($due>0): ?><span class="chip due-mini" title="SPP belum"><?= $due ?></span><?php endif; ?></div>
              <div class="wali-sub">Wali: <span><?= e($it['nama_wali']); ?></span></div>
            </td>
            <td data-th="NIS" class="col-nisn"><code><?= e($it['nisn']); ?></code></td>
            <td data-th="Saldo" class="text-end col-saldo">
              <span class="chip saldo <?= $saldo<=0? 'zero':'' ?>">Rp <?= number_format($saldo,0,',','.') ?></span>
            </td>
            <td data-th="Tagihan" class="text-center col-spp">
              <?php if($due>0): ?><span class="chip due" title="Tagihan belum lunas (SPP / Daftar Ulang)"><?= $due ?> belum</span><?php else: ?><span class="chip ok">Lunas</span><?php endif; ?>
            </td>
            <td data-th="Aksi" class="col-aksi"><a class="mini-btn" href="<?= url('admin/pengguna-detail?id='.(int)$it['id']); ?>">Detail</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if($totalPages>1): ?>
    <nav class="pager" aria-label="Pagination">
      <?php
        $baseParams = $_GET; unset($baseParams['p']);
        $build = function($p) use ($baseParams){ return '?'.http_build_query(array_merge($baseParams,['p'=>$p])); };
      ?>
      <a class="pg-btn" href="<?= $build(max(1,$page-1)) ?>" aria-label="Prev" <?= $page==1?'aria-disabled="true"':'' ?>>&laquo;</a>
      <?php
        $window = 3;
        $start = max(1,$page-$window); $end = min($totalPages,$page+$window);
        if($start>1){ echo '<span class="pg-ellipsis">…</span>'; }
        for($i=$start;$i<=$end;$i++){
          echo '<a class="pg-btn '.($i==$page?'active':'').'" href="'.$build($i).'">'.$i.'</a>';
        }
        if($end<$totalPages){ echo '<span class="pg-ellipsis">…</span>'; }
      ?>
      <a class="pg-btn" href="<?= $build(min($totalPages,$page+1)) ?>" aria-label="Next" <?= $page==$totalPages?'aria-disabled="true"':'' ?>>&raquo;</a>
    </nav>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
