<?php
// Deprecated legacy transaksi detail page. Redirect to invoice or wallet topups list.
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
header('Location: '.url('admin/invoice.php').'?legacy=transaksi_detail');
return;