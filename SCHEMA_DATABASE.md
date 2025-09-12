# Ringkasan Skema Database SakuSantri

Dokumen ini merangkum struktur tabel yang digunakan aplikasi (berdasarkan `db_connect.php` runtime migration & file SQL lama). Sesuaikan nama database sesuai konfigurasi (`.env` / config). Semua tabel menggunakan InnoDB & charset utf8mb4.

## Tabel Utama

### 1. users
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | INT AUTO_INCREMENT PK | ID user |
| nama_wali | VARCHAR(100) | Nama wali |
| nama_santri | VARCHAR(100) | Nama santri |
| nisn | VARCHAR(20) UNIQUE | Login identifier |
| password | VARCHAR(255) | Hash password (bcrypt) |
| role | ENUM('admin','wali_santri') DEFAULT 'wali_santri' | Peran |
| avatar (opsional) | VARCHAR(255) | Bisa ada di instalasi baru |
| saldo (cache) | DECIMAL(12,2) DEFAULT 0 | Cache saldo wallet (disinkron dari ledger) |
| created_at (opsional) | DATETIME | Jika pernah ditambahkan |

Index: UNIQUE (nisn)

### 2. invoice
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | INT PK |
| user_id | INT FK -> users.id |
| type | VARCHAR(30) (contoh: 'spp','daftar_ulang') |
| period | VARCHAR(12) NULL (format YYYYMM) |
| amount | DECIMAL(12,2) |
| paid_amount | DECIMAL(12,2) DEFAULT 0 |
| status | ENUM('pending','partial','paid','overdue','canceled') DEFAULT 'pending' |
| due_date | DATE NULL |
| notes | VARCHAR(255) NULL |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |
| updated_at | DATETIME NULL |
| meta_json | JSON NULL (opsional; terdeteksi dinamis) |
| source | VARCHAR(30) NULL |

Index: (status), (type,period), (user_id)

### 3. payment
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | INT PK |
| invoice_id | INT NULL FK -> invoice.id (NULL jika top-up wallet) |
| user_id | INT FK -> users.id |
| method | VARCHAR(30) (manual_transfer, wallet, dll) |
| amount | DECIMAL(12,2) |
| status | ENUM('initiated','awaiting_proof','awaiting_confirmation','awaiting_gateway','settled','failed','reversed') DEFAULT 'initiated' |
| idempotency_key | VARCHAR(64) UNIQUE NULL |
| note | VARCHAR(255) NULL |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |
| updated_at | DATETIME NULL |
| settled_at | DATETIME NULL |
| proof_file | VARCHAR(255) NULL |
| meta_json | JSON NULL |
| source | VARCHAR(30) NULL |

Index: (invoice_id), (user_id), (status)

### 4. ledger_entries
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | INT PK |
| user_id | INT NULL FK -> users.id (SET NULL jika user dihapus) |
| account | VARCHAR(40) (contoh: 'WALLET','AR_SPP','CASH_IN') |
| debit | DECIMAL(12,2) DEFAULT 0 |
| credit | DECIMAL(12,2) DEFAULT 0 |
| ref_type | VARCHAR(30) NULL (payment, purchase, dll) |
| ref_id | INT NULL |
| note | VARCHAR(255) NULL |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |

Index: (user_id), (account), (ref_type,ref_id)

### 5. invoice_history
| Kolom | Tipe |
| id | INT PK |
| invoice_id | INT FK -> invoice.id |
| from_status | VARCHAR(20) NULL |
| to_status | VARCHAR(20) NOT NULL |
| actor_id | INT NULL |
| note | VARCHAR(255) NULL |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |

Index: (invoice_id)

### 6. payment_history
| Kolom | Tipe |
| id | INT PK |
| payment_id | INT FK -> payment.id |
| from_status | VARCHAR(20) NULL |
| to_status | VARCHAR(20) NOT NULL |
| actor_id | INT NULL |
| note | VARCHAR(255) NULL |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |

Index: (payment_id)

### 7. notifications
| Kolom | Tipe |
| id | INT PK |
| user_id | INT NULL FK -> users.id (CASCADE) |
| type | VARCHAR(50) |
| message | VARCHAR(255) |
| read_at | DATETIME NULL |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |

Index: (user_id, created_at)

### 8. wallet_ledger (legacy – jika masih ada)
| Kolom | Tipe | Catatan |
| id | INT PK |
| user_id | INT FK |
| direction | ENUM('credit','debit') |
| amount | DECIMAL(12,2) |
| ref_type | VARCHAR(30) NULL |
| ref_id | INT NULL |
| note | VARCHAR(255) NULL |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP |
Digunakan sebelum migrasi penuh ke `ledger_entries`. Masih diisi untuk kompatibilitas kasir.

### 9. transaksi (legacy)
Dipertahankan untuk data lama; SPP baru berpindah ke invoice/payment.

| Kolom | Tipe |
| id | INT PK |
| user_id | INT FK |
| jenis_transaksi | ENUM('spp','uang_saku') |
| deskripsi | VARCHAR(255) |
| jumlah | DECIMAL(12,2) |
| bukti_pembayaran | VARCHAR(255) NULL |
| status | ENUM('menunggu_pembayaran','menunggu_konfirmasi','lunas','ditolak') |
| tanggal_upload | DATETIME NULL |
| deleted_at | DATETIME NULL |

## Relasi Kunci Utama / Asing
- users (1) -- (N) invoice
- users (1) -- (N) payment
- users (1) -- (N) ledger_entries
- invoice (1) -- (N) payment
- invoice (1) -- (N) invoice_history
- payment (1) -- (N) payment_history

## Akuntansi Wallet (Ringkas)
- Top-up sukses: ledger_entries (WALLET debit, AR_SPP optional / CASH_IN debit) tergantung alur.
- Bayar invoice via wallet: WALLET credit (mengurangi saldo), AR_SPP debit (mengurangi piutang).
- Kasir pembelian: WALLET credit (purchase) + optional legacy wallet_ledger debit.
- Recalc saldo cache: users.saldo = SUM(debit - credit) pada ledger_entries akun WALLET.

## Status Lifecycle
Invoice: pending -> (partial) -> paid | overdue | canceled
Payment top-up: initiated -> awaiting_proof -> awaiting_confirmation -> settled | failed | reversed

## Query Referensi (Contoh)
Hitung saldo wallet real:  
SELECT COALESCE(SUM(debit-credit),0) FROM ledger_entries WHERE user_id=? AND account='WALLET';

Tagihan SPP belum lunas:  
SELECT COUNT(*) FROM invoice WHERE user_id=? AND type='spp' AND status IN ('pending','partial','overdue');

## Catatan Migrasi
- Field opsional (meta_json, avatar, saldo) dibuat kondisional; pastikan environment produksi punya kolom tersebut sebelum fitur terkait dipakai.
- Legacy tabel `transaksi` dan `wallet_ledger` dapat dihentikan setelah seluruh data dimigrasikan ke invoice/payment/ledger.

## Rekomendasi Backup
Gunakan mysqldump (contoh):
```
mysqldump -u root -p db_saku_santri users invoice payment ledger_entries invoice_history payment_history notifications > backup_core.sql
```
Tambahkan tabel legacy bila masih digunakan.

---
Generated otomatis untuk dokumentasi internal.
