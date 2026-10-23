# Penyederhanaan Work Order — 20 Oktober 2026

Checklist:
- [x] Label navigasi cabang menjadi Work Order.
- [x] Hilangkan card kendala terpisah; detail kendala, tindak lanjut dan penyelesaian tetap dapat dilakukan dalam update progress.
- [x] Form perangkat dibuka melalui tombol, otomatis terbuka kembali saat validasi gagal.
- [x] Detail sumber order accordion; log BIMA menyimpan semua perubahan inbound dengan tanggal/waktu WIB dan pagination.
- [x] Tests, build, pemeriksaan UI light/dark/mobile, backup dan migrasi actual.

Arsitektur: Laravel 13/Blade/JS ringan dengan token CSS; update progress memakai service transaction/revision/audit, import XLSX dan legacy Sheets memakai WorkOrderSourceWriter. Log lama hanya task/status. Perubahan ini menyimpan diff atribut inbound secara terenkripsi pada log yang sama; log lama tetap terbaca dan tidak direkayasa menjadi riwayat yang tidak tersedia. Perubahan source identik tetap skip. Progress/perangkat cabang tercatat pada audit/riwayat masing-masing, tidak dicampur sebagai perubahan sumber BIMA.

Card kendala dihilangkan tanpa menghapus data kendala. Form progress akan menampilkan kendala aktif; KENDALA wajib memiliki detail, catatan berikutnya menjadi tindak lanjut. Penyelesaian kendala melalui checkbox konfirmasi dalam form progress; CLOSE tetap memerlukan kendala aktif selesai. Ini menjaga alur order lama yang sudah memiliki kendala agar tetap dapat diselesaikan.


## Perilaku

Navigasi, judul daftar dan tautan kembali role cabang memakai Work Order; scope cabang tidak berubah. Card kendala terpisah dihilangkan, tetapi tabel/endpoint kendala lama tetap dipertahankan. Dropdown KENDALA dan catatan wajib otomatis membuat kendala pertama atau menambah tindak lanjut ke kendala aktif terbaru. Submit ulang catatan yang sama tidak menambah riwayat/issue. Accordion kecil di dalam progress mempertahankan akses ke detail kendala lama/selesai. Checkbox “Seluruh kendala aktif sudah selesai” menyelesaikan seluruh issue OPEN dengan catatan progress sebagai hasil penyelesaian; semua dilakukan atomik bersama progress, audit dan satu kenaikan revision. Permintaan basi ditolak 409. Checkbox tidak boleh dikirim untuk progress KENDALA, dan menutup order tanpa menyelesaikan kendala tetap ditolak.

Data perangkat dan sumber order menggunakan disclosure HTML native: tombol dapat dioperasikan dengan mouse/keyboard dan tetap bekerja tanpa JavaScript. Form perangkat tertutup pada awal, otomatis terbuka pada error validasi atribut perangkat, dan mempertahankan old input. Permission edit tidak berubah. Penyesuaian label Detail kendala/Catatan progress mengikuti dropdown; JS memberi petunjuk dan menampilkan konfirmasi penyelesaian hanya bila relevan.

Migrasi 000013 menambah nullable LONGTEXT field_changes ke bima_task_logs. Model memakai encrypted:array dan menyembunyikannya dari serialisasi umum. Setiap perubahan atribut inbound yang dipetakan mencatat before/after, termasuk perubahan referensi BIMA pada perangkat yang dilindungi override lokal. Kolom turunan waktu (bulan/tahun/umur/kategori) tidak menghasilkan log tersendiri; metadata/hash/tab bukan perubahan task. Sumber yang tidak dipetakan tetap diabaikan sesuai kontrak upload. Satu log per WO/job dengan transaksi row-lock tetap berlaku. Pertama kali order diimpor menjadi kondisi awal, bukan perubahan; tidak dibuat log palsu. Snapshot perubahan pada data pelanggan hanya ditampilkan melalui detail WO yang diotorisasi.

Log menampilkan WIB sampai detik dengan elemen time, actor, status sebelum-sesudah dan seluruh field diff. Seluruh riwayat dapat dibuka lewat pagination 20 log/halaman. Log lama yang belum mempunyai field_changes tetap menampilkan transisi task/status dengan tanggal aslinya. Tidak mengarang perubahan atribut pada periode sebelum pencatatan diff tersedia.

Environment/paket baru: tidak ada. Deployment memakai backup lalu `php artisan migrate --force`, `php artisan queue:restart`, `php artisan view:cache`, dan `npm run build`. Rollback 000013 hanya menghapus detail diff baru; log task/status lama tetap ada. Backup diperlukan jika detail diff harus dipulihkan persis.

## File berubah

- resources/views/layouts/app.blade.php; resources/views/work-orders/index.blade.php; resources/views/branch/material.blade.php (label)
- resources/views/work-orders/show.blade.php; progress.blade.php; devices.blade.php; bima-task-log.blade.php
- resources/js/app.js; resources/css/app.css
- app/Http/Controllers/BranchProgressController.php; app/Services/BranchProgressService.php
- app/Services/WorkOrderSourceWriter.php; app/Models/BimaTaskLog.php
- database/migrations/2026_10_20_000013_add_bima_log_field_changes.php (baru)
- tests/Feature/WorkOrderDetailFlowTest.php; BimaLogChangesMigrationTest.php (baru)
- tests/Feature/BimaWorkOrdersTemplateTest.php (diff dari XLSX)
- tests/Feature/BranchProgressMigrationTest.php; BranchRegionMigrationTest.php; LocationsMigrationTest.php; ExcelSourceColumnsMigrationTest.php; SingleBranchMigrationTest.php; MigrationRollbackTest.php; BimaStatusMigrationTest.php; WorkOrderColumnNamesMigrationTest.php (regression migrasi)
- AGENTS.md; SKILLS.md; PRD_OLO_Command_Center_v2.md; dokumen ini

Tidak ada perubahan sumber tanggal PS; konfirmasi kolom TGL PS pada preset Work Orders tetap diperlukan pada tahap terkait. Google/Telegram tidak diuji E2E pada perubahan UI/log ini. Tidak ada commit/push.


## Hasil aktual

- SQLite: **130 tes / 2.381 assertions lulus**. MariaDB sementara: **128 tes / 2.370 assertions lulus**. Dua tes snapshot Sheets legacy besar dikecualikan hanya di MariaDB karena packet lokal 1 MiB. Termasuk validasi detail KENDALA, tindak lanjut, penyelesaian eksplisit, stale revision/outsider ditolak, validasi perangkat membuka kembali form, seluruh 22 perubahan task pada dua halaman, diff XLSX/Sheets, enkripsi source PII, skip/retry tanpa duplikasi, dan preservasi log lama lewat migration/rollback.
- Pint, git diff --check, build Vite dan kompilasi Blade lulus. Setelah perapian alignment tombol accordion, build diulang dan lulus.
- UI aktual diperiksa read-only pada port 8011: desktop 1280, tablet 768, mobile 375 px, light/dark. Tombol perangkat membuka enam input, accordion sumber membuka/menutup, memilih KENDALA mengganti label/placeholder Detail kendala; tidak ada overflow halaman. Preferensi kembali light, viewport override di-reset, pilihan kategori percobaan dikembalikan tanpa menyimpan. Bukti tanpa data pelanggan: /private/tmp/olo-work-order-flow.png.
- **Migrasi 000013 sudah dijalankan ke MariaDB aplikasi.** Backup terenkripsi sebelum perubahan: storage/app/private/maintenance/before-work-order-detail-flow-20261020-083000.json.enc (0600, membutuhkan APP_KEY asli). Verifikasi **19 tabel** terhadap backup lulus: 13 WO, 13 log task lama, 3 riwayat progress, 1 kendala, 1 catatan kendala dan 178 master mapping dipertahankan. Kolom diff baru pada log lama NULL; tidak mengarang rekonstruksi before/after yang belum direkam.
- Aplikasi sudah aktif kembali dan worker import dijalankan ulang. Tidak ada kebutuhan environment baru atau input yang memblokir perubahan UI ini. Mapping sumber TGL PS tetap menjadi keputusan tahap terpisah, seperti sebelumnya.
