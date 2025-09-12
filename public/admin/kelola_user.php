<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
$pesan_error = $pesan_error ?? null; // init if not set
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 40; $offset=($page-1)*$perPage;

// Handle POST actions (add, reset password, delete) — avatar di-nonaktifkan (gunakan inisial saja)
if($_SERVER['REQUEST_METHOD']==='POST'){
    $token = $_POST['csrf_token'] ?? '';
    if(!verify_csrf_token($token)){
        $pesan_error='Token tidak valid.';
    } else {
        $aksi = $_POST['aksi'] ?? '';

        // Hapus user
        if($aksi==='hapus_user'){
            $uid=(int)($_POST['user_id']??0);
            if($uid>0){
                if($cek = mysqli_prepare($conn,"SELECT id,nama_wali,nama_santri FROM users WHERE id=? AND role='wali_santri' LIMIT 1")){
                    mysqli_stmt_bind_param($cek,'i',$uid); mysqli_stmt_execute($cek); $res=mysqli_stmt_get_result($cek); $row=$res?mysqli_fetch_assoc($res):null;
                }
                if(!empty($row)){
                    if($del = mysqli_prepare($conn,'DELETE FROM users WHERE id=? LIMIT 1')){
                        mysqli_stmt_bind_param($del,'i',$uid); mysqli_stmt_execute($del);
                        if(mysqli_affected_rows($conn)>0){
                            $pesan='Pengguna dihapus.';
                            if(function_exists('audit_log')) audit_log($conn,(int)($_SESSION['user_id']??0),'delete_user','users',$uid,['nama_wali'=>$row['nama_wali']]);
                        } else $pesan_error='Gagal hapus.';
                    }
                } else { $pesan_error='Pengguna tidak ditemukan.'; }
            }

        // Reset password
        } elseif($aksi==='reset_password'){
            $uid=(int)($_POST['user_id']??0); $np=$_POST['new_password']??''; $cp=$_POST['confirm_password']??'';
            if($uid>0 && $np!=='' && $np===$cp && strlen($np)>=8){
                if($cek=mysqli_prepare($conn,"SELECT id FROM users WHERE id=? AND role='wali_santri' LIMIT 1")){
                    mysqli_stmt_bind_param($cek,'i',$uid); mysqli_stmt_execute($cek); $r=mysqli_stmt_get_result($cek);
                    if($r && mysqli_fetch_assoc($r)){
                        $hash=password_hash($np,PASSWORD_DEFAULT);
                        if($upd=mysqli_prepare($conn,'UPDATE users SET password=? WHERE id=?')){
                            mysqli_stmt_bind_param($upd,'si',$hash,$uid);
                            if(mysqli_stmt_execute($upd)) $pesan='Password direset.'; else $pesan_error='Gagal reset.';
                        }
                    }
                }
            } else { $pesan_error='Data reset tidak valid.'; }

        // Tambah user baru
    } elseif($aksi==='tambah_user'){
            $nama_wali=trim((string)($_POST['nama_wali']??''));
            $nama_santri=trim((string)($_POST['nama_santri']??''));
            $nisn_raw=trim((string)($_POST['nisn']??''));
            // Biarkan alfanumerik & beberapa simbol sederhana, tolak lainnya
            $nisn = preg_replace('/[^A-Za-z0-9._-]/','', $nisn_raw);
            $pw=(string)($_POST['password']??'');
            // Avatar foto dinonaktifkan: kita tidak terima upload dan tidak menyimpan kolom avatar
            if($nama_wali===''||$nama_santri===''||$nisn_raw===''||$pw===''){
                $pesan_error='Semua field wajib diisi.';
            } elseif(strlen($pw)<8){
                $pesan_error='Password minimal 8 karakter.';
            } elseif(strlen($nisn)<3){
                $pesan_error='NIS minimal 3 karakter.';
            } else {
                // pre-check duplikat
                if($stDup=mysqli_prepare($conn,"SELECT 1 FROM users WHERE nisn=? LIMIT 1")){
                    mysqli_stmt_bind_param($stDup,'s',$nisn); mysqli_stmt_execute($stDup); $rsDup=mysqli_stmt_get_result($stDup); if($rsDup && mysqli_fetch_row($rsDup)){ $pesan_error='NIS sudah terdaftar.'; }
                }
                // tidak ada proses upload avatar
                
                if(empty($pesan_error)){
                    $hash=password_hash($pw,PASSWORD_DEFAULT);
                    $sqlIns="INSERT INTO users (nama_wali,nama_santri,nisn,password,role) VALUES (?,?,?,?, 'wali_santri')";
                    $ins=mysqli_prepare($conn,$sqlIns);
                    if($ins){
                        mysqli_stmt_bind_param($ins,'ssss',$nama_wali,$nama_santri,$nisn,$hash);
                        if(mysqli_stmt_execute($ins)){
                            $pesan='Pengguna baru ditambahkan.';
                            // Sengaja tidak mengirim notifikasi ke pengguna baru (permintaan: jangan tampil di sisi wali)
                            // Jika ingin log internal, bisa gunakan audit_log di sini.
                            if(function_exists('audit_log')){ audit_log($conn,(int)($_SESSION['user_id']??0),'create_user','users',mysqli_insert_id($conn),['source'=>'kelola_user']); }
                        } else {
                            $errNo=mysqli_errno($conn); $errMsg=mysqli_error($conn);
                            if($errNo==1062){
                                $pesan_error='NIS sudah terdaftar.';
                            } else {
                                $pesan_error='Gagal tambah pengguna ('.$errNo.').';
                            }
                            error_log('[kelola_user] insert fail errno='.$errNo.' msg='.$errMsg.' sql='+$sqlIns);
                        }
                    } else {
                        $errMsg=mysqli_error($conn); $errNo=mysqli_errno($conn);
                        $pesan_error='Gagal menyiapkan insert ('.$errNo.').';
                        error_log('[kelola_user] prepare fail errno='.$errNo.' msg='.$errMsg.' sqlAttempt='+$sqlIns);
                    }
                }
            }

        // Aksi update avatar dihapus (fitur dinonaktifkan)
        }
    }
}

// Build WHERE for listing
$conds=["role='wali_santri'"];
if($q!==''){
    $safe='%'.mysqli_real_escape_string($conn,$q).'%';
    $conds[]="(nama_wali LIKE '$safe' OR nama_santri LIKE '$safe' OR nisn LIKE '$safe')";
}
$where=implode(' AND ',$conds);
$totalFiltered=0; if($rsC=mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE $where")){ if($rw=mysqli_fetch_assoc($rsC)) $totalFiltered=(int)$rw['c']; }
$totalAll=0; if($rsA=mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE role='wali_santri'")){ if($ra=mysqli_fetch_assoc($rsA)) $totalAll=(int)$ra['c']; }
$avgSaldo=0; if($rsS=mysqli_query($conn,"SELECT AVG(saldo) a FROM users WHERE role='wali_santri'")){ if($rS=mysqli_fetch_assoc($rsS)) $avgSaldo=(float)$rS['a']; }

// Cek kolom avatar & saldo (beberapa instalasi lama belum migrasi)
$hasAvatar=false; // avatar foto dinonaktifkan
$hasSaldo=false; if($chk2=mysqli_query($conn,"SHOW COLUMNS FROM users LIKE 'saldo'")){ if(mysqli_fetch_assoc($chk2)) $hasSaldo=true; }
// Jika saldo belum ada, coba otomatis tambahkan sekali (aman default 0)
if(!$hasSaldo){
    @mysqli_query($conn,"ALTER TABLE users ADD COLUMN saldo DECIMAL(12,2) NOT NULL DEFAULT 0");
    if($chk3=mysqli_query($conn,"SHOW COLUMNS FROM users LIKE 'saldo'")){ if(mysqli_fetch_assoc($chk3)) $hasSaldo=true; }
}
$selectAvatar = '';
$saldoSelect = $hasSaldo? 'saldo,' : '0 AS saldo,';
// Sesuaikan avg saldo
if(!$hasSaldo){ $avgSaldo = 0; }

$users=[]; $sql="SELECT id,nama_wali,nama_santri,nisn,$saldoSelect $selectAvatar (SELECT COUNT(*) FROM transaksi t WHERE t.user_id=users.id AND t.jenis_transaksi='spp' AND t.status='menunggu_pembayaran') spp_due FROM users WHERE $where ORDER BY spp_due DESC, id DESC LIMIT $offset,$perPage"; $res=mysqli_query($conn,$sql); while($res && $r=mysqli_fetch_assoc($res)){ if(!$hasAvatar){ $r['avatar']=''; } if(!$hasSaldo){ $r['saldo']=0; } $users[]=$r; }
$totalPages=max(1, (int)ceil($totalFiltered/$perPage));

require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell kelola-user-page enhanced">
    <div class="content-header">
        <h1>Kelola Pengguna</h1>
        <div class="quick-actions-inline">
            <button class="qa-btn" type="button" id="btnOpenAdd">+Tambah</button>
        </div>
    </div>
    <div class="inv-chips user-chips compact" aria-label="Ringkasan pengguna">
        <div class="inv-chip info"><span class="k">Total</span><span class="v"><?= number_format($totalAll) ?></span></div>
        <div class="inv-chip ok"><span class="k">Ditampilkan</span><span class="v"><?= number_format($totalFiltered) ?></span></div>
        <div class="inv-chip"><span class="k">Rata2 Saldo</span><span class="v">Rp <?= number_format($avgSaldo,0,',','.') ?></span></div>
    </div>
    <?php if(isset($pesan)): ?><div class="alert success" role="alert"><?= e($pesan) ?></div><?php endif; ?>
    <?php if(isset($pesan_error)): ?><div class="alert error" role="alert"><?= e($pesan_error) ?></div><?php endif; ?>
    <form method="get" class="user-filter simple-search" autocomplete="off" id="searchForm">
        <div class="search-pill">
            <span class="icon" aria-hidden="true">🔍</span>
            <input type="text" id="fQ" name="q" value="<?= e($q) ?>" placeholder="Cari nama / wali / NIS" autocomplete="off" />
            <button type="button" class="clear" id="btnClearSearch" aria-label="Bersihkan" <?= $q===''?'hidden':'' ?>>&times;</button>
        </div>
        <?php if($q!==''): ?><a class="reset-link" href="kelola_user.php">Reset</a><?php endif; ?>
        <noscript><button class="btn-action primary">Cari</button></noscript>
    </form>
    <div class="table-scroll-wrap">
        <table class="pengguna-table ku-table refined">
            <thead><tr><th>#</th><th>Santri</th><th>NIS</th><th>Saldo</th><th>SPP</th><th>Wali</th><th>Aksi</th></tr></thead>
            <tbody>
                <?php $i=$offset+1; if(!empty($users)): foreach($users as $u): ?>
                <tr>
                    <td data-th="#" class="row-num"><?= $i++ ?></td>
                    <td data-th="Santri" class="col-santri with-avatar">
                        <?php $initial = mb_strtoupper(mb_substr($u['nama_santri']??'',0,1,'UTF-8'),'UTF-8'); ?>
                        <div class="avatar-sm no-img"><span class="av-initial" aria-hidden="true"><?= e($initial) ?></span></div>
                        <div class="sn-block">
                            <a class="row-link" href="<?= url('admin/pengguna-detail?id='.(int)$u['id']); ?>" title="Detail pengguna"><?= e($u['nama_santri']) ?></a>
                            <div class="nisn-mobile"><code><?= e($u['nisn']) ?></code></div>
                        </div>
                    </td>
                    <td data-th="NIS" class="col-nisn"><code><?= e($u['nisn']) ?></code></td>
                    <td data-th="Saldo" class="col-saldo text-end"><span class="chip saldo <?= (float)$u['saldo']<=0?'zero':'' ?>">Rp <?= number_format($u['saldo'],0,',','.') ?></span></td>
                    <td data-th="SPP" class="col-spp text-center"><?php if((int)$u['spp_due']>0): ?><span class="chip due-mini" title="Tagihan menunggu"><?= (int)$u['spp_due'] ?></span><?php else: ?><span class="chip ok" style="font-size:10px">Lunas</span><?php endif; ?></td>
                    <td data-th="Wali" class="col-wali"><span class="wali-name-short" title="<?= e($u['nama_wali']) ?>"><?= e($u['nama_wali']) ?></span></td>
                    <td data-th="Aksi" class="col-aksi">
                        <div class="action-row">
                            <button type="button" class="mini-btn reset-toggle" data-id="<?= (int)$u['id'] ?>">Reset PW</button>
                            
                            <form method="POST" action="" data-confirm="Hapus pengguna & transaksi terkait?" style="display:inline">
                                <input type="hidden" name="aksi" value="hapus_user" />
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>" />
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                                <button type="submit" class="mini-btn danger">Hapus</button>
                            </form>
                        </div>
                        <div class="reset-inline-wrap" id="resetPanel<?= (int)$u['id'] ?>" hidden>
                        
                            <form class="reset-inline" method="POST" action="" data-confirm="Reset password?">
                                <input type="hidden" name="aksi" value="reset_password" />
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>" />
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                                <input type="password" name="new_password" minlength="8" placeholder="Password Baru" />
                                <input type="password" name="confirm_password" minlength="8" placeholder="Konfirmasi" />
                                <button class="mini-btn" type="submit">Simpan</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="7" style="text-align:center;padding:18px 8px;color:#64748b;font-size:13px">Tidak ada data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if($totalPages>1): ?>
        <nav class="pager" aria-label="Pagination">
            <?php $params=$_GET; unset($params['p']); $build=function($p) use ($params){ return '?'.http_build_query(array_merge($params,['p'=>$p])); }; ?>
            <a class="pg-btn" href="<?= $build(max(1,$page-1)) ?>" aria-label="Prev" <?= $page==1?'aria-disabled="true"':'' ?>>&laquo;</a>
            <?php $win=3; $start=max(1,$page-$win); $end=min($totalPages,$page+$win); if($start>1) echo '<span class="pg-ellipsis">…</span>'; for($p=$start;$p<=$end;$p++){ echo '<a class="pg-btn '.($p==$page?'active':'').'" href="'.$build($p).'">'.$p.'</a>'; } if($end<$totalPages) echo '<span class="pg-ellipsis">…</span>'; ?>
            <a class="pg-btn" href="<?= $build(min($totalPages,$page+1)) ?>" aria-label="Next" <?= $page==$totalPages?'aria-disabled="true"':'' ?>>&raquo;</a>
        </nav>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<div class="overlay-modal" id="addUserModal" hidden>
    <div class="om-backdrop" data-close></div>
    <div class="om-card" role="dialog" aria-modal="true" aria-labelledby="addUserTitle">
        <button class="om-close" type="button" data-close>&times;</button>
        <h2 id="addUserTitle">Tambah Pengguna</h2>
    <form method="POST" action="" class="form-vertical" autocomplete="off">
            <input type="hidden" name="aksi" value="tambah_user" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
            <label>Nama Wali<input type="text" name="nama_wali" required /></label>
            <label>Nama Santri<input type="text" name="nama_santri" required /></label>
            <label>NIS<input type="text" name="nisn" required /></label>
            <label>Password Awal<input type="password" name="password" minlength="8" required /></label>
            
            <div class="om-actions"><button class="btn-action primary" type="submit">Simpan</button><button class="btn-action" type="button" data-close>Batal</button></div>
        </form>
    </div>
</div>

<script src="../assets/js/admin_users.js" defer></script>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>