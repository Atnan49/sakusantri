<?php
// Jalankan sekali saja, lalu hapus file ini!
require_once __DIR__ . '/../src/includes/init.php';

$nama = 'Adek Atnan'; // Ganti sesuai nama santri
$avatar = 'NAMA_FILE_GAMBAR.jpg'; // Ganti sesuai nama file di assets/uploads

$sql = "UPDATE users SET avatar=? WHERE nama_santri=? AND role='wali_santri' LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if($stmt){
    mysqli_stmt_bind_param($stmt, 'ss', $avatar, $nama);
    if(mysqli_stmt_execute($stmt)){
        echo "Berhasil update avatar $nama ke $avatar\n";
    } else {
        echo "Gagal update: ".mysqli_error($conn)."\n";
    }
} else {
    echo "Gagal prepare: ".mysqli_error($conn)."\n";
}
