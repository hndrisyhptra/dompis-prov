# Pemulihan upload BIMA setelah pembaruan Date Modified

## Checklist

- [x] Periksa versi upload, tahap job, dan worker tanpa membaca data pelanggan.
- [x] Perjelas pesan penolakan versi dan pilihan mencoba ulang preview.
- [x] Uji upload → mapping → retry preview → konfirmasi dengan Date Modified.
- [x] Muat ulang worker khusus Dompis Prov dan verifikasi preview file aktual.
- [x] Catat hasil pengujian dan status data.

## Diagnosis

Upload #23 dan #24 menggunakan kontrak terbaru `v5-date-modified-ps`, sama dengan aplikasi web, tetapi ditolak pada tahap preview. Keduanya mempunyai 3.470 baris mentah dan nol baris diterapkan. Worker Dompis Prov dimulai 24 Oktober sebelum pembaruan 26 Oktober dan masih memuat kelas PHP versi lama. Worker proyek lain (`dompis-qe`) tidak terkait.

Mengunggah ulang tidak memuat ulang worker daemon. Pengaman versi tetap diperlukan: preview dari kontrak lama tidak boleh langsung diterapkan menggunakan aturan tanggal PS baru.

## Operasional lokal

Gunakan listener untuk pengembangan agar setiap job memuat kode terbaru:

```sh
php artisan queue:listen --queue=default --tries=5 --timeout=180 --sleep=1
```

Untuk worker production yang dikelola Supervisor/systemd, jalankan `php artisan queue:restart` setelah deployment dan pastikan pengelola proses menyalakan worker kembali. Perintah restart hanya mengirim sinyal berhenti setelah job selesai; tidak menyalakan proses baru. `retry_after` database 300 detik harus lebih panjang daripada timeout worker 180 detik.

Preview gagal dengan versi yang masih sesuai dapat memakai **Coba ulang preview** pada detail sinkronisasi setelah worker dimuat ulang. Preview versi lama tetap harus dibuat dari upload baru. Tidak ada perubahan kolom, migration, dependency, atau variabel environment.

## Berkas terkait

- `app/Jobs/ProcessWorkOrderImport.php`: pesan penolakan kontrak.
- `resources/views/sync/show.blade.php`: bantuan pemulihan dan tombol retry preview.
- `tests/Feature/ImportWorkerRecoveryTest.php`: regresi pemulihan dan perlindungan kontrak.
- `README.md`: penggunaan listener untuk pengembangan.
- Dokumen ini: diagnosis dan hasil aktual.

## Hasil 26 Oktober 2026

- Mengirim sinyal graceful restart untuk worker Dompis Prov, memastikan worker lama berhenti, lalu mengaktifkan listener lokal. Worker `dompis-qe` tetap berjalan.
- Mencoba ulang **preview #24** melalui guard retry controller yang sama: operator asal masih berhak sync, kontrak terbaru sesuai, tahap preview, belum ada baris diterapkan. Tidak mengubah hash versi atau mengonfirmasi commit secara manual.
- Preview file aktual selesai dalam sekitar 5 detik: **3.470 dibaca, 0 baru, 2.990 berubah, 480 tetap, 0 ditolak, 0 konflik**. Mapping Tanggal PS tetap menunjuk **kolom B / Date Modified**. Prepared preview ini masih membutuhkan pemeriksaan dan konfirmasi operator sebelum diterapkan.
- Jumlah work order sebelum/sesudah tetap **3.470**, baris diterapkan pada job ini **0**. Job #23 tetap menjadi riwayat kegagalan; upload #25 tetap menunggu mapping. Tidak menghapus riwayat, mengubah template sumber, atau menerapkan progress/tanggal PS pada database aktual.
- Listener baru berhasil mengonsumsi probe antrean tanpa perubahan data: job untuk preview yang sudah selesai langsung kembali melalui guard `!busy()`, antrean kembali kosong.
- Suite PHP penuh: **298 tes / 26.554 assertions lulus**. Termasuk tiga regresi baru untuk retry tanpa upload ulang, tanggal PS dengan kolom berpindah, hak akses/owner, idempotensi, dan blok kontrak lama pada preview maupun commit.
- MariaDB database sementara terpisah: **29 tes / 978 assertions lulus**, database sementara dihapus setelah tes. Tidak menjalankan migration pada database aplikasi.
- **27 tes JavaScript**, build frontend, Pint, kompilasi Blade, dan pemeriksaan whitespace diff lulus. Verifikasi UI melalui rendering feature test; tidak melakukan verifikasi visual browser manual.

Jika aplikasi/komputer dihentikan, listener lokal perlu dijalankan kembali. Di production gunakan pengelola proses agar worker pulih otomatis. Tidak ada kredensial atau koneksi Google/Telegram baru pada perbaikan ini; tidak ada input bisnis tambahan yang menghalangi upload baru.
