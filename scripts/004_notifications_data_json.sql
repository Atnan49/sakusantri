-- Migration 004: add data_json column to notifications (idempotent safe pattern)
-- Jalankan manual jika kolom belum ada.

-- Cek eksistensi kolom sebelum ALTER (gunakan secara manual):
-- SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='data_json';
-- Jika tidak ada row -> jalankan ALTER berikut:
ALTER TABLE notifications ADD data_json JSON NULL AFTER message; -- Akan error jika kolom sudah ada (abaikan)

-- Optional index jika perlu query berbasis key di JSON (contoh path invoice_id)
-- CREATE INDEX idx_notif_type_created ON notifications(type, created_at);
