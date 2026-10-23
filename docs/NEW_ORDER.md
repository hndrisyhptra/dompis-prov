# New Order — 21 Oktober 2026

Discovery: Laravel 13, Blade, layanan analytics dan scope cabang bersama. Potensi PS sudah scoped untuk Admin/HD/TL Branch dan filter aktif. New Order memakai pola kartu→antrean WO yang sama. Tidak perlu menambahkan tabel database atau menyalin data WO: klasifikasi dihitung dari status_order terkini setelah import.

Checklist:
- [x] Tujuh kombinasi status_order, scope dan hitungan antrean bersama.
- [x] Kartu New Order pada dashboard Area/Branch, tautan mengikuti scope dan filter.
- [x] List New Order dengan tab, pencarian, pagination, status order lengkap dan tampilan mobile.
- [x] Feature tests kombinasi, scope/filter, transisi import dan hasil kosong.
- [x] Validasi tes, build dan UI aktual.

## Aturan

New Order = status_order sesuai salah satu dari:
1. Review Order TSQ Metro Manual-COMPLETE
2. Review Order TSQ Metro Manual-STARTWORK
3. Review Order TSQ Modify Metro Manual-COMPLETE
4. Review Order TSQ Modify Metro Manual-STARTWORK
5. Review Order TSQ WDM-COMPLETE
6. Review Order TSQ WDM-STARTWORK
7. Review Order TSQ-STARTWORK

Query parameter whereIn mengikuti collation database untuk kapitalisasi, sama dengan Potensi PS. Tidak menebak dari suffix COMPLETE saja, tanggal hari ini, operational_status NEW, mapping_order atau progress branch. Tidak mengubah progress menjadi NEW. Ketika task/status BIMA berubah melalui import, order otomatis masuk/keluar daftar sesuai status terkini. History import dan progress tetap memakai mekanisme yang sudah ada.

Kartu dan tab membuka /work-orders?queue=new_order. Dashboard Area mengikuti regional, branch, layanan dan jenis pekerjaan (default semua jenis). Dashboard Branch mengikuti branch yang diizinkan dan jenis pekerjaan (default NEW INSTALL). Pilih Semua jenis untuk ikut menampilkan MODIFY dan jenis lain. Bulan/periode hanya mengatur grafik PS. Potensi PS tetap tampil pada kedua dashboard dengan scope yang sama seperti sebelumnya; PS terkonfirmasi tetap memakai aturan CLOSE + status_bima CLOSE/COMPLETE.

Enam kartu dashboard diatur tiga kolom desktop/dua kolom tablet-mobile. Tabel New Order dan Potensi PS menampilkan kolom Status order BIMA tersendiri; mobile menampilkan status lengkap dalam kartu order. Tindakan progress/kendala/close tetap mengikuti policy.

## File berubah

- app/Models/WorkOrder.php — NEW_ORDER_STATUSES dan scopeNewOrder.
- app/Services/AreaAnalyticsService.php; app/Services/BranchAnalyticsService.php — hitungan New Order.
- app/Services/WorkOrderQueueService.php; app/Http/Controllers/WorkOrderController.php — antrean/count/validasi new_order.
- resources/views/area/dashboard.blade.php; resources/views/branch/dashboard.blade.php — kartu dan tautan list.
- resources/views/work-orders/index.blade.php; resources/views/work-orders/mobile-card.blade.php — list/tab/status dan hasil kosong.
- resources/css/app.css — grid enam kartu responsif.
- tests/Feature/NewOrderTest.php (baru); tests/Feature/AreaDashboardTest.php; tests/Feature/OperationsWorkspaceTest.php — tes dan metrik baru.
- AGENTS.md; dokumen ini.

Migration, backfill, env dan dependensi baru: tidak ada. Tidak mengubah data asli untuk widget, tidak ada input bisnis/kredensial yang memblokir. Tidak melakukan commit/push atau mengklaim tes API Google/Telegram.

## Hasil validasi

- `php artisan test --compact --filter='NewOrderTest|PotentialPsTest|AreaDashboardTest|OperationsWorkspaceTest'`: 12 tes / 360 assertions lulus.
- `php artisan test --compact`: **139 tes / 2.694 assertions lulus**, SQLite.
- `php /private/tmp/olo-id-mysql-tests.php`: **137 tes / 2.683 assertions lulus**, MariaDB sementara terpisah; database tes dibersihkan setelah selesai. Dua tes snapshot Sheets legacy besar (`historical_size_batch`, `provided_bima_imports`) dikecualikan khusus MariaDB karena max_allowed_packet lokal 1 MiB, sama dengan baseline. Seluruh tes New Order/Potensi PS dan XLSX lulus pada kedua database.
- `vendor/bin/pint --dirty`, `vendor/bin/pint --dirty --test`, `git diff --check`, `npm run build`, `php artisan view:cache`: lulus.
- Pengujian mencakup tujuh status yang diterima dan tujuh pembanding, tanggal order lama tetap dihitung, filter regional/branch/layanan/jenis, keempat role Area, Admin/HD/TL Branch, forbidden lintas cabang, akun tanpa assignment, pencarian, hasil kosong, baca tanpa mutasi, reimport identik dan perpindahan New Order→Potensi PS tanpa mengubah progress.
- Browser aplikasi aktual port 8011: Area New Order **5** dari 13 WO; klik kartu membuka tabel **5 baris** dengan seluruh status sesuai daftar. Branch SEMARANG/Semua jenis menunjukkan **1**, klik kartu membawa branch=9, jenis_pekerjaan=all dan queue=new_order, list menunjukkan **1**. Potensi PS tetap hadir di kedua dashboard.
- UI 1280 px desktop, 768 px tablet, 375 px mobile diperiksa dalam light/dark: grid tiga/dua kolom, list mobile memakai lima kartu dengan status lengkap, tabel desktop memiliki kolom Status order BIMA, tidak ada overflow halaman. Browser memakai superadmin untuk kedua POV; scope tiga role branch diuji otomatis. Tidak menyimpan perubahan progress atau mengubah data WO untuk contoh. Preferensi kembali light dan viewport di-reset.
- Screenshot dashboard tanpa data pelanggan: /private/tmp/olo-new-order-dashboard.png. Tidak ada migration/env/dependensi baru atau input yang memblokir. Google/Telegram tidak diuji E2E pada tahap ini; tidak ada commit/push.
