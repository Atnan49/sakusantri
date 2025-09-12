<?php
// Deprecated legacy riwayat_transaksi page. Redirect to wallet_topups (for topups) or invoice listing.
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
header('Location: '.url('admin/wallet_topups.php').'?legacy=riwayat');
return;


