<?php
// SMS sending abstraction. Real credentials should be placed in config.php (never commit secrets)
// Supports: twilio, zenziva, custom (simple POST), or fallback dev log.

function sms_send(string $to, string $message): bool {
    $provider = defined('SMS_PROVIDER') ? SMS_PROVIDER : 'custom';
    $to = trim($to);
    if($to===''){ return false; }
    // Normalize: ensure starts with + or 0; (very light validation)
    if($provider==='twilio') {
        return sms_send_twilio($to,$message);
    } elseif($provider==='zenziva') {
        return sms_send_zenziva($to,$message);
    } elseif($provider==='custom') {
        return sms_send_custom($to,$message);
    }
    return sms_dev_log($to,$message);
}

function sms_send_twilio(string $to,string $message): bool {
    if(!defined('TWILIO_SID')||!defined('TWILIO_TOKEN')||!defined('TWILIO_FROM')) return false;
    $sid = TWILIO_SID; $token = TWILIO_TOKEN; $from = TWILIO_FROM;
    if(!$sid || !$token || !$from) return false;
    $url = 'https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json';
    $data = http_build_query(['From'=>$from,'To'=>$to,'Body'=>$message]);
    $opts = [ 'http' => [ 'method'=>'POST','header'=>"Authorization: Basic ".base64_encode($sid.':'.$token)."\r\nContent-Type: application/x-www-form-urlencoded","content"=>$data,'timeout'=>10]];
    $res = @file_get_contents($url,false,stream_context_create($opts));
    return $res!==false;
}

function sms_send_zenziva(string $to,string $message): bool {
    if(!defined('ZENZIVA_USERKEY')||!defined('ZENZIVA_PASSKEY')) return false;
    $userkey=ZENZIVA_USERKEY; $passkey=ZENZIVA_PASSKEY;
    if(!$userkey||!$passkey) return false;
    $url='https://gsm.zenziva.net/api/sendsms/';
    $payload=http_build_query(['userkey'=>$userkey,'passkey'=>$passkey,'to'=>$to,'message'=>$message]);
    $opts=['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded","content"=>$payload,'timeout'=>10]];
    $res=@file_get_contents($url,false,stream_context_create($opts));
    return $res!==false;
}

function sms_send_custom(string $to,string $message): bool {
    if(!defined('SMS_CUSTOM_ENDPOINT')||!SMS_CUSTOM_ENDPOINT){ return sms_dev_log($to,$message); }
    $endpoint = SMS_CUSTOM_ENDPOINT; $token = defined('SMS_CUSTOM_TOKEN')?SMS_CUSTOM_TOKEN:'';
    $payload = json_encode(['to'=>$to,'message'=>$message]);
    $headers = "Content-Type: application/json\r\n"; if($token){ $headers.='Authorization: Bearer '. $token ."\r\n"; }
    $opts=['http'=>['method'=>'POST','header'=>$headers,'content'=>$payload,'timeout'=>10]];
    $res=@file_get_contents($endpoint,false,stream_context_create($opts));
    return $res!==false;
}

function sms_dev_log(string $to,string $message): bool {
    // Development fallback: log to file
    $logDir = BASE_PATH . '/logs';
    if(!is_dir($logDir)) @mkdir($logDir,0775,true);
    $line = date('c')."\t".$to."\t".str_replace(["\r","\n"],' ',$message)."\n";
    @file_put_contents($logDir.'/sms.log',$line,FILE_APPEND);
    return true;
}
