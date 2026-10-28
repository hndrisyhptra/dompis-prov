# Pemulihan tabel BOQ terhapus — 27 Oktober 2026

## Inspeksi dan checklist

- [x] Konfirmasi database tujuan dan metadata migrasi, tanpa rollback/destructive reset.
- [x] Ditemukan hilang: `qe_boqs`, `qe_boq_items`, `qe_boq_histories`; migration 000017/000020 masih berstatus Ran.
- [x] Master tetap ada: designator_types 5, designators 724, packages 2, designator_package_prices 1.448; WO 3.482, user 3, KML 1.
- [x] Buat migration aditif pemulihan hanya ketiga tabel hilang, tanpa seed/reset master.
- [x] Uji pemulihan/no-op/penolakan kehilangan sebagian dan alur BOQ/Revenue.
- [x] Eksekusi path migration pada database aplikasi, verifikasi digest tabel lain.
- [x] Catat batas pemulihan data dan tindak lanjut backup/file sumber.

Satu file BOQ masih tersimpan di storage privat. Tidak ditemukan dump SQL pada storage privat. Backup maintenance terenkripsi yang ditemukan bertanggal sebelum fitur BOQ (18–21 Oktober), sehingga tidak dianggap sebagai backup transaksi BOQ. Pemulihan struktur tidak berarti baris atau riwayat lama sudah kembali.

## File SQL dan keputusan pemilik

Ketiga file SQL pemilik dibaca lengkap sebagai data, bukan dieksekusi sebagai instruksi. Semuanya bertanda database `dompis_qe`, export 25 Oktober 2026. Isinya 4 header BOQ proyek QE, 83 item termasuk versi soft-deleted, dan 5 riwayat. Header masih memakai `id_boq`/`qe_lop_id`, pengguna `users.id_user`; Dompis Prov memakai `id_qe_boqs`/`id_work_orders` dan `users.id_users`. Dump tidak memiliki pasangan No WO yang memungkinkan pengaitan aman. Data tersebut tidak diimpor atau ditebak relasinya ke Work Order.

Setelah penjelasan, pemilik memilih **Tidak ada; cukup pulihkan tabel untuk upload ulang**. Karena itu target adalah ketiga tabel kosong sesuai kontrak aplikasi saat ini. File SQL asli, file BOQ privat dan KML tidak diubah. Tidak merekonstruksi riwayat/actor/tanggal atau menganggap nilai BOQ dari proyek lain sebagai Revenue Dompis Prov.

## Migrasi pemulihan

`2026_10_27_000022_restore_deleted_boq_tables.php`:

- Pada database normal dengan ketiga tabel masih ada, tidak melakukan perubahan.
- Bila hanya sebagian tabel hilang, berhenti sebelum DDL agar item/riwayat yang tersisa tidak kehilangan relasi atau tertimpa.
- Bila ketiganya hilang, memeriksa key master Work Order/users/packages/designators, lalu membuat header/item/history sesuai kontrak migration000017; dua total material/jasa dari000020 ditambahkan setelah tabel anak siap.
- Mempertahankan PK/FK, unique satu BOQ per WO, desimal volume/harga, file/content hash, revision, soft-delete item dan histori append-only. Tidak reseed atau mengubah master/harga.
- `down()` sengaja tidak menghapus tabel/data hasil pemulihan. Kepemilikan drop tabel tetap pada migration000017; jangan menjalankan rollback massal untuk memperbaiki tabel yang dihapus manual.

Migrasi dipilih secara spesifik, tanpa menghapus entri lama pada tabel migrations:

```sh
php artisan migrate --path=database/migrations/2026_10_27_000022_restore_deleted_boq_tables.php --force
```

MySQL DDL tidak sepenuhnya transaksional. Gangguan di tengah pembuatan tabel dapat menyisakan schema parsial; retry akan berhenti untuk rekonsiliasi, bukan menghapus tabel otomatis.

## Pengujian aktual

- SQLite terkait BOQ/migration/Revenue: **29 tes, 17.201 assertions lulus**.
- MariaDB terisolasi: **31 tes, 17.226 assertions lulus**; database tes sementara dihapus. Meliputi pemulihan, kesetaraan kolom/index/FK dengan schema normal, master/WO/user tidak berubah, import/preview/Revenue, no-op, down non-destruktif, penolakan schema parsial, serta rollback/reapply migration lama.
- Suite penuh: **348 dari 349 tes lulus, 29.959 assertions**. Satu tes `UsernameAndThemeTest::test_theme_icon_exists_only_once_in_authenticated_topbar` masih mengharapkan tulisan Dompis Prov, sementara halaman login yang sudah diubah di workspace memakai Wibsy. Halaman login dan ekspektasi branding tersebut tidak diubah pada pemulihan BOQ ini.
- Build Vite, Pint dan diff check lulus. Tidak ada perubahan JS/dependency/env dan tidak ada pengujian browser/Google/Telegram baru.

## Eksekusi dan backup

Helper lokal mengambil lock inbound, membatasi koneksi **hanya dompisprov**, mencadangkan data tabel bisnis yang masih ada, memvalidasi hasil dekripsi/digest backup, menjalankan hanya migration000022, lalu membandingkan jumlah dan SHA-256 seluruh baris tabel lama sebelum/sesudah. Penambahan satu audit `boq.schema_restored` dilakukan setelah pemeriksaan data lama.

Pemeriksaan backup awal sempat berhenti sebelum DDL: enumerasi schema awal terlalu luas lalu diperbaiki agar hanya dompisprov; pencadangan sekaligus melebihi memori512MiB lalu diganti chunk maksimal100 baris/sekitar1MiB per bagian. Tidak ada rollback/reset data aplikasi akibat kegagalan pemeriksaan tersebut.

Backup akhir memakai file privat `maintenance/before-boq-table-recovery-*.chunks.enc`, mode0600, berformat baris envelope Laravel Crypt; setiap baris didekripsi lalu gzip-decode menjadi record JSON. Record berisi metadata, chunk `table`/`rows` dan manifest akhir `complete=true` dengan jumlah/digest. APP_KEY yang sama diperlukan untuk membacanya. File ini mencadangkan data **yang masih ada sebelum pemulihan**, bukan transaksi BOQ yang sudah terhapus. Tidak menulis dump plaintext atau kredensial ke repository.

Eksekusi aktual pada **dompisprov** berhasil:

- Migration000022 tercatat; `qe_boqs=0`, `qe_boq_items=0`, `qe_boq_histories=0`, lengkap dengan kolom total material/jasa.
- **27 tabel bisnis lama** mempunyai jumlah/digest sebelum-sesudah identik sebelum penambahan audit pemulihan. Termasuk **3.482 Work Order**, **1.448 harga**, **3 user**, dan **1 KML**.
- Backup terverifikasi: `storage/app/private/maintenance/before-boq-table-recovery-20261027-164554-0e273403.chunks.enc`.
- Audit `boq.schema_restored` mencatat actor superadmin, migration, tabel dipulihkan, backup dan manifest; tidak mencatat transaksi BOQ palsu.
- File BOQ sebelumnya tetap tersimpan. Pengguna dapat membuka order yang sedang/pernah JT lalu Upload RAB/BOQ kembali; unggahan baru membuat transaksi/riwayat baru sesuai validasi normal, bukan memulihkan riwayat terhapus. Sesuai keputusan pemilik, tidak ada data lama yang direkonstruksi dan tidak ada input tambahan yang menghalangi upload ulang.

## Berkas tahap ini

- `database/migrations/2026_10_27_000022_restore_deleted_boq_tables.php` (baru).
- `tests/Feature/BoqRecoveryMigrationTest.php` (baru).
- Enam tes rollback lama ditambah satu langkah karena migration baru: `SingleBranchMigrationTest`, `BranchProgressMigrationTest`, `MigrationRollbackTest`, `BranchRegionMigrationTest`, `LocationsMigrationTest`, `ExcelSourceColumnsMigrationTest`.
- `docs/BOQ_TABLE_RECOVERY.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Tidak menimpa perubahan UI/branding pengguna atau progress branch. Tidak ada deploy/push, env atau migration lain yang dijalankan pada database aplikasi.
