# Gateway Stub

File yang ditambahkan:
- `public/gateway_init.php` : Inisiasi payment metode `gateway` (status awal `awaiting_gateway`).
- `public/gateway_sim.php` : Halaman simulasi (biasanya user akan dibawa ke halaman eksternal). Di sini ada tombol sukses / gagal.
- `public/gateway_callback.php` : Endpoint callback (webhook) yang akan mengubah status payment menjadi `settled` atau `failed`.

## Keamanan
Tambahkan ke `config.php`:
```
define('GATEWAY_SECRET','ganti_dengan_rahasia');
```
Webhook dipanggil dengan `?secret=RAHASIA` atau header `X-GATEWAY-SECRET`.

## Alur
1. User (wali) di halaman invoice klik "Bayar via Gateway".
2. Browser memanggil `gateway_init.php` (POST) -> create payment + status `awaiting_gateway` -> respon dengan url redirect ke `gateway_sim.php`.
3. User di halaman simulasi menekan sukses / gagal.
4. Form simulasi memanggil `gateway_callback.php` (POST) dengan status.
5. Callback menjalankan `payment_update_status(...,'settled')` (atau failed) -> trigger ledger/invoice/notification.

## Produksi
- Ganti implementasi init agar memanggil API gateway nyata (Midtrans/Xendit dsb) dan simpan `meta_json` (order_id, VA number, dsb).
- Ganti `gateway_sim.php` dengan halaman info menunggu pembayaran.
- Pastikan webhook memverifikasi signature (HMAC) bukan sekedar secret query.
