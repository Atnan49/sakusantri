<?php
// Halaman ini sudah digantikan oleh sistem invoice baru.
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
header('Location: '.url('wali/invoice.php').'?legacy=bayar_spp');
exit; 
