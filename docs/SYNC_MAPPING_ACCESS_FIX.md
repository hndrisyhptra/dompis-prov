# Akses mapping upload di server — 28 Oktober 2026

## Checklist

- [x] Reproduksi driver database yang mengembalikan ID numerik sebagai teks.
- [x] Normalkan ID operator job tanpa melonggarkan kepemilikan atau Gate sync.
- [x] Detail riwayat operator lain tetap dapat dibaca tanpa redirect ke form mapping milik pengunggah.
- [x] Uji upload/replay/mapping/preview/commit/retry, penolakan akun lain dan batas role.
- [x] Build/lint dan catat hasil serta langkah penerapan di server.

`awaiting_mapping` adalah tahap menunggu konfirmasi kolom, bukan job worker macet. Belum ada bukti/log runtime dari server; diagnosis server harus dibedakan dari reproduksi lokal.

## Temuan dan perbaikan

Alur upload membuat SyncJob dengan stage=mapping/state=awaiting_mapping dan staging terenkripsi, tanpa mengantrekan worker. Pengunggah memeriksa kolom lalu mengonfirmasi mapping untuk mengantrekan preview. Konfirmasi import setelah preview baru mengantrekan commit. Worker tidak boleh melompati dua konfirmasi tersebut.

Pada endpoint `/imports/{id}/mapping`, Gate sync harus lolos dan ID pengunggah harus sama dengan ID akun aktif. Sebelumnya SyncJob tidak mempunyai cast integer untuk FK id_users. Driver PDO dapat mengembalikan FK sebagai teks sementara primary key User otomatis dibaca sebagai integer oleh Eloquent. Perbandingan ketat `"1" === 1` gagal walaupun kedua nilai merujuk pengguna yang sama. Akibatnya GET mapping, upload replay, preview, commit, retry dan tombol pemilik pada Blade berpotensi ditolak/tersembunyi setelah model dihidrasi ulang.

Tes regresi memakai PDO::ATTR_STRINGIFY_FETCHES=true pada koneksi database sungguhan. Sebelum perbaikan, test akun pengunggah yang sama gagal tepat pada GET mapping: **expected200, received403**. Sesudah menambah cast `id_users => integer` pada SyncJob, alur upload sampai commit dan retry kembali lulus. Tidak mengganti perbandingan menjadi longgar, menghapus Gate, mengambil alih job atau mengubah data pemilik.

Temuan terpisah: riwayat sinkronisasi dapat dibaca seluruh operator yang mempunyai Gate sync, tetapi Detail untuk job tahap mapping selalu redirect ke form privat pengunggah. Sekarang hanya pengunggah yang diarahkan ke mapping. Operator sync lain membaca status dan petunjuk menunggu pengunggah pada detail, tanpa kontrol mapping/commit/retry. Akses langsung ke form mapping operator lain tetap403, dengan pesan penjelas. Role Branch, Koordinator Area, akun nonaktif dan role lain tanpa izin sync tetap ditolak.

## Penerapan di server

Perubahan sudah dibuat di repository lokal, **belum diterapkan atau diverifikasi pada server pengguna**. URL yang dilaporkan mengarah ke endpoint mapping; penyebab nyata tetap perlu konfirmasi akun pengunggah dan perilaku driver/log di server. Perbaikan mencakup bug yang berhasil direproduksi, tanpa mengklaim telah menginspeksi database atau sesi server.

1. Deploy berkas aplikasi di bawah dari revisi yang memuat perbaikan melalui proses rilis biasa. Tidak ada migration, dependency atau env baru.
2. Pada direktori aplikasi server, gunakan PHP CLI yang sesuai aplikasi:

   ```sh
   php artisan view:clear
   php artisan queue:restart
   ```

   Pastikan process manager menghidupkan worker kembali. Jika PHP web memakai OPcache tanpa pemeriksaan timestamp, reload PHP-FPM/Apache sesuai prosedur server agar kode model/controller terbaru terbaca; restart worker saja tidak memuat ulang kode proses web.
3. Login memakai akun yang mengunggah file, buka Detail dari Sync Center, periksa mapping → preview → konfirmasi import. Bila snapshot sudah melewati batas30menit atau kontrak kolom berbeda, upload ulang; tidak perlu menghapus job/queue/tabel secara manual.
4. Jika403 tetap terjadi, cocokkan pengunggah job dan akun login. Query diagnostik berikut hanya membaca data job dan status akun; tidak menampilkan password/token:

   ```sql
   SELECT j.id_sync_jobs, j.id_users, u.name, u.role, u.active,
          j.stage, j.state, j.expires_at
   FROM sync_jobs AS j
   JOIN users AS u ON u.id_users = j.id_users
   WHERE j.id_sync_jobs = 28;
   ```

   Role yang dapat sync: super_admin, area_admin, area_hd aktif. Jangan mengubah pemilik job atau menonaktifkan otorisasi untuk melewati403. Bila akun dan kode sudah cocok, periksa apakah403 berasal dari Laravel atau lapisan web server/WAF melalui log server sebelum melakukan perubahan konfigurasi tambahan.

## Hasil pengujian

- SQLite: **46 tes /1.229 assertions lulus**, mencakup alur import, worker recovery, template/Date Modified, sync legacy, akses dan penghapusan push.
- MariaDB terisolasi: **46 tes /1.229 assertions lulus**, termasuk simulasi fetch ID sebagai teks; database sementara sudah dihapus.
- Tes baru memastikan awaiting_mapping tidak menjalankan worker atau mengubah WO; upload replay tidak menggandakan staging, preview tidak menulis WO, commit idempotent, tombol commit/retry tersedia bagi pemilik, operator lain tidak mengambil alih dan JSON tidak memuat snapshot.
- JavaScript29tes, Vite build, Pint dan git diff --check lulus. Tidak ada verifikasi visual browser/HTTP server atau layanan eksternal baru.

```sh
php -d memory_limit=512M vendor/bin/phpunit --filter 'SyncMappingAccessTest|AccessAndSyncTest|ExcelWorkOrderImportTest|ImportWorkerRecoveryTest|BimaWorkOrdersTemplateTest|BimaPsDate|SyncHistory|MonitoringPushTest|NamedKeysBehaviorTest|BimaMapping' --no-progress --colors=never
php /private/tmp/olo-sync-mapping-mysql.php
node --test tests/JavaScript/*.test.js
npm run build
vendor/bin/pint --dirty --test
git diff --check
```

Helper MariaDB adalah alat pengujian lokal, bukan kebutuhan rilis. Suite penuh tidak diulang untuk perubahan terbatas ini. Tidak memodifikasi database aplikasi aktual, template sumber, branding atau progress/riwayat WO.

## Berkas diubah

- `app/Models/SyncJob.php`: cast id_users konsisten lintas driver.
- `app/Http/Controllers/SyncController.php`: redirect mapping khusus pengunggah, riwayat operator lain tetap read-only.
- `app/Http/Controllers/WorkOrderImportController.php`: pesan403 kepemilikan mapping dan pemeriksaan akun dari request.
- `resources/views/sync/show.blade.php`: penjelasan awaiting_mapping bagi pembaca riwayat.
- `tests/Feature/SyncMappingAccessTest.php`: tiga tes regresi menggunakan fetch ID sebagai teks.
- Dokumen ini serta addendum `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.
