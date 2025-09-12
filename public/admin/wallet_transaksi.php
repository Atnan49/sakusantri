<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('admin');

$page = max(1,(int)($_GET['page']??1));
$perPage = 50; $offset = ($page-1)*$perPage;
$userQ = trim($_GET['q']??'');
$type = $_GET['type'] ?? ''; // in|out
$ref = trim($_GET['ref']??'');
$where = "le.account='WALLET'"; $params=[]; $types='';
if($userQ!==''){ $where .= " AND (u.nama_wali LIKE ? OR u.nama_santri LIKE ? OR u.id=?)"; $like='%'.$userQ.'%'; $params[]=$like; $types.='s'; $params[]=$like; $types.='s'; $params[]=(int)$userQ; $types.='i'; }
if($type==='in'){ $where.=" AND le.debit>0"; }
elseif($type==='out'){ $where.=" AND le.credit>0"; }
if($ref!==''){ $where.=' AND le.ref_type=?'; $params[]=$ref; $types.='s'; }

$total=0; $sqlCnt = "SELECT COUNT(*) c FROM ledger_entries le JOIN users u ON u.id=le.user_id WHERE $where";
if($st = mysqli_prepare($conn,$sqlCnt)){ if($params){ mysqli_stmt_bind_param($st,$types,...$params);} mysqli_stmt_execute($st); $rs=mysqli_stmt_get_result($st); $total=(int)(mysqli_fetch_assoc($rs)['c']??0);} 

$sql = "SELECT le.id,le.user_id,u.nama_wali,u.nama_santri,le.debit,le.credit,le.note,le.ref_type,le.ref_id,le.created_at
        FROM ledger_entries le JOIN users u ON u.id=le.user_id
        WHERE $where ORDER BY le.id DESC LIMIT $perPage OFFSET $offset";
$rows=[]; if($st = mysqli_prepare($conn,$sql)){ if($params){ mysqli_stmt_bind_param($st,$types,...$params);} mysqli_stmt_execute($st); $rs=mysqli_stmt_get_result($st); while($rs && $r=mysqli_fetch_assoc($rs)) $rows[]=$r; }
$pages = $total? (int)ceil($total/$perPage):1;
require_once __DIR__.'/../../src/includes/header.php';
?>
<div class="page-shell wallet-trans-page">
  <div class="content-header">
    <h1>Rincian Transaksi Wallet</h1>
    <div class="quick-actions-inline">
      <a class="qa-btn" href="wallet_topups.php">Top-Up</a>
    </div>
  </div>
  <form class="wallet-filter" method="get">
    <div class="grp"><label>Cari Wali/Santri/ID</label><input type="text" name="q" value="<?= e($userQ) ?>" placeholder="Nama atau ID" /></div>
    <div class="grp"><label>Jenis</label><select name="type">
      <option value="">Semua</option>
      <option value="in" <?= $type==='in'?'selected':'' ?>>Masuk</option>
      <option value="out" <?= $type==='out'?'selected':'' ?>>Keluar</option>
    </select></div>
    <div class="grp"><label>Ref Type</label><input type="text" name="ref" value="<?= e($ref) ?>" placeholder="payment/purchase" /></div>
    <div class="grp actions"><button class="btn-action primary">Filter</button><a class="btn-action" href="wallet_transaksi.php">Reset</a></div>
  </form>
  <div class="panel">
    <div class="table-wrap">
      <table class="table wallet-ledger-table" style="min-width:960px">
        <thead><tr><th>ID</th><th>Santri</th><th>Wali</th><th class="num">Masuk</th><th class="num">Keluar</th><th>Ref</th><th>Catatan</th><th>Waktu</th></tr></thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8" style="text-align:center;font-size:12px;color:#777">Tidak ada data.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= e($r['nama_santri']) ?></td>
            <td><?= e($r['nama_wali']) ?></td>
            <td class="num in"><?= $r['debit']>0? 'Rp '.number_format($r['debit'],0,',','.'):'' ?></td>
            <td class="num out"><?= $r['credit']>0? 'Rp '.number_format($r['credit'],0,',','.'):'' ?></td>
            <td><?= e($r['ref_type'].($r['ref_id']?'#'.$r['ref_id']:'')) ?></td>
            <td><?= e($r['note']) ?></td>
            <td><?= date('d M Y H:i',strtotime($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($pages>1): ?>
      <div class="pagination small">
        <?php for($i=1;$i<=$pages;$i++): $qs=http_build_query(array_merge($_GET,['page'=>$i])); ?>
          <a class="pg<?= $i===$page?' active':'' ?>" href="?<?= e($qs) ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
