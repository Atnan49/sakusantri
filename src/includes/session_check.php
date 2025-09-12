<?php
// Middleware sesi: memastikan pengguna terautentikasi dan mengelola timeout.
// Pastikan bootstrap inti sudah dimuat agar BASE_URL dan helper url() tersedia.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/../../'));
}
// Jika init belum pernah dimuat (BASE_URL belum terdefinisi), muat sekarang.
if (!defined('BASE_URL')) {
    $init = __DIR__ . '/init.php';
    if (is_file($init)) {
        require_once $init; // ini juga akan start session dengan parameter aman
    }
}
// Jika setelah itu sesi masih belum aktif (fallback), start manual.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Fungsi untuk memaksa logout yang aman
function force_logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    // Gunakan helper url() bila tersedia untuk konsistensi path.
    if (function_exists('url')) {
        $to = url('login?pesan=sesi_berakhir');
    } else {
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        $to = $base . '/login?pesan=sesi_berakhir';
    }
    header('Location: ' . $to);
    exit();
}

// Wajib login: jika belum ada user_id di sesi, logout paksa
if (!isset($_SESSION['user_id'])) {
    force_logout();
}

// Timeout idle: perpanjang ke 20 menit (dapat diubah sesuai kebutuhan)
$max_idle_time = 20 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_idle_time)) {
    force_logout();
}

// Perbarui cap waktu aktivitas terakhir
$_SESSION['last_activity'] = time();

// Pembatasan peran: panggil require_role('admin') / require_role('wali_santri') di halaman
function require_role($role) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        http_response_code(403);
        die('<h1>Akses Ditolak!</h1><p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>');
    }
}
?>
