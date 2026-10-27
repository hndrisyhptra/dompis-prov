# Tanggal PS dari Date Modified BIMA

Checklist:
- [x] Inspect mapping, parser tanggal, hash/preview/commit, master Mapping Order, auto CLOSE dan analytics Area/Branch.
- [x] Tambahkan Date Modified → tgl_ps pada preset berbasis header, dengan penjelasan kondisional di UI.
- [x] Isi tanggal hanya untuk kombinasi status PS yang berlaku dan master mapping PS aktif; jaga tanggal lama untuk order non-PS/sumber kosong.
- [x] Hash/log idempotent, cek perubahan master setelah preview, invalidasi preview kontrak lama.
- [x] Uji import/ulang/pergeseran kolom/tanggal/otorisasi/analytics pada SQLite dan MariaDB; lint/build dan dokumentasi hasil.

Aturan baru menggantikan catatan lama bahwa Date Modified bukan sumber tanggal PS. Definisi PS tetap progress CLOSE + daftar status PS yang telah disetujui. Pada import, tanggal diambil dari Date Modified hanya bila status sumber memenuhi daftar tersebut dan lookup aktif Mapping Order menghasilkan PS. Tidak menganggap semua status COMPLETE sebagai PS. Tidak melakukan backfill dengan tanggal yang ditebak atau mengubah file sumber.


## Perilaku
- Preset v4-ps-date menampilkan **14 pasangan** termasuk Date Modified → tgl_ps, berdasarkan header unik sehingga posisi kolom boleh bergeser. Pada preset lengkap, pemilihan tanggal lain (misalnya Date Created/Status Date) ditolak; operator boleh memilih Tidak diimpor.
- Eligibility tanggal menggunakan status task/status BIMA efektif (termasuk atribut lama jika mapping manual parsial) dan lookup aktif master Mapping Order PS. Daftar 14 kombinasi PS yang sudah disetujui tetap berlaku; mapping PS tambahan untuk status di luar daftar tidak mengubah definisi PS secara implisit.
- Tanggal kalender diambil dari nilai file (teks, timestamp termasuk milidetik, serial Excel 1900/1904), tanpa mengganti tanggal ke hari import. Kolom SQL tetap DATE; tidak membuat kolom timestamp baru. Legacy task kosong + COMPWORK mengikuti aturan -COMPWORK yang ada; task bernama COMPWORK dengan status kosong tidak dianggap PS.
- Nilai kosong/- mempertahankan tanggal lama; order baru tetap NULL jika tanggal belum ada. Non-PS dan mapping yang bukan PS/tidak aktif/tidak dikenal mengabaikan Date Modified. Baris PS dengan tanggal salah/formula/error ditolak dengan pesan Date Modified. Tidak mengambil cache hasil formula.
- Koreksi Date Modified untuk order PS mengubah tgl_ps sesuai sumber terbaru; hash sumber, before/after log BIMA, audit dan waktu perubahan tetap lewat writer yang sama. Waktu/format mentah disimpan di JSON sumber TGL PS. Unggah identik tidak menggandakan WO/log; perubahan Date Modified yang tidak dipakai pada order non-PS tidak memaksa update. Tanggal lokal lama yang tidak berasal dari mapping file tidak dimasukkan ke hash sumber secara spekulatif.
- Progress/riwayat Branch, kendala, perangkat, verifikasi dan tanggal lain tetap. Import PS masih dapat auto CLOSE sesuai aturan sebelumnya; tidak menutup kendala. Riwayat tidak direkayasa sebagai tanggal historis. Tidak mengubah bulan_ps secara spekulatif; grafik/PS harian tetap memakai tgl_ps.
- Cache lookup master tanggal hanya dalam satu tahap yang dilindungi lock inbound dan dibersihkan di finally. Kunci cache memuat task dan status terpisah. Preview menyimpan fingerprint rule; perubahan master/eligibility setelah preview menjadi conflict dan memerlukan preview baru.
- File generik dengan kolom TGL PS eksplisit dan adapter Sheets 32 kolom tetap memakai kontrak sebelumnya. Date Modified yang dipilih manual juga melewati eligibility baru. Tidak menambahkan integrasi/kredensial.

## Penggunaan
1. Upload ulang export BIMA dari Sync Center (boleh file yang sama), periksa **TGL PS → Date Modified**.
2. Buat preview, periksa baris ditolak/konflik, lalu konfirmasi import.
3. Dashboard Area/Branch, grafik harian, filter PS hari ini dan detail WO membaca tanggal yang tersimpan melalui mekanisme yang sama.

Snapshot Excel lama memakai connection hash v4; kontrak baru v5-date-modified-ps menolaknya agar tanggal baru tidak ditafsirkan dari preview lama. Job/data/riwayat lama tetap tersimpan; tidak dihapus atau di-commit otomatis. Tanggal pada order yang sudah ada akan terisi/dikoreksi saat file sumber valid diimpor kembali. Tidak menjalankan backfill pada database aktual karena Date Modified untuk order lama belum disediakan sebagai sumber terverifikasi pada pekerjaan ini. Worker daemon yang masih memuat kode lama perlu graceful restart (`php artisan queue:restart`, pastikan supervisor/worker aktif kembali).

## File diubah
- `app/Services/BimaWorkOrdersTemplate.php`
- `app/Services/ExcelWorkOrderMapper.php`
- `app/Services/BimaPsDateResolver.php` (baru)
- `app/Services/ExcelWorkOrderImportService.php`
- `app/Http/Controllers/WorkOrderImportController.php`
- `resources/views/imports/mapping.blade.php`
- `resources/views/imports/mapping-field.blade.php`
- `tests/Feature/BimaPsDateTest.php` (baru)
- `tests/Feature/BimaWorkOrdersTemplateTest.php`
- `docs/BIMA_PS_DATE.md`, `docs/BIMA_MAPPING_PERFORMANCE.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Perubahan UI prioritas/performance/login yang sudah ada di workspace dipertahankan. Tidak ada checkout/reset/commit/push.

## Validasi
- `php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never`: **295 tes / 26.500 assertions lulus**.
- Sesudah penambahan kasus cache legacy COMPWORK: `php -d memory_limit=512M vendor/bin/phpunit --filter BimaPsDateTest --no-progress --colors=never`: **7 tes / 328 assertions lulus**.
- MariaDB strict pada database sementara terpisah: **83 tes / 5.488 assertions lulus**, mencakup aturan tanggal, kedua format BIMA, routing, auto progress/CLOSE, dashboard, privasi prioritas, ekspor, Revenue dan performance budget. Database sementara dihapus pada finally; data aplikasi tidak diubah.
- `node --test tests/JavaScript/*.test.js`: **27 tes lulus**.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check`: lulus.
- Kasus baru: semua 14 status PS (termasuk -COMPWORK), COMPLETE non-PS, mapping PS status di luar daftar, rule nonaktif/hilang/non-PS, retry, koreksi, tanggal kosong, tanggal salah/formula/error, pergeseran kolom, serial dua epoch, tanggal order Juli dan PS Oktober, rule berubah setelah preview, preview kontrak lama, partial mapping dan penolakan Date Created sebagai PS. Dashboard dan antrean Area/Branch direkonsiliasi dengan tanggal import.

## Migrasi/env/batas
Tidak ada migration, env, dependency atau perubahan schema. Tidak ada upload/commit aktual, backfill database aplikasi, atau tes end-to-end Google/Telegram pada pekerjaan ini. Pemeriksaan visual browser manual belum dilakukan; Blade/build dan regression render sudah lulus. Tidak ada input bisnis yang menghalangi aturan ini; Date Modified kosong tetap menjadi data tanggal yang belum tersedia, bukan ditebak dari waktu upload.
