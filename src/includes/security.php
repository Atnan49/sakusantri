<?php
/** Security helper functions */

function hmac_verify_request(string $sharedSecret, int $maxSkewSeconds = 300): bool {
    if(!$sharedSecret) return false;
    $ts = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    if($ts === '' || $sig === '') return false;
    if(!ctype_digit($ts)) return false;
    $tsInt = (int)$ts;
    $now = time();
    if(abs($now - $tsInt) > $maxSkewSeconds) return false; // replay / old request
    $raw = file_get_contents('php://input');
    $base = $ts."\n".$raw; // canonical
    $calc = hash_hmac('sha256', $base, $sharedSecret);
    return hash_equals($calc, $sig);
}

?>