<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/status_helpers.php';

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
// Map jenis ke label Indonesia
$typeLabel = ($type==='all') ? 'Semua' : strtoupper(str_replace('_',' ',$type));
$subtitle = 'Jenis: '.$typeLabel.' | Tahun: '.$year.($scope==='monthly'?' | Bulan: '.$month:'');
$statusLine = '';
if($byStatus){
  $parts=[]; foreach($byStatus as $k=>$v){ $parts[] = htmlspecialchars(t_status_invoice($k),ENT_QUOTES,'UTF-8').': '.$v; }
  $statusLine = implode(', ', $parts);
}

// Logo (opsional)
$logoData = '';
$logoFile = BASE_PATH.'/public/assets/img/logo.png';
if(is_file($logoFile)){
  $mime = 'image/png'; $raw = @file_get_contents($logoFile);
  if($raw!==false){ $logoData = 'data:'.$mime.';base64,'.base64_encode($raw); }
}

ob_start();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 48px 36px 54px 36px; }
    body{ font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#222; }
    .header{ display:flex; gap:12px; align-items:center; margin-bottom:8px; }
    .header img{ height: 42px; }
    .title-wrap{ display:flex; flex-direction:column; }
    .title-wrap h1{ font-size: 18px; margin:0 0 2px; }
    .muted{ color:#555; margin:0; }
    .summary{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:6px 18px; margin:12px 0 14px; }
    .summary .item{ margin:0; }
    table{ width:100%; border-collapse: collapse; font-size: 11.5px; }
    colgroup col.num { width: 110px; }
    th, td{ border:1px solid #d9d9d9; padding:6px 8px; }
    th{ background:#f4f6f8; text-align:left; }
    tbody tr:nth-child(even){ background:#fbfbfc; }
    td.num{ text-align:right; white-space:nowrap; }
    .small{ font-size: 11px; color:#666; }
    .tot-row td{ font-weight:700; background:#f9fafb; }
  </style>
  <title><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></title>
  </head>
<body>
  <div class="header">
    <?php if($logoData): ?><img src="<?= $logoData ?>" alt="Logo" /><?php endif; ?>
    <div class="title-wrap">
      <h1><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></h1>
      <div class="muted"><?= htmlspecialchars($subtitle,ENT_QUOTES,'UTF-8') ?></div>
    </div>
  </div>
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
        <th style="width:92px">NIS</th>
        <th>Wali</th>
        <th>Santri</th>
        <th style="width:92px">Jenis</th>
        <th style="width:84px">Periode</th>
        <th style="width:110px">Nominal</th>
        <th style="width:110px">Dibayar</th>
        <th style="width:84px">Status</th>
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
          <td><?= htmlspecialchars(t_status_invoice($r['status']),ENT_QUOTES,'UTF-8') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      <?php if($rows): ?>
      <tr class="tot-row">
        <td colspan="6">Total</td>
        <td class="num">Rp <?= number_format($sumAmount,0,',','.') ?></td>
        <td class="num">Rp <?= number_format($sumPaid,0,',','.') ?></td>
        <td></td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
$html = ob_get_clean();

// Debug mode: allow viewing raw HTML without PDF rendering via ?format=html
$fmt = strtolower($_GET['format'] ?? 'pdf');
if ($fmt === 'html') {
  header('Content-Type: text/html; charset=UTF-8');
  echo $html;
  exit;
}

require_once BASE_PATH.'/src/includes/pdf_helper.php';
pdf_render_and_output($html, $filename);
exit;
