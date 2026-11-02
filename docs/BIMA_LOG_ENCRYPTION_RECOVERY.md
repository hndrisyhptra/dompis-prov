# Pemulihan tampilan Log Task BIMA — 2 November 2026

Error server terkonfirmasi: `The MAC is invalid` pada `work-orders/bima-task-log.blade.php`. Pengguna menyatakan progress/kendala sudah tersimpan setelah reload. Cast encrypted:array field_changes melempar DecryptException saat view membaca log, sehingga redirect sesudah simpan terlihat gagal walaupun transaksi pembaruan sudah selesai.

## Checklist

- [x] Reproduksi log berkunci berbeda: sebelum perbaikan GET detail menghasilkan500, sesudahnya detail serta redirect setelah simpan berhasil.
- [x] Tangani kegagalan baca hanya pada presentasi log, dengan peringatan nyata tanpa memodifikasi ciphertext/menonaktifkan MAC.
- [x] Pemeriksaan read-only dan panduan APP_PREVIOUS_KEYS tanpa menampilkan/mengganti kunci.
- [x] Uji rotasi kunci resmi, payload rusak, scope dan integritas data; build/lint dan dokumentasikan hasil.

Data terenkripsi yang gagal dibaca belum dapat dianggap pulih. Ketidakcocokan kunci, payload berubah/rusak atau konfigurasi proses berbeda perlu dibedakan melalui pemeriksaan; jangan menebak isi riwayat.

## Perubahan aplikasi

`BimaTaskLogPresentation` membaca cast terenkripsi yang sudah ada, menangkap hanya DecryptException, serta memvalidasi bentuk diff. SQL NULL pada riwayat lama tetap dianggap tanpa rincian; ciphertext dengan JSON/bentuk tidak sesuai mendapat peringatan, bukan disamakan dengan riwayat kosong. Detail sehat, status sebelum/sesudah, actor dan waktu tetap tampil. Log yang tidak terbaca mendapat pesan eksplisit. Halaman hanya memeriksa20 log pada pagination aktif setelah Gate view lolos, dengan satu warning berisi ID order/log saja.

Tidak ada perubahan cast model, verifikasi MAC, transaksi progress/kendala, data terenkripsi, scope Branch atau sumber BIMA. Tidak menghapus/menimpa log, tidak mencoba menebak isinya, dan tidak memulihkan ciphertext secara otomatis. Log baru tetap memakai kunci aktif; previous keys hanya untuk pembacaan resmi Laravel.

## Pemeriksaan server dan pemulihan kunci

Belum ada akses server atau kepastian backup kunci lama. Jalankan langkah berikut melalui pengelola server yang berwenang. Jangan salin isi kunci ke chat, screenshot, tiket atau Git.

1. Deploy file perbaikan di bawah. Pertahankan `.env` produksi dan APP_KEY aktif. Jangan menjalankan `key:generate`, mengganti kunci aktif, menghapus log, atau `migrate:fresh` untuk mengatasi error ini. Tidak diperlukan migration baru.
2. Cari keberadaan backup `.env` sebelum deploy melalui file manager server/backup panel hosting, folder release lama, pengelola secret deployment, atau lingkungan asal database (misalnya komputer lokal yang datanya dipindahkan ke server). Cukup inventarisasi nama/lokasi file; jangan mencetak isinya ke terminal yang direkam. Database dump saja tidak membuktikan bahwa kunci enkripsinya ikut disimpan. Jangan menimpa seluruh `.env` produksi dari lingkungan lain.
3. Setelah deploy, jalankan pemeriksaan awal dari direktori aplikasi:

   ```bash
   cd /www/wwwroot/wibsy
   php artisan olo:check-bima-log-encryption --work-order=123
   ```

   Ganti123 dengan ID numerik pada URL Detail Order, bukan No WO/TQ-SC-ID. Hilangkan opsi untuk memeriksa seluruh log; pembacaan dibatch100 baris. Perintah tidak menulis database dan tidak menampilkan kunci, ciphertext maupun isi perubahan. Exit0 berarti log dalam cakupan terbaca; exit1 berarti ada log yang belum terbaca atau pemeriksaan database gagal; exit2 berarti input ID tidak valid. Hasil tanpa log belum memverifikasi kunci terhadap data lama.
4. Jika ditemukan kunci asli dari sumber tepercaya, tambahkan nilainya secara privat melalui editor `.env` server pada `APP_PREVIOUS_KEYS`. Pertahankan `APP_KEY` aktif. Jika sudah ada previous keys, pertahankan semuanya dan pisahkan dengan koma tanpa spasi. Jangan menaruh kunci pada argumen shell. Kunci harus valid untuk cipher yang digunakan; jangan mengubah cipher untuk mencoba membuka payload. Dukungan ini sudah tersedia pada `config/app.php` dan provider Laravel13 terpasang; `.env.example` hanya menambahkan petunjuk kosong. Referensi: [Laravel encryption — graceful rotation](https://laravel.com/framework/docs/12.x/encryption#gracefully-rotating-encryption-keys).
5. Sesudah perubahan environment, perbarui konfigurasi dan proses:

   ```bash
   php artisan config:cache
   php artisan view:clear
   php artisan queue:restart
   ```

   Pastikan process manager menjalankan kembali worker. Reload PHP-FPM/Apache melalui panel server bila proses web/opcache masih menggunakan release atau konfigurasi lama. CLI, worker dan web harus memakai release/environment yang sama. Jangan menyalin file cache konfigurasi dari komputer lokal ke server.
6. Jalankan kembali pemeriksaan langkah3, lalu buka Detail Order dengan akun berwenang dan uji satu pembaruan progress/kendala yang memang diperlukan. Jika sebelumnya gagal dan sekarang terbaca setelah menambah kunci, ciphertext asli sudah dapat dibaca tanpa ditulis ulang. Jika masih gagal, periksa kunci asal lain atau backup ciphertext yang valid; pesan MAC saja tidak membedakan kunci salah dengan payload rusak.

Jika kunci lama tidak ditemukan, perbaikan tampilan tetap memungkinkan Detail Order dan alur simpan berjalan dengan peringatan pada rincian log terkait. Isi terenkripsi lama belum pulih dan tidak boleh diklaim pulih. Fitur terenkripsi lain mungkin memerlukan kunci yang sama; perbaikan presentasi ini khusus Log Task BIMA.

## File dan validasi

- `app/Services/BimaTaskLogPresentation.php`: pembacaan aman untuk presentasi, validasi bentuk, warning berbasis ID.
- `app/Http/Controllers/WorkOrderController.php`: siapkan rincian log pada halaman aktif setelah otorisasi.
- `resources/views/work-orders/bima-task-log.blade.php`: tampilkan rincian sehat atau peringatan nyata tanpa membaca cast langsung.
- `app/Console/Commands/CheckBimaLogEncryption.php`: diagnostik read-only terbatasi ID atau seluruh log.
- `.env.example`: APP_PREVIOUS_KEYS opsional, tanpa kunci nyata.
- `tests/Feature/BimaLogEncryptionTest.php`: wrong key, redirect sesudah simpan, pemulihan previous key, ciphertext tetap, kunci baru untuk log baru, payload rusak, format invalid, SQL NULL legacy, scope Branch dan output tanpa secret.
- `AGENTS.md` dan dokumen ini: catatan implementasi dan langkah server.

Tes khusus SQLite terisolasi:5 tes/50 assertions lulus. Reproduksi sebelum perbaikan gagal dengan expected200/actual500. Vite production build dan Pint file perubahan lulus. Tidak mengakses database aktual, menjalankan migration, mengubah `.env`/cache aktif lokal, melakukan browser QA atau deploy server.

Suite penuh SQLite:374 dari375 tes lulus (30.979 assertions). Satu kegagalan yang sudah diketahui berada pada UsernameAndThemeTest: tes masih meminta teks Dompis Prov sementara login saat ini memakai branding Wibsy. Branding pengguna tidak diubah dalam perbaikan ini. Semua tes enkripsi dan alur order lulus. Pengujian memakai APP_CONFIG_CACHE ke path sementara yang tidak ada dan DB SQLite in-memory, sehingga cache konfigurasi lokal yang menunjuk MySQL tidak dipakai.
