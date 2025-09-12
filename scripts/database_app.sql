-- Application schema for Saku Santri (UPDATED to match runtime schema in PHP).
-- Jalankan file ini sekali pada instalasi awal. Migrasi incremental selanjutnya memakai skrip di folder scripts/migrations.

CREATE DATABASE IF NOT EXISTS db_saku_santri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_saku_santri;

/* =============================================================
   TABEL UTAMA
   ============================================================= */

-- 1. users (menyimpan akun wali & admin)
CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT,
  nama_wali VARCHAR(100) NOT NULL,
  nama_santri VARCHAR(100) NOT NULL,
  nisn VARCHAR(20) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','wali_santri') NOT NULL DEFAULT 'wali_santri',
  saldo DECIMAL(12,2) NOT NULL DEFAULT 0,                 -- cache saldo wallet (SUM ledger WALLET)
  avatar VARCHAR(255) NULL,                               -- opsional (migrasi 007)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, -- opsional untuk audit
  PRIMARY KEY (id),
  UNIQUE KEY uniq_nisn (nisn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. invoice (tagihan seperti SPP)
CREATE TABLE IF NOT EXISTS invoice (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  type VARCHAR(30) NOT NULL,                -- contoh: 'spp'
  period VARCHAR(12) DEFAULT NULL,          -- format YYYYMM atau custom
  amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  due_date DATE DEFAULT NULL,
  status ENUM('pending','partial','paid','overdue','canceled') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  meta_json JSON DEFAULT NULL,              -- info tambahan (gateway id, dsb.)
  source VARCHAR(30) DEFAULT NULL,          -- penanda asal (generator, manual)
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_status (status),
  KEY idx_type_period (type, period),
  CONSTRAINT fk_invoice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. payment (pembayaran invoice atau top-up wallet)
CREATE TABLE IF NOT EXISTS payment (
  id INT NOT NULL AUTO_INCREMENT,
  invoice_id INT DEFAULT NULL,                              -- NULL jika top-up wallet
  user_id INT NOT NULL,
  method VARCHAR(30) NOT NULL,                              -- manual_transfer, wallet, dll
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
  PRIMARY KEY (id),
  KEY idx_invoice (invoice_id),
  KEY idx_user (user_id),
  KEY idx_status (status),
  UNIQUE KEY uniq_idem (idempotency_key),
  CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(id) ON DELETE SET NULL,
  CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. ledger_entries (buku besar sumber kebenaran saldo)
CREATE TABLE IF NOT EXISTS ledger_entries (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT DEFAULT NULL,                   -- boleh NULL jika user dihapus (riwayat tetap)
  account VARCHAR(40) NOT NULL,               -- 'WALLET','AR_SPP','CASH_IN', dll
  debit DECIMAL(12,2) NOT NULL DEFAULT 0,
  credit DECIMAL(12,2) NOT NULL DEFAULT 0,
  ref_type VARCHAR(30) DEFAULT NULL,          -- misal: 'payment','purchase'
  ref_id INT DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_account (account),
  KEY idx_ref (ref_type, ref_id),
  CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. invoice_history (riwayat perubahan status invoice)
CREATE TABLE IF NOT EXISTS invoice_history (
  id INT NOT NULL AUTO_INCREMENT,
  invoice_id INT NOT NULL,
  from_status VARCHAR(20) DEFAULT NULL,
  to_status VARCHAR(20) NOT NULL,
  actor_id INT DEFAULT NULL,                 -- admin / sistem
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_invoice (invoice_id),
  CONSTRAINT fk_inv_hist_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. payment_history (riwayat perubahan status payment)
CREATE TABLE IF NOT EXISTS payment_history (
  id INT NOT NULL AUTO_INCREMENT,
  payment_id INT NOT NULL,
  from_status VARCHAR(20) DEFAULT NULL,
  to_status VARCHAR(20) NOT NULL,
  actor_id INT DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payment (payment_id),
  CONSTRAINT fk_pay_hist_payment FOREIGN KEY (payment_id) REFERENCES payment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. notifications (notifikasi user)
CREATE TABLE IF NOT EXISTS notifications (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT DEFAULT NULL,
  type VARCHAR(50) NOT NULL,
  message VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_read (read_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* =============================================================
   TABEL LEGACY (dipertahankan sementara untuk kompatibilitas)
   ============================================================= */

-- 8. wallet_ledger (legacy sebelum ledger_entries penuh)
CREATE TABLE IF NOT EXISTS wallet_ledger (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. transaksi (legacy awal, akan di-migrate ke invoice/payment)
CREATE TABLE IF NOT EXISTS transaksi (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* =============================================================
   OPSIONAL: AUDIT LOG (jika nanti diaktifkan)
   ============================================================= */
CREATE TABLE IF NOT EXISTS audit_log (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT NULL,
  detail TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_user (user_id),
  KEY idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* =============================================================
   MAINTENANCE / HOUSEKEEPING
   ============================================================= */
-- Contoh pembersihan notifikasi lama:
-- DELETE FROM notifications
--  WHERE (read_at IS NOT NULL AND read_at < NOW() - INTERVAL 30 DAY)
--     OR created_at < NOW() - INTERVAL 90 DAY;

/* =============================================================
   SEED ADMIN (opsional, ubah password setelah login pertama)
   ============================================================= */
-- Password plaintext: admin123
INSERT INTO users (nama_wali, nama_santri, nisn, password, role)
SELECT 'Administrator', '-', 'admin', '$2y$10$0iuJm5.1xTgG1mM3v5YlYOGQ7kX3pTgrmEHmG8vGgmq8Nt0kMkPpC', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE role='admin' LIMIT 1);

/* =============================================================
   RINGKASAN PENTING
   - Saldo real = SUM(debit-credit) ledger_entries account='WALLET'
   - Cache saldo pada users.saldo harus disinkron (script wallet_sync)
   - Tabel legacy dapat dihapus setelah migrasi penuh
   ============================================================= */
