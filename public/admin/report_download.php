<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

// Basic params: scope=monthly|yearly, year=YYYY, month=1-12 (optional)
$scope = $_GET['scope'] ?? 'monthly';
$year = isset($_GET['year']) && preg_match('/^[0-9]{4}$/', $_GET['year']) ? $_GET['year'] : date('Y');
$month = isset($_GET['month']) && ctype_digit($_GET['month']) ? str_pad((int)$_GET['month'],2,'0',STR_PAD_LEFT) : '';
$type = $_GET['type'] ?? 'all'; // spp|daftar_ulang|all
$status = $_GET['status'] ?? ''; // optional filter

// Validate scope
if(!in_array($scope, ['monthly','yearly'], true)) $scope = 'monthly';
$periodLike = '';
if($scope==='monthly'){
  if($month===''){ $month = date('m'); }
  $periodLike = $year.$month; // expects YYYYMM
} else {
  $periodLike = $year; // matches LEFT(period,4)=year
}

$conds = [];
if($type !== 'all'){ $conds[] = "type='".mysqli_real_escape_string($conn,$type)."'"; }
if($status !== ''){ $conds[] = "status='".mysqli_real_escape_string($conn,$status)."'"; }
if($scope==='monthly'){
  $conds[] = "(CHAR_LENGTH(period)=6 AND period='".mysqli_real_escape_string($conn,$periodLike)."')";
} else {
  $conds[] = "LEFT(period,4)='".mysqli_real_escape_string($conn,$periodLike)."'";
}
$where = $conds ? ('WHERE '.implode(' AND ',$conds)) : '';

$sql = "SELECT i.id, i.user_id, i.type, i.period, i.amount, i.paid_amount, i.status, i.created_at, u.nama_wali, u.nama_santri, u.nisn
        FROM invoice i JOIN users u ON u.id=i.user_id $where ORDER BY i.id ASC";
$rs = mysqli_query($conn,$sql);
$rows=[]; while($rs && $r=mysqli_fetch_assoc($rs)){ $rows[]=$r; }

// Compute aggregates
$totalInvoices = count($rows); $sumAmount=0; $sumPaid=0; $sumOutstanding=0; $byStatus=[];
foreach($rows as $r){
  $sumAmount += (float)$r['amount'];
  $sumPaid += (float)$r['paid_amount'];
  $sumOutstanding += max(0.0, (float)$r['amount'] - (float)$r['paid_amount']);
  $st = $r['status']; $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
}

// Prepare filename
$label = ($scope==='monthly') ? ("laporan-".$type."-".$year."-".$month) : ("laporan-".$type."-".$year);
$filename = $label.'.pdf';

// Build HTML for PDF
$title = 'Laporan Tagihan ('.strtoupper($scope).')';
$subtitle = 'Jenis: '.strtoupper($type).' | Tahun: '.$year.($scope==='monthly'?' | Bulan: '.$month:'');
$statusLine = '';
if($byStatus){
  $parts=[]; foreach($byStatus as $k=>$v){ $parts[] = htmlspecialchars($k,ENT_QUOTES,'UTF-8').': '.$v; }
  $statusLine = implode(', ', $parts);
}

ob_start();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body{ font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#222; }
    h1{ font-size: 18px; margin:0 0 6px; }
    .muted{ color:#555; margin:0 0 10px; }
    .summary{ margin:8px 0 12px; }
    .summary .item{ margin:2px 0; }
    table{ width:100%; border-collapse: collapse; }
    th, td{ border:1px solid #ddd; padding:6px 8px; }
    th{ background:#f1f3f5; text-align:left; }
    td.num{ text-align:right; white-space:nowrap; }
    .small{ font-size: 11px; color:#666; }
  </style>
  <title><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></title>
  </head>
<body>
  <h1><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></h1>
  <div class="muted"><?= htmlspecialchars($subtitle,ENT_QUOTES,'UTF-8') ?></div>
  <div class="summary">
    <div class="item">Total Tagihan: <b><?= number_format($totalInvoices) ?></b></div>
    <div class="item">Total Nominal: <b>Rp <?= number_format($sumAmount,0,',','.') ?></b></div>
    <div class="item">Total Dibayar: <b>Rp <?= number_format($sumPaid,0,',','.') ?></b></div>
    <div class="item">Tunggakan: <b>Rp <?= number_format($sumOutstanding,0,',','.') ?></b></div>
    <?php if($statusLine): ?><div class="item small">Status: <?= $statusLine ?></div><?php endif; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:48px">ID</th>
        <th style="width:80px">NIS</th>
        <th>Wali</th>
        <th>Santri</th>
        <th style="width:80px">Jenis</th>
        <th style="width:80px">Periode</th>
        <th style="width:100px">Nominal</th>
        <th style="width:100px">Dibayar</th>
        <th style="width:80px">Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="9" class="small" style="text-align:center;padding:12px 8px">Tidak ada data.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td>#<?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['nisn'] ?? '-',ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['nama_wali'] ?? '-',ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['nama_santri'] ?? '-',ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars(strtoupper(str_replace('_',' ',$r['type'])),ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['period'] ?? '-',ENT_QUOTES,'UTF-8') ?></td>
          <td class="num">Rp <?= number_format((float)$r['amount'],0,',','.') ?></td>
          <td class="num">Rp <?= number_format((float)$r['paid_amount'],0,',','.') ?></td>
          <td><?= htmlspecialchars($r['status'],ENT_QUOTES,'UTF-8') ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
$html = ob_get_clean();
require_once BASE_PATH.'/src/includes/pdf_helper.php';
pdf_render_and_output($html, $filename);
exit;
