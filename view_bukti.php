<?php
// Redirect shim so hardcoded /saku_santri/view_bukti.php links land on the real public endpoint
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = $base . '/public/view_bukti.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $target, true, 302);
exit;
