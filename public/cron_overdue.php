<?php
// Cron-style endpoint to mark overdue invoices securely.
// Require secret key via ?key= or X-CRON-KEY header.
require_once __DIR__.'/../src/includes/init.php';
require_once __DIR__.'/../src/includes/payments.php';
header('Content-Type: application/json; charset=utf-8');
if(!defined('CRON_SECRET') || !constant('CRON_SECRET')){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'CRON_SECRET not configured']); exit; }
// Require HMAC header in production; allow query fallback only when APP_DEV true
$k = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
$hasHmac = isset($_SERVER['HTTP_X_SIGNATURE']) && isset($_SERVER['HTTP_X_TIMESTAMP']);
if($hasHmac){
	if(!hmac_verify_request(constant('CRON_SECRET'))){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'bad_hmac']); exit; }
} else {
	if(empty($APP_DEV)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hmac_required']); exit; }
	if($k !== constant('CRON_SECRET')){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
}
$res = invoice_mark_overdue_bulk($conn);
echo json_encode(['ok'=>true,'updated'=>$res['updated'],'time'=>date('c')]);
