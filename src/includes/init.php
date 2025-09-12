<?php
// Central bootstrap
if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/../../'));
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'public');
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', BASE_PATH);
}
// Define BASE_URL dynamically; if URL path contains "/public/", trim everything after it.
if (!defined('BASE_URL')) {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '/';
    $ru = $_SERVER['REQUEST_URI'] ?? $sn;
    // Remove query string from REQUEST_URI
    $qpos = strpos($ru, '?');
    if ($qpos !== false) { $ru = substr($ru, 0, $qpos); }

    $sn = str_replace('\\', '/', $sn);
    $ru = str_replace('\\', '/', $ru);

    if (strpos($ru, '/public/') !== false) {
        $base = substr($ru, 0, strpos($ru, '/public/'));
    } elseif (strpos($sn, '/public/') !== false) {
        $base = substr($sn, 0, strpos($sn, '/public/'));
    } else {
        // Jika tidak ada segmen /public/, mungkin DocumentRoot masih parent project
        // Contoh: akses http://localhost/saku_santri/login -> SCRIPT_NAME (/saku_santri/index.php)
        // Ambil segmen pertama setelah slash dan gunakan sebagai BASE_URL.
        $seg = '';
        $snTrim = ltrim($sn, '/');
        if ($snTrim !== '') {
            $parts = explode('/', $snTrim);
            if (!empty($parts[0]) && $parts[0] !== 'index.php') {
                $seg = '/' . $parts[0];
            }
        }
        $base = $seg === '' ? '/' : $seg; // fallback root jika gagal deteksi
    }
    $base = rtrim($base, '/');
    define('BASE_URL', ($base === '' ? '/' : $base . '/'));
}
// Compute scheme/host/origin for absolute URLs and canonical links
if (!defined('APP_SCHEME')) {
    $isHttps = false;
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        $isHttps = true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $isHttps = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_SSL'])) {
        $isHttps = $isHttps || strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on';
    }
    define('APP_SCHEME', $isHttps ? 'https' : 'http');
}
if (!defined('APP_HOST')) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // strip :port when present for display/canonical purposes
    $host = preg_replace('/:\\d+$/', '', (string)$host);
    define('APP_HOST', $host);
}
if (!defined('APP_ORIGIN')) {
    define('APP_ORIGIN', APP_SCHEME . '://' . APP_HOST);
}
if (session_status() === PHP_SESSION_NONE) {
    // Set secure session cookie params (will apply for new session id)
    $params = session_get_cookie_params();
    // If PHP < 7.3, session_set_cookie_params array might not support SameSite; suppress warnings safely
    @session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (APP_SCHEME === 'https'),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}
// Core includes (pastikan file berikut ada di src/includes/)
require_once BASE_PATH . '/src/includes/helpers.php';
require_once BASE_PATH . '/src/includes/status.php';
require_once BASE_PATH . '/src/includes/security.php';
require_once BASE_PATH . '/src/includes/db_connect.php';
require_once BASE_PATH . '/src/includes/csrf.php';
require_once BASE_PATH . '/src/includes/notifications.php';
// New payment system helpers (optional early load)
if (file_exists(BASE_PATH . '/src/includes/payments.php')) { require_once BASE_PATH . '/src/includes/payments.php'; }
// Analytics helpers (optional)
if (file_exists(BASE_PATH . '/src/includes/analytics.php')) { require_once BASE_PATH . '/src/includes/analytics.php'; }
if (file_exists(BASE_PATH . '/src/includes/sms.php')) { require_once BASE_PATH . '/src/includes/sms.php'; }
// Audit logger (soft delete & critical actions)
if (file_exists(BASE_PATH . '/src/includes/audit.php')) {
    require_once BASE_PATH . '/src/includes/audit.php';
}

// Enforce presence of critical shared secrets in production
if(!$APP_DEV){
    $missing=[];
    foreach(['GATEWAY_SECRET','CRON_SECRET','INTEGRITY_KEY'] as $sec){
        if(!defined($sec) || !constant($sec)){ $missing[]=$sec; }
    }
    if($missing){
        // Log minimal (avoid leaking full env) – fallback: simple error page
        error_log('Missing critical secrets: '.implode(',',$missing));
        // Continue (soft fail) to avoid total outage, but could optionally die()
    }
}

// Auto-clean notifications: delete read >3 days or any >3 days (strict short retention)
try {
    if(!isset($_SESSION['notif_cleanup_last']) || $_SESSION['notif_cleanup_last'] < (time()-3600)) { // at most once per hour
        if(function_exists('cleanup_notifications')){
            cleanup_notifications($conn, 3, 3); // both readRetentionDays & maxAgeDays = 3
        }
        $_SESSION['notif_cleanup_last'] = time();
    }
} catch(Throwable $e){ /* ignore cleanup errors */ }

// First-run: jika belum ada user admin, arahkan ke halaman setup admin
// Kecualikan halaman setup itu sendiri untuk mencegah redirect loop
try {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $isSetupPage = (strpos($script, 'setup_admin.php') !== false);
    if (!$isSetupPage) {
        $rs = @mysqli_query($conn, "SELECT 1 FROM users WHERE role='admin' LIMIT 1");
        $hasAdmin = $rs && mysqli_fetch_row($rs);
        if (!$hasAdmin) {
            header('Location: ' . url('setup_admin.php'));
            exit;
        }
    }
} catch (Throwable $e) {
    // Diamkan di dev; jika koneksi DB gagal, db_connect sudah memunculkan pesan yang sesuai
}
