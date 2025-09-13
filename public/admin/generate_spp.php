<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';
$pesan=$err=null;
// Jenis tagihan: spp (default) atau daftar_ulang
$type = isset($_POST['type']) ? preg_replace('/[^a-z_]/','', strtolower($_POST['type'])) : (isset($_GET['type'])?preg_replace('/[^a-z_]/','',strtolower($_GET['type'])):'spp');
if(!in_array($type,['spp','daftar_ulang'],true)) $type='spp';
// Preview endpoint
if(isset($_GET['preview']) && $_GET['preview']=='1'){
  header('Content-Type: application/json; charset=utf-8');
  $y=preg_replace('/[^0-9]/','', $_GET['year']??''); $m=preg_replace('/[^0-9]/','', $_GET['month']??'');
  $resp=['ok'=>false];
  if($type==='spp'){
    if(strlen($y)===4 && (int)$m>=1 && (int)$m<=12){
      $period=$y.str_pad($m,2,'0',STR_PAD_LEFT); $totalWali=0; $existing=0;
      if($rs=mysqli_query($conn,"SELECT COUNT(id) c FROM users WHERE role='wali_santri'")){ $totalWali=(int)(mysqli_fetch_assoc($rs)['c']??0); }
      if($st=mysqli_prepare($conn,'SELECT COUNT(DISTINCT user_id) c FROM invoice WHERE type="spp" AND period=?')){ mysqli_stmt_bind_param($st,'s',$period); mysqli_stmt_execute($st); $r=mysqli_stmt_get_result($st); if($r && ($rw=mysqli_fetch_assoc($r))) $existing=(int)$rw['c']; }
      $resp=['ok'=>true,'period'=>$period,'total_wali'=>$totalWali,'sudah_ada'=>$existing,'akan_dibuat'=>max(0,$totalWali-$existing)];
    }
  } else if($type==='daftar_ulang') { // daftar_ulang preview by year only
    if(strlen($y)===4){
      $period=$y; $totalWali=0; $existing=0;
      if($rs=mysqli_query($conn,"SELECT COUNT(id) c FROM users WHERE role='wali_santri'")){ $totalWali=(int)(mysqli_fetch_assoc($rs)['c']??0); }
      if($st=mysqli_prepare($conn,'SELECT COUNT(DISTINCT user_id) c FROM invoice WHERE type="daftar_ulang" AND period=?')){ mysqli_stmt_bind_param($st,'s',$period); mysqli_stmt_execute($st); $r=mysqli_stmt_get_result($st); if($r && ($rw=mysqli_fetch_assoc($r))) $existing=(int)$rw['c']; }
      $resp=['ok'=>true,'period'=>$period,'total_wali'=>$totalWali,'sudah_ada'=>$existing,'akan_dibuat'=>max(0,$totalWali-$existing)];
    }
  }
  echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
// Handle submit (structured fields)
$rawYear=trim($_POST['year']??''); $rawMonth=trim($_POST['month']??'');
$period='';
if($rawYear && $rawMonth){ $period=preg_replace('/[^0-9]/','',$rawYear).str_pad(preg_replace('/[^0-9]/','',$rawMonth),2,'0',STR_PAD_LEFT); }
$amount = normalize_amount($_POST['amount'] ?? 0);
$due_date = trim($_POST['due_date'] ?? '');
if($_SERVER['REQUEST_METHOD']==='POST'){
  $tok=$_POST['csrf_token']??''; if(!verify_csrf_token($tok)){ $err='Token tidak valid'; }
  elseif($amount<=0){ $err='Nominal harus > 0'; }
  // Branch: single user vs bulk
  elseif(isset($_POST['mode']) && $_POST['mode']==='single'){
    $nisn = preg_replace('/[^A-Za-z0-9_-]/','', (string)($_POST['nisn'] ?? ''));
    // period validation
  if($type==='spp' && !preg_match('/^[0-9]{6}$/',$period)) $err='Pilih Tahun & Bulan';
  elseif($type==='daftar_ulang' && !preg_match('/^[0-9]{6}$/',$period)) $err='Pilih Tahun & Bulan';
    else {
  // Resolve user by NIS only (field name still 'nisn')
      $targetId = 0;
      if($nisn){ $rsU = mysqli_query($conn, "SELECT id FROM users WHERE nisn='".mysqli_real_escape_string($conn,$nisn)."' AND role='wali_santri' LIMIT 1"); if($rsU && ($r=mysqli_fetch_assoc($rsU))) $targetId=(int)$r['id']; }
      if($targetId<=0){ $err='Pengguna tidak ditemukan.'; }
      else {
        $due = $due_date ?: null;
        if($type==='spp'){
          $res = invoice_generate_spp_single($conn,$targetId,$period,$amount,$due);
          $pesan = 'Generate SPP untuk user #'.$targetId.' periode '.$period.' selesai. Dibuat: '.$res['created'].', Skip: '.$res['skipped'].($res['invoice_id']?(' (ID #'.$res['invoice_id'].')'):'');
        } else if($type==='daftar_ulang') {
          $res = invoice_generate_daftar_ulang_single($conn,$targetId,$period,$amount,$due);
          $pesan = 'Generate Daftar Ulang untuk user #'.$targetId.' periode '.$period.' selesai. Dibuat: '.$res['created'].', Skip: '.$res['skipped'].($res['invoice_id']?(' (ID #'.$res['invoice_id'].')'):'');
        }
      }
    }
  }
  // Bulk branch (default)
  else {
    if($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due_date)) $due_date='';
  if($type==='spp' && !preg_match('/^[0-9]{6}$/',$period)){ $err='Pilih Tahun & Bulan'; }
  elseif($type==='daftar_ulang' && !preg_match('/^[0-9]{6}$/',$period)){ $err='Pilih Tahun & Bulan'; }
    else {
    if($type==='spp'){
      $res=invoice_generate_spp_bulk($conn,$period,$amount,$due_date?:null);
      $pesan='Generate SPP '.$period.' selesai. Dibuat: '.$res['created'].', Skip: '.$res['skipped'];
    } else if($type==='daftar_ulang') {
      $res=invoice_generate_daftar_ulang_bulk($conn,$period,$amount,$due_date?:null);
      $pesan='Generate Daftar Ulang '.$period.' selesai. Dibuat: '.$res['created'].', Skip: '.$res['skipped'];
    }
    // Optional force update nominal existing pending invoices
    if(isset($_POST['force_update']) && $_POST['force_update']=='1' && $amount>0){
      if($type==='spp'){
        @mysqli_query($conn, "UPDATE invoice SET amount=".(float)$amount." WHERE type='spp' AND period='".mysqli_real_escape_string($conn,$period)."' AND status='pending'");
      } else if($type==='daftar_ulang') {
        @mysqli_query($conn, "UPDATE invoice SET amount=".(float)$amount." WHERE type='daftar_ulang' AND period='".mysqli_real_escape_string($conn,$period)."' AND status='pending'");
      }
      $aff = mysqli_affected_rows($conn);
      if($aff>0) $pesan .= ' | Nominal diperbarui pada '.$aff.' invoice pending.';
    }
    // Diagnostic: jelaskan skip
    if(!isset($err) && isset($res) && $res['created']==0 && $res['skipped']>0){
      $sample=[]; $qDiag=null;
      if($type==='spp') $qDiag = mysqli_query($conn, "SELECT id,user_id,amount,status FROM invoice WHERE type='spp' AND period='".mysqli_real_escape_string($conn,$period)."' LIMIT 5");
      else $qDiag = mysqli_query($conn, "SELECT id,user_id,amount,status FROM invoice WHERE type='daftar_ulang' AND period='".mysqli_real_escape_string($conn,$period)."' LIMIT 5");
      while($qDiag && ($r=mysqli_fetch_assoc($qDiag))) $sample[]=$r;
      if($sample){
        $pesan .= ' (Semua skip karena sudah ada invoice periode ini. Contoh ID: '.implode(',', array_map(function($s){return '#'.(int)$s['id'];},$sample)).')';
      }
    }
  }
}
}
require_once __DIR__.'/../../src/includes/header.php';
?>
<div class="page-shell generate-spp-page">
  <div class="content-header">
  <h1>Generate Tagihan</h1>
    <div class="quick-actions-inline">
      <a class="qa-btn" href="invoice.php" title="Lihat Invoice">Lihat Invoice</a>
  <a class="qa-btn" href="reset_tagihan.php" style="background:#fff3f3;color:#b91c1c" title="Reset semua data tagihan">Reset Tagihan</a>
    </div>
  </div>
  <?php if($pesan): ?><div class="alert success" role="alert"><?= e($pesan) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>
  <?php $recentPeriods=[]; $rsP=mysqli_query($conn,"SELECT period, COUNT(*) c, SUM(amount) total FROM invoice WHERE type='".($type==='spp'?'spp':($type==='daftar_ulang'?'daftar_ulang':'beasiswa'))."' GROUP BY period ORDER BY period DESC LIMIT 6"); while($rsP && $r=mysqli_fetch_assoc($rsP)) $recentPeriods[]=$r; $nowY=(int)date('Y'); $yStart=max(2020,$nowY-1); $yEnd=$nowY+3; ?>
  <div class="inv-chips spp-preview-chips" aria-label="Preview generate">
    <div class="inv-chip info"><span class="k">Periode</span><span class="v" id="chipPeriod">-</span></div>
    <div class="inv-chip"><span class="k">Total Wali</span><span class="v" id="chipTotal">-</span></div>
    <div class="inv-chip warn"><span class="k">Sudah Ada</span><span class="v" id="chipExisting">-</span></div>
    <div class="inv-chip ok"><span class="k">Akan Dibuat</span><span class="v" id="chipToCreate">-</span></div>
    <button class="btn-action small" type="button" id="btnFocusForm">Atur</button>
  </div>
  <div class="panel generate-panel">
    <div class="gen-layout">
      <div class="gen-left">
        <form method="POST" id="genSPPForm" class="gen-form" autocomplete="off">
          <input type="hidden" name="mode" value="bulk" />
          <div class="row">
            <label>Jenis Tagihan</label>
            <select name="type" id="typeSel">
              <option value="spp" <?= $type==='spp'?'selected':'' ?>>SPP</option>
              <option value="daftar_ulang" <?= $type==='daftar_ulang'?'selected':'' ?>>Daftar Ulang</option>
            </select>
          </div>
          <div class="row">
            <label>Periode</label>
            <div class="period-dual">
              <div class="ywrap"><select name="year" id="yearSel" required><?php for($y=$yStart;$y<=$yEnd;$y++):?><option value="<?= $y ?>" <?php if(substr($period?:date('Ym'),0,4)==$y) echo 'selected';?>><?= $y ?></option><?php endfor;?></select></div>
              <div class="mwrap" id="monthWrap"><select name="month" id="monthSel" required><?php $curM=substr($period?:date('Ym'),4,2); for($m=1;$m<=12;$m++): $mm=str_pad($m,2,'0',STR_PAD_LEFT);?><option value="<?= $mm ?>" <?php if($mm==$curM) echo 'selected';?>><?= $mm ?></option><?php endfor;?></select></div>
            </div>
          </div>
          <div class="row">
            <label>Nominal</label>
            <div class="amount-wrap">
              <input type="text" id="amountDisplay" placeholder="Rp 0" inputmode="numeric" autocomplete="off" value="<?= $amount? 'Rp '.number_format($amount,0,',','.') : '' ?>">
              <input type="hidden" name="amount" id="amountRaw" value="<?= (float)$amount ?>">
            </div>
          </div>
          <div class="row">
            <label>Jatuh Tempo</label>
            <div class="due-wrap">
              <input type="date" name="due_date" id="dueDateInput" value="<?= e($due_date) ?>">
              <div class="due-actions"><label class="auto"><input type="checkbox" id="autoEnd" checked> Akhir bulan otomatis</label><button type="button" class="btn-action xsmall btn-end-month">Akhir Bulan</button><button type="button" class="btn-action xsmall btn-plus7">+7 Hari</button></div>
            </div>
          </div>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="row" style="margin-top:4px">
            <label style="display:flex;align-items:center;gap:6px;font-weight:500"><input type="checkbox" name="force_update" value="1"> <span style="font-weight:400">Force update nominal invoice pending yang sudah ada</span></label>
          </div>
          <div class="actions-row"><button type="submit" class="btn-action primary" id="btnGenerate" disabled>Generate</button></div>
          <div class="small muted">Wali yang sudah punya tagihan periode ini tidak dibuat ulang.</div>
        </form>
      </div>
      <div class="gen-right">
  <h3 style="margin:0 0 12px;font-size:16px;font-weight:700">Periode Terakhir</h3>
  <div class="recent-head"><span>Periode</span><span>Jumlah</span><span>Total</span></div>
  <ul class="recent-periods"><?php if($recentPeriods){ foreach($recentPeriods as $rp){ ?><li><span class="p"><?= e($rp['period']) ?></span><span class="c"><?= (int)$rp['c'] ?> inv</span><span class="amt">Rp <?= number_format((float)$rp['total'],0,',','.') ?></span></li><?php } } else { ?><li class="empty">Belum ada.</li><?php } ?></ul>
        <div class="tip">Gunakan nominal konsisten untuk mempermudah monitoring.</div>
      </div>
    </div>
  </div>

  <!-- Single User Generation Panel -->
  <div class="panel generate-panel">
    <div class="gen-layout">
      <div class="gen-left">
        <h3 style="margin:0 0 12px;font-size:16px;font-weight:700">Generate untuk Satu Pengguna</h3>
        <form method="POST" class="gen-form" autocomplete="off" id="genSingleForm">
          <input type="hidden" name="mode" value="single" />
          <div class="row">
            <label>Jenis Tagihan</label>
            <select name="type" id="typeSelSingle">
              <option value="spp">SPP</option>
              <option value="daftar_ulang">Daftar Ulang</option>
            </select>
          </div>
          <div class="row">
            <label>Periode</label>
            <div class="period-dual">
              <div class="ywrap"><select name="year" id="yearSelSingle" required><?php for($y=$yStart;$y<=$yEnd;$y++):?><option value="<?= $y ?>" <?php if(substr($period?:date('Ym'),0,4)==$y) echo 'selected';?>><?= $y ?></option><?php endfor;?></select></div>
              <div class="mwrap" id="monthWrapSingle"><select name="month" id="monthSelSingle" required><?php $curM=substr($period?:date('Ym'),4,2); for($m=1;$m<=12;$m++): $mm=str_pad($m,2,'0',STR_PAD_LEFT);?><option value="<?= $mm ?>" <?php if($mm==$curM) echo 'selected';?>><?= $mm ?></option><?php endfor;?></select></div>
            </div>
          </div>
          <div class="row">
            <label>Nominal</label>
            <div class="amount-wrap">
              <input type="text" id="amountDisplaySingle" placeholder="Rp 0" inputmode="numeric" autocomplete="off" value="<?= $amount? 'Rp '.number_format($amount,0,',','.') : '' ?>">
              <input type="hidden" name="amount" id="amountRawSingle" value="<?= (float)$amount ?>">
            </div>
          </div>
          <div class="row">
            <label>Jatuh Tempo</label>
            <div class="due-wrap">
              <input type="date" name="due_date" id="dueDateInputSingle" value="<?= e($due_date) ?>">
              <div class="due-actions"><label class="auto"><input type="checkbox" id="autoEndSingle" checked> Akhir bulan otomatis</label></div>
            </div>
          </div>
          <div class="row">
            <label>NIS</label>
            <input type="text" name="nisn" id="nisnSingle" placeholder="Masukkan NIS" required />
          </div>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="actions-row"><button type="submit" class="btn-action primary" id="btnGenerateSingle">Generate Satu Pengguna</button></div>
          <div class="small muted">Jika invoice periode ini sudah ada untuk pengguna tsb, akan di-skip.</div>
        </form>
      </div>
      <div class="gen-right">
  <div class="tip">Anda bisa memilih berdasarkan NIS.</div>
      </div>
    </div>
  </div>
  <div class="panel recent-invoices">
  <div class="panel-header"><h2>Invoice <?= $type==='spp'?'SPP':'Daftar Ulang' ?> Terbaru</h2></div>
  <?php $recent=[]; $rs=mysqli_query($conn,"SELECT id,user_id,type,period,amount,status,created_at FROM invoice WHERE type='".($type==='spp'?'spp':'daftar_ulang')."' ORDER BY id DESC LIMIT 30"); while($rs && $r=mysqli_fetch_assoc($rs)) $recent[]=$r; ?>
  <div class="table-wrap"><table class="table mini-table" style="min-width:780px"><thead><tr><th>ID</th><th>Periode</th><th>Jumlah</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody><?php if($recent){ foreach($recent as $r){ ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= e($r['period']??'-') ?></td><td>Rp <?= number_format((float)$r['amount'],0,',','.') ?></td><td><span class="status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td><td><?= $r['created_at']?date('d M Y H:i',strtotime($r['created_at'])):'-' ?></td><td><a class="btn-detail" href="invoice_detail.php?id=<?= (int)$r['id'] ?>">Detail</a></td></tr><?php } } else { ?><tr><td colspan="6" style="text-align:center;color:#777;font-size:13px">Belum ada invoice <?= $type==='spp'?'SPP':($type==='daftar_ulang'?'Daftar Ulang':'Beasiswa') ?>.</td></tr><?php } ?></tbody></table></div>
  </div>
</div>
<script src="<?= url('assets/js/generate_spp.js') ?>?v=20250906b" defer></script>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>