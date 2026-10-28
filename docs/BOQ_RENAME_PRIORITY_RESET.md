# Nama tabel BOQ dan Reset Bookmark — 28 Oktober 2026

## Checklist

- [x] Inspect struktur BOQ, migrasi historis, referensi query/model serta scope/audit bookmark.
- [x] Migrasi rename tanpa mengganti ID/nilai/revisi/riwayat; update model, query dan tests.
- [x] Reset bookmark dengan modal konfirmasi, scope Area/lokal Branch dari server, audit dan perlindungan retry/stale.
- [x] Uji SQLite/MariaDB, rollback/migrasi, privasi, lintas halaman, build.
- [x] Jalankan migrasi rename pada database aplikasi dan dokumentasikan hasil aktual.

Nama baru: boq_jt, boq_items, boq_histories. PK/FK mengikuti nama spesifik: id_boq_jt, id_boq_items, id_boq_histories; nilai ID tetap. Migrasi historis tetap mempertahankan nama saat tahapnya berjalan.

Reset Bookmark berlaku untuk seluruh pilihan scope actor di semua halaman, bukan hanya filter/pagination yang terlihat. Area melepas pilihan Area saja. Branch melepas pilihan lokal tim pada penugasan aktif, tanpa melepas arahan Area atau pilihan Branch lain. Aksi tidak dilakukan otomatis pada data pengguna saat fitur dibuat.

## Kontrak tabel/model baru

| Lama | Baru | PK baru |
| --- | --- | --- |
| qe_boqs | boq_jt | id_boq_jt |
| qe_boq_items | boq_items | id_boq_items |
| qe_boq_histories | boq_histories | id_boq_histories |

FK kedua tabel anak menjadi `id_boq_jt`. Seluruh nilai ID tetap sama, sehingga `after_data.item_ids`/snapshot riwayat tetap menunjuk item yang sama. `id_work_orders`, relasi paket/designator/user, status, harga/volume, total, hash isi/file, revisi, soft-delete, timestamp dan file privat tidak diubah.

Model menjadi `BoqJt`, `BoqItem`, `BoqHistory`. WorkOrder::boq, importer/template/preview/download dan query Revenue menggunakan model/kolom baru. Tidak ada referensi `qe_boq` pada runtime `app/` atau `resources/`. Audit BOQ baru memakai entity_type `boq_jt`; audit historis tetap immutable dengan nama entity saat dibuat. Hash semantik import tetap sama, tanpa revisi palsu karena rename.

Migration aditif000023 menangani rename tabel, PK/FK dan nama index/constraint. Migrasi historis000017/000020/000022 tetap memakai kontrak lama agar fresh install/rollback dapat diputar menurut urutan yang benar. Tes migration historis membuka/menutup boundary000023 secara eksplisit; tes rollback lama ditambah satu langkah.

Prasyarat lama/baru diperiksa sebelum DDL; konflik dua tabel atau kolom ditolak tanpa menimpa data. MySQL/MariaDB DDL tidak sepenuhnya transaksional, sehingga langkah mengenali nama lama/baru saat retry dan membentuk kembali FK. Untuk kompatibilitas MariaDB lama yang tidak mendukung RENAME INDEX, key pengganti dibuat **sebelum** key lama dilepas; retry mengenali key pengganti yang identik. Unique WO tetap dijaga dan index tujuan berbeda tidak ditimpa. Down mengembalikan nama lama beserta relasinya tanpa menghapus isi.

```sh
php artisan migrate --path=database/migrations/2026_10_28_000023_rename_boq_tables.php --force
```

Tidak ada env/dependency baru. Pada deployment lain, kode dan migration harus dirilis bersamaan; jangan menjalankan importer dengan kode lama setelah nama tabel berubah. Gunakan backup dan maintenance singkat saat perubahan schema.

## Reset Bookmark

Di **List Order Prioritas** tersedia tombol **Reset Bookmark**, jumlah bookmark scope akun dan petunjuk bahwa reset tidak mengikuti filter. Tombol nonaktif bila tidak ada bookmark yang dapat dilepas. Modal menampilkan jumlah, cakupan Area/lokal Branch, **Ya, Lepas Semua** dan **Tidak**; pembatalan/Escape tidak mengirim request. Loading submit memakai mekanisme form yang sudah ada. Form confirmation menerima label khusus tanpa mengubah alur simpan/hapus lainnya.

Backend `DELETE /priority-orders/reset` memakai autentikasi aktif, Gate priority-orders, CSRF dan throttle yang sama dengan fitur prioritas. Tidak memberi izin baru kepada Koordinator Area, Admin Material atau Bot. `scope_key`/id Branch/user/order client ditolak. Semua keputusan scope dibentuk server, tidak menggunakan filter halaman atau selected_by sebagai batas daftar bersama.

- Area: melepas bookmark bersama Area, termasuk yang dibuat rekan Area dan seluruh halaman. Bookmark lokal Branch tidak dibaca dalam snapshot atau dihapus.
- Branch/TL: melepas bookmark lokal tim pada penugasan aktif, dengan key Branch yang konsisten dan assignment WO terkini. Arahan Area, Branch lain dan bookmark lokal lama untuk WO yang sudah berpindah tetap tersimpan.
- Snapshot hanya membaca identitas/key/token bookmark, tanpa payload sumber WO. Satu query tambahan pada List Prioritas, memakai cursor; tidak ada query per baris yang ditampilkan.
- Token terenkripsi terikat actor/role, jumlah/digest pilihan dan batas15menit. Perubahan daftar, penggantian token bookmark, assignment atau pencabutan akses setelah halaman dibuka meminta Perbarui; belum ada bookmark dilepas bila snapshot stale.
- Commit mengunci actor, WO urut ID, lalu bookmark dalam transaksi. Audit per bookmark dibatch100 dan event ringkasan `priority.reset` menyimpan batch/actor/cakupan/jumlah. Hanya ID snapshot terkunci yang dihapus, sehingga pilihan baru tidak ikut tersapu DELETE luas.
- Retry batch yang sudah berhasil mengembalikan hasil lama tanpa melepas pilihan yang ditambahkan kembali. Kegagalan audit membatalkan seluruh delete. Work Order, progress, kendala, perangkat, BIMA, BOQ, material dan riwayatnya tidak dimutasi.

Pengujian reset mencakup60bookmark lintas halaman dengan filter aktif, shared Area, multi-Branch/asing/pindah, nonaktif/role ditolak, token salah actor/kedaluwarsa/stale, konfirmasi wajib, replay dan rollback saat audit gagal. List Prioritas tetap8query pada5/30WO (sebelumnya7, bertambah satu snapshot); Work Order tetap7query. Ini pengukuran sintetis, bukan SLA server.

## Hasil aktual dan eksekusi

- Suite penuh SQLite: **355 tes, 354 lulus, 30.051 assertions**. Satu kegagalan yang sudah ada adalah `UsernameAndThemeTest::test_theme_icon_exists_only_once_in_authenticated_topbar`: ekspektasi tulisan Dompis Prov belum mengikuti branding login Wibsy yang diubah pengguna. Login/branding tidak diubah pada tahap ini.
- Sesudah penyesuaian terakhir kompatibilitas index, tes SQLite migration terkait: **8 tes /16.696 assertions lulus**.
- MariaDB terisolasi regresi akhir: **53 tes /17.662 assertions lulus** (BOQ, kalkulasi/Revenue, migration/recovery/rename, prioritas/bulk/reset dan query budget). Database tes sementara dihapus. Tes rename menyertakan data/revisi/history dan simulasi retry saat key lama serta key pengganti masih ada bersamaan.
- JavaScript: **29 tes lulus**, termasuk label scope/Ya/Lepas Semua, pembatalan dan pengiriman satu kali pada reset.
- Build Vite, Pint, `git diff --check` dan cache config/events/routes/views terisolasi: lulus. Cache konfigurasi lokal tidak diubah. Belum ada verifikasi visual browser baru karena izin sebelumnya ditolak; tidak ada klaim E2E layanan eksternal.

Migration000023 **sudah diterapkan pada dompisprov** memakai helper lokal ber-lock inbound dan maintenance singkat. Backup terenkripsi per chunk dibuat/dekripsi-diverifikasi sebelum DDL. **30 tabel bisnis** cocok jumlah/digest sebelum-sesudah setelah normalisasi nama tabel/key BOQ saja; nilai/urutan ID tetap. Termasuk **3.514 WO**, **1.448 harga**, **1 KML**, serta bookmark yang sudah ada. Sebelum dan sesudah rename, ketiga tabel BOQ memang kosong sesuai pemulihan sebelumnya (`boq_jt=0`, `boq_items=0`, `boq_histories=0`); rename tidak merekonstruksi data BOQ yang pernah terhapus.

Backup privat: `storage/app/private/maintenance/before-boq-table-rename-20261028-111629-e4e02a3c.chunks.enc`, mode0600, format yang sama dengan [backup pemulihan](BOQ_TABLE_RECOVERY.md). Audit `boq.tables_renamed` mencatat migration, actor, backup dan manifest. Maintenance sudah dilepas. **Tidak menjalankan Reset Bookmark pada data aktual**; pengguna menekan tombol dan mengonfirmasi sendiri saat ingin mengosongkan prioritas scope-nya.

Perintah pemeriksaan/eksekusi:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-boq-rename-reset-mysql.php
node --test tests/JavaScript/*.test.js
npm run build
vendor/bin/pint --dirty --test
git diff --check
php -d memory_limit=512M /private/tmp/olo-apply-boq-rename.php
```

`php artisan optimize` diuji dengan APP_CONFIG_CACHE/APP_ROUTES_CACHE/APP_EVENTS_CACHE/VIEW_COMPILED_PATH ke direktori sementara lalu dibersihkan. Dua helper `/private/tmp` adalah alat lokal pengujian/backup/aplikasi migration, bukan kebutuhan deployment; environment lain memakai migration000023 melalui proses rilis normal. Tidak ada env/dependency baru, tidak ada input bisnis tambahan yang diperlukan. Ekspektasi branding login lama tetap menjadi catatan tes terpisah.

## Berkas diubah

- `database/migrations/2026_10_28_000023_rename_boq_tables.php` (baru).
- `app/Models/BoqJt.php`, `BoqItem.php`, `BoqHistory.php` menggantikan tiga model `QeBoq*`; `app/Models/WorkOrder.php`.
- `app/Services/Boq/BoqImportService.php`, `app/Services/RevenueOverviewService.php`.
- `app/Services/PriorityResetService.php` (baru), `app/Http/Controllers/PriorityOrderController.php`, `routes/web.php`.
- `resources/views/priorities/reset.blade.php` (baru), `resources/views/priorities/index.blade.php`, `resources/js/form-confirmation.js`.
- Tests baru: `BoqRenameMigrationTest`, `PriorityResetTest`.
- Tests terkait: `BoqImportTest`, `BoqCalculationTest`, `DompisBoqTemplateTest`, `BoqCatalogMigrationTest`, `BoqValueMigrationTest`, `BoqRecoveryMigrationTest`, `PerformanceBudgetTest`, enam tes rollback lokasi/Branch/sumber WO, `tests/JavaScript/form-confirmation.test.js`.
- Dokumen ini, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md` (addendum).

Perubahan pengguna pada login/branding, UI dan kode sebelumnya dipertahankan. Tidak ada commit/push/deploy atau integrasi eksternal yang dilakukan.

Pembaruan lanjutan28Oktober: Reset Bookmark kini di sebelah Download Excel pada toolbar tabel. List Prioritas menggunakan ulang base query dan total agregasi sehingga8→7query; snapshot reset tanpa hidrasi model. Hasil/manifest terbaru: [Toolbar dan optimasi prioritas](PRIORITY_TOOLBAR_OPTIMIZATION.md).
