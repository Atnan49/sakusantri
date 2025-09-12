<?php
// Simulated gateway callback (webhook). Secured by shared secret GATEWAY_SECRET.
require_once __DIR__.'/../src/includes/init.php';
require_once BASE_PATH.'/src/includes/payments.php';
header('Content-Type: application/json; charset=utf-8');
if(!defined('GATEWAY_SECRET') || !constant('GATEWAY_SECRET')){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'secret_not_set']); exit; }
$secret = $_GET['secret'] ?? ($_SERVER['HTTP_X_GATEWAY_SECRET'] ?? '');
// Support stronger HMAC auth if headers provided (X-TIMESTAMP + X-Signature)
if(isset($_SERVER['HTTP_X_SIGNATURE']) && isset($_SERVER['HTTP_X_TIMESTAMP'])){
	if(!hmac_verify_request(constant('GATEWAY_SECRET'))){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'bad_hmac']); exit; }
} else if($secret !== constant('GATEWAY_SECRET')){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$pid = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$status = $_POST['status'] ?? '';
if(!$pid || !in_array($status,['settled','failed'],true)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }
$toStatus = $status === 'settled' ? 'settled' : 'failed';
$ok = payment_update_status($conn,$pid,$toStatus,null,'gateway callback');
echo json_encode(['ok'=>$ok,'payment_id'=>$pid,'final_status'=>$toStatus]);
