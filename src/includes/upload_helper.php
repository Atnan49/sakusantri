<?php
// Central helper untuk upload bukti pembayaran / topup
// Returns array [ok=>bool, file=>string|null, error=>string|null]
function handle_payment_proof_upload(string $field, array $allowedExt=['jpg','jpeg','png','pdf'], int $maxBytes=2097152): array {
    if(empty($_FILES[$field]['name'] ?? '')) return ['ok'=>false,'file'=>null,'error'=>'File belum dipilih'];
    $fn = $_FILES[$field]['name']; $tmp = $_FILES[$field]['tmp_name']; $size = (int)($_FILES[$field]['size'] ?? 0);
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    if(!in_array($ext,$allowedExt,true)) return ['ok'=>false,'file'=>null,'error'=>'Tipe file tidak diizinkan'];
    if($size > $maxBytes) return ['ok'=>false,'file'=>null,'error'=>'Ukuran melebihi batas (maksimal 2MB)'];
    if(!is_uploaded_file($tmp)) return ['ok'=>false,'file'=>null,'error'=>'Upload tidak valid'];
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false; $mime = $finfo? @finfo_file($finfo,$tmp):''; if($finfo) @finfo_close($finfo);
    $allowedMime = ['image/jpeg','image/png','application/pdf'];
    if($mime && !in_array($mime,$allowedMime,true)) return ['ok'=>false,'file'=>null,'error'=>'MIME tidak valid'];
    if(!function_exists('payments_random_name')){ return ['ok'=>false,'file'=>null,'error'=>'Helper random name tidak ada']; }
    $newName = payments_random_name('payproof',$ext);
    $destDir = BASE_PATH.'/public/uploads/payment_proof'; if(!is_dir($destDir)) @mkdir($destDir,0775,true);
    $dest = $destDir.'/'.$newName;
    // Kompresi gambar jika JPEG/PNG dan ekstensi GD tersedia
    if(in_array($ext,['jpg','jpeg','png'],true) && function_exists('imagecreatefromjpeg')){
        $img = null;
        if($ext==='jpg'||$ext==='jpeg') $img = @imagecreatefromjpeg($tmp);
        elseif($ext==='png') $img = @imagecreatefrompng($tmp);
        if($img){
            // Resize jika >2000px (opsional, untuk keamanan)
            $w = imagesx($img); $h = imagesy($img);
            $maxDim = 2000;
            if($w > $maxDim || $h > $maxDim){
                $scale = min($maxDim/$w, $maxDim/$h);
                $nw = (int)($w*$scale); $nh = (int)($h*$scale);
                $resized = imagecreatetruecolor($nw,$nh);
                imagecopyresampled($resized,$img,0,0,0,0,$nw,$nh,$w,$h);
                imagedestroy($img); $img = $resized;
            }
            // Kompresi dan simpan
            if($ext==='jpg'||$ext==='jpeg'){
                $ok = imagejpeg($img,$dest,80); // quality 80
            } else {
                $ok = imagepng($img,$dest,6); // compression level 6
            }
            imagedestroy($img);
            if(!$ok) return ['ok'=>false,'file'=>null,'error'=>'Gagal kompresi gambar'];
            return ['ok'=>true,'file'=>$newName,'error'=>null];
        }
        // fallback jika gagal kompresi
    }
    if(!@move_uploaded_file($tmp,$dest)) return ['ok'=>false,'file'=>null,'error'=>'Gagal simpan file'];
    return ['ok'=>true,'file'=>$newName,'error'=>null];
}
?>