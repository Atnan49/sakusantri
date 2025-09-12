<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('admin');

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['aksi']) && $_POST['aksi']==='tandai' && isset($_POST['id'])){
    $tok=$_POST['csrf_token']??''; if(verify_csrf_token($tok)){ mark_notification_read($conn,(int)$_POST['id']); }
    header('Location: '.url('admin/notifikasi')); exit;
}
$notifs = fetch_notifications($conn,null,80);
require_once __DIR__.'/../../src/includes/header.php';
?>
<main class="container notif-page">
  <h1 class="notif-title">Notifikasi</h1>
  <div class="notif-list">
    <?php if(!$notifs): ?>
      <p class="text-muted">Belum ada notifikasi.</p>
    <?php else: foreach($notifs as $n): ?>
      <?php
        $link = null;
    if(isset($n['data_json']) && $n['data_json']){
          $payload = json_decode($n['data_json'], true);
          if(is_array($payload)){
            if(!empty($payload['invoice_id'])){ $link = url('admin/invoice_detail.php?id='.(int)$payload['invoice_id']); }
      if(!empty($payload['payment_id']) && empty($payload['invoice_id']) && $n['type']==='wallet_topup_settled'){ $link = url('admin/wallet_topups.php'); }
          }
        }
      ?>
      <div class="notif-item <?php echo $n['read_at']? 'read':'unread'; ?>">
        <div class="meta"><span class="type"><?php echo htmlspecialchars($n['type']); ?></span> <span class="time"><?php echo date('d M Y H:i',strtotime($n['created_at'])); ?></span></div>
        <div class="message"><?php if($link): ?><a href="<?php echo htmlspecialchars($link,ENT_QUOTES,'UTF-8'); ?>" style="text-decoration:none;color:#14599d"><?php echo htmlspecialchars($n['message']); ?></a><?php else: echo htmlspecialchars($n['message']); endif; ?></div>
        <?php if(!$n['read_at']): ?>
        <form method="post" class="inline">
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
</main>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
