<?php
// Helper CSRF sederhana: membuat token per sesi dan memverifikasi token pada permintaan POST
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start(); // Pastikan sesi aktif karena token disimpan di sesi
}

// Ambil/generasikan token acak 32-byte (hex) dan simpan di sesi
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verifikasikan token yang dikirim form dengan yang ada di sesi menggunakan perbandingan aman
function verify_csrf_token(?string $token): bool {
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
