<?php
// Deprecated legacy confirmation page (transaksi). Redirect to new invoice listing.
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
header('Location: '.url('admin/invoice.php').'?legacy=konfirmasi');
return; // explicit return

