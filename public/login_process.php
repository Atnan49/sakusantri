<?php declare(strict_types=1);
require_once __DIR__ . '/../src/includes/init.php';

// Proses login yang aman; init.php sudah memuat koneksi DB ($conn) dan helper CSRF

// Pastikan hanya menerima metode POST dari form login
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('login'));
    exit();
}

// Ambil input dari form dengan default kosong jika tidak ada
$nisn_raw = $_POST['nisn'] ?? '';
// Izinkan huruf, angka, titik, underscore, dash
$nisn = preg_replace('/[^A-Za-z0-9._-]/','', (string)$nisn_raw);
if(strlen($nisn) < 3){ header('Location: ' . url('login?pesan=gagal')); exit(); }
$password = $_POST['password'] ?? '';
// Validasi panjang password minimal 8 karakter (server-side)
if(strlen($password) < 8){
    header('Location: ' . url('login?pesan=gagal'));
    exit();
}
$token = $_POST['csrf_token'] ?? '';

// Validasi token CSRF untuk mencegah permintaan palsu lintas situs
if (!verify_csrf_token($token)) {
    header('Location: ' . url('login?pesan=gagal'));
    exit();
}

// Ambil user berdasarkan NISN (menggunakan prepared statement)
// Rate limiting login: Redis (jika tersedia), fallback ke file-based
$now = time();
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipKey = 'loginrate:'.preg_replace('/[^0-9a-fA-F:\.]/','_', $ip);
$rateLimit = 20; // max 20 attempt per 10 menit per IP
$rateWindow = 600; // 10 menit
// Cek Redis tersedia dan class/method ada
// Gunakan Redis jika tersedia, jika tidak fallback ke file
$useRedis = false;
$redisObj = null;
if (class_exists('Redis')) {
    try {
        $redisClass = 'Redis';
        $redisObj = new $redisClass();
        if (method_exists($redisObj, 'connect') && $redisObj->connect('127.0.0.1', 6379, 1.5)) {
            $useRedis = true;
        }
    } catch (\Throwable $e) { $useRedis = false; $redisObj = null; }
}
if ($useRedis && $redisObj && method_exists($redisObj, 'get') && method_exists($redisObj, 'multi')) {
    $c = (int)$redisObj->get($ipKey);
    if ($c >= $rateLimit) { sleep(2); header('Location: '.url('login?pesan=gagal')); exit(); }
    $pipe = $redisObj->multi(constant(get_class($redisObj).'::PIPELINE'));
    $pipe->incr($ipKey);
    $pipe->expire($ipKey, $rateWindow);
    $pipe->exec();
} else {
    $rateDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saku_login_rate';
    if(!is_dir($rateDir)) @mkdir($rateDir,0700,true);
    $ipFile = $rateDir.DIRECTORY_SEPARATOR.preg_replace('/[^a-zA-Z0-9_]/','_',$ipKey).'.json';
    $ipData = ['c'=>0,'t'=>$now];
    if(is_file($ipFile)){
        $raw=@file_get_contents($ipFile); $jd=@json_decode($raw,true); if(is_array($jd) && isset($jd['c'],$jd['t'])) $ipData=$jd;
        if($now - $ipData['t'] > $rateWindow){ $ipData=['c'=>0,'t'=>$now]; }
    }
    if($ipData['c'] >= $rateLimit){ sleep(2); header('Location: '.url('login?pesan=gagal')); exit(); }
    $ipData['c']++;
    @file_put_contents($ipFile,json_encode($ipData));
}
if(!isset($_SESSION['login_attempts'])){ $_SESSION['login_attempts']=0; }
if(!isset($_SESSION['login_first_attempt'])){ $_SESSION['login_first_attempt']=$now; }
// Reset window setiap 10 menit
if($now - ($_SESSION['login_first_attempt'] ?? 0) > 600){ $_SESSION['login_attempts']=0; $_SESSION['login_first_attempt']=$now; }
if($_SESSION['login_attempts'] >= 5){
    // Delay incremental (simple backoff)
    $sleep = min(5, $_SESSION['login_attempts'] - 4);
    sleep($sleep);
}
$sql = "SELECT id, nama_wali, role, password FROM users WHERE nisn = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    // Jika prepare gagal, kembalikan ke halaman login dengan pesan umum
    header('Location: ' . url('login?pesan=gagal'));
    exit();
}

mysqli_stmt_bind_param($stmt, 's', $nisn);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Verifikasi password yang cocok dengan hash yang tersimpan
if ($result && ($user = mysqli_fetch_assoc($result))) {
    if (password_verify($password, $user['password'])) {
        // Rehash jika algoritme default berubah atau parameter diperbarui
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE id = ?');
            if ($upd) {
                mysqli_stmt_bind_param($upd, 'si', $newHash, $user['id']);
                @mysqli_stmt_execute($upd);
            }
        }
        // Regenerasi ID sesi untuk cegah session fixation
        session_regenerate_id(true);
        // Simpan identitas minimal ke sesi
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['nama_wali'] = $user['nama_wali'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();

        // Arahkan sesuai peran pengguna
        if ($user['role'] === 'admin') {
            header('Location: ' . url('admin/'));
        } else {
            header('Location: ' . url('wali/'));
        }
        exit();
    }
}

// Gagal login (user tidak ditemukan atau password salah) -> pesan umum
$_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
// Persist IP counter
$ipData['c']++; @file_put_contents($ipFile,json_encode($ipData));
header('Location: ' . url('login?pesan=gagal'));
exit();


