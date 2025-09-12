<?php
// Deprecated legacy transaksi detail page for wali. Redirect to invoice listing.
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
header('Location: '.url('wali/invoice.php').'?legacy=transaksi_detail');
return;