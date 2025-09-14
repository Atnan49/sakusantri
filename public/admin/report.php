<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

http_response_code(410); // Gone
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Fitur Laporan PDF Dinonaktifkan</title>
  <meta name="robots" content="noindex" />
  <style>
    body{font-family: Arial, sans-serif; color:#222; padding:24px}
    .panel{border:1px solid #e5e7eb; border-radius:8px; padding:18px; max-width:720px}
    h1{margin:0 0 6px 0}
    .muted{color:#666}
    a.btn{display:inline-block; padding:8px 12px; background:#1f2937; color:#fff; text-decoration:none; border-radius:6px}
  </style>
</head>
<body>
  <div class="panel">
    <h1>Fitur Laporan PDF telah dihapus</h1>
    <p class="muted">Halaman ini tidak lagi tersedia. Silakan gunakan halaman <b>Tagihan</b> untuk melihat data.</p>
    <p><a class="btn" href="<?= htmlspecialchars(url('admin/invoice.php'),ENT_QUOTES,'UTF-8'); ?>">Ke Halaman Tagihan</a></p>
  </div>
</body>
</html>
