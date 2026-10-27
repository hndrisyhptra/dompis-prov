# Penghapusan rekap dan optimasi sebelum deploy — 27 Oktober 2026

## Checklist implementasi

- [x] Periksa Laravel, Blade, layanan agregasi, filter/scope, tests dan proses deployment yang ada.
- [x] Hapus tabel Rekap jenis pekerjaan dan perhitungan khusus tabel yang tidak dipakai.
- [x] Jalankan agregasi per tab aktif saja, tanpa cache lintas user atau perubahan definisi KPI.
- [x] Ukur query pada 20/1.000 order, uji scope dan konsistensi hasil lintas tab.
- [x] Jalankan tests, build dan pemeriksaan cache deployment; catat hasil serta batas verifikasi server.

Laravel 13 / PHP ^8.3, layanan query terpisah, Blade dan Vite tetap dipakai. Perubahan workspace sebelumnya dipertahankan. Tidak ada rencana migrasi/schema atau mutasi data operasional pada tahap ini.

## Hasil perubahan

Tabel **Rekap jenis pekerjaan** dihapus dari dashboard Area beserta variabel `recap`, pengelompokan agregat utama per jenis pekerjaan dan iterasi khususnya. Filter jenis pekerjaan, tabel OPEN & STUCK per Jenis Pekerjaan, Mapping Aging Order dan KPI tetap tersedia. Mapping Aging Order tetap di Progress, mengikuti pembatalan permintaan pindah tab sebelumnya.

`AreaAnalyticsService` hanya menghitung laporan yang dibuka:

| Tab | Laporan yang dihitung |
| --- | --- |
| Progress | Matrix Region/Branch, Mapping Order beserta OPEN/STUCK dan aging, cohort bulan order |
| Owner Group | Distribusi Owner Group |
| Ach PS | Seri harian PS dan matrix Branch × tanggal |

KPI bersama dan waktu data terakhir dihitung pada ketiga tab memakai scope/filter yang sama. Pengelompokan Branch pada agregat utama hanya diperlukan di Progress. Data laporan yang tidak aktif bernilai `null` (seri kosong), sehingga pemanggil tidak menganggap angka nol sebagai hasil laporan yang belum dihitung. Bulan grafik tetap dibawa antar-tab dan hanya membatasi laporan Ach PS, bukan laporan kumulatif lainnya.

Master Branch/Region dibaca sekali per request Progress, lalu dipakai ulang dalam agregasi Mapping Order. Owner Group memakai baris agregat biasa melalui `toBase()`, menghindari pembuatan model WO untuk hasil hitungan. Tidak menambah cache hasil lintas request/user; perubahan progress/master terbaca pada request berikutnya.

Modal daftar order dashboard hanya mengambil atribut identitas/status/tanggal yang diperlukan, dengan relasi Branch. JSON sumber, hash, perangkat, relasi bookmark dan hitungan kendala yang tidak ditampilkan tidak dimuat. Predikat antrean/kendala/PS/owner/aging tetap diterapkan sebelum pagination; filter kendala tetap boleh menggunakan EXISTS. Tautan Detail membuka model lengkap melalui otorisasi endpoint lama. Daftar utama dan export seluruh kolom database tetap mengikuti perilaku sebelumnya.

## Pengukuran nyata pada data sintetis

Data uji aman, bukan data pelanggan. Angka berikut adalah query layanan tanpa request/session/filter dropdown, pada 20 dan 1.000 WO tanpa Region. Master Region yang terisi dapat menambah satu query eager load; anggaran tes mengakomodasi hal ini.

| Jalur | Sebelum tahap ini | Sesudah, 20 WO | Sesudah, 1.000 WO |
| --- | ---: | ---: | ---: |
| Area Progress | 9 | 4 | 4 |
| Area Owner Group | Tidak diukur terpisah | 4 | 4 |
| Area Ach PS | Tidak diukur terpisah | 3 | 3 |
| Dashboard Branch | 10 | 10 | 10 |
| Ringkasan antrean | 1 | 1 | 1 |

SQLite dan MariaDB menghasilkan jumlah yang sama. Render daftar utama Work Order/List Prioritas tetap 7 query pada 5/30 WO; modal dashboard 3 query pada 5/30 WO dengan maksimal 25 per halaman. Pengujian membatasi jumlah query, bukan timing yang mudah berubah karena beban mesin. Ini tidak menjamin waktu respons atau kapasitas concurrency server produksi.

Pemeriksaan alur lain mempertahankan optimasi yang sudah ada: agregasi SQL dan rentang tanggal, pagination 25 order, export generator 250 baris, insert staging batch, lock/idempotency import, dan lazy import Leaflet hanya pada peta. Tidak menambah index tanpa bukti rencana query server. Tidak menjalankan import/backfill atau memodifikasi koneksi eksternal.

## Pengujian dan pemeriksaan aktual

- `php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never`: **347 tes, 29.935 assertions, lulus**.
- MariaDB terisolasi, strict mode, paket maksimum 1 MiB: **64 tes, 6.374 assertions, lulus**; database sementara dihapus setelah selesai. Meliputi Area/Branch, aging/mapping, PS, drilldown, export, Owner Group, priority, master dan query budget.
- `node --test tests/JavaScript/*.test.js`: **28 tes lulus**.
- `npm run build`: lulus; JavaScript utama 19,30 kB / gzip 6,78 kB, peta tetap terpisah.
- `vendor/bin/pint --dirty --test`, `git diff --check`, `composer validate --no-check-publish`, `composer check-platform-reqs --no-dev`: lulus pada runtime lokal.
- Pemeriksaan ekstensi lokal PDO MySQL, ZIP, XMLWriter, DOM, XML dan mbstring: tersedia.
- `php artisan optimize`: config/events/routes/views berhasil dibuat dan diperiksa dengan path cache sementara, kemudian dihapus. Cache konfigurasi aplikasi berjalan tidak diubah; tidak ada kredensial cache yang masuk repository.

Kasus regresi mencakup KPI sama lintas tab, update langsung terbaca, laporan tidak aktif tidak menjalankan query, angka modal sesuai list/filter, Branch asing tetap ditolak, tanggal kosong/masa depan, master arsip, query budget konstan dan source/audit tidak termutasi saat membaca. Assertions untuk tabel yang dihapus diganti dengan verifikasi KPI/filter/PS pada tampilan yang masih digunakan.

## Pelaksanaan di server

Belum dilakukan deployment dan belum ada pengukuran beban/browser server. Jalankan tahap berikut pada direktori rilis yang akan diaktifkan; sesuaikan pengelola layanan dengan server yang digunakan.

1. Simpan backup database/file privat. Pertahankan `.env`, **APP_KEY yang sama**, serta direktori privat upload/BOQ/KML/material. Jangan menjalankan `migrate:fresh` atau membuat APP_KEY baru untuk database yang berisi ciphertext.
2. Gunakan PHP yang memenuhi `composer.json`/lock (proyek ^8.3) pada CLI dan FPM. Selain persyaratan Composer, aplikasi memakai PDO MySQL, ZIP/ZipArchive, XMLWriter, DOM, XML dan mbstring untuk upload/export; pastikan tersedia pada kedua runtime. Jadikan `public/` document root; `storage` dan `bootstrap/cache` dapat ditulis oleh proses aplikasi.
3. Pasang dependensi terkunci dan asset production:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   npm ci --ignore-scripts
   npm run build
   ```

   Asset dapat dibangun di CI lalu kirim `public/build`. Jangan membawa `public/hot` development ke rilis production. Jangan menyalin cache bootstrap/config dari laptop.

4. Isi environment server: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` URL HTTPS aktual; `QUEUE_CONNECTION=database`, `CACHE_STORE=database` (atau shared Redis yang sudah dikonfigurasi), `DB_QUEUE_RETRY_AFTER=300`. Pastikan database dan sesi sesuai server. Konfigurasi PHP/web server upload minimal `upload_max_filesize=10M`, `post_max_size=12M`, beserta batas request proxy yang sesuai. Jangan mengubah `.env` lokal untuk meniru server.
5. Tahap ini tidak menambah migration. Bila server tertinggal migrasi dari fitur sebelumnya, periksa `php artisan migrate:status` dan jalankan `php artisan migrate --force` hanya pada database tujuan yang sudah dibackup sesuai rencana rilis.
6. Setelah `.env`/file rilis final, jalankan:

   ```sh
   php artisan optimize
   php artisan queue:restart
   ```

   `queue:restart` memerlukan worker dikelola Supervisor/systemd agar hidup kembali. Perintah worker yang berlaku di proyek: `php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1`; retry_after 300 detik lebih panjang dari timeout job 180 dan lock 240. Scheduler menjalankan `php artisan schedule:run` setiap menit. Aktifkan OPcache pada FPM dan lakukan reload graceful melalui pengelola server saat pergantian kode memerlukannya. Hindari `optimize:clear` massal pada cache store shared ketika ada job berlock aktif.
7. Uji staging/server sesudah rilis: login Area/Branch, tiga tab dashboard, filter/modal/detail, import preview dan commit dengan file uji yang disetujui, export serta worker/scheduler. Periksa respons, error/slow query dan antrean pada trafik sebenarnya sebelum menyatakan kapasitas server memadai.

Tidak ada variabel env baru atau migration baru. Kredensial Google/Telegram dan webhook tetap mengikuti konfigurasi sebelumnya; hasil tes lokal tidak menyatakan integrasi tersebut terhubung. Informasi server/akses dan hasil staging diperlukan bila pemilik meminta pelaksanaan deploy berikutnya; tidak ada keputusan bisnis tambahan yang menghalangi perubahan kode ini. Verifikasi visual browser tidak dijalankan karena izin akses sebelumnya ditolak.

Perintah cache dan pengelolaan worker juga mengikuti dokumentasi resmi [Deployment Laravel 13](https://laravel.com/docs/13.x/deployment#optimization) dan [Queue Workers and Deployment](https://laravel.com/docs/13.x/queues#queue-workers-and-deployment); timeout/cache driver/lock di atas mengikuti kode dan konfigurasi proyek ini.

## Berkas tahap ini

- `resources/views/area/dashboard.blade.php`
- `app/Services/AreaAnalyticsService.php`
- `app/Services/MappingOrderAnalyticsService.php`
- `app/Services/OwnerGroupAnalyticsService.php`
- `app/Http/Controllers/WorkOrderController.php`
- `tests/Feature/AreaDashboardTest.php`
- `tests/Feature/AreaOpenTransactionsTest.php`
- `tests/Feature/DashboardDrilldownTest.php`
- `tests/Feature/PerformanceBudgetTest.php`
- `docs/PREDEPLOY_OPTIMIZATION.md` (baru)
- `README.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md` (catatan lanjutan)

Perubahan pengguna pada teks dashboard/login dan progress workspace lainnya tidak direset. Tidak ada commit/push/deploy otomatis.
