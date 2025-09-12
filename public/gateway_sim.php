<?php
// Simple simulation page for gateway (user would normally be redirected here externally)
require_once __DIR__.'/../src/includes/init.php';
require_once BASE_PATH.'/src/includes/header.php';
$pid = (int)($_GET['pid'] ?? 0); $token = $_GET['token'] ?? '';
?>
<main class="container" style="padding-bottom:60px">
  <h1 style="margin:20px 0 18px;font-size:26px">Simulasi Gateway</h1>
  <p style="font-size:14px">Payment ID: <b>#<?php echo $pid; ?></b></p>
  <p style="font-size:13px;color:#555">Halaman simulasi. Tekan tombol untuk menyelesaikan pembayaran.</p>
  <form method="post" action="gateway_callback.php?secret=<?php echo defined('GATEWAY_SECRET')?urlencode(constant('GATEWAY_SECRET')):''; ?>" style="display:flex;gap:12px;flex-wrap:wrap">
    <input type="hidden" name="payment_id" value="<?php echo $pid; ?>" />
    <button class="btn-action primary" name="status" value="settled">Settle (Sukses)</button>
    <button class="btn-action danger" name="status" value="failed" type="submit">Fail (Gagal)</button>
  </form>
</main>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
