# Potensi PS — 21 Oktober 2026

Laravel 13 memakai Blade dan design tokens light/dark. Dashboard Area/Branch serta list WO sudah memakai scope cabang dan filter bersama. Fitur ini menambah metrik baca-saja; tidak mengubah data sumber, progress, mapping_order atau definisi PS terkonfirmasi.

Checklist implementasi:
- [x] Definisi delapan status_order dan query bersama pada WorkOrder::potentialPs().
- [x] Angka dashboard Area/Branch → antrean potential_ps dengan scope/filter yang sama.
- [x] Label antrean dan status_order lengkap pada tabel desktop/kartu mobile.
- [x] Feature tests kombinasi status, filter, scope, reimport, PS terkonfirmasi dan hasil kosong.
- [x] Validasi penuh SQLite/MariaDB, build, lint dan UI light/dark responsif.

## Aturan

status_order harus cocok dengan salah satu kombinasi berikut (bukan pencarian awalan task atau semua akhiran COMPLETE):

1. Activate Access Manual-STARTWORK
2. Activate Cross Connect-STARTWORK
3. Activate Global-COMPLETE
4. Activate Metro Manual-COMPLETE
5. Activate Metro Manual-STARTWORK
6. Activate NCIX-STARTWORK
7. Activate Service-STARTWORK
8. Activation Power-STARTWORK

Query memakai whereIn dengan parameter, mengikuti collation database untuk huruf besar/kecil. Tidak ada syarat tambahan progress, tanggal, outstanding, atau mapping_order. Dengan demikian Potensi PS dan PS terkonfirmasi dapat beririsan jika suatu order memenuhi kedua aturan. PS terkonfirmasi tetap progress CLOSE + status_bima CLOSE/COMPLETE; harian memakai tgl_ps.

Kartu selalu dapat diklik, termasuk angka nol. Area mengikuti regional/cabang/layanan/jenis pekerjaan; default semua jenis. Branch mengikuti cabang yang diizinkan/jenis pekerjaan; default NEW INSTALL. Bulan/periode grafik tidak membatasi potensi. Klik kartu membuka /work-orders?queue=potential_ps dengan filter relevan. Tab Potensi PS membawa juga pencarian, progress dan bucket yang sedang aktif di list. Tidak ada panggilan API eksternal ketika menghitung widget.

## File berubah

- app/Models/WorkOrder.php — daftar status dan scope potentialPs.
- app/Services/AreaAnalyticsService.php; app/Services/BranchAnalyticsService.php — metrik scoped.
- app/Services/WorkOrderQueueService.php; app/Http/Controllers/WorkOrderController.php — hitungan, filter dan validasi antrean.
- resources/views/area/dashboard.blade.php; resources/views/branch/dashboard.blade.php — kartu bertaut ke list.
- resources/views/work-orders/index.blade.php; resources/views/work-orders/mobile-card.blade.php — tab, konteks dan status_order.
- resources/css/app.css — lima kartu desktop, dua kolom tablet/mobile.
- tests/Feature/PotentialPsTest.php — tes baru.
- tests/Feature/AreaDashboardTest.php; tests/Feature/OperationsWorkspaceTest.php — ekspektasi metrik tambahan.
- AGENTS.md; dokumen ini.

Migration/env/dependensi baru: **tidak ada**. Tidak menjalankan migration atau backfill; hitungan langsung membaca status_order terkini. File template asli dan progress branch tetap terjaga. Tidak ada input/kredensial baru yang diperlukan. Tidak ada klaim tes E2E Google/Telegram.

## Validasi

- `php artisan test --compact`: **136 tes / 2.583 assertions lulus** (SQLite).
- `php /private/tmp/olo-id-mysql-tests.php`: **134 tes / 2.572 assertions lulus** di MariaDB sementara terpisah; database tes dihapus setelah selesai. Dua tes snapshot Sheets legacy besar (`historical_size_batch`, `provided_bima_imports`) dikecualikan pada MariaDB karena max_allowed_packet lokal 1 MiB, sama dengan baseline. Seluruh tes fitur Potensi PS dan import XLSX lulus pada kedua database.
- `vendor/bin/pint --dirty`, `vendor/bin/pint --dirty --test`, `git diff --check`, `npm run build`, `php artisan view:cache`: lulus.
- Tes baru memeriksa kedelapan pasangan, enam status mirip/tidak lengkap yang dikecualikan, klik URL dengan filter regional/cabang/layanan/jenis pekerjaan, scope Admin/HD/TL Branch, larangan lintas cabang, hasil kosong, tidak ada mutasi ketika membaca, serta import perubahan task yang mengubah potensi tanpa menimpa progress CLOSE. PS terkonfirmasi tetap dihitung terpisah.
- UI aktual port 8011 diperiksa pada 1280, 768 dan 375 px, light/dark; kartu lima kolom desktop dan dua kolom tablet/mobile, tanpa overflow halaman. Klik kartu Area membuka queue=potential_ps dengan semua jenis; kartu Branch dengan MATARAM membawa branch=16 dan jenis NEW INSTALL. Browser memakai superadmin untuk melihat kedua POV; pembatasan tiga role Branch diverifikasi pada feature tests.
- Data aktual saat pemeriksaan: Potensi PS 0 dari 13 WO pada dashboard Area; daftar menampilkan hasil kosong yang sesuai. Tidak mengubah data produksi untuk membuat contoh angka. Screenshot: /private/tmp/olo-potential-ps.png. Mode dikembalikan ke light dan viewport override di-reset.
- Tidak ada migration/backfill, environment baru, input bisnis yang memblokir, atau pengujian API eksternal pada perubahan ini. Tidak melakukan commit/push.
