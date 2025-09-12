<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('wali_santri');

$userId = (int)($_SESSION['user_id'] ?? 0);

// Tandai satu notifikasi dibaca
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['aksi']) && $_POST['aksi']==='tandai' && isset($_POST['id'])){
    $tok = $_POST['csrf_token'] ?? '';
    if(verify_csrf_token($tok)){
        // pastikan notifikasi milik user ini
        $nid = (int)$_POST['id'];
    $nidSafe = (int)$nid; $uidSafe = (int)$userId;
    $check = mysqli_query($conn,"SELECT id FROM notifications WHERE id=$nidSafe AND (user_id=$uidSafe OR user_id IS NULL) LIMIT 1");
        if($check && mysqli_fetch_row($check)){
            mark_notification_read($conn,$nid);
        }
    }
    header('Location: '.url('wali/notifikasi')); exit;
}

// Tandai semua dibaca
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['aksi']) && $_POST['aksi']==='tandai_semua'){
    if(verify_csrf_token($_POST['csrf_token'] ?? '')){
        mysqli_query($conn, "UPDATE notifications SET read_at=NOW() WHERE (user_id=$userId OR user_id IS NULL) AND read_at IS NULL");
    }
    header('Location: '.url('wali/notifikasi')); exit;
}

// Ambil notifikasi (user khusus + global)
$rows=[]; $sql = "SELECT * FROM notifications WHERE (user_id=$userId OR user_id IS NULL) ORDER BY id DESC LIMIT 80";
$res = mysqli_query($conn,$sql); while($res && $r=mysqli_fetch_assoc($res)){$rows[]=$r;}

require_once __DIR__.'/../../src/includes/header.php';
?>
<div class="notif-page">
  <h1 class="notif-title">Notifikasi</h1>
  <form method="post" class="notif-actions" style="margin:0 0 16px;display:flex;gap:8px;align-items:center">
    <input type="hidden" name="aksi" value="tandai_semua" />
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
    <button type="submit" class="btn-mark-all">Tandai Semua Dibaca</button>
  </form>
  <div class="notif-list">
    <?php if(!$rows): ?>
      <p class="text-muted">Belum ada notifikasi.</p>
    <?php else: foreach($rows as $n): ?>
      <?php
        $link = null;
        if(isset($n['data_json']) && $n['data_json']){
          $payload = json_decode($n['data_json'], true);
          if(is_array($payload)){
            if(!empty($payload['invoice_id'])){ $link = url('wali/invoice_detail.php?id='.(int)$payload['invoice_id']); }
            if(!empty($payload['payment_id']) && $n['type']==='wallet_topup_settled'){ $link = url('wali/wallet_riwayat'); }
          }
        }
      ?>
    <div class="notif-item <?php echo $n['read_at']? 'read':'unread'; ?>">
        <div class="meta"><span class="type"><?php echo htmlspecialchars($n['type']); ?></span> <span class="time"><?php echo date('d M Y H:i',strtotime($n['created_at'])); ?></span></div>
        <div class="message">
          <?php if($link): ?><a href="<?php echo htmlspecialchars($link,ENT_QUOTES,'UTF-8'); ?>" style="text-decoration:none;color:#14599d"><?php echo htmlspecialchars($n['message']); ?></a><?php else: echo htmlspecialchars($n['message']); endif; ?>
        </div>
        <?php if(!$n['read_at']): ?>
      <form method="post" class="inline form-mark-one">
            <input type="hidden" name="aksi" value="tandai" />
            <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>" />
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
            <button class="btn-mark" type="submit">Tandai dibaca</button>
          </form>
        <?php else: ?>
          <span class="sudah">Sudah dibaca</span>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
