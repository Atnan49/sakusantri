<?php
require_once __DIR__.'/../src/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
$dbOk = isset($conn) && @mysqli_query($conn,'SELECT 1');
$resp = [
  'ok' => $dbOk ? true : false,
  'time' => date('c'),
  'version' => '1.1.1',
];
echo json_encode($resp, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
?>