# Nama fisik kolom work_orders — 20 Oktober 2026

Masalah: label import/detail sudah mengikuti daftar pengguna, tetapi nama fisik database masih bahasa Inggris. Koreksi ini mengganti nama fisik 28 atribut sumber ke snake_case sesuai label pengguna; hanya satu BRANCH. Kolom identitas internal, FK, operasional, audit dan timestamp tetap dibutuhkan aplikasi.

Checklist:
- [x] Migrasi rename-only yang menjaga nilai, tipe, unique index/kolasi identitas dan relasi.
- [x] Sesuaikan mapper, query/model, dashboard, monitoring dan tampilan.
- [x] Bedakan kolom branch dari relasi master cabang (assignedBranch).
- [x] Invalidasi snapshot input lama; pertahankan riwayat dan data operasional.
- [x] Uji migrasi populated/rollback, alur import, RBAC, dashboard dan build.
- [x] Backup, migrasi aktual dan verifikasi nama fisik pada database dompisprov.

Tidak memakai migrate:fresh/truncate pada database aplikasi. Migration lama tetap utuh. Tidak mengubah template asli atau membuat klaim koneksi eksternal baru.

## Nama kolom fisik hasil akhir

| Label sumber | Nama kolom MySQL |
|---|---|
| TGL ORDER | `tgl_order` |
| BULAN | `bulan` |
| TAHUN | `tahun` |
| REGIONAL | `regional` |
| BRANCH | `branch` |
| STO | `sto` |
| NO TQ/SC/ID | `no_tq_sc_id` |
| NAMA OLO | `nama_olo` |
| ALAMAT | `alamat` |
| LAYANAN | `layanan` |
| JENIS PEKERJAAN | `jenis_pekerjaan` |
| BW | `bw` |
| STATUS ORDER | `status_order` |
| TASK BIMA | `task_bima` |
| TGL PS | `tgl_ps` |
| SID | `sid` |
| KETERANGAN | `keterangan` |
| ODP | `odp` |
| SN | `sn` |
| OLT | `olt` |
| PORT OLT | `port_olt` |
| METRO | `metro` |
| PORT METRO | `port_metro` |
| STATUS FINAL (OPEN,CLOSE,CANCEL) | `status_final` |
| BULAN PS | `bulan_ps` |
| PS HARI INI | `ps_hari_ini` |
| UMUR (HARI) | `umur_hari` |
| KATEGORI UMUR | `kategori_umur` |

25 nama kolom diganti; regional, olt dan metro sudah sesuai. Jumlah/nilai kolom tidak berkurang. Nama menggunakan huruf kecil dan underscore sesuai konvensi SQL/Laravel. Tipe, nullability, binary collation identitas, unique index dan FK tidak diubah. Urutan fisik kolom tetap; daftar di atas mengikuti urutan permintaan.

Migration: `2026_10_20_000010_rename_work_order_source_columns.php`. Nama fisik lama tidak menjadi kolom alias/duplikat. Relasi model ke master cabang bernama `assignedBranch`, sedangkan `branch` adalah teks sumber BIMA. Parameter URL filter lama `job_type` tetap diterima sebagai alias `jenis_pekerjaan`.

Snapshot input Excel v1/v2 dan job Sheets dengan hash kontrak lama tidak boleh dikonfirmasi/dilanjutkan pada skema baru; upload/preview ulang. Audit/log lama tetap tersedia. Tidak ada environment atau credential baru. API Google/Telegram tidak diuji melalui perubahan ini.

## Hasil pengujian dan penerapan

- SQLite: **107 tes lulus, 1.516 assertions**. MariaDB: **105 tes lulus, 1.504 assertions** pada suite penuh sebelum penyesuaian assertion tanggal fixture lintas driver. Setelah penyesuaian fixture SQLite, seluruh suite SQLite kembali lulus; tidak ada perubahan kode aplikasi sesudah suite MariaDB. Dua tes snapshot Sheets legacy tetap dikecualikan hanya pada MariaDB karena max_allowed_packet 1 MiB, seperti pengujian sebelumnya. Seluruh tes Excel dan rename skema berjalan pada kedua engine.
- Tes khusus berisi 28 nilai sumber pada tabel populated, identitas berbeda kapital, leading zeros, progress/revisi/timestamp, event/kendala, tipe/nullability, unique index, rollback dan nama fisik lama yang harus hilang. Tes alur juga memeriksa relasi master cabang dan bookmark filter job_type lama.
- Pint, build Vite, kompilasi Blade dan git diff --check lulus. Tidak melakukan panggilan Google/Telegram baru.
- Migrasi `2026_10_20_000010_rename_work_order_source_columns` berhasil diterapkan pada database aktif **dompisprov**.
- Backup terenkripsi privat: `storage/app/private/maintenance/before-work-order-column-rename-20261020-023616.json.enc` (0600, diabaikan Git).
- Verifikasi aktual: semua 28 nama baru tersedia dan 25 nama lama hilang. Nilai pada 13 tabel bisnis/auth sesuai backup; tipe, nullable, default, binary collation identitas, unique index dan FK sama. Tepat sebelum migrasi terdapat 0 WO; perilaku populated diuji pada database testing terpisah, bukan memasukkan contoh ke aplikasi aktual.
- Aplikasi aktif kembali, config cache dibersihkan dan worker dimuat ulang memakai kontrak nama kolom baru. Environment tidak berubah.

Perintah yang dijalankan: `php artisan test --compact`, `php /private/tmp/olo-id-mysql-tests.php` (database sementara terpisah), `vendor/bin/pint --dirty --test`, `npm run build`, `php artisan view:cache`, `git diff --check`, `php artisan down --retry=60`, `php artisan queue:restart`, backup privat, `php artisan migrate --force`, pemeriksaan skema/data terhadap backup, `php artisan config:clear`, `php artisan up`, dan `php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1`.

## File yang diubah pada koreksi nama fisik

- database/migrations/2026_10_20_000010_rename_work_order_source_columns.php (baru)
- app/Models/WorkOrder.php
- app/Services/ExcelWorkOrderMapper.php, ExcelWorkOrderImportService.php, BimaRowMapper.php, BimaOrderResolver.php, BimaSheetPullService.php
- app/Services/AreaAnalyticsService.php, BranchAnalyticsService.php, BranchOrderScope.php, MonitoringSheetPushService.php
- app/Http/Controllers/WorkOrderController.php, WorkOrderImportController.php, AreaDashboardController.php, BranchDashboardController.php
- app/Console/Commands/CheckGoogleSheets.php
- resources/views/imports/mapping.blade.php, sync/show.blade.php, work-orders/index.blade.php, work-orders/show.blade.php, area/dashboard.blade.php, branch/dashboard.blade.php
- tests/Feature/WorkOrderColumnNamesMigrationTest.php, WorkOrderSourceNamesTest.php (baru)
- tests/Feature/ExcelWorkOrderImportTest.php, ExcelSourceColumnsMigrationTest.php, SingleBranchMigrationTest.php, BranchProgressMigrationTest.php, BranchRegionMigrationTest.php, LocationsMigrationTest.php, MigrationRollbackTest.php, NamedKeysMigrationTest.php
- tests/Feature/AreaDashboardTest.php, BimaPullTest.php, BranchAnalyticsTest.php, BranchWorkflowTest.php, PsEligibilityTest.php
- tests/Unit/BimaRowMapperTest.php, ExcelWorkOrderMapperTest.php, WorkOrderXlsxReaderTest.php
- AGENTS.md, SKILLS.md, PRD_OLO_Command_Center_v2.md, README.md, docs/EXCEL_WORK_ORDER_IMPORT.md, docs/WORK_ORDER_COLUMN_NAMES.md

Template upload baru tetap menunggu file pengguna. Tidak ada keputusan nama kolom/BRANCH kedua yang masih tertunda. Tidak melakukan commit atau push.
