<?php
// Koneksi Database: mencoba membuat DB jika belum ada dan memastikan tabel kunci tersedia
// Default development values (override via src/includes/config.php on hosting)
$host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'db_saku_santri';
$APP_DEV = true; // set false on production (sembunyikan detail error di produksi)

// Disable runtime auto schema migration by default (enable only in dev explicitly)
if(!defined('AUTO_MIGRATE_RUNTIME')){
    define('AUTO_MIGRATE_RUNTIME', false);
}

// Allow overrides from deployment config if present
if (defined('BASE_PATH')) {
    $cfg = BASE_PATH . '/src/includes/config.php';
    if (is_file($cfg)) {
        /** @noinspection PhpIncludeInspection */
        include $cfg; // should define $host, $db_user, $db_pass, $db_name, $APP_DEV
    }
}

// Avoid mysqli throwing exceptions that break fallbacks; we'll handle errors manually.
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

// Membuat tabel kunci jika belum ada (idempotent)
function ensure_schema(mysqli $conn): void {
    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT,
            nama_wali VARCHAR(100) NOT NULL,
            nama_santri VARCHAR(100) NOT NULL,
            nisn VARCHAR(20) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','wali_santri') NOT NULL DEFAULT 'wali_santri',
            PRIMARY KEY (id),
            UNIQUE KEY uniq_nisn (nisn)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        // LEGACY (akan dihapus setelah migrasi final). Disisakan sementara agar skrip migrasi bisa berjalan jika masih ada data.
        "CREATE TABLE IF NOT EXISTS transaksi (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            jenis_transaksi ENUM('spp','uang_saku') NOT NULL,
            deskripsi VARCHAR(255) DEFAULT NULL,
            jumlah DECIMAL(12,2) NOT NULL,
            bukti_pembayaran VARCHAR(255) DEFAULT NULL,
            status ENUM('menunggu_pembayaran','menunggu_konfirmasi','lunas','ditolak') NOT NULL DEFAULT 'menunggu_pembayaran',
            tanggal_upload DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_status (status),
            CONSTRAINT fk_transaksi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        // Tambah kolom saldo pada users jika belum ada (abaikan error jika sudah ada)
        "ALTER TABLE users ADD COLUMN saldo DECIMAL(12,2) NOT NULL DEFAULT 0",
        // Buku besar dompet (ledger) untuk topup/belanja/penyesuaian
        "CREATE TABLE IF NOT EXISTS wallet_ledger (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            direction ENUM('credit','debit') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            ref_type ENUM('topup','purchase','adjust') NOT NULL,
            ref_id INT DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT DEFAULT NULL,
            type VARCHAR(50) NOT NULL,
            message VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            KEY idx_user (user_id),
            KEY idx_read (read_at),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        // New payment system tables
        "CREATE TABLE IF NOT EXISTS invoice (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            type VARCHAR(30) NOT NULL,
            period VARCHAR(12) DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            due_date DATE DEFAULT NULL,
            status ENUM('pending','partial','paid','overdue','canceled') NOT NULL DEFAULT 'pending',
            notes VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            meta_json JSON DEFAULT NULL,
            source VARCHAR(30) DEFAULT NULL,
            PRIMARY KEY(id),
            KEY idx_user (user_id),
            KEY idx_status (status),
            KEY idx_type_period (type,period),
            CONSTRAINT fk_invoice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS payment (
            id INT NOT NULL AUTO_INCREMENT,
            invoice_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            method VARCHAR(30) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status ENUM('initiated','awaiting_proof','awaiting_confirmation','awaiting_gateway','settled','failed','reversed') NOT NULL DEFAULT 'initiated',
            idempotency_key VARCHAR(64) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            settled_at DATETIME DEFAULT NULL,
            proof_file VARCHAR(255) DEFAULT NULL,
            meta_json JSON DEFAULT NULL,
            source VARCHAR(30) DEFAULT NULL,
            PRIMARY KEY(id),
            KEY idx_invoice (invoice_id),
            KEY idx_user (user_id),
            KEY idx_status (status),
            UNIQUE KEY uniq_idem (idempotency_key),
            CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(id) ON DELETE SET NULL,
            CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS ledger_entries (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT DEFAULT NULL,
            account VARCHAR(40) NOT NULL,
            debit DECIMAL(12,2) NOT NULL DEFAULT 0,
            credit DECIMAL(12,2) NOT NULL DEFAULT 0,
            ref_type VARCHAR(30) DEFAULT NULL,
            ref_id INT DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_user (user_id),
            KEY idx_account (account),
            KEY idx_ref (ref_type,ref_id),
            CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS invoice_history (
            id INT NOT NULL AUTO_INCREMENT,
            invoice_id INT NOT NULL,
            from_status VARCHAR(20) DEFAULT NULL,
            to_status VARCHAR(20) NOT NULL,
            actor_id INT DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_invoice (invoice_id),
            CONSTRAINT fk_inv_hist_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS payment_history (
            id INT NOT NULL AUTO_INCREMENT,
            payment_id INT NOT NULL,
            from_status VARCHAR(20) DEFAULT NULL,
            to_status VARCHAR(20) NOT NULL,
            actor_id INT DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_payment (payment_id),
            CONSTRAINT fk_pay_hist_payment FOREIGN KEY (payment_id) REFERENCES payment(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    ];
    foreach ($queries as $sql) {
    @mysqli_query($conn, $sql); // Abaikan error kecil; skema dievaluasi tiap start
    }
}

function try_db_connect(array $hosts, $user, $pass, $db, bool $dev): mysqli
{
    $errors = [];

    foreach ($hosts as $h) {
        // 1) Prefer server-only connection to create DB if missing
        $serverConn = @mysqli_connect($h, $user, $pass);
        if ($serverConn) {
            @mysqli_query($serverConn, "CREATE DATABASE IF NOT EXISTS `" . $db . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            @mysqli_close($serverConn);
        }

        // 2) Now try connect directly to target DB
        $conn = @mysqli_connect($h, $user, $pass, $db);
        if ($conn) {
            if(defined('AUTO_MIGRATE_RUNTIME') && AUTO_MIGRATE_RUNTIME){
                ensure_schema($conn);
            } else {
                // Deteksi minimal: jika tabel invoice belum ada, jalankan ensure_schema sekali
                $need = false;
                if($chk=@mysqli_query($conn, "SHOW TABLES LIKE 'invoice'")){
                    if(mysqli_num_rows($chk)==0){ $need=true; }
                } else { $need=true; }
                if($need){ ensure_schema($conn); }
            }
            return $conn;
        }

        // 3) Record last error for diagnostics
    $errors[$h] = mysqli_connect_error(); // Simpan pesan error terakhir untuk diagnosa dev

        // 4) As a fallback, try creating DB once more on same host (in case previous step raced)
        $serverConn2 = @mysqli_connect($h, $user, $pass);
        if ($serverConn2) {
            @mysqli_query($serverConn2, "CREATE DATABASE IF NOT EXISTS `" . $db . "`");
            @mysqli_close($serverConn2);
            $conn2 = @mysqli_connect($h, $user, $pass, $db);
            if ($conn2) {
                if(defined('AUTO_MIGRATE_RUNTIME') && AUTO_MIGRATE_RUNTIME){
                    ensure_schema($conn2);
                } else {
                    $need=false; if($chk=@mysqli_query($conn2, "SHOW TABLES LIKE 'invoice'")){ if(mysqli_num_rows($chk)==0) $need=true; } else $need=true; if($need) ensure_schema($conn2);
                }
                return $conn2;
            }
        }
    }

    if ($dev) {
        $msg = "Koneksi ke database gagal. Percobaan host:\n";
        foreach ($errors as $hostTried => $err) {
            $msg .= "- $hostTried: $err\n";
        }
        $msg .= "\nLangkah cek:\n";
        $msg .= "- Pastikan MariaDB/MySQL berjalan di XAMPP.\n";
        $msg .= "- Cek kredensial di includes/db_connect.php.\n";
        $msg .= "- Coba akses phpMyAdmin.\n";
        die(nl2br(htmlspecialchars($msg)));
    }

    http_response_code(500);
    die('Terjadi kesalahan koneksi database.');
}

$hosts_to_try = [$host, '127.0.0.1', 'localhost:3306'];
$conn = try_db_connect($hosts_to_try, $db_user, $db_pass, $db_name, $APP_DEV);
?>
