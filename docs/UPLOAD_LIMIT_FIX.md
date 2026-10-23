# Perbaikan upload Sync Center — 23 Oktober 2026

Discovery: aplikasi membatasi XLSX 10 MiB, tetapi PHP CLI/HTTP lokal sebelumnya memakai upload_max_filesize=2M dan post_max_size=8M. Error uploaded bawaan Laravel tidak menjelaskan kode kegagalan PHP. File contoh Order-OLO-20102026.xlsx lama berukuran 870 KiB, sehingga penyebab file terbaru tetap perlu dibedakan berdasarkan ukuran/error aktual; jangan mengklaim semua kegagalan berasal dari ukuran.

Checklist:
- [x] Samakan batas aplikasi dan child server artisan serve (upload 10M, POST 12M), tanpa mengubah php.ini global.
- [x] Konfigurasi per direktori untuk Apache mod_php serta FPM/CGI; pesan PHP upload terputus/penyimpanan/ukuran dalam bahasa Indonesia.
- [x] Tampilkan batas HTTP efektif dan cegah file terlalu besar di browser; tangani HTTP 413 dengan halaman yang dapat ditindaklanjuti.
- [x] Tes otomatis workbook >2 MiB hingga mapping pada database terisolasi; pemeriksaan batas HTTP melalui UI baca-saja.
- [ ] Uji unggah browser: tidak dijalankan karena izin unggah file uji ke localhost:8011 ditolak; tidak dicoba melalui jalur lain.
- [x] Muat ulang server dompisprov port 8001/8011 dan verifikasi batas efektif 10 MiB.

Tidak ada migration, env atau dependensi baru. Batas XLSX aplikasi tetap 10 MiB, 10.000 order dan validasi isi workbook tetap berlaku. File template asli tidak diubah. Perintah serve proyek diikat ke LocalServeCommand yang menyisipkan -d pada child HTTP PHP; flag pada parent artisan/ini_set saja tidak cukup untuk upload yang telah ditolak sebelum Laravel.


## Hasil aktual dan batas verifikasi

Dua proses HTTP dompisprov lama (port 8001 dan 8011) dimuat ulang. Child PHP terverifikasi berjalan dengan `-d upload_max_filesize=10M -d post_max_size=12M`. Sync Center pada 8011 menampilkan maksimal 10 MB. Server dompis-qe port 8000 tidak diubah; php.ini global juga tidak diubah. CLI biasa tetap dapat menunjukkan default 2M karena batas proyek diterapkan langsung pada child HTTP.

Validasi `WorkOrderUploadTest` mencakup kode PHP INI_SIZE, PARTIAL, NO_TMP_DIR, CANT_WRITE, file kosong/ekstensi salah/>10 MiB/input array, HTML/JSON 413, tampilan batas efektif, binding server dan workbook XLSX sintetis 3 MiB hingga mapping tanpa membuat WO. Lima tes / 58 assertions lulus setelah pemeriksaan tambahan input array. Suite seluruh 163 tes / 3.407 assertions lulus sebelum tambahan dua assertions input array; tes terkait diulang dan lulus. Build Vite, cache Blade, Pint dan diff check lulus.

Perintah:

```sh
php artisan test --compact --filter='WorkOrderUploadTest|ExcelWorkOrderImportTest'
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php artisan test --compact --filter=WorkOrderUploadTest
npm run build
php artisan view:cache
vendor/bin/pint --dirty --test
git diff --check
php artisan serve --host=127.0.0.1 --port=8001
php artisan serve --host=127.0.0.1 --port=8011
```

Unggah HTTP langsung belum diverifikasi: percobaan memilih file uji 11 MiB melalui file chooser ditolak oleh pemeriksaan izin browser karena izin unggah tidak diberikan. Tidak ada file uji yang terkirim, tidak ada import/job/WO aktual dibuat, dan tidak ada workaround HTTP/browser lain. Bukti UI baca-saja tersimpan `/private/tmp/olo-upload-limit-10mb.png`.

Untuk Apache mod_php disediakan blok php_module pada `public/.htaccess`; FPM/CGI memakai `public/.user.ini` (mengikuti cache user_ini layanan). Kedua deployment tersebut belum diuji end-to-end dalam sesi ini; server aktif yang telah dibuktikan adalah artisan serve. Proxy eksternal dapat mempunyai batas tersendiri. Bila file <=10 MiB masih gagal, pesan Indonesia kini menunjukkan kategori kegagalan; ukuran file/alamat akses serta pesan baru diperlukan untuk memastikan penyebab file aktual.

## File berubah

- `app/Console/LocalServeCommand.php` (baru), `app/Providers/AppServiceProvider.php`: batas upload pada child server lokal.
- `app/Support/WorkOrderUpload.php` (baru), `app/Http/Controllers/WorkOrderImportController.php`, `app/Http/Controllers/SyncController.php`, `app/Services/WorkOrderXlsxReader.php`: batas bersama dan error yang dapat ditindaklanjuti.
- `bootstrap/app.php`, `resources/views/errors/upload-too-large.blade.php` (baru): HTML/JSON 413 sebelum middleware session.
- `public/.htaccess`, `public/.user.ini` (baru): batas per direktori server lain.
- `resources/views/sync/excel-inbound.blade.php`, `resources/js/app.js`: batas efektif, informasi ukuran file dan validasi sebelum submit.
- `tests/Feature/WorkOrderUploadTest.php` (baru); file dokumentasi ini, `docs/EXCEL_WORK_ORDER_IMPORT.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Tidak ada migration/env baru atau perubahan data operasional/credential. Sumber XLSX asli hanya diperiksa ukuran filenya, tidak diubah.
