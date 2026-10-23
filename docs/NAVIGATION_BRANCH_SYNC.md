# Penyederhanaan menu dan istilah Branch — 23 Oktober 2026

Discovery: menu Blade terpusat, Sync Center mempunyai panel inbound/outbound terpisah; push dapat dibuat melalui route dan diulang dari detail job. Distribusi order otomatis tetap bergantung pada referensi Branch/STO internal.

Checklist:
- [x] Hapus menu/tautan Branch & Mapping dari UI tanpa menghapus data referensi distribusi.
- [x] Hapus panel/form push pada kedua mode inbound, tutup endpoint push dan retry/commit job push lama, hentikan queued push tanpa menulis Google.
- [x] Gunakan istilah Branch pada label, petunjuk, validasi dan teks bantuan pengguna.
- [x] Tes, build/lint, periksa desktop/mobile light/dark; dokumentasikan hasil nyata.

Tidak ada perubahan struktur database/env, nama Branch aktual, assignment, hasil import atau progress. Riwayat publikasi lama tetap dapat dibaca sebagai arsip tanpa aksi menjalankan ulang. Menu mapping dihilangkan; referensi dan route administrasi yang sudah ada tetap dipertahankan untuk kompatibilitas internal.


## Implementasi

Navigasi utama Area kini Dashboard Area, Work Orders, Sync Center, User Management (sesuai izin). Tautan Branch & Mapping di sidebar, tombol mapping dari kedua mode Sync Center dan petunjuk tautan pada User Management dihapus. Data dan otorisasi referensi distribusi tetap ada.

Panel/form Kirim progress monitoring di mode Excel maupun Sheets dihapus; Sync Center memakai satu panel inbound dengan riwayat di bawahnya. Route POST /sync/monitoring/push dan action push controller dihapus. Commit/retry job arah push mengembalikan 410, detail arsip tidak memiliki form konfirmasi/retry/poll aktif. Worker menghentikan queued/running/retrying push sebagai failed dengan alasan fitur dinonaktifkan dan audit monitoring.disabled, tanpa memanggil gateway. Job sukses lama tidak diubah. Service/protokol monitoring legacy tetap tersimpan sebagai kode kompatibilitas, tidak dipanggil route atau worker aplikasi.

Istilah cabang diganti Branch pada label dashboard/list/detail, petunjuk import, materi, user management, status bantuan dinamis, validasi backend, dan pesan perintah lokasi. Nama Branch sebenarnya seperti SEMARANG/SURABAYA, ID tabel, route, foreign key dan kontrak file sumber tidak diganti.

## Validasi aktual

- `php artisan test --compact --filter='MonitoringPushTest|AccessAndSyncTest|OperationsWorkspaceTest|UserManagementTest'`: 22 tes, 329 assertions lulus.
- `php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never`: seluruh 158 tes SQLite, 3.351 assertions lulus. Memori hanya untuk proses tes fixture historis; konfigurasi aplikasi tidak diubah.
- `php /private/tmp/olo-navigation-mysql-tests.php`: 20 tes MySQL terisolasi / 271 assertions lulus (MonitoringPushTest, AccessAndSyncTest, UserManagementTest). Database uji dihapus setelah pengujian. Tidak memanggil API Google asli.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check`: lulus.
- Browser localhost 8011: empat menu Area tanpa Branch & Mapping; satu panel inbound, nol form outbound, label Distribusi Branch. Desktop 1280px dan mobile 375px dark tidak overflow halaman; drawer mobile berfungsi. Bukti `/private/tmp/olo-sync-center-branch-menu.png`. Tidak mengirim upload/ubah data aktual saat pemeriksaan.
- Worker lokal dompisprov dimuat ulang (`php artisan queue:restart`, lalu `php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1`) agar guard push lama berlaku. Worker repository lain tidak diubah.

## File berubah pada tahap ini

- Backend: `routes/web.php`, `app/Http/Controllers/SyncController.php`, `app/Jobs/ProcessSheetSync.php`, `app/Http/Controllers/WorkOrderImportController.php`, `app/Http/Requests/SaveUserRequest.php`, `app/Services/UserManagementService.php`, `app/Console/Commands/AssignUserLocation.php`.
- UI: `resources/views/layouts/app.blade.php`; `resources/views/sync/excel-inbound.blade.php`, `sheets-inbound.blade.php`, `history.blade.php`, `show.blade.php`; `resources/views/area/dashboard.blade.php`, `operations-handoff.blade.php`; `resources/views/branch/dashboard.blade.php`, `material.blade.php`; `resources/views/work-orders/index.blade.php`, `show.blade.php`; `resources/views/owner-groups/summary.blade.php`; `resources/views/imports/mapping.blade.php`; `resources/views/admin/users/index.blade.php`, `form.blade.php`; `resources/js/app.js`.
- Tes: `tests/Feature/MonitoringPushTest.php`, `OperationsWorkspaceTest.php`, `BimaWorkOrdersTemplateTest.php`.
- Dokumentasi: file ini, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Tidak ada migration atau env/dependency baru. Tidak menghapus master Branch/STO/mapping, progress, assignment, riwayat atau file template. Tidak ada input tambahan yang diperlukan pada permintaan ini.
