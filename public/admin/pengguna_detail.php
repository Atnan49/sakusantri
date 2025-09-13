<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

// Detect optional soft delete column on transaksi
$hasSoftDelete=false;
if($colChk = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='transaksi' AND COLUMN_NAME='deleted_at' LIMIT 1")){
  if(mysqli_fetch_row($colChk)) $hasSoftDelete=true;
}
$softWhere = $hasSoftDelete ? ' AND deleted_at IS NULL' : '';
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if($id<=0){ header('Location: '.url('admin/pengguna')); exit; }
$pesan = $pesan_error = null;

// Data user
$stmt = mysqli_prepare($conn, "SELECT id, nama_wali, nama_santri, saldo FROM users WHERE id=? AND role='wali_santri' LIMIT 1");
if(!$stmt){ die('DB err'); }
mysqli_stmt_bind_param($stmt,'i',$id); mysqli_stmt_execute($stmt); $resU = mysqli_stmt_get_result($stmt); $user = $resU?mysqli_fetch_assoc($resU):null;
if(!$user){ header('Location: '.url('admin/pengguna')); exit; }

// Optional: kolom beasiswa/discount SPP pada tabel users
$hasDisc=false; $disc=['type'=>null,'value'=>null,'until'=>null,'year'=>null];
if($col = mysqli_query($conn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME IN ('spp_discount_type','spp_discount_value','spp_discount_until','spp_discount_year')")){
  $found=0; while($row=mysqli_fetch_row($col)){ $found++; }
  if($found>=2) $hasDisc=true;
}
if($hasDisc){
  // Try to also fetch year column if exists
  $hasYear=false; if($chkY = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'spp_discount_year'")){ if(mysqli_fetch_row($chkY)) $hasYear=true; }
  if($hasYear && ($st = mysqli_prepare($conn, "SELECT spp_discount_type, spp_discount_value, spp_discount_until, spp_discount_year FROM users WHERE id=? LIMIT 1"))){
    mysqli_stmt_bind_param($st,'i',$id); mysqli_stmt_execute($st); $rs=mysqli_stmt_get_result($st); if($rs && ($r=mysqli_fetch_row($rs))){
      $disc=['type'=>$r[0]?:null,'value'=>$r[1]!==null?(float)$r[1]:null,'until'=>$r[2]?:null,'year'=>$r[3]!==null?(int)$r[3]:null];
    }
  } elseif($st = mysqli_prepare($conn, "SELECT spp_discount_type, spp_discount_value, spp_discount_until FROM users WHERE id=? LIMIT 1")){
    mysqli_stmt_bind_param($st,'i',$id); mysqli_stmt_execute($st); $rs=mysqli_stmt_get_result($st); if($rs && ($r=mysqli_fetch_row($rs))){
      $disc=['type'=>$r[0]?:null,'value'=>$r[1]!==null?(float)$r[1]:null,'until'=>$r[2]?:null,'year'=>null];
    }
  }
}

// Simpan pengaturan beasiswa
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['aksi']??'')==='simpan_beasiswa'){
  $token = $_POST['csrf_token'] ?? '';
  if(!verify_csrf_token($token)){
    $pesan_error='Token tidak valid.';
  } elseif(!$hasDisc){
    $pesan_error='Fitur beasiswa belum diaktifkan. Jalankan migrasi 008_user_spp_discount.';
  } else {
    $tipe = trim(strtolower($_POST['tipe'] ?? ''));
    $nilai = (float)str_replace([',','.'],'', $_POST['nilai'] ?? '0'); // support formatted
    // If nilai posted in plain number with dot thousands, above would over-strip decimals; but we only need integer rupiah
    $nilai = max(0.0, $nilai);
    $yearRaw = trim($_POST['year'] ?? ''); $year = $yearRaw!=='' ? (int)preg_replace('/[^0-9]/','',$yearRaw) : null;
    if($tipe!=='' && !in_array($tipe,['percent','nominal'],true)){
      $pesan_error='Tipe tidak valid.';
    } else if($tipe==='percent' && ($nilai<0 || $nilai>100)){
      $pesan_error='Persentase harus 0-100%.';
    } else if($year!==null && ($year<2000 || $year>3000)){
      $pesan_error='Tahun tidak valid.';
    } else {
      // Normalisasi: jika tipe kosong, set null semua
      if($tipe===''){
        $q = mysqli_prepare($conn, "UPDATE users SET spp_discount_type=NULL, spp_discount_value=NULL, spp_discount_until=NULL, spp_discount_year=NULL WHERE id=? LIMIT 1");
        if($q){ mysqli_stmt_bind_param($q,'i',$id); mysqli_stmt_execute($q); if(mysqli_affected_rows($conn)>=0){ $pesan='Pengaturan beasiswa dihapus.'; $disc=['type'=>null,'value'=>null,'until'=>null,'year'=>null]; } else { $pesan_error='Gagal menyimpan.'; } }
      } else {
        // Simpan
        $valStore = $tipe==='percent'? min(100.0,$nilai) : $nilai;
        // Include year column if available
        $hasYearCol=false; if($chkY2=mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'spp_discount_year'")){ if(mysqli_fetch_row($chkY2)) $hasYearCol=true; }
        if($hasYearCol){
          $q = mysqli_prepare($conn, "UPDATE users SET spp_discount_type=?, spp_discount_value=?, spp_discount_until=NULL, spp_discount_year=? WHERE id=? LIMIT 1");
          $yBind = $year!==null ? $year : null;
          if($q){ mysqli_stmt_bind_param($q,'sdii',$tipe,$valStore,$yBind,$id); mysqli_stmt_execute($q); if(mysqli_affected_rows($conn)>=0){ $pesan='Pengaturan beasiswa disimpan.'; $disc=['type'=>$tipe,'value'=>$valStore,'until'=>null,'year'=>$yBind]; } else { $pesan_error='Gagal menyimpan.'; } }
        } else {
          $q = mysqli_prepare($conn, "UPDATE users SET spp_discount_type=?, spp_discount_value=?, spp_discount_until=NULL WHERE id=? LIMIT 1");
          if($q){ mysqli_stmt_bind_param($q,'sdi',$tipe,$valStore,$id); mysqli_stmt_execute($q); if(mysqli_affected_rows($conn)>=0){ $pesan='Pengaturan beasiswa disimpan.'; $disc=['type'=>$tipe,'value'=>$valStore,'until'=>null,'year'=>null]; } else { $pesan_error='Gagal menyimpan.'; } }
        }
        // Recalc invoices for chosen year if provided
        if(empty($pesan_error)){
          require_once BASE_PATH.'/src/includes/payments.php';
          $recalcYear = $year !== null ? $year : (int)date('Y');
          if(function_exists('spp_recalc_user_for_year')){ $res = spp_recalc_user_for_year($conn,$id,$recalcYear); if(isset($res['updated'])){ $pesan .= ' | Recalc SPP '.$recalcYear.': '.$res['updated'].' diupdate, '.$res['skipped'].' dilewati.'; } }
          if(function_exists('daftar_ulang_recalc_user_for_year')){ $res2 = daftar_ulang_recalc_user_for_year($conn,$id,$recalcYear); if(isset($res2['updated'])){ $pesan .= ' | Recalc DU '.$recalcYear.': '.$res2['updated'].' diupdate, '.$res2['skipped'].' dilewati.'; } }
        }
      }
    }
  }
}

// Cabut beasiswa cepat
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['aksi']??'')==='cabut_beasiswa'){
  $token = $_POST['csrf_token'] ?? '';
  if(!verify_csrf_token($token)){
    $pesan_error='Token tidak valid.';
  } elseif(!$hasDisc){
    $pesan_error='Fitur beasiswa belum diaktifkan. Jalankan migrasi 008_user_spp_discount.';
  } else {
    $yearRaw = trim($_POST['year'] ?? ''); $yearSel = $yearRaw!=='' ? (int)preg_replace('/[^0-9]/','',$yearRaw) : (int)date('Y');
    if($q = mysqli_prepare($conn, "UPDATE users SET spp_discount_type=NULL, spp_discount_value=NULL, spp_discount_until=NULL, spp_discount_year=NULL WHERE id=? LIMIT 1")){
      mysqli_stmt_bind_param($q,'i',$id); mysqli_stmt_execute($q);
      if(mysqli_affected_rows($conn)>=0){
        $pesan='Beasiswa dicabut.';
        $disc=['type'=>null,'value'=>null,'until'=>null,'year'=>null];
        // Recalc revert for the selected year (pending/partial invoices only)
        require_once BASE_PATH.'/src/includes/payments.php';
  if(function_exists('spp_recalc_user_for_year')){ $res = spp_recalc_user_for_year($conn,$id,$yearSel); if(isset($res['updated'])){ $pesan .= ' | Recalc SPP '.$yearSel.': '.$res['updated'].' diupdate, '.$res['skipped'].' dilewati.'; } }
  if(function_exists('daftar_ulang_recalc_user_for_year')){ $res2 = daftar_ulang_recalc_user_for_year($conn,$id,$yearSel); if(isset($res2['updated'])){ $pesan .= ' | Recalc DU '.$yearSel.': '.$res2['updated'].' diupdate, '.$res2['skipped'].' dilewati.'; } }
      } else {
        $pesan_error='Gagal mencabut beasiswa.';
      }
    }
  }
}

// Hapus tagihan SPP individual (hanya status menunggu_pembayaran) -- akan dipertahankan untuk administrasi, tetapi fitur generate SPP dihapus.
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['aksi']??'')==='hapus_tagihan'){
  $token = $_POST['csrf_token'] ?? '';
  $tid = (int)($_POST['transaksi_id'] ?? 0);
  if(!verify_csrf_token($token)){
    $pesan_error = 'Token tidak valid.';
  } elseif($tid>0){
  $stmtChk = mysqli_prepare($conn, $hasSoftDelete ? "SELECT id,status FROM transaksi WHERE id=? AND user_id=? AND jenis_transaksi='spp' AND deleted_at IS NULL LIMIT 1" : "SELECT id,status FROM transaksi WHERE id=? AND user_id=? AND jenis_transaksi='spp' LIMIT 1");
    if($stmtChk){
      mysqli_stmt_bind_param($stmtChk,'ii',$tid,$id);
      mysqli_stmt_execute($stmtChk);
      $resChk = mysqli_stmt_get_result($stmtChk);
      $rowChk = $resChk?mysqli_fetch_assoc($resChk):null;
      if($rowChk){
        if($rowChk['status']==='menunggu_pembayaran'){
          if($hasSoftDelete){
            mysqli_query($conn, "UPDATE transaksi SET deleted_at=NOW() WHERE id=".$tid." AND deleted_at IS NULL");
          } else {
            // Fallback hard delete if no soft delete column
            mysqli_query($conn, "DELETE FROM transaksi WHERE id=".$tid." LIMIT 1");
          }
          if(mysqli_affected_rows($conn)>0){
            $pesan = 'Tagihan berhasil dihapus (soft delete).';
            @add_notification($conn, $id, 'spp_delete', 'Tagihan SPP dihapus oleh admin.');
            if(function_exists('audit_log')){ audit_log($conn, (int)($_SESSION['user_id']??null), 'delete_tagihan', 'transaksi', $tid, ['page'=>'pengguna_detail','status_before'=>$rowChk['status']]); }
          } else { $pesan_error='Gagal menghapus tagihan.'; }
        } else { $pesan_error='Tagihan sudah diproses dan tidak bisa dihapus.'; }
      } else { $pesan_error='Tagihan tidak ditemukan.'; }
    } else { $pesan_error='Query hapus gagal disiapkan.'; }
  }
}

// Fitur generate/backfill SPP dihapus.

// Ambil daftar tagihan SPP terakhir (limit 12)
// Dataset tagihan SPP model lama (table transaksi)
$tagihan=[]; $rsT = mysqli_query($conn, "SELECT id, deskripsi, jumlah, status, tanggal_upload FROM transaksi WHERE user_id=$id AND jenis_transaksi='spp' $softWhere ORDER BY (status='menunggu_pembayaran') DESC, id DESC LIMIT 12");
while($rsT && $r=mysqli_fetch_assoc($rsT)){ $tagihan[]=$r; }
// Fallback ke sistem invoice baru jika tidak ada data transaksi SPP lama
$invoice_fallback=[];
if(empty($tagihan)){
  // Fallback hanya invoice type SPP
  if($rsInv = mysqli_query($conn, "SELECT id,type,period,amount,paid_amount,status,due_date,created_at FROM invoice WHERE user_id=$id AND type='spp' ORDER BY id DESC LIMIT 30")){
    while($rsInv && $ri=mysqli_fetch_assoc($rsInv)){ $invoice_fallback[]=$ri; }
  }
}
// Hitung tagihan belum bayar
// Hitung total tagihan belum bayar (seluruh, tidak hanya 12 terbaru)
$belum_bayar_total = 0; if($rsC = mysqli_query($conn, "SELECT COUNT(*) c FROM transaksi WHERE user_id=$id AND jenis_transaksi='spp' AND status='menunggu_pembayaran' $softWhere")){ if($rC = mysqli_fetch_assoc($rsC)) $belum_bayar_total=(int)$rC['c']; }
// Jika model lama kosong & fallback invoice ada, gunakan hitungan unpaid invoice
if($belum_bayar_total===0 && empty($tagihan) && !empty($invoice_fallback)){
  $belum_bayar_total = 0;
  foreach($invoice_fallback as $iv){ if(in_array($iv['status'],['pending','partial','overdue'],true)) $belum_bayar_total++; }
}
// Jumlah yang terlihat di list pendek
$belum_bayar_visible = 0; foreach($tagihan as $t){ if($t['status']==='menunggu_pembayaran') $belum_bayar_visible++; }
if($belum_bayar_visible===0 && empty($tagihan) && !empty($invoice_fallback)){
  foreach($invoice_fallback as $iv){ if(in_array($iv['status'],['pending','partial','overdue'],true)) $belum_bayar_visible++; }
}
// Rekap saldo: ambil ledger (wallet_ledger) 30 terakhir
$ledger=[]; $rsL = mysqli_query($conn, "SELECT id, direction, amount, ref_type, note, created_at FROM wallet_ledger WHERE user_id=$id ORDER BY id DESC LIMIT 60");
while($rsL && $r=mysqli_fetch_assoc($rsL)){ $ledger[]=$r; }
// KUMPULKAN SEMUA TAGIHAN (legacy SPP + semua invoice baru) UNTUK USER INI
$legacy_all=[]; $rsLegacyAll = mysqli_query($conn, "SELECT id, deskripsi, jumlah, status, tanggal_upload FROM transaksi WHERE user_id=$id AND jenis_transaksi='spp' $softWhere ORDER BY id DESC LIMIT 120");
while($rsLegacyAll && $r=mysqli_fetch_assoc($rsLegacyAll)){ $legacy_all[]=$r; }
$invoice_all=[]; if($rsInvAll = mysqli_query($conn, "SELECT id,type,period,amount,paid_amount,status,due_date,created_at FROM invoice WHERE user_id=$id ORDER BY id DESC LIMIT 200")){ while($rsInvAll && $ri=mysqli_fetch_assoc($rsInvAll)){ $invoice_all[]=$ri; } }
// Normalisasi gabungan
$combined_all=[];
foreach($legacy_all as $lg){
  $combined_all[]=[
    'src'=>'legacy',
    'id'=>$lg['id'],
    'type'=>'spp',
    'period'=>$lg['deskripsi'],
    'amount'=>$lg['jumlah'],
    'paid_amount'=>0,
    'status'=>$lg['status'],
    'due_date'=>'',
    'created_at'=>$lg['tanggal_upload']?:null
  ];
}
foreach($invoice_all as $iv){
  $combined_all[]=[
    'src'=>'invoice',
    'id'=>$iv['id'],
    'type'=>$iv['type'],
    'period'=>$iv['period'],
    'amount'=>$iv['amount'],
    'paid_amount'=>$iv['paid_amount'],
    'status'=>$iv['status'],
    'due_date'=>$iv['due_date'],
    'created_at'=>$iv['created_at']
  ];
}
usort($combined_all,function($a,$b){
  $ta=strtotime($a['created_at']??'1970-01-01');
  $tb=strtotime($b['created_at']??'1970-01-01');
  if($ta==$tb) return $b['id'] <=> $a['id'];
  return $tb <=> $ta; // desc
});
require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell pengguna-detail-page enhanced">
  <div class="content-header">
    <h1><?= e($user['nama_santri']); ?></h1>
    <div class="quick-actions-inline">
      <a class="qa-btn" href="<?= url('admin/pengguna'); ?>">&larr; Kembali</a>
      <a class="qa-btn" href="<?= url('kasir/transaksi?user_id='.$user['id']); ?>">Top-Up</a>
    </div>
  </div>
  <div class="user-summary-cards">
    <div class="u-card saldo"><h3>Saldo</h3><div class="val">Rp <?= number_format($user['saldo'],0,',','.') ?></div><div class="sub">Tabungan Santri</div></div>
  <div class="u-card spp <?= $belum_bayar_total>0?'warn':'' ?>"><h3>SPP Belum</h3><div class="val"><?= (int)$belum_bayar_total ?></div><div class="sub">Tagihan</div><?php if($belum_bayar_total>$belum_bayar_visible): ?><div class="sub" style="font-size:10px;color:#a15b00">Hanya menampilkan sebagian terbaru</div><?php endif; ?></div>
  </div>
  <?php if($pesan): ?><div class="alert success" role="alert"><?= e($pesan) ?></div><?php endif; ?>
  <?php if($pesan_error): ?><div class="alert error" role="alert"><?= e($pesan_error) ?></div><?php endif; ?>
  <div class="panel beasiswa-panel" style="margin:16px 0;padding:12px;border:1px solid #e3e3e3;border-radius:8px;background:#fafafa">
    <h3 style="margin-top:0;margin-bottom:12px">Beasiswa / Potongan SPP</h3>
    <?php if(!$hasDisc): ?>
      <div class="text-muted" style="font-size:13px">Kolom pengaturan belum tersedia. Jalankan migrasi <code>scripts/migrations/008_user_spp_discount.sql</code> untuk mengaktifkan.</div>
    <?php else: ?>
      <?php
        $aktifTxt = '';
        if($disc['type'] && $disc['value']>0){
          if($disc['type']==='percent'){ $aktifTxt = (int)$disc['value']."%"; }
          else { $aktifTxt = 'Rp '.number_format((float)$disc['value'],0,',','.'); }
          if(!empty($disc['year'])){ $aktifTxt .= ' (Tahun '.$disc['year'].')'; }
        }
      ?>
      <?php if($aktifTxt): ?><div style="font-size:12px;color:#226622;margin-bottom:8px">Aktif: <strong><?php echo htmlspecialchars($aktifTxt,ENT_QUOTES,'UTF-8'); ?></strong></div><?php endif; ?>
      <form method="POST" class="form-inline">
        <input type="hidden" name="aksi" value="simpan_beasiswa" />
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <label for="tipe" style="display:block;font-size:12px;color:#555">Tipe Potongan</label>
            <select id="tipe" name="tipe">
              <option value="" <?php echo !$disc['type']?'selected':''; ?>>Tidak ada</option>
              <option value="percent" <?php echo ($disc['type']==='percent')?'selected':''; ?>>Persen (%)</option>
              <option value="nominal" <?php echo ($disc['type']==='nominal')?'selected':''; ?>>Nominal (Rp)</option>
            </select>
          </div>
          <div>
            <label for="nilai" style="display:block;font-size:12px;color:#555">Nilai</label>
            <input type="number" step="1" min="0" id="nilai" name="nilai" value="<?php echo $disc['value']!==null? (int)$disc['value'] : 0; ?>" />
          </div>
          
          <div>
            <label for="year" style="display:block;font-size:12px;color:#555">Tahun Berlaku</label>
            <?php $curY=(int)date('Y'); $yStart=$curY-1; $yEnd=$curY+4; $valY = !empty($disc['year'])?(int)$disc['year']:$curY; ?>
            <select id="year" name="year">
              <?php for($y=$yStart;$y<=$yEnd;$y++): ?>
                <option value="<?= $y ?>" <?= $y==$valY?'selected':'' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <button type="submit" class="btn">Simpan</button>
          </div>
        </div>
        <div style="font-size:12px;color:#666;margin-top:8px">Potongan otomatis diterapkan pada tagihan SPP yang dibuat setelah pengaturan disimpan. Jika ingin mengubah tagihan yang sudah ada, lakukan penyesuaian manual.</div>
      </form>
      <?php if($disc['type']): ?>
      <form method="POST" style="display:inline" data-confirm="Cabut beasiswa untuk pengguna ini?">
        <input type="hidden" name="aksi" value="cabut_beasiswa" />
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
        <input type="hidden" name="year" value="<?php echo isset($disc['year']) && $disc['year']?$disc['year']:(int)date('Y'); ?>" />
        <button type="submit" class="btn" style="background:#c0392b;border-color:#c0392b;margin-top:8px">Cabut Beasiswa</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="tabs-wrap user-tabs">
  <button class="tab-btn active" data-tab="spp">Tagihan <?= empty($tagihan)&&!empty($invoice_fallback)?'Invoice':'' ?> SPP</button>
    <button class="tab-btn" data-tab="rekap">Rekap Saldo</button>
    <button class="tab-btn" data-tab="semua">Semua Tagihan</button>
  </div>
  <div class="tab-content active" id="tab-spp">
    <table class="t-spp">
      <?php if(!empty($tagihan)): ?>
      <thead><tr><th>No</th><th>Bulan</th><th>Jumlah (Rp)</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php $i=1; foreach($tagihan as $t): $bulan = $t['deskripsi'] ?: date('M Y', strtotime($t['tanggal_upload']??'now')); ?>
          <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo htmlspecialchars($bulan); ?></td>
            <td><?php echo number_format($t['jumlah'],0,',','.'); ?></td>
            <td class="st-<?php echo $t['status']; ?>"><?php echo $t['status']; ?></td>
            <td>
              <?php if($t['status']==='menunggu_pembayaran'): ?>
                <form method="POST" style="display:inline" data-confirm="Hapus tagihan ini?">
                  <input type="hidden" name="aksi" value="hapus_tagihan" />
                  <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
                  <input type="hidden" name="transaksi_id" value="<?php echo (int)$t['id']; ?>" />
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                  <button type="submit" class="btn-inline" style="color:#c0392b;font-size:11px">Hapus</button>
                </form>
              <?php else: ?><span style="font-size:11px;color:#888">-</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if(empty($tagihan)): ?>
          <tr><td colspan="5" class="text-muted">Belum ada tagihan.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php else: // fallback invoice ?>
      <thead><tr><th>No</th><th>Jenis</th><th>Periode</th><th>Nominal</th><th>Bayar</th><th>Status</th><th>Jatuh Tempo</th></tr></thead>
      <tbody>
        <?php if(!empty($invoice_fallback)): $i=1; foreach($invoice_fallback as $iv): $amt=(float)$iv['amount']; $paid=(float)$iv['paid_amount']; $ratio=$amt>0?min(1,$paid/$amt):0; $pct=round($ratio*100,1); ?>
          <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo htmlspecialchars(strtoupper(str_replace('_',' ',$iv['type'])),ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($iv['period']??'-',ENT_QUOTES,'UTF-8'); ?></td>
            <td>Rp <?php echo number_format($amt,0,',','.'); ?></td>
            <td>Rp <?php echo number_format($paid,0,',','.'); ?><?php if($paid>0 && $paid<$amt) echo ' ('.$pct.'%)'; ?></td>
            <td class="st-<?php echo htmlspecialchars($iv['status'],ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($iv['status'],ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($iv['due_date']??'-',ENT_QUOTES,'UTF-8'); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7" class="text-muted">Belum ada invoice.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php endif; ?>
    </table>
  </div>
  <div class="tab-content" id="tab-rekap">
    <div class="rekap-box">
      <?php
        if(empty($ledger)) { echo '<p class="text-muted">Belum ada data ledger.</p>'; }
        $groupIn = []; $groupOut=[];
        foreach($ledger as $l){ $d=date('d M Y', strtotime($l['created_at'])); if($l['direction']==='credit'){ $groupIn[$d][]=$l; } else { $groupOut[$d][]=$l; } }
        $sumIn=0; foreach($groupIn as $g){ foreach($g as $l){ $sumIn+=$l['amount']; } }
        $sumOut=0; foreach($groupOut as $g){ foreach($g as $l){ $sumOut+=$l['amount']; } }
      ?>
      <div class="rekap-summary">
        <div class="col in">
          <h4>Pemasukan</h4>
          <div class="total plus">+ Rp <?php echo number_format($sumIn,0,',','.'); ?></div>
          <?php foreach($groupIn as $date=>$rows): ?>
            <div class="date-group"><div class="dg-head"><?php echo $date; ?></div>
              <?php foreach($rows as $row): ?>
                <div class="row plus"><span class="icon">💰</span> + Rp <?php echo number_format($row['amount'],0,',','.'); ?><button class="detail-btn" onclick="alert('Detail ledger #<?php echo (int)$row['id']; ?>')">Detail</button></div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="divider"></div>
        <div class="col out">
          <h4>Pengeluaran</h4>
          <div class="total minus">- Rp <?php echo number_format($sumOut,0,',','.'); ?></div>
          <?php foreach($groupOut as $date=>$rows): ?>
            <div class="date-group"><div class="dg-head"><?php echo $date; ?></div>
              <?php foreach($rows as $row): ?>
                <div class="row minus"><span class="icon">💸</span> - Rp <?php echo number_format($row['amount'],0,',','.'); ?><button class="detail-btn" onclick="alert('Detail ledger #<?php echo (int)$row['id']; ?>')">Detail</button></div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="tab-content" id="tab-semua">
    <table class="t-spp" style="min-width:880px">
      <thead><tr><th>No</th><th>Jenis</th><th>Periode / Deskripsi</th><th>Nominal</th><th>Dibayar</th><th>Status</th><th>Jatuh Tempo</th><th>Sumber</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php if($combined_all): $k=1; foreach($combined_all as $row): $amt=(float)$row['amount']; $paid=(float)$row['paid_amount']; $ratio=$amt>0?min(1,$paid/$amt):0; $pct=round($ratio*100,1); ?>
          <tr>
            <td><?php echo $k++; ?></td>
            <td><?php echo htmlspecialchars(strtoupper(str_replace('_',' ',$row['type'])),ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($row['period']??'-',ENT_QUOTES,'UTF-8'); ?></td>
            <td>Rp <?php echo number_format($amt,0,',','.'); ?></td>
            <td>Rp <?php echo number_format($paid,0,',','.'); ?><?php if($paid>0 && $paid<$amt) echo ' ('.$pct.'%)'; ?></td>
            <td class="st-<?php echo htmlspecialchars($row['status'],ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($row['status'],ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($row['due_date']??'-',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo $row['src']==='legacy'?'Transaksi Lama':'Invoice'; ?></td>
            <td>
              <?php if($row['src']==='legacy' && $row['status']==='menunggu_pembayaran'): ?>
                <form method="POST" style="display:inline" data-confirm="Hapus tagihan ini?">
                  <input type="hidden" name="aksi" value="hapus_tagihan" />
                  <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
                  <input type="hidden" name="transaksi_id" value="<?php echo (int)$row['id']; ?>" />
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                  <button type="submit" class="btn-inline" style="color:#c0392b;font-size:11px">Hapus</button>
                </form>
              <?php else: ?><span style="font-size:11px;color:#888">-</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="9" class="text-muted">Belum ada tagihan.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php /* Inline tab script removed due to CSP; handled globally in assets/js/ui.js */ ?>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
