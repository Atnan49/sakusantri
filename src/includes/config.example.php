<?php
// Copy to src/includes/config.php on hosting and fill with your DB credentials.
// This file overrides defaults in db_connect.php.
$host   = 'localhost';       // e.g., localhost
$db_user = 'u123456_app';    // MySQL username
$db_pass = 'yourStrongPass'; // MySQL password
$db_name = 'u123456_appdb';  // Database name
$APP_DEV = false;            // false in production to hide verbose errors

// --- SMS / OTP Configuration (isi di config.php) ---
// Pilih provider: 'twilio' | 'zenziva' | 'custom'
if (!defined('SMS_PROVIDER')) {
	define('SMS_PROVIDER', 'custom');
}
// Nomor HP admin yang akan menerima OTP (format internasional disarankan, contoh: +6281249575363)
if (!defined('ADMIN_OTP_PHONE')) {
	define('ADMIN_OTP_PHONE', '+6281249575363'); // GANTI sesuai nomor admin nyata
}
// Twilio example credentials (ganti di config.php, jangan commit rahasia asli)
if (!defined('TWILIO_SID')) { define('TWILIO_SID', 'YOUR_TWILIO_SID'); }
if (!defined('TWILIO_TOKEN')) { define('TWILIO_TOKEN', 'YOUR_TWILIO_AUTH_TOKEN'); }
if (!defined('TWILIO_FROM')) { define('TWILIO_FROM', '+15005550006'); }
// Zenziva example (https://zenziva.id/) – set userkey & passkey
if (!defined('ZENZIVA_USERKEY')) { define('ZENZIVA_USERKEY', 'YOUR_ZENZIVA_USERKEY'); }
if (!defined('ZENZIVA_PASSKEY')) { define('ZENZIVA_PASSKEY', 'YOUR_ZENZIVA_PASSKEY'); }
// Custom gateway endpoint (jika punya API sendiri)
if (!defined('SMS_CUSTOM_ENDPOINT')) { define('SMS_CUSTOM_ENDPOINT', ''); }
if (!defined('SMS_CUSTOM_TOKEN')) { define('SMS_CUSTOM_TOKEN', ''); }

// Google OAuth (isi untuk mengaktifkan tombol "Masuk Dengan Google")
// Dapatkan kredensial di https://console.cloud.google.com/apis/credentials
// Redirect URI contoh: https://domain-anda.com/google_callback.php
// atau jika memakai subfolder/public: sesuaikan path sebenarnya.
if (!defined('GOOGLE_CLIENT_ID')) {
	define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
	define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
}
// Opsional: override redirect default otomatis
// define('GOOGLE_REDIRECT_URI', 'https://domain-anda.com/google_callback.php');
