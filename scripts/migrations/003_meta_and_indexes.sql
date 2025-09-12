-- Migration 003: add meta_json columns & extra indexes
-- Jalankan manual; sesuaikan jika kolom sudah ada.
ALTER TABLE invoice ADD COLUMN meta_json JSON NULL AFTER notes;
ALTER TABLE payment ADD COLUMN meta_json JSON NULL AFTER note;
-- Optional source kolom (identifikasi asal invoice)
ALTER TABLE invoice ADD COLUMN source VARCHAR(32) NULL AFTER type;
-- Index tambahan
ALTER TABLE payment ADD INDEX idx_invoice_status (invoice_id, status);
ALTER TABLE invoice ADD INDEX idx_user_status (user_id, status);
