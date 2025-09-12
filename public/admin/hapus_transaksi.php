<?php
// Deprecated legacy deletion page - no longer needed with new invoice/payment system.
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
header('Location: '.url('admin/invoice.php').'?legacy=hapus_transaksi');
return;
