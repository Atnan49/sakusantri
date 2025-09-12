<?php
// Simulated payment gateway initiation endpoint.
// Creates a payment with status awaiting_gateway and returns a fake redirect URL.
require_once __DIR__.'/../src/includes/init.php';
require_once __DIR__.'/../public/includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';
header('Content-Type: application/json; charset=utf-8');
// CSRF protection: require valid token (token dapat dikirim via header X-CSRF-TOKEN atau field csrf_token)
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if(!verify_csrf_token($token)){
	http_response_code(403);
	echo json_encode(['ok'=>false,'error'=>'csrf']);
	exit;
}
$uid = (int)($_SESSION['user_id'] ?? 0);
$invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
if(!$invoiceId || $amount <= 0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }
$pid = payment_initiate($conn,$uid,$invoiceId,'gateway',$amount,'gw-'.bin2hex(random_bytes(4)),'gateway init');
if(!$pid){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'create_failed']); exit; }
// Move to awaiting_gateway
payment_update_status($conn,$pid,'awaiting_gateway',$uid,'redirecting to gateway');
$redirect = url('gateway_sim.php?pid='.$pid.'&token='.bin2hex(random_bytes(6)));
echo json_encode(['ok'=>true,'payment_id'=>$pid,'redirect'=>$redirect]);
