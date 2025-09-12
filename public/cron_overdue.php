<?php
// Cron-style endpoint to mark overdue invoices securely.
// Require secret key via ?key= or X-CRON-KEY header.
require_once __DIR__.'/../src/includes/init.php';
require_once __DIR__.'/../src/includes/payments.php';
header('Content-Type: application/json; charset=utf-8');
if(!defined('CRON_SECRET') || !constant('CRON_SECRET')){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'CRON_SECRET not configured']); exit; }
$k = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
if(isset($_SERVER['HTTP_X_SIGNATURE']) && isset($_SERVER['HTTP_X_TIMESTAMP'])){
	if(!hmac_verify_request(constant('CRON_SECRET'))){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'bad_hmac']); exit; }
} else if($k !== constant('CRON_SECRET')){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$res = invoice_mark_overdue_bulk($conn);
echo json_encode(['ok'=>true,'updated'=>$res['updated'],'time'=>date('c')]);
