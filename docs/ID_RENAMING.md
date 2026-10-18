# Penamaan ID tabel

Checklist implementasi (18 Oktober 2026):

- [x] Cadangkan data lokal secara terenkripsi dan hentikan sementara penulisan aplikasi/worker.
- [x] Tambahkan migrasi maju/mundur yang mempertahankan nilai ID dan foreign key.
- [x] Gunakan primary key `id_<nama_tabel>` untuk 10 tabel bisnis (termasuk users); pivot mendapat ID sendiri dan pasangan foreign key tetap unik.
- [x] Sesuaikan model, scope akses, controller, layanan sinkronisasi, view, dan pengujian.
- [x] Pertahankan seluruh tabel internal Laravel sesuai instruksi pengguna: migrations, sessions, jobs, job_batches, failed_jobs, cache, cache_locks, password_reset_tokens.
- [x] Uji migrasi dengan data, rollback, autentikasi database, cache/lock, antrean/retry/failed job dan seluruh regresi.
- [x] Jalankan migrasi pada database lokal, verifikasi jumlah data/constraint, lalu hidupkan aplikasi dan worker.

Migrasi tidak mengubah workbook sumber atau menghubungi Google/Telegram.


## Skema yang diterapkan

| Tabel bisnis | Primary key |
| --- | --- |
| users | id_users |
| branches | id_branches |
| user_branches | id_user_branches |
| branch_mappings | id_branch_mappings |
| work_orders | id_work_orders |
| sync_jobs | id_sync_jobs |
| sync_job_rows | id_sync_job_rows |
| audit_logs | id_audit_logs |
| wo_status_events | id_wo_status_events |
| wo_issues | id_wo_issues |

Foreign key menggunakan nama primary key tujuan: `id_users`, `id_branches`, `id_work_orders`, `id_sync_jobs`. Relasi aktor tetap bernama `actor()` pada model, sedangkan kolomnya menjadi `id_users`. Aturan DELETE RESTRICT/CASCADE tetap seperti sebelumnya. Pasangan `(id_users, id_branches)` pada `user_branches` tetap unik.

`audit_logs.entity_id` menjadi `id_entity`. Ini referensi audit yang ditafsirkan bersama `entity_type`, bukan foreign key SQL ke satu tabel. Metadata audit historis dan snapshot terenkripsi tidak ditulis ulang; pembaca pull menerima `branch_id` dari preview lama dan `id_branches` dari preview baru. Identitas eksternal Bima `external_order_id` dan `source_sid` tetap sesuai kontrak sumber.

Skema delapan tabel internal Laravel sama persis dengan sebelum migrasi. `sessions.user_id` tetap standar Laravel dan menyimpan nilai `users.id_users`; autentikasi menggunakan primary key model yang baru. Tabel `migrations` bertambah satu catatan migrasi sebagaimana normalnya.

## Penerapan dan verifikasi aktual

- Migrasi `2026_10_18_000004_name_table_primary_and_foreign_keys.php` berhasil diterapkan pada database lokal `dompisprov`, MariaDB 10.4.28, menggunakan `php artisan migrate --force` (sekitar 529 ms).
- Aplikasi masuk maintenance dan worker proyek dihentikan sebelum perubahan. Setelah verifikasi, `php artisan up` dan `php artisan queue:work --tries=5 --timeout=180 --sleep=1` mengaktifkan kembali aplikasi/worker. Tidak ada `migrate:fresh` pada database aplikasi.
- Cadangan sebelum migrasi: `storage/app/private/maintenance/before-id-rename-20261018-015009.json.enc`, permission 0600, terenkripsi dengan APP_KEY lokal. Simpan APP_KEY dengan aman untuk pemulihan; file ini tidak untuk source control.
- Seluruh baris pada 18 tabel dibandingkan dengan cadangan, dengan pemetaan nama kolom. Nilai data tetap sama, termasuk hash password, snapshot terenkripsi, metadata audit, sesi, dan failed job lama. Data bisnis yang sudah ada: 1 akun, 8 audit, 6 sync job, 27 detail baris. WO dan branch masih kosong pada saat migrasi.
- Primary key 10 tabel dan seluruh 11 foreign key diverifikasi langsung. Identitas/role/active superadmin, relasi aktor, detail job, dan dekripsi snapshot lolos.
- Sesi browser superadmin yang sudah ada tetap bisa membuka detail sinkronisasi #5 dan Branch & Mapping setelah reload; form mapping memakai `id_branches`. Tidak menambahkan data dummy ke database aplikasi.
- SQLite: **56 tests lulus, 356 assertions**, termasuk workbook referensi 3.298 baris, migrasi berisi data maju/mundur, unique/FK/cascade/restrict, mapping, JSON, preview lama, sesi database, queue release/retry, cache lock, failed job dan batch.
- MariaDB sementara terisolasi: **54 tests lulus, 345 assertions**. Dua uji snapshot ribuan baris dikecualikan setelah percobaan awal menemukan `max_allowed_packet=1048576` (1 MiB). Ketujuh tests migrasi/kompatibilitas juga dijalankan khusus pada MariaDB: **7 lulus, 122 assertions**. Semua database uji sementara dihapus setelah selesai.
- Pint, Vite production build dan Blade compilation lulus. PHPUnit memakai maintenance driver `array` agar maintenance lokal tidak mengganggu pengujian terisolasi.
- Tidak ada env baru atau perubahan credential. Perubahan ini tidak mengirim request ke Google/Telegram dan tidak menulis Google Sheets. Workbook sumber tidak diubah.

## Batas nyata dan input lanjutan

Server MariaDB lokal membatasi paket ke 1 MiB. Snapshot preview ribuan baris dapat melampaui batas ini dan memutus koneksi; dua uji besar gagal pada percobaan MariaDB awal, sedangkan SQLite lulus. Pengelola server perlu menaikkan `max_allowed_packet` (misalnya 64 MiB) dan membuka ulang koneksi sebelum pilot pull volume penuh. Pengaturan global tidak diubah karena server XAMPP juga dapat digunakan proyek lain. Ini terpisah dari migrasi ID, yang sudah selesai dan terverifikasi.

Rollback satu migrasi tersedia melalui `php artisan migrate:rollback --step=1 --force` saat maintenance, bersama pengembalian source aplikasi ke versi sebelum penamaan ID. Nilai ID dan baris bisnis tetap dipertahankan; ID tambahan pivot dihapus karena skema lama menggunakan primary key gabungan. Jangan rollback source/skema secara terpisah saat aplikasi menerima request. MySQL/MariaDB DDL tidak atomik, sehingga backup dan maintenance tetap diperlukan.

## File baru

- `database/migrations/2026_10_18_000004_name_table_primary_and_foreign_keys.php`
- `tests/Feature/NamedKeysMigrationTest.php`
- `tests/Feature/NamedKeysBehaviorTest.php`
- `docs/ID_RENAMING.md`

## File diubah

- Model: `app/Models/User.php`, `Branch.php`, `BranchMapping.php`, `WorkOrder.php`, `SyncJob.php`, `SyncJobRow.php`, `AuditLog.php`, `WoStatusEvent.php`, `WoIssue.php`.
- Controller: `app/Http/Controllers/AuthController.php`, `BranchMappingController.php`, `BranchProgressController.php`, `SyncController.php`.
- Layanan: `app/Services/BimaSheetPullService.php`, `BranchProgressService.php`, `MonitoringSheetPushService.php`.
- Akses/provisioning: `app/Policies/WorkOrderPolicy.php`, `app/Console/Commands/CreateOloUser.php`.
- View: `resources/views/admin/mappings.blade.php`, `resources/views/sync/index.blade.php`, `resources/views/sync/show.blade.php`, `resources/views/work-orders/issues.blade.php`.
- Tests: `tests/Feature/OloTestCase.php`, `AccessAndSyncTest.php`, `BimaPullTest.php`, `BranchOperationsTest.php`, `MonitoringPushTest.php`, `SourceTemplateTest.php`, `MigrationRollbackTest.php`; `phpunit.xml`.
- Dokumentasi: `README.md`, `docs/IMPLEMENTATION.md`.

Migrasi lama tidak ditulis ulang. Tidak ada repository `.git` pada workspace ini; tidak ada reset/checkout/commit atau penimpaan branch Bima.
