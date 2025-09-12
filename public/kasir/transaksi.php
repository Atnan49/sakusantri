<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin'); // sementara admin sebagai kasir

// Avatar foto dinonaktifkan (gunakan inisial)
$hasAvatar=false; $avatarSelect = '';

// Ambil NISN dari GET untuk pencarian sederhana
$nisn = trim($_GET['nisn'] ?? '');
$pengguna = null;
if($nisn !== ''){
  if($stmt = mysqli_prepare($conn,'SELECT id,nama_wali,nama_santri,nisn,saldo'.$avatarSelect.' FROM users WHERE nisn=? AND role="wali_santri" LIMIT 1')){
    mysqli_stmt_bind_param($stmt,'s',$nisn); mysqli_stmt_execute($stmt); $res = mysqli_stmt_get_result($stmt); $pengguna = $res?mysqli_fetch_assoc($res):null;
  if(!$pengguna) $err = 'Akun dengan NIS itu tidak ditemukan';
  }
}

// Proses pembelian (debit) - fallback non-JS.
// DILENGKAPI: Post/Redirect/Get + proteksi duplikat agar reload tidak menduplikasi transaksi.
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['aksi']??'')==='beli'){
  $token = $_POST['csrf_token'] ?? '';
  if(!verify_csrf_token($token)){
    $err = 'Token tidak valid';
  } else {
    $uid = (int)($_POST['user_id']??0);
    $nominal = (int)($_POST['nominal']??0);
    $catatan = trim($_POST['catatan'] ?? 'Belanja koperasi');
    if($uid<=0 || $nominal<=0){ $err='Data tidak lengkap'; }
    else {
      // Cegah duplikat sangat cepat (misal F5 setelah POST) memakai signature sederhana tersimpan di session
      $sig = $uid.'|'.$nominal.'|'.date('YmdHis'); // include detik untuk granularitas
      $last = $_SESSION['kasir_last_sig']['sig'] ?? null;
      $lastTs = $_SESSION['kasir_last_sig']['ts'] ?? 0;
      if($last && $last === $sig && (time()-$lastTs) < 5){
        // Dianggap duplikat
        $_SESSION['kasir_flash'] = 'Transaksi duplikat diabaikan.';
        header('Location: '.url('kasir/transaksi?nisn='.rawurlencode($nisn)));
        exit;
      }
      @mysqli_begin_transaction($conn);
      $lock = mysqli_prepare($conn,'SELECT saldo FROM users WHERE id=? FOR UPDATE');
      if($lock){ mysqli_stmt_bind_param($lock,'i',$uid); mysqli_stmt_execute($lock); $rs= mysqli_stmt_get_result($lock); $row=$rs?mysqli_fetch_assoc($rs):null; }
      if(empty($row)){ @mysqli_rollback($conn); $err='Pengguna tidak ditemukan'; }
      elseif((int)$row['saldo'] < $nominal){ @mysqli_rollback($conn); $err='Saldo tidak cukup'; }
      else {
  // Pencatatan baru: gunakan ledger_entries agar konsisten dengan halaman wali
  require_once BASE_PATH.'/src/includes/payments.php';
  // Ledger: credit WALLET (keluar uang)
  ledger_post($conn,$uid,'WALLET',0,(float)$nominal,'purchase',null,$catatan);
  // (Opsional) Simpan jejak tambahan di wallet_ledger lama untuk kompatibilitas
  $ins = mysqli_prepare($conn,'INSERT INTO wallet_ledger (user_id,direction,amount,ref_type,ref_id,note) VALUES (? ,"debit", ?, "purchase", NULL, ?)');
  if($ins){ $nF=(float)$nominal; mysqli_stmt_bind_param($ins,'ids',$uid,$nF,$catatan); mysqli_stmt_execute($ins); }
  // Sinkronisasi saldo cache
  @mysqli_query($conn,'UPDATE users u SET saldo = (SELECT COALESCE(SUM(debit-credit),0) FROM ledger_entries le WHERE le.user_id='.(int)$uid.' AND le.account="WALLET") WHERE u.id='.(int)$uid.' LIMIT 1');
  add_notification($conn,$uid,'purchase','Belanja koperasi Rp '.number_format($nominal,0,',','.'));
  @mysqli_commit($conn);
  $_SESSION['kasir_last_sig'] = ['sig'=>$sig,'ts'=>time()];
  $_SESSION['kasir_flash'] = 'Transaksi diproses.';
  // refresh data pengguna (saldo terbaru)
  if($stmt = mysqli_prepare($conn,'SELECT id,nama_wali,nama_santri,nisn,saldo'.$avatarSelect.' FROM users WHERE id=? LIMIT 1')){ mysqli_stmt_bind_param($stmt,'i',$uid); mysqli_stmt_execute($stmt); $r=mysqli_stmt_get_result($stmt); $pengguna=$r?mysqli_fetch_assoc($r):$pengguna; }
  $nisn = $pengguna['nisn'] ?? $nisn; // kalau kolom nisn tidak di select sebelumnya aman di GET
  // Redirect (PRG) supaya reload tidak kirim ulang POST
  header('Location: '.url('kasir/transaksi?nisn='.rawurlencode($nisn)));
  exit;
      }
    }
  }
}

// Transaksi terbaru
$recent=[]; if($conn){
  if($rsR=mysqli_query($conn,"SELECT l.id,l.amount,l.created_at,u.nama_santri FROM wallet_ledger l JOIN users u ON l.user_id=u.id WHERE l.ref_type='purchase' ORDER BY l.id DESC LIMIT 8")){
    while($r=mysqli_fetch_assoc($rsR)) $recent[]=$r;
  }
}

require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell kasir-page minimal">
  <div class="content-header">
    <h1>Kasir Koperasi</h1>
    <div class="quick-actions-inline"></div>
  </div>
  <?php if(!empty($_SESSION['kasir_flash'])): ?><div class="alert success" role="alert"><?= e($_SESSION['kasir_flash']) ?></div><?php unset($_SESSION['kasir_flash']); endif; ?>
  <?php if(!empty($err)): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="get" class="kasir-search-pill" autocomplete="off">
    <div class="pill">
      <span class="icon" aria-hidden="true">🔍</span>
  <input type="text" name="nisn" value="<?= e($nisn) ?>" placeholder="Masukkan / scan NIS" autofocus />
  <?php if($nisn!==''): ?><button type="button" class="clear js-clear-nisn" aria-label="Bersihkan">&times;</button><?php endif; ?>
    </div>
    <noscript><button class="btn-action primary">Cari</button></noscript>
  </form>

  <?php if($pengguna): ?>
  <div class="kasir-card pengguna-block">
    <div class="pb-top">
      <div class="pb-ident">
        <?php $initial = mb_strtoupper(mb_substr($pengguna['nama_santri']??'',0,1,'UTF-8'),'UTF-8'); ?>
        <div class="pb-avatar avatar-sm no-img"><span class="av-initial" aria-hidden="true"><?= e($initial) ?></span></div>
        <div class="nm-santri"><?= e($pengguna['nama_santri']) ?></div>
        <div class="nm-wali">Wali: <span><?= e($pengguna['nama_wali']) ?></span></div>
      </div>
      <div class="pb-saldo"><span class="label">Saldo</span><span class="val">Rp <?= number_format((float)$pengguna['saldo'],0,',','.') ?></span></div>
    </div>
    <form method="post" class="form-beli compact js-kasir-form" autocomplete="off" data-user="<?= (int)$pengguna['id'] ?>">
      <input type="hidden" name="aksi" value="beli" />
      <input type="hidden" name="user_id" value="<?= (int)$pengguna['id'] ?>" />
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
      <div class="fld">
        <label>Nominal</label>
        <input type="text" id="nominalDisplay" placeholder="Rp 0" inputmode="numeric" autocomplete="off" required />
        <input type="hidden" name="nominal" id="nominal" />
      </div>
      <div class="quick-amounts" aria-label="Jumlah cepat">
        <?php foreach([5000,10000,20000,50000,100000] as $q): ?>
          <button type="button" class="qa-chip" data-val="<?= $q ?>"><?= number_format($q/1000,0) ?>k</button>
        <?php endforeach; ?>
      </div>
      <div class="fld">
        <label>Catatan</label>
        <input type="text" name="catatan" value="Belanja koperasi" />
      </div>
      <div class="actions"><button type="submit" class="btn-action primary">Proses</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="kasir-recent">
    <h2 class="section-title">Transaksi Terakhir</h2>
    <div class="recent-list simple">
      <?php if(!$recent): ?>
        <div class="empty">Belum ada transaksi.</div>
      <?php else: foreach($recent as $r): ?>
        <div class="rc-row"><div class="rc-main"><span class="rc-name"><?= e($r['nama_santri']) ?></span><span class="rc-time"><?= date('d M H:i',strtotime($r['created_at'])) ?></span></div><div class="rc-amt">- Rp <?= number_format($r['amount'],0,',','.') ?></div></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<script src="<?= url('assets/js/kasir_transaksi.js'); ?>?v=20250901a" defer></script>
<script defer>
(function(){
  const form = document.querySelector('.js-kasir-form');
  if(!form) return;
  const saldoEl = document.querySelector('.pb-saldo .val');
  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    const fd = new FormData(form);
    const nominal = parseInt(fd.get('nominal')||'0',10);
    if(!nominal || nominal<=0){ alert('Nominal belum diisi'); return; }
    form.querySelector('button[type=submit]').disabled = true;
    fetch('<?= url('admin/ajax_kasir_beli.php'); ?>', {method:'POST', body:fd, credentials:'same-origin'})
      .then(r=>r.json()).then(j=>{
        form.querySelector('button[type=submit]').disabled = false;
        if(!j.ok){ alert(j.msg||'Gagal'); return; }
        // Update saldo tampilan
        if(typeof j.saldo !== 'undefined' && saldoEl){ saldoEl.textContent = 'Rp '+Number(j.saldo).toLocaleString('id-ID'); }
        // Broadcast ke tab lain (wali dashboard / riwayat) via localStorage event
        try{
          localStorage.setItem('wallet_update', JSON.stringify({uid: form.dataset.user, ts: Date.now(), saldo: j.saldo}));
          // Hapus key agar event bisa dipicu lagi segera (optional)
          setTimeout(()=>localStorage.removeItem('wallet_update'),150);
        }catch(e){}
        // Reset input nominal
        const hiddenNom = document.getElementById('nominal');
        const disp = document.getElementById('nominalDisplay');
        if(hiddenNom) hiddenNom.value=''; if(disp) { disp.value=''; }
  // Trigger custom event so kasir_transaksi.js can enforce new entry
  window.dispatchEvent(new Event('kasir:trx:success'));
      }).catch(e=>{ form.querySelector('button[type=submit]').disabled = false; alert('Terjadi kesalahan jaringan'); });
  });
})();
</script>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
