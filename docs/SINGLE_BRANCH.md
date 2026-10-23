# Satu BRANCH — 20 Oktober 2026

Checklist:
- [x] Mapping dan detail WO memakai satu BRANCH (`source_branch_name`), total 28 atribut.
- [x] File yang berisi dua header BRANCH tetap dapat dipilih salah satunya; saran memilih yang pertama. Kolom turunan BRANCH / DISTRICT tidak dipilih otomatis.
- [x] Migrasi baru 000009 mengarsipkan nilai kolom kedua, kemudian menghapus `source_district`.
- [x] Snapshot import v1 ditolak agar payload lama tidak menulis kolom yang telah dihapus; upload ulang sebelum konfirmasi.
- [x] Pengujian akhir dan migrasi database aktual.

Arsitektur tetap Laravel 13/Blade/Tailwind, menggunakan mapper allowlist, queue dan RBAC yang sudah ada. FK `id_branches` tetap menunjuk master cabang untuk otorisasi/penugasan; bukan kolom BRANCH kedua pada form. Migrasi tidak mengganti source_branch_name, id_branches, progress, kendala, revisi atau timestamp. Nilai source_district lama disalin ke source_bima_fields._legacy_source_district sebagai arsip tersembunyi; rollback mengembalikan kolom lama dari arsip tersebut. Benturan arsip menyebabkan migrasi berhenti, bukan overwrite diam-diam. Migration 000008 yang telah diterapkan tidak diedit.

Tidak ada environment atau dependensi baru. Template Excel asli tetap tidak diubah. Template upload baru tetap menunggu file pengguna; arti BRANCH kedua tidak lagi perlu dikonfirmasi.

File yang diubah pada koreksi ini:
- app/Services/ExcelWorkOrderMapper.php
- app/Services/ExcelWorkOrderImportService.php
- app/Http/Controllers/WorkOrderImportController.php
- resources/views/imports/mapping.blade.php
- resources/views/sync/show.blade.php
- database/migrations/2026_10_20_000009_consolidate_work_order_branch_column.php (baru)
- tests/Feature/SingleBranchMigrationTest.php (baru)
- tests/Feature/ExcelWorkOrderImportTest.php
- tests/Feature/ExcelSourceColumnsMigrationTest.php
- tests/Feature/BranchProgressMigrationTest.php
- tests/Feature/BranchRegionMigrationTest.php
- tests/Feature/LocationsMigrationTest.php
- tests/Feature/MigrationRollbackTest.php
- AGENTS.md, SKILLS.md, PRD_OLO_Command_Center_v2.md
- docs/EXCEL_WORK_ORDER_IMPORT.md, docs/SINGLE_BRANCH.md

Perintah verifikasi: `php artisan test --compact`, `php /private/tmp/olo-id-mysql-tests.php` (database sementara terpisah), `vendor/bin/pint --dirty --test`, `npm run build`, `php artisan view:cache`, `git diff --check`. MariaDB mengecualikan dua tes snapshot Sheets legacy karena batas packet 1 MiB, seperti pengujian sebelumnya; seluruh tes Excel dan migrasi konsolidasi tetap dijalankan.

Hasil akhir:
- 105 tes SQLite lulus, 1.293 assertions.
- 103 tes MariaDB lulus, 1.282 assertions (dua tes Sheets legacy dikecualikan seperti sebelumnya).
- Pint, build Vite, kompilasi Blade dan git diff --check lulus.
- Migrasi 2026_10_20_000009_consolidate_work_order_branch_column berhasil diterapkan pada database aplikasi.
- Backup privat terenkripsi: storage/app/private/maintenance/before-single-branch-20261020-020636.json.enc, izin 0600, diabaikan Git.
- Perbandingan sebelum/sesudah: 13 tabel bisnis/auth terjaga, semua 28 atribut tersedia dan source_district sudah tidak ada. Pada saat migrasi terdapat 0 WO; perilaku arsip nilai lama/rollback diuji menggunakan tiga WO pada database testing, tanpa memasukkan contoh ke database aplikasi.
- Aplikasi aktif kembali dan worker sudah dimuat ulang. Tidak ada commit/push atau panggilan Google/Telegram.
- Perubahan UI diverifikasi melalui feature test rendering mapping/detail: hanya satu target mapping BRANCH, kolom kedua tidak tampil. Tidak ada perubahan layout/CSS atau screenshot browser baru pada koreksi ini.
