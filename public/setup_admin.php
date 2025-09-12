<?php
require_once __DIR__ . '/../src/includes/init.php';

// Setup admin satu kali. Jika admin sudah ada, tampilkan info dan tautan ke login.

// Cek apakah admin sudah ada
$has_admin = false;
$exists = @mysqli_query($conn, "SELECT 1 FROM users WHERE role='admin' LIMIT 1");
if ($exists && mysqli_fetch_row($exists)) {
	$has_admin = true;
}

// Tangani submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$has_admin) {
	$token = $_POST['csrf_token'] ?? '';
	if (!verify_csrf_token($token)) {
		$err = 'Token tidak valid.';
	} else {
		$nama = trim((string)($_POST['nama'] ?? 'Administrator'));
		$nisn = trim((string)($_POST['username'] ?? 'admin'));
		$pass = (string)($_POST['password'] ?? '');

		if ($pass === '') {
			$err = 'Password tidak boleh kosong.';
		} elseif (strlen($pass) < 8) {
			$err = 'Password minimal 8 karakter.';
		} else {
			$hash = password_hash($pass, PASSWORD_DEFAULT);
			$stmt = mysqli_prepare($conn, "INSERT INTO users (nama_wali, nama_santri, nisn, password, role) VALUES (?, '-', ?, ?, 'admin')");
			if ($stmt) {
				mysqli_stmt_bind_param($stmt, 'sss', $nama, $nisn, $hash);
				if (mysqli_stmt_execute($stmt)) {
					$msg = 'Admin berhasil dibuat. Anda bisa login sekarang.';
					$has_admin = true;
				} else {
					$err = 'Gagal membuat admin.';
				}
			} else {
				$err = 'Gagal menyiapkan query.';
			}
		}
	}
}

require_once __DIR__ . '/../src/includes/header.php';
?>
<main class="container" style="max-width:720px;">
	<h1>Setup Admin</h1>
	<?php if (isset($msg)) echo '<div class="info">' . e($msg) . '</div>'; ?>
	<?php if (isset($err)) echo '<div class="error">' . e($err) . '</div>'; ?>

	<?php if ($has_admin): ?>
		<p>Admin sudah ada. <a class="btn-menu" href="<?php echo url('login'); ?>">Kembali ke login</a>.</p>
	<?php else: ?>
		<form method="post">
			<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
			<div class="input-group">
				<label>Nama Admin</label>
				<input type="text" name="nama" value="Administrator" required>
			</div>
			<div class="input-group">
				<label>Username (NIS)</label>
				<input type="text" name="username" value="admin" required>
			</div>

			<div class="input-group">
				<label>Password</label>
				<input type="password" name="password" minlength="8" required>
			</div>
			<button type="submit">Buat Admin</button>
		</form>
	<?php endif; ?>
</main>
<?php require_once __DIR__ . '/../src/includes/footer.php'; ?>
