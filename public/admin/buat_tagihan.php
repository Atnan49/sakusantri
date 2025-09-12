<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

$pesan = $pesan_error = null;

// Deteksi apakah kolom deleted_at tersedia (agar kode tetap jalan di DB lama)
$hasDeletedAt = false;
if($conn){
  if($resCol = mysqli_query($conn, "SHOW COLUMNS FROM transaksi LIKE 'deleted_at'")){
    $hasDeletedAt = mysqli_num_rows($resCol) > 0;
  }
}
$deletedFilter = $hasDeletedAt ? " AND deleted_at IS NULL" : ""; // sisipkan hanya bila kolom ada

// Nama bulan Indonesia untuk generate otomatis
$bulan_nama = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// Tahun yang dipilih di UI (default sekarang, bisa diganti via GET year)
$tahun_pilih = (int)($_GET['year'] ?? date('Y'));
if($tahun_pilih < date('Y')-1 || $tahun_pilih > date('Y')+5){ $tahun_pilih = (int)date('Y'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // DEBUG: log semua input POST dan error utama ke file dan ke layar (sementara)
  $debug_log = __DIR__ . '/debug_buat_tagihan.log';
  $debug_data = [
    'datetime' => date('Y-m-d H:i:s'),
    'POST' => $_POST,
    'pesan' => $pesan,
    'pesan_error' => $pesan_error,
    'SERVER' => $_SERVER
  ];
  file_put_contents($debug_log, print_r($debug_data,1)."\n---\n", FILE_APPEND);
  echo '<pre style="background:#ffe;border:1px solid #cc0;padding:8px;font-size:12px;">DEBUG:<br>';
  print_r($debug_data);
  echo '</pre>';
  $token = $_POST['csrf_token'] ?? '';
  if (!verify_csrf_token($token)) {
    $pesan_error = 'Token tidak valid.';
  } else {
    // QUICK GENERATE: buat tagihan SPP untuk bulan tertentu (untuk semua wali) jika belum ada
    if(isset($_POST['generate_month'])){
      $bulan_gen = (int)($_POST['bulan_gen'] ?? 0); // 1..12
      $tahun_gen = (int)($_POST['tahun_gen'] ?? (int)date('Y'));
      $jumlah_gen = isset($_POST['jumlah_gen']) ? (float)$_POST['jumlah_gen'] : 0;
      if($bulan_gen < 1 || $bulan_gen > 12){ $pesan_error='Bulan tidak valid.'; }
      elseif($jumlah_gen <= 0){ $pesan_error='Nominal harus > 0.'; }
      else {
        $desc = 'SPP Bulan '.$bulan_nama[$bulan_gen-1].' '.$tahun_gen;
        // Ambil semua user wali_santri
        $res_users = mysqli_query($conn, "SELECT id FROM users WHERE role='wali_santri'");
        if($res_users){
          $created = 0;
          $stmtCheck = mysqli_prepare($conn, "SELECT COUNT(*) FROM transaksi WHERE user_id=? AND jenis_transaksi='spp' AND deskripsi=?".$deletedFilter);
          $stmtIns = mysqli_prepare($conn, "INSERT INTO transaksi (user_id, jenis_transaksi, deskripsi, jumlah, status) VALUES (?, 'spp', ?, ?, 'menunggu_pembayaran')");
          if($stmtCheck && $stmtIns){
            while($u = mysqli_fetch_assoc($res_users)){
              $uid = (int)$u['id'];
              // Cek apakah user ini sudah punya tagihan bulan ini
              mysqli_stmt_reset($stmtCheck);
              mysqli_stmt_bind_param($stmtCheck, 'is', $uid, $desc);
              mysqli_stmt_execute($stmtCheck);
              $exists = false;
              $countChk = 0;
              if (function_exists('mysqli_stmt_get_result')) {
                $resChk = mysqli_stmt_get_result($stmtCheck);
                $rowChk = $resChk ? mysqli_fetch_row($resChk) : null;
                $exists = $rowChk && $rowChk[0] > 0;
              } else {
                mysqli_stmt_bind_result($stmtCheck, $countChk);
                if (mysqli_stmt_fetch($stmtCheck)) {
                  $exists = $countChk > 0;
                }
                mysqli_stmt_free_result($stmtCheck);
              }
              if(!$exists){
                mysqli_stmt_reset($stmtIns);
                mysqli_stmt_bind_param($stmtIns,'isd',$uid,$desc,$jumlah_gen);
                mysqli_stmt_execute($stmtIns);
                if(mysqli_stmt_affected_rows($stmtIns)>0){ $created++; }
              }
            }
            if($created>0){
              $pesan = 'Tagihan SPP '.$bulan_nama[$bulan_gen-1].' '.$tahun_gen.' berhasil dibuat untuk '.$created.' wali.';
            } else {
              $pesan_error = 'Semua wali sudah memiliki tagihan bulan ini.';
            }
          } else { $pesan_error='Gagal menyiapkan query.'; }
        } else { $pesan_error='Gagal mengambil data wali santri.'; }
      }
    }
    // UPDATE NOMINAL: ubah jumlah tagihan bulan tertentu (hanya yang status menunggu_pembayaran)
    elseif(isset($_POST['update_month'])){
      $bulan_up = (int)($_POST['bulan_gen'] ?? 0);
      $tahun_up = (int)($_POST['tahun_gen'] ?? (int)date('Y'));
      $jumlah_up = isset($_POST['jumlah_gen']) ? (float)$_POST['jumlah_gen'] : 0;
      if($bulan_up <1 || $bulan_up>12){ $pesan_error='Bulan tidak valid.'; }
      elseif($jumlah_up <=0){ $pesan_error='Nominal harus > 0.'; }
      else {
        $desc = 'SPP Bulan '.$bulan_nama[$bulan_up-1].' '.$tahun_up;
  $sqlUp = "UPDATE transaksi SET jumlah=? WHERE jenis_transaksi='spp' AND deskripsi=? AND status='menunggu_pembayaran'".$deletedFilter;
  $stmtU = mysqli_prepare($conn, $sqlUp);
        if($stmtU){
          mysqli_stmt_bind_param($stmtU,'ds',$jumlah_up,$desc);
          mysqli_stmt_execute($stmtU);
          $aff = mysqli_stmt_affected_rows($stmtU);
          if($aff>0){ $pesan = 'Nominal diperbarui untuk '.$aff.' tagihan '.$bulan_nama[$bulan_up-1].' '.$tahun_up.'.'; }
          else { $pesan_error='Tidak ada tagihan menunggu pembayaran untuk bulan itu atau sudah dihapus.'; }
        } else { $pesan_error='Query update gagal.'; }
      }
    }
    // Hapus tagihan (hanya status menunggu_pembayaran)
    elseif (isset($_POST['hapus_id'])) {
      $hapus_id = (int)$_POST['hapus_id'];
      if ($hapus_id > 0) {
                $sqlSelDel = "SELECT id, user_id, status FROM transaksi WHERE id=? AND jenis_transaksi='spp'".$deletedFilter." LIMIT 1";
                $stmtDel = mysqli_prepare($conn, $sqlSelDel);
        if ($stmtDel) {
          mysqli_stmt_bind_param($stmtDel,'i',$hapus_id);
          mysqli_stmt_execute($stmtDel);
          $resDel = mysqli_stmt_get_result($stmtDel);
          $rowDel = $resDel?mysqli_fetch_assoc($resDel):null;
          if ($rowDel) {
            if ($rowDel['status'] === 'menunggu_pembayaran') {
              if($hasDeletedAt){
                mysqli_query($conn, "UPDATE transaksi SET deleted_at=NOW() WHERE id=".$hapus_id." AND deleted_at IS NULL");
                $deletedOk = mysqli_affected_rows($conn) > 0;
              } else {
                // fallback hard delete jika kolom belum ada
                mysqli_query($conn, "DELETE FROM transaksi WHERE id=".$hapus_id." LIMIT 1");
                $deletedOk = mysqli_affected_rows($conn) > 0;
              }
              if ($deletedOk) {
                $pesan = $hasDeletedAt ? 'Tagihan berhasil dihapus (soft delete).' : 'Tagihan dihapus.';
                @add_notification($conn, (int)$rowDel['user_id'], 'spp_delete', 'Tagihan SPP dihapus oleh admin.');
                if(function_exists('audit_log')){ audit_log($conn, (int)($_SESSION['user_id']??null), 'delete_tagihan', 'transaksi', $hapus_id, ['jenis'=>'spp','status_before'=>$rowDel['status']]); }
              } else {
                $pesan_error = 'Gagal menghapus tagihan.';
              }
            } else {
              $pesan_error = 'Tagihan tidak dapat dihapus (sudah diproses).';
            }
          } else {
            $pesan_error = 'Tagihan tidak ditemukan.';
          }
        } else {
          $pesan_error = 'Query hapus tidak dapat disiapkan.';
        }
      }
  }
  }
}

// Ambil beberapa tagihan SPP terakhir untuk daftar ringkas
$recent = mysqli_query($conn, "SELECT id, deskripsi, jumlah, status, tanggal_upload FROM transaksi WHERE jenis_transaksi='spp'".$deletedFilter." ORDER BY id DESC LIMIT 12");
$recentRows=[];while($recent && $r=mysqli_fetch_assoc($recent)){$recentRows[]=$r;}

// Data status per bulan untuk UI quick generate
$status_bulan = [];
for($i=1;$i<=12;$i++){
  $desc = 'SPP Bulan '.$bulan_nama[$i-1].' '.$tahun_pilih;
  $sqlStatus = "SELECT COUNT(*) c, SUM(CASE WHEN status='menunggu_pembayaran' THEN 1 ELSE 0 END) m FROM transaksi WHERE jenis_transaksi='spp' AND deskripsi=?".$deletedFilter;
  $q = mysqli_prepare($conn, $sqlStatus);
  if($q){
    mysqli_stmt_bind_param($q,'s',$desc);
    mysqli_stmt_execute($q);
    $res = mysqli_stmt_get_result($q);
    $row = $res?mysqli_fetch_assoc($res):null;
    $status_bulan[$i] = [
      'total' => $row? (int)$row['c'] : 0,
      'menunggu' => $row? (int)$row['m'] : 0,
      'desc' => $desc
    ];
  } else {
    $status_bulan[$i] = ['total'=>0,'menunggu'=>0,'desc'=>$desc];
  }
}

require_once __DIR__ . '/../../src/includes/header.php';
?>
<main class="container spp-create">
  <h1 class="admin-heading" style="margin-top:0">Buat Tagihan SPP</h1>
  <?php if($pesan): ?><div class="pill pill-lunas" style="margin:0 0 16px;font-size:13px"><?php echo e($pesan); ?></div><?php endif; ?>
  <?php if($pesan_error): ?><div class="pill pill-ditolak" style="margin:0 0 16px;font-size:13px"><?php echo e($pesan_error); ?></div><?php endif; ?>

  <div class="panel" style="max-width:1000px;margin:0 0 40px">
    <div class="panel-header" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
      <h2 style="margin:0;font-size:20px">Generate Cepat Per Bulan</h2>
      <form method="GET" style="display:flex;gap:6px;align-items:center;margin:0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
        <label style="font-size:12px">Tahun:</label>
        <select name="year" class="input" style="height:34px;font-size:13px" onchange="this.form.submit()">
          <?php for($ty=date('Y')-1;$ty<=date('Y')+5;$ty++): ?>
            <option value="<?php echo $ty; ?>" <?php if($ty==$tahun_pilih) echo 'selected'; ?>><?php echo $ty; ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>
    <div style="margin:12px 0 18px;font-size:12px;color:#666;line-height:1.5">Klik tombol bulan untuk membuat tagihan bagi SEMUA wali santri. Sistem otomatis menolak duplikasi jika bulan sudah dibuat. Gunakan tombol Update untuk mengubah nominal (hanya mempengaruhi tagihan yang masih berstatus menunggu_pembayaran).</div>
  <form id="quickGenForm" method="POST" style="display:none">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
      <input type="hidden" name="bulan_gen" id="bulan_gen" />
      <input type="hidden" name="tahun_gen" value="<?php echo $tahun_pilih; ?>" />
      <input type="hidden" name="jumlah_gen" id="jumlah_gen" />
      <input type="hidden" name="generate_month" value="1" id="action_generate" />
    </form>
    <form id="quickUpdateForm" method="POST" style="display:none">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
      <input type="hidden" name="bulan_gen" id="bulan_gen_up" />
      <input type="hidden" name="tahun_gen" value="<?php echo $tahun_pilih; ?>" />
      <input type="hidden" name="jumlah_gen" id="jumlah_gen_up" />
      <input type="hidden" name="update_month" value="1" />
    </form>
    <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-bottom:16px">
      <div>
        <label style="font-size:12px;font-weight:600;display:block;margin:0 0 4px">Nominal (Rp)</label>
        <input type="text" id="quickNominalDisplay" class="input" placeholder="Rp 0" style="height:42px;width:180px" inputmode="numeric" />
        <input type="hidden" id="quickNominal" value="0" />
      </div>
      <div style="font-size:11px;color:#888;max-width:300px;line-height:1.4">Nilai ini dipakai saat men-generate bulan baru atau update nominal.</div>
    </div>
    <div class="month-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">
      <?php for($i=1;$i<=12;$i++): $st = $status_bulan[$i]; $created = $st['total']>0; ?>
        <div class="month-box" style="border:1px solid #ddd;padding:10px 12px;border-radius:10px;background:#fafafa;position:relative">
          <div style="font-weight:600;font-size:14px;margin:0 0 4px"><?php echo $bulan_nama[$i-1]; ?></div>
          <div style="font-size:11px;color:#555;margin:0 0 8px">
            <?php if($created): ?>
              Sudah dibuat<br><span style="color:#2c7">Menunggu: <?php echo $st['menunggu']; ?></span>
            <?php else: ?>Belum dibuat<?php endif; ?>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if(!$created): ?>
              <button type="button" class="btn-action primary" style="flex:1;padding:6px 8px;font-size:11px" onclick="window.submitGenerate ? submitGenerate(<?php echo $i; ?>) : document.getElementById('quickGenForm').submit();">Generate</button>
            <?php else: ?>
              <button type="button" class="btn-action" style="flex:1;padding:6px 8px;font-size:11px;background:#5e765c;color:#fff" onclick="submitUpdate(<?php echo $i; ?>)">Update Nominal</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>


  <div class="panel" style="max-width:880px;margin:0 0 80px">
    <div class="panel-header"><h2 style="margin:0;font-size:20px">Riwayat Tagihan Terbaru</h2></div>
    <div class="table-wrap" style="overflow-x:auto">
      <table class="table mini-table" style="min-width:760px">
        <thead><tr><th>Deskripsi</th><th>Jumlah</th><th>Status</th><th>Tanggal</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if($recentRows): foreach($recentRows as $r): ?>
          <tr>
            <td><?php echo e($r['deskripsi']); ?></td>
            <td>Rp <?php echo number_format($r['jumlah'],0,',','.'); ?></td>
            <td><span class="status-<?php echo htmlspecialchars(str_replace('_','-',$r['status']),ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars(str_replace('_',' ',ucfirst($r['status'])),ENT_QUOTES,'UTF-8'); ?></span></td>
            <td><?php echo $r['tanggal_upload']?date('d M Y H:i',strtotime($r['tanggal_upload'])):'-'; ?></td>
            <td>
              <?php if($r['status']==='menunggu_pembayaran'): ?>
                <form method="POST" style="display:inline" data-confirm="Hapus tagihan ini?">
                  <input type="hidden" name="hapus_id" value="<?php echo (int)$r['id']; ?>" />
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                  <button type="submit" class="btn-inline" style="color:#c0392b;font-size:11px;font-weight:600">Hapus</button>
                </form>
              <?php else: ?>
                <span style="font-size:11px;color:#888">-</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;color:#777;font-size:13px">Belum ada tagihan SPP.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
<script src="assets/js/buat_tagihan.js" defer data-require-script="buat_tagihan"></script>
<div id="jsErrorMsg" style="display:none;color:#c00;background:#fee;padding:8px;margin:12px 0;font-size:14px;font-weight:bold">JS gagal dimuat! Cek path <b>assets/js/buat_tagihan.js</b> dan pastikan file ada.</div>
<style>
/* Amplify typography & width for SPP create page */
.spp-create .admin-heading{font-size:32px;}
.spp-create label{font-size:14px!important;letter-spacing:.4px}
.spp-create .panel{max-width:none!important;width:100%!important}
.spp-create .panel-header h2{font-size:24px!important}
.spp-create .admin-card h3{font-size:22px}
.spp-create .admin-card .value{font-size:34px}
.spp-create #previewNominal{font-size:34px}
.spp-create .input, .spp-create select{font-size:15px}
.spp-create #jumlahDisplay{font-size:16px;font-weight:600}
.spp-create .table{font-size:14px}
.spp-create .table thead th{font-size:13px;}
.spp-create .table tbody td{font-size:14px}
@media (max-width:860px){
  .spp-create .admin-heading{font-size:28px}
  .spp-create .panel-header h2{font-size:20px}
  .spp-create .admin-card .value,#previewNominal{font-size:30px}
}
</style>


