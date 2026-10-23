# Owner Group dan penghapusan widget PS terkonfirmasi — 21 Oktober 2026

Discovery: Laravel 13/Blade, MySQL, import XLSX melalui mapping→preview→commit dengan snapshot/hash per WO. Source writer sudah membatasi atribut sumber, menjaga progress/perangkat lokal dan mencatat diff terenkripsi. Tambahan ini memakai alur yang sama; tidak mengganti workbook asli.

Checklist:
- [x] Migration nullable owner_grup dan preset J Owner Group.
- [x] Mapping manual/otomatis, hash skip/update dan log perubahan.
- [x] Hapus kartu/tab PS terkonfirmasi dan tampilkan Owner Group pada detail.
- [x] Tes penuh SQLite/MariaDB, migration aktual dan verifikasi integritas.
- [x] Build, lint dan pemeriksaan UI light/dark/mobile.

## Perilaku

- Tabel fisik **work_orders**, kolom **owner_grup VARCHAR(255) NULL**. Migration 000014 menambah kolom saja, tanpa backfill atau perubahan nilai/timestamp lama. Tabel internal Laravel tetap.
- Preset BIMA Work Orders v3 memilih **13 kolom**, tambahan **J (Owner Group) → owner_grup**. Seluruh header/posisi preset wajib cocok. Generic mapping juga mengenali header OWNER GROUP; operator dapat memilih kolom berbeda melalui form mapping.
- Owner Group ikut snapshot/hash sumber. Import ulang dengan data identik → unchanged; perubahan J → updated; perubahan dicatat dalam Log Task BIMA dengan nilai sebelum/sesudah, pengguna dan waktu. Identitas tetap source_system + no_tq_sc_id.
- Mapping yang tidak memilih owner_grup mempertahankan nilai lama. Jika J dipilih dan nilainya kosong, nilai menjadi NULL. Nilai >255 karakter, error Excel atau formula ditolak oleh mapper. Field sumber ini tidak dapat diedit melalui form progress/perangkat cabang.
- Metadata preset baru memakai bima-work-orders-v3. Kontrak job import tetap kompatibel karena field baru opsional; job/preview lama yang sudah dikonfirmasi tidak ditambahkan mapping J secara diam-diam. Upload ulang dan konfirmasi mapping untuk mengisi Owner Group pada order lama.
- Owner Group tampil pada ringkasan detail dan accordion Detail sumber order. Nilai belum diimpor tampil eksplisit. Pengguna tetap hanya dapat membuka order pada scope yang diizinkan.
- Kartu Area dan tab list **PS terkonfirmasi** dihapus; indikator Konfirmasi PS pada detail diganti Owner Group. Copy label di dashboard/form disesuaikan. **PS hari ini, grafik harian, dan rekap Completed yang sudah ada tetap memakai aturan sebelumnya** (progress CLOSE + status_bima CLOSE/COMPLETE, tanggal tgl_ps untuk harian). Tidak menghapus history atau status order dan tidak mengubah aturan New Order/Potensi PS.

## File berubah

- database/migrations/2026_10_21_000014_add_owner_grup_to_work_orders.php (baru).
- app/Services/BimaWorkOrdersTemplate.php; app/Services/ExcelWorkOrderMapper.php.
- resources/views/imports/mapping.blade.php; resources/views/area/dashboard.blade.php; resources/views/branch/dashboard.blade.php.
- resources/views/work-orders/index.blade.php; resources/views/work-orders/show.blade.php; resources/views/work-orders/progress.blade.php; resources/css/app.css.
- tests/Feature/BimaWorkOrdersTemplateTest.php; tests/Unit/ExcelWorkOrderMapperTest.php.
- tests/Feature/OwnerGrupMigrationTest.php; tests/Feature/OwnerGrupUiTest.php (baru).
- tests/Feature/{SingleBranchMigrationTest,ExcelSourceColumnsMigrationTest,LocationsMigrationTest,BranchRegionMigrationTest,MigrationRollbackTest,BranchProgressMigrationTest}.php — sesuaikan jumlah langkah rollback karena satu migration baru.
- AGENTS.md; docs/BIMA_WORK_ORDERS_TEMPLATE.md; dokumen ini.

Environment/dependensi baru: tidak ada. Worker queue perlu dimuat ulang setelah perubahan parser. Tidak melakukan commit/push atau klaim integrasi Google/Telegram E2E.

## Hasil

- `php artisan test --compact --filter='BimaWorkOrdersTemplateTest|OwnerGrupMigrationTest|OwnerGrupUiTest|ExcelWorkOrderMapperTest'`: **9 tes / 307 assertions lulus**.
- `php artisan test --compact`: **144 tes / 2.857 assertions lulus**, SQLite.
- `php /private/tmp/olo-id-mysql-tests.php`: **142 tes / 2.846 assertions lulus**, MariaDB sementara terpisah. Database tes dibersihkan setelah selesai. Dua tes snapshot Sheets legacy besar (`historical_size_batch`, `provided_bima_imports`) dikecualikan pada MariaDB karena max_allowed_packet lokal 1 MiB, sama seperti baseline. Semua tes XLSX/Owner Group/migration lulus pada kedua database.
- Tes mencakup import J, identik→skip/timestamp tetap, perubahan J→update/hash baru/log before-after, progress/revision/perangkat tetap, mapping J tidak dipilih→nilai lama tetap, sel J kosong→NULL, header J salah→preset tidak dikenal, validasi panjang/formula, migration populated/reversible dan UI/scope cabang.
- `vendor/bin/pint --dirty`, `vendor/bin/pint --dirty --test`, `git diff --check`, `npm run build`, `php artisan view:cache`: lulus. Build dan lint diulang setelah penyesuaian grid: Area lima kartu satu baris, Branch enam kartu tiga kolom, tablet/mobile dua kolom.
- Migration aktual 000014 **DONE**, dijalankan setelah memperoleh lock inbound, memastikan tidak ada job inbound aktif, dan membuat backup terenkripsi yang diverifikasi. **16 tabel bisnis tidak berubah**; seluruh **13 WO lama memiliki owner_grup NULL**. Tidak melakukan import nyata atau backfill nilai yang belum tersedia.
- Backup: `storage/app/private/maintenance/before-owner-grup-20261021-074631.json.enc`, permission 0600; pemulihan membutuhkan APP_KEY yang sama. Skrip pemeliharaan `/private/tmp/olo-owner-migrate.php` menjalankan `php artisan migrate --path=database/migrations/2026_10_21_000014_add_owner_grup_to_work_orders.php --force` melalui Artisan, lalu membandingkan semua nilai lama termasuk hash/timestamp/relasi.
- `php artisan queue:restart` berhasil; worker lama berhenti dengan restart signal dan worker baru berjalan memakai `php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1`.
- Browser port 8011 diperiksa pada 1280/768/375 px, light/dark. Dashboard Area dan Branch/list tidak menampilkan PS terkonfirmasi; Owner Group pada detail menampilkan Belum diimpor untuk data lama. Tidak ada overflow halaman. Scope Admin/HD/TL diperiksa pada tests. Browser hanya membaca, tidak mengubah WO. Preferensi kembali light dan viewport di-reset.
- Bukti tanpa data pelanggan: `/private/tmp/olo-owner-dashboard.png`, `/private/tmp/olo-owner-detail.png`.

Langkah pengguna: upload ulang export BIMA, periksa mapping **OWNER GROUP → J · Owner Group**, preview, lalu konfirmasi import. Nilai order lama terisi melalui proses ini. Tidak ada environment/dependensi baru atau input bisnis yang memblokir; belum ada tes API Google/Telegram E2E pada perubahan ini. Tidak melakukan commit/push.
