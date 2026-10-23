# Referensi mapping order — 20 Oktober 2026

Checklist:
- [x] Baca referensi secara read-only, validasi header, kategori dan duplikasi; ekstrak data aturan dengan hash sumber.
- [x] Perluas kategori master dengan OPEN; resolver persis dan dukungan kunci task kosong dari referensi.
- [x] Terapkan referensi dan backfill idempotent dengan lock inbound/transaksi/audit, tanpa perubahan progress maupun hash.
- [x] Unit/feature/regression tests SQLite dan MariaDB, backup lalu eksekusi pada database aplikasi.

Arsitektur saat ini: Laravel 13, MySQL, model WorkOrder menyusun status_order saat simpan; master mapping_order sudah ada (000011), enum awal STUCK/PS/CANCLWORK, FK id_mapping_order. Perubahan ini memperluas enum tanpa mengganti tabel/ID. Aturan berasal dari Sheet1 A1:B179 pada MAPPING-ORDER.xlsx; 178 kunci unik: OPEN 106, CANCLWORK 28, STUCK 30, PS 14. Sumber asli tidak diubah. SHA256 14e1b9732d05d8b90f6ec2c87f8c2eb9ab166b6d39e915fe2c9d140f6c116de1.

Kunci -COMPWORK dan -STARTWORK menyatakan task kosong. Status tampilan yang sebelumnya hanya COMPWORK/STARTWORK tetap dipertahankan; resolver secara eksplisit juga mencoba kunci berawalan '-' jika task kosong. Tidak ada aturan fallback berdasarkan akhiran. Contoh Pickup NTE from SCM Manual-CANCLWORK dan Activate Cross Connect-CANCLWORK tetap STUCK mengikuti referensi. Kategori mapping PS tidak mengganti KPI progress CLOSE + status_bima CLOSE/COMPLETE. TGL PS tetap memerlukan mapping sumber yang dikonfirmasi.


## Implementasi dan penggunaan

Data referensi versi ini dibawa bersama repository sebagai JSON, tanpa menyalin data pelanggan. Perintah `olo:apply-order-mapping` membaca versi tersebut, memvalidasi seluruh aturan sebelum penulisan, kemudian mengambil lock `olo:work-order-inbound`. Jika import berjalan, penerapan mapping ditolak dan dapat dicoba lagi setelah selesai. Upsert aturan dan backfill seluruh WO BIMA berada pada satu transaksi; jika gagal semuanya rollback. Audit mencatat hash sumber, actor jika disediakan, statistik, dan nilai sebelum/sesudah aturan yang berubah.

Master yang sudah ada mempertahankan ID. Aturan pada file diaktifkan sesuai referensi; aturan lain tidak dihapus. Penerapan ulang pada kondisi sama tidak menambah aturan/WO/log task atau mengubah timestamp WO; tetap ada audit penerapan. Backfill hanya menulis `id_mapping_order` dan `mapping_order` yang berbeda. Hash sumber, status mentah/gabungan, tanggal, assignment, progress, issues, device overrides dan revision tetap. Status tidak dikenal atau status BIMA kosong tetap NULL; tidak diisi OPEN secara default. Resolver pada penyimpanan sumber memakai aturan yang sama untuk upload berikutnya. Update progress/perangkat tidak mengembalikan mapping basi dari form yang sudah terbuka.

Migrasi `2026_10_20_000012_allow_open_order_mapping.php` hanya memperluas enum master dengan OPEN. Tidak membuat ulang identitas bisnis atau mengubah tabel internal Laravel. Rollback ditolak selama master masih mengandung OPEN agar kategori tidak hilang atau dikonversi diam-diam. Backup diperlukan untuk pemulihan persis; rekonsiliasi OPEN harus diselesaikan sebelum menurunkan skema.

Environment dan paket baru: **tidak ada**. Perintah deployment:

```sh
php artisan down --retry=60
# Backup database sebelum migrasi/penerapan referensi.
php artisan migrate --force
php artisan olo:apply-order-mapping --actor=superadmin
php artisan queue:restart
php artisan view:cache
php artisan up
```

Gunakan username Area yang aktif dan memiliki izin sync untuk actor audit. Tanpa --actor, perintah CLI merupakan penerapan sistem dan audit id_users NULL. Perintah ini hanya tersedia di terminal server, tidak menambah endpoint web yang bisa diakses branch.

## File berubah

- database/data/order_mapping_20261020.json (baru, hasil ekstraksi 178 aturan)
- database/migrations/2026_10_20_000012_allow_open_order_mapping.php (baru)
- app/Services/WorkOrderMappingResolver.php; OrderMappingReferenceService.php (baru)
- app/Console/Commands/ApplyOrderMappingReference.php (baru)
- app/Models/WorkOrder.php (resolver dan perlindungan refresh concurrent)
- resources/views/work-orders/show.blade.php (keterangan mapping terkini)
- tests/Feature/OrderMappingReferenceTest.php; OrderMappingMigrationTest.php (baru)
- tests/Feature/BimaStatusDevicesTest.php (alur XLSX dengan referensi aktual)
- tests/Feature/BranchProgressMigrationTest.php; BranchRegionMigrationTest.php; LocationsMigrationTest.php; ExcelSourceColumnsMigrationTest.php; SingleBranchMigrationTest.php; MigrationRollbackTest.php (langkah rollback regression bertambah satu)
- AGENTS.md; SKILLS.md; PRD_OLO_Command_Center_v2.md; README.md; docs/BIMA_STATUS_AND_DEVICES.md; dokumen ini

Keputusan yang masih diperlukan hanya untuk data sumber yang belum tersedia: kolom TGL PS pada preset Work Orders. File referensi klasifikasi tidak menyediakan tanggal PS dan tidak dipakai untuk menebak tanggal tersebut. Integrasi Google/Telegram tidak diuji E2E pada perubahan ini.


## Hasil aktual

- SQLite: **125 tes lulus / 2.306 assertions**. MariaDB terpisah: **123 tes lulus / 2.295 assertions**. Dua tes snapshot Sheets legacy berukuran besar dikecualikan hanya pada MariaDB karena max_allowed_packet 1 MiB. Semua tes import XLSX dengan mapping aktual lulus, termasuk OPEN→PS, pengecualian CANCLWORK→STUCK, unknown→NULL, task kosong, seluruh 178 kunci, idempotency, perlindungan data dan rollback enum yang aman.
- Pint, git diff --check, build Vite, kompilasi Blade dan syntax PHP lulus.
- Migrasi **000012 sudah dijalankan** dan referensi diterapkan ke MariaDB aplikasi: **178 inserted**, 0 updated; master OPEN 106 / STUCK 30 / PS 14 / CANCLWORK 28. Backup terenkripsi: storage/app/private/maintenance/before-order-mapping-reference-20261020-072335.json.enc (0600, memerlukan APP_KEY asli). Verifikasi 17 tabel terhadap backup lulus, selain perubahan klasifikasi yang memang diizinkan. Identitas master lama, seluruh audit lama dan data WO dipertahankan.
- Kondisi data aplikasi: **13 WO belum memiliki status_bima** karena berasal dari upload sebelum kolom K diaktifkan. Hasil backfill: **0 matched, 13 unmapped**, 0 WO diubah. Tidak menebak status mentah dari task atau mapping. Untuk melengkapi: upload ulang export BIMA melalui Sync Center, periksa bahwa K Status→STATUS BIMA, buat preview, lalu konfirmasi import. Sesudah status mentah tersedia, kombinasi yang cocok dengan referensi otomatis terklasifikasi. Tidak membuat WO duplikat karena identitas dan hash tetap berlaku.
- File asli MAPPING-ORDER.xlsx tetap berhash 14e1b9732d05d8b90f6ec2c87f8c2eb9ab166b6d39e915fe2c9d140f6c116de1 setelah proses. EXPORT-BIMA.xlsx dan LHPP OLO.xlsx tidak disentuh.
- Aplikasi diaktifkan kembali dan worker import dimuat ulang. Tidak ada commit/push, environment/paket baru, atau klaim E2E Google/Telegram.
