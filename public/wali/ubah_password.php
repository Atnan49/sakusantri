<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $old = (string)($_POST['old_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!verify_csrf_token($token)) {
        $err = 'Token tidak valid.';
    } elseif ($old === '' || $new === '' || $confirm === '') {
        $err = 'Semua field wajib diisi.';
    } elseif (strlen($new) < 8) {
        $err = 'Password baru minimal 8 karakter.';
    } elseif ($new !== $confirm) {
        $err = 'Konfirmasi password tidak cocok.';
    } else {
        // Ambil hash password lama
        $stmt = mysqli_prepare($conn, 'SELECT password FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if (!$row || !password_verify($old, (string)$row['password'])) {
                $err = 'Password lama tidak sesuai.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $upd = mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE id = ?');
                if ($upd) {
                    mysqli_stmt_bind_param($upd, 'si', $hash, $user_id);
                    if (mysqli_stmt_execute($upd)) {
                        // Regenerasi session id untuk keamanan
                        session_regenerate_id(true);
                        $msg = 'Password berhasil diperbarui.';
                    } else {
                        $err = 'Gagal menyimpan password baru.';
                    }
                } else {
                    $err = 'Gagal menyiapkan pembaruan.';
                }
            }
        } else {
            $err = 'Gagal memuat data pengguna.';
        }
    }
}

require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="change-pass-page" style="max-width:720px;">
    <h1 class="admin-heading">Ubah Password</h1>
    <?php if (!empty($msg)) echo '<div class="info">' . e($msg) . '</div>'; ?>
    <?php if (!empty($err)) echo '<div class="error">' . e($err) . '</div>'; ?>
    <div class="panel pass-panel" style="margin-top:20px;">
        <form method="post" class="stack gap-3 pass-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
            <div class="input-group has-toggle">
                <label>Password Lama</label>
                <div class="field-wrap"><input type="password" name="old_password" required /><button type="button" class="pw-toggle" aria-label="Tampilkan/Sembunyikan"><span class="material-symbols-outlined">visibility</span></button></div>
            </div>
            <div class="input-group has-toggle">
                <label>Password Baru</label>
                <div class="field-wrap"><input type="password" name="new_password" minlength="8" required /><button type="button" class="pw-toggle" aria-label="Tampilkan/Sembunyikan"><span class="material-symbols-outlined">visibility</span></button></div>
            </div>
            <div class="input-group has-toggle">
                <label>Konfirmasi Password Baru</label>
                <div class="field-wrap"><input type="password" name="confirm_password" minlength="8" required /><button type="button" class="pw-toggle" aria-label="Tampilkan/Sembunyikan"><span class="material-symbols-outlined">visibility</span></button></div>
            </div>
            <button type="submit" class="btn-pill" style="height:42px;">Simpan</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
