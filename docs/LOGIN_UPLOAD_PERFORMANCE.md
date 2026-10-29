# Login dan upload menuju mapping — 28 Oktober 2026

## Checklist

- [x] Ukur pembacaan XLSX pada data sintetis sebelum/sesudah; pertahankan kontrak validasi.
- [x] Kurangi query/payload Sync Center yang tidak dipakai pada mode Excel dan riwayat.
- [x] Sediakan pengukuran opt-in waktu aplikasi/database untuk login, dashboard dan import tanpa mencatat isi request/SQL.
- [x] Uji regresi akses, import/BOQ/parser, batas query dan privasi diagnostik; build/lint.
- [x] Dokumentasikan hasil lokal dan cara mengukur server, tanpa mengklaim penyebab produksi yang belum diukur.

Keluhan pengguna: klik Login menuju dashboard lambat dan upload menuju mapping lambat, file sekitar0,9MB. Spesifikasi/runtime/log server belum tersedia. Worker belum menangani tahap upload→mapping; pembacaan dan staging masih berlangsung dalam request upload.

## Temuan dan perubahan

Klik Login terdiri dari POST autentikasi lalu GET dashboard. Login memakai pencarian username/email yang sudah berindex unik, pemeriksaan password, pembaruan session dan audit. Dashboard memakai agregasi terpisah menurut tab, tanpa query per order. Jumlah query yang kecil tidak membuktikan query cepat: waktu koneksi, scan data, CPU untuk password, antrean PHP-FPM dan beban worker perlu dipisahkan. Tidak mengurangi kekuatan hash/password, meniadakan audit atau menambahkan cache KPI lintas akun untuk menyembunyikan kelambatan.

Upload masih menerima file, membaca XML XLSX, mengenkripsi staging dan menyimpannya sebelum redirect ke mapping. Ukuran0,9MB adalah ukuran ZIP terkompresi; jumlah baris, teks dan XML hasil ekstraksi menentukan pekerjaan sebenarnya. Worker hanya menangani preview/commit sesudah konfirmasi. Tahap upload ini tetap sinkron pada perubahan ini; tidak mengklaim file sudah dipindah ke antrean.

Perbaikan parser: XMLReader melompati subtree row/shared-string yang sudah diparsing, dan anak value/formula tiap sel diperiksa sekali. Tidak mengubah urutan/index baris, header, nol depan, shared/rich strings, sistem tanggal1904, formula volume BOQ, batas10MB/10.000baris, validasi XML/entity/macro/external link atau isi template sumber. XML rusak sesudah baris terakhir tetap ditolak.

Sync Center hanya mengambil informasi inbound yang dipakai mode aktif. Pada mode Excel tidak memeriksa file kredensial atau menjalankan dua query riwayat Sheets; mode Sheets tidak mencari import Excel. Lima riwayat terakhir dan halaman seluruh riwayat memilih hanya identitas/operator/status/stats/waktu yang ditampilkan, tanpa mentransfer snapshot terenkripsi besar. Detail job tetap mengambil model lengkap melalui otorisasi lama. Tidak ada cache data lintas request/user atau perubahan scope.

## Pengukuran lokal

Parser diuji tiga kali per ukuran, 14kolom BIMA sintetis dengan teks/ID/tanggal, tanpa menyentuh workbook pengguna. Median dan peak memory proses:

| Baris | Sebelum | Sesudah | Perubahan | Peak memory |
| --- | ---: | ---: | ---: | ---: |
| 1.000 | 120,66ms | 99,25ms | sekitar18% lebih singkat | 14MiB |
| 5.000 | 585,27ms | 502,97ms | sekitar14% lebih singkat | 41,98MiB |

Ini waktu membaca saja pada runtime lokal, bukan upload browser atau hasil server produksi. Arsip sintetis masing-masing sekitar56/272KiB, tidak dianggap setara dengan workbook0,9MB pengguna. Tidak memasang assertion batas milidetik yang rentan berubah karena beban mesin.

Fixture request MariaDB lokal, tanpa transfer jaringan browser:

| Jalur | Query | Waktu aplikasi | Waktu database |
| --- | ---: | ---: | ---: |
| POST login | 2 | 186,82ms | 0,60ms |
| GET dashboard Area, 1.000WO | 8 | 38,79ms | 29,83ms |
| Upload200baris → redirect mapping | 5 | 37,90ms | 8,88ms |
| Upload1.000baris → redirect mapping | 13 | 183,99ms | 46,49ms |

Test environment memakai session/cache array dan fixture autentikasi; angka bukan perkiraan biaya login/session/cache database produksi. Misalnya password verification termasuk waktu aplikasi tetapi bukan waktu SQL. Query staging bertambah per batch, bukan satu INSERT per order. Mapping berikutnya membaca tiga sampel dan count. Sync Center Excel berisi riwayat memakai4query, turun dari6query pada fixture yang sama sebelum perubahan (dua query Sheets dihapus), dan tidak memuat snapshot sumber.

## Pengukuran server opt-in

Middleware MeasureWebPerformance default **nonaktif**. Jika diaktifkan, hanya login, dashboard Area/Branch, import dan sync yang diukur. Header Server-Timing pada response normal memuat `app;dur=...` dan `db;dur=...` dalam milidetik. Log `web.performance` untuk request melewati ambang memuat **route template/nama, method, status, app_ms, database_ms**. Tidak mencatat identitas akun, password, URL/query string, SQL/bindings, ukuran/isi file atau data pelanggan.

Waktu database dihitung dari selisih totalQueryDuration per koneksi, tidak mengaktifkan query log, tidak menyimpan bindings dan tidak memasang listener baru per request. Pengukuran request berikutnya tidak membawa durasi request sebelumnya. Jika handler melempar exception, durasi tetap dicatat; status berasal dari exception yang dikenali sebelum rendering exception/redirect oleh kernel. Header hanya ditambahkan bila handler menghasilkan response.

1. Deploy kode perbaikan beserta middleware, config dan perubahan bootstrap. Tidak ada migration/dependency baru. Dua env berikut **opsional** dan tidak diaktifkan otomatis pada `.env` lokal/server:

   ```dotenv
   WEB_PERFORMANCE_ENABLED=true
   WEB_PERFORMANCE_SLOW_MS=0
   ```

2. Cache konfigurasi/route/view rilis final, lalu muat ulang worker yang dikelola process manager:

   ```sh
   php artisan optimize
   php artisan queue:restart
   ```

   Jangan menyalin cache konfigurasi laptop ke server atau menjalankan clear cache massal saat import berlock aktif. Bila OPcache web tidak memeriksa timestamp, reload graceful PHP-FPM/Apache sesuai pengelola server agar kode web terbaru terbaca.
3. Coba sekali login dan upload file yang disetujui. Ambang0 sementara mencatat semua request terpilih. Cari baris `web.performance` di kanal log aplikasi yang dikonfigurasi; LOG_LEVEL harus mengizinkan info. Bandingkan POST login, GET area.dashboard/branch.dashboard, POST imports.upload dan GET imports.mapping secara terpisah. Log aman tersebut dapat dibagikan untuk diagnosis, tanpa mengirim `.env`, password, token atau SQL/data pelanggan.
4. Matikan diagnostik setelah pengukuran (`WEB_PERFORMANCE_ENABLED=false`) dan jalankan config:cache. Ambang normal jika tetap diaktifkan adalah1000ms. Perubahan env pada worker memerlukan restart melalui process manager.

| Hasil | Arah pemeriksaan berikutnya |
| --- | --- |
| database_ms mendekati app_ms yang tinggi | Latensi koneksi DB, waktu query/lock, rencana query aktual dan beban database |
| app_ms tinggi, database_ms rendah | Password verification, pembacaan Excel/enkripsi, CPU/memori PHP |
| Keduanya rendah tetapi browser lama menunggu | Waktu transfer/TTFB sebelum handler, antrean PHP-FPM/proxy, TLS/jaringan atau pemuatan asset |

Timer mulai di middleware setelah bootstrap PHP dan setelah PHP menerima body upload; tidak mengukur transfer file/browser, waktu menunggu slot FPM, seluruh bootstrap atau rendering exception di kernel. Durasi database adalah bagian dari durasi aplikasi, bukan angka untuk dijumlahkan lagi.

Pastikan konfigurasi produksi yang sudah dipakai proyek konsisten: APP_ENV=production, APP_DEBUG=false, asset public/build tersedia dan tidak membawa public/hot development; PHP CLI/FPM kompatibel, OPcache aktif pada FPM dan DB memakai koneksi yang tepat. QUEUE_CONNECTION harus menunjuk backend antrean yang dipakai worker (contoh database), bukan sync jika preview/commit dimaksudkan berjalan terpisah. Mengubah queue tidak mempercepat tahap upload→mapping yang masih sinkron. Tuning worker/FPM atau penambahan index menunggu bukti metrik server, bukan perkiraan dari ukuran file saja.

## Hasil validasi

- Full SQLite: **367tes, 366lulus, 30.875assertions**. Satu kegagalan lama pada UsernameAndThemeTest mengharapkan Dompis Prov sementara login pengguna sudah Wibsy; branding pengguna dipertahankan.
- MariaDB terisolasi: **89tes /3.699assertions lulus**, mencakup parser/upload/template, tanggal PS, ownership403, worker recovery, dashboard/query budget, BOQ/formula/nilai dan privasi diagnostik. Database sementara sudah dihapus.
- JavaScript29tes, Vite build, Pint dan git diff --check lulus.
- Config/events/routes/views cache berhasil dibuat memakai direktori sementara privat lalu dihapus; cache aktif dan `.env` lokal tidak diubah.
- Parser mempertahankan150baris/shared rich strings/formula, mendeteksi XML rusak di akhir file; upload tetap terenkripsi/idempotent dan belum menulis WO atau menjalankan preview sebelum konfirmasi.

Perintah:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-login-upload-mysql.php
node --test tests/JavaScript/*.test.js
npm run build
vendor/bin/pint --dirty --test
git diff --check
```

Dua profiler `/private/tmp/olo-xlsx-read-profile.php` dan helper MariaDB adalah alat lokal, bukan dependency rilis. Server pengguna belum diakses/deploy/diukur; tidak ada klaim bahwa penyebab produksi atau SLA sudah dipastikan. Tidak mengubah data aktual, template sumber, progress Branch, branding maupun koneksi layanan eksternal. Input yang masih dibutuhkan untuk diagnosis produksi: hasil timing terpisah, perkiraan durasi aktual dan kapasitas/runtime server.

## Berkas diubah pada tahap ini

- `app/Services/WorkOrderXlsxReader.php`: traversal XML dan pembacaan value/formula.
- `app/Http/Controllers/SyncController.php`: query inbound aktif dan proyeksi riwayat.
- `app/Http/Middleware/MeasureWebPerformance.php` (baru): timing opt-in, tanpa isi request/SQL.
- `config/performance.php` (baru), `.env.example`, `bootstrap/app.php`.
- `tests/Feature/WebPerformanceTest.php`, `tests/Feature/LoginUploadBudgetTest.php` (baru), `tests/Unit/WorkOrderXlsxReaderTest.php`.
- Dokumen ini dan addendum `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Perbaikan ownership403 dari tahap sebelumnya tetap ada pada working tree dan didokumentasikan terpisah di SYNC_MAPPING_ACCESS_FIX.md. Tidak ada commit/push/deployment otomatis.
