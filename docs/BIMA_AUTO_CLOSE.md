# CLOSE otomatis dari BIMA — 23 Oktober 2026

Discovery: Laravel 13/Blade, import Excel dan adapter Sheets memakai WorkOrderSourceWriter; hash sumber terpisah dari progress. PS memakai 14 pasangan penuh pada PsEligibility. Arahan terbaru mengizinkan penyesuaian progress CLOSE dari status BIMA tersebut, termasuk data historis. Tidak ada perubahan skema dan tidak ada tanggal historis yang ditebak.

Checklist implementasi:
- [x] Pisahkan kecocokan status sumber dari syarat progress PS, satu definisi 14 status.
- [x] Penyesuaian atomik dengan row lock, revision, event/audit; tidak menyelesaikan kendala atau mengubah source/hash/perangkat/tanggal PS.
- [x] Terapkan pada import baru/berubah/identik; selain 14 status tetap menjaga progress, tanpa auto-reopen.
- [x] Perintah backfill dengan actor Area, lock inbound, dry-run, rincian bulan dan retry idempotent.
- [x] Rekap bulan order Area/Branch dengan tautan list berfilter bulan (termasuk Juli–Agustus), scope yang sama.
- [x] Tes, build dan pemeriksaan UI.
- [x] Dry-run lalu eksekusi pada database lokal; rekonsiliasi hasil dan dokumentasi angka aktual.

Ketentuan: progress CLOSE otomatis berasal dari konfirmasi BIMA, tidak mengklaim Branch melakukan update manual atau Area memverifikasi lapangan. Operational status menjadi FIELD_DONE kecuali COMPLETED yang sudah ada dipertahankan. Tanggal branch_completed_at/field_done_at/verified_at tidak direkayasa dari bulan order; verifikasi Area yang belum ada tidak diberikan otomatis. Riwayat menyimpan waktu penyesuaian saat ini, progress lama dan alasan. Kendala aktif tetap OPEN untuk ditinjau, bukan otomatis resolved. Manual CLOSE tetap memiliki validasi kendala. Perubahan sumber setelah PS tidak otomatis membuka kembali progress.

Rekap bulan memakai TGL ORDER (cohort), bukan tanggal selesai. Sudah PS adalah bagian dari Progress CLOSE, bukan angka yang dijumlahkan dengan CLOSE. PS harian memakai tgl_ps yang valid; order historis tanpa tanggal PS tetap masuk PS total/cohort tetapi tidak menjadi perolehan hari ini. Tidak menghidupkan kembali kartu/tab PS terkonfirmasi yang pernah dihapus.


## Hasil penerapan database lokal

Eksekusi 23 Oktober 2026 dengan actor `superadmin`: 3.368 WO tetap tersimpan; 2.880 order BIMA memenuhi aturan dan disesuaikan menjadi progress CLOSE. Sebelum penerapan, 0 dari 2.880 order tersebut sudah progress CLOSE. Tidak ada kendala aktif pada kelompok yang disesuaikan. Dibuat 2.880 event progress dan 2.880 audit per order, ditambah satu audit ringkasan batch.

| Bulan TGL ORDER | Total WO | Progress CLOSE sesudah | Sudah PS | Outstanding |
|---|---:|---:|---:|---:|
| Mei 2026 | 53 | 44 | 44 | 9 |
| Juni 2026 | 534 | 487 | 487 | 47 |
| Juli 2026 | 568 | 519 | 519 | 49 |
| Agustus 2026 | 621 | 593 | 593 | 28 |
| September 2026 | 806 | 717 | 717 | 89 |
| Oktober 2026 | 786 | 521 | 520 | 265 |

Angka di atas seluruh jenis pekerjaan/cabang. Default dashboard Branch tetap NEW INSTALL; memilih Semua jenis memberi angka cohort yang sebanding dengan Area. Satu CLOSE manual Oktober masih menunggu BIMA, sehingga total progress CLOSE 2.881 sedangkan PS 2.880.

Checksum sebelum/sesudah cocok untuk seluruh kolom WO di luar allowlist penyesuaian progress (`progress_category`, `operational_status`, `revision`, `last_progress_at`, `updated_at`, `verification_status`, `verified_at`) serta seluruh record kendala. Ini mencakup data sumber/hash, assignment, perangkat, TGL ORDER/TGL PS, tanggal selesai lapangan dan data lain yang dilindungi. Dry-run setelah eksekusi: matched=2880, already_close=2880, to_close=0, updated=0. Retry tidak menggandakan event progress.

Batas data nyata: seluruh 2.880 PS saat ini tanpa `tgl_ps`, sehingga PS harian tetap 0. Diperlukan tanggal PS dari sumber yang disetujui untuk mengetahui hari/bulan kejadian PS; bulan order tidak dipakai sebagai pengganti tanggal selesai. Tidak ada tanggal yang dibuat-buat.

## Perintah dan pengujian

```sh
php artisan olo:reconcile-bima-close --actor=superadmin --dry-run
php artisan olo:reconcile-bima-close --actor=superadmin
php artisan olo:reconcile-bima-close --actor=superadmin --dry-run
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php artisan test --compact --filter=BimaAutoCloseTest
npm run build
php artisan view:cache
vendor/bin/pint --dirty --test
git diff --check
php artisan queue:restart
php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1
```

Hasil: seluruh 158 tes SQLite / 3.323 assertions lulus. MariaDB terisolasi: 156 tes / 3.312 assertions lulus melalui helper `/private/tmp/olo-status-progress-mysql-tests.php`; database uji dihapus dalam finally. Dua fixture snapshot Sheets historis besar dikecualikan hanya di MariaDB karena batas max_allowed_packet 1 MiB lokal; keduanya lulus dalam suite SQLite. Memory 512M hanya untuk proses tes fixture besar, bukan perubahan konfigurasi runtime. Pemeriksaan ulang 4 tes BimaAutoClose / 147 assertions lulus. Build Vite, cache Blade, Pint dan diff check lulus.

Tes meliputi seluruh 14 status, batas exact matching, source non-PS, dry-run, actor tidak berwenang/nonaktif, lock inbound, idempotency, event lama/baru, source/hash/perangkat/tanggal/kendala tetap, input baru/berubah/identik, preview tanpa penulisan, tidak auto-reopen, scope cabang dan tautan cohort bulan order. Fixture alur manual diperbarui agar mulai dengan BIMA STARTWORK, lalu konfirmasi COMPLETE setelah Close lapangan.

Browser lokal: dashboard menampilkan Juli=519 PS dan Agustus=593 PS. Klik Juli membuka `/work-orders?jenis_pekerjaan=all&order_month=2026-07&queue=complete`, total 519, seluruh baris yang terlihat progress CLOSE dan tanggal Juli. Pagination menjaga filter bulan/cakupan. Desktop light, mobile 375 dark dan tablet 768 tidak overflow halaman; tabel horizontal scroll dalam panel. Bukti tampilan: `/private/tmp/olo-ps-bulan-order.png`.

Worker dompisprov lama dimuat ulang dan dijalankan kembali agar upload berikutnya memakai aturan baru; worker repository lain tidak diubah. Tidak menjalankan sinkronisasi Google/Telegram end-to-end.

## File berubah pada tahap ini

- Logika PS/penyesuaian: `app/Support/PsEligibility.php`, `app/Services/BimaAutoCloseService.php` (baru), `app/Console/Commands/ReconcileBimaClose.php` (baru), `app/Services/WorkOrderSourceWriter.php`, `app/Services/ExcelWorkOrderImportService.php`, `app/Services/BimaSheetPullService.php`.
- Rekap/scope: `app/Services/OrderMonthAnalyticsService.php` (baru), `app/Services/AreaAnalyticsService.php`, `app/Services/BranchAnalyticsService.php`, `app/Services/BranchOrderScope.php`, `app/Http/Controllers/WorkOrderController.php`.
- UI: `resources/views/work-orders/order-month-summary.blade.php` (baru), `resources/views/work-orders/index.blade.php`, `resources/views/work-orders/mobile-card.blade.php`, `resources/views/area/dashboard.blade.php`, `resources/views/area/operations-handoff.blade.php`, `resources/views/branch/dashboard.blade.php`, `resources/views/imports/mapping.blade.php`, `resources/css/app.css`.
- Tes: `tests/Feature/BimaAutoCloseTest.php` (baru), `tests/Feature/BimaStatusDevicesTest.php`, `tests/Feature/BimaWorkOrdersTemplateTest.php`.
- Dokumentasi: file ini, `docs/WORK_ORDER_STATUS_PROGRESS.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Tidak ada migration, env, dependency baru. Backfill dilakukan lewat command terotorisasi dan diaudit, bukan migrasi struktur. Progress lain di branch dan file template asli tetap dipertahankan. Tidak ada input tambahan yang diperlukan untuk klasifikasi CLOSE/PS; TGL PS masih diperlukan untuk statistik tanggal selesai historis.
