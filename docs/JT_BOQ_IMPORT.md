# RAB/BOQ untuk JT — 25 Oktober 2026

Checklist implementasi: inspeksi SQL dan alur JT → katalog/migration BOQ yang terkait WO → importer/validasi/snapshot harga → JT opsional/skip/upload belakangan/preview → pengujian dan migration aplikasi.

Laravel 13.37 / PHP 8.5, Blade, WorkOrderXlsxReader yang sudah ada, storage private, transaksi/row lock/revision/policy/audit. Struktur katalog menggunakan nama kolom/PK referensi secara eksplisit; users memakai PK lokal id_users. BOQ proyek QE lama tidak diimpor karena qe_lop_id tidak memiliki padanan WO yang disetujui. Tidak mengubah file SQL asli.

- [x] Baca struktur keenam referensi dan kontrak aplikasi.
- [x] Buat katalog dan BOQ/migration.
- [x] Import dengan aritmetika desimal dan snapshot harga.
- [x] Alur JT opsional, skip, upload belakangan dan preview BOQ.
- [x] Tes, build dan migration aditif.

Referensi designator_types diterima lengkap: MATERIAL, JASA, INSTALASI, PENGUKURAN, DISMANTLE. Sesuai arahan terbaru pemilik, designator_categories dan designator_category_id tidak digunakan; FK tipe terhubung ke id_designator_type. Actor created_by/updated_by asal berada di database berbeda: salinan katalog mempertahankan nilai mentah pada JSON referensi, sedangkan FK actor lokal diisi null untuk menghindari atribusi palsu. Tidak membuat atau mengubah tabel internal Laravel.


## Alur yang tersedia

1. Pada halaman Update Progress, pilih JT dan isi catatan.
2. Pilih **Simpan JT & Upload RAB/BOQ** atau **Simpan JT · Skip dulu**. Progress disimpan lebih dahulu; kegagalan upload tidak menghapus progress JT.
3. Halaman upload juga memiliki **Skip dulu**. Belakangan, **Upload RAB/BOQ** tersedia di Detail Order untuk order yang sedang/pernah JT (kategori lama/baru pada event adalah bukti).
4. Pilih Paket 5 atau Paket 10 dari katalog, lalu unggah XLSX/CSV. Template CSV dapat diunduh pada halaman tersebut.
5. Kolom wajib **DESIGNATOR, QTY**, kolom **TYPE** opsional mengikuti tipe master aktif. Alias KODE DESIGNATOR/DESIGNATOR CODE, VOLUME, TIPE didukung. Header XLSX dapat dipilih pada baris 1–50 dan sheet dapat ditentukan. Contoh file RAB pemilik belum diberikan; susunan dokumen lain belum diklaim kompatibel.
6. Semua item divalidasi sebelum menyimpan. Setelah berhasil, halaman **Preview BOQ** menampilkan rincian, nilai, paket, versi, riwayat dan unduhan sumber. Detail Order juga menyediakan tombol Preview BOQ dan Upload revisi BOQ.

Batas: 10 MiB aplikasi (mengikuti batas efektif PHP di UI), maksimal 2.000 item, qty positif maksimal 12 digit bulat + 3 desimal dengan titik; tidak memakai pemisah ribuan pada input. Kode harus dikenal/tidak diarsipkan, tipe harus aktif dan harga harus tersedia pada paket. Duplikat designator, formula/error, qty tidak valid, tipe tidak cocok dan overflow nilai ditolak seluruh file dengan nomor baris. Harga nol eksplisit pada master tetap valid; harga yang tidak ada tidak dianggap nol.

## Struktur/data

Migration `2026_10_25_000017_create_work_order_boq_catalog.php` menambahkan:

- `designator_types`, PK `id_designator_type`: 5 tipe dari SQL pemilik, kolom/ID asal dipertahankan.
- `designators`, PK `id_designator`: 724 item. Kolom kategori dihilangkan sesuai koreksi pemilik; tipe memakai FK ke master tipe.
- `packages`, PK `id_package`: 2 paket; `designator_package_prices`, PK `id_designator_package_price`: 1.030 pasangan harga. Presisi decimal dan unique key asal dipertahankan.
- `qe_boqs`, PK `id_qe_boqs`, FK unik `id_work_orders` menggantikan `qe_lop_id`. Satu BOQ aktif per WO, paket, status ready/source import, item_count, grand_total decimal(18,2), revision, file_hash, content_hash, file_path/private dan original_name.
- `qe_boq_items`, PK `id_qe_boq_items`, FK `id_qe_boqs`/designator_id, snapshot kode/uraian/satuan/tipe/qty(15,3)/harga(18,2)/total. Satuan memakai panjang 255 mengikuti master agar tidak terpotong. Versi lama soft-delete, tidak dihapus permanen saat revisi.
- `qe_boq_histories`, PK `id_qe_boq_histories`, FK `id_qe_boqs`/`id_users`, event created/updated, before_data/after_data/note/timestamps. Snapshot header menunjuk `item_ids` versi immutable; rincian harga lengkap tetap berada pada item versi tersebut. Ini menghindari duplikasi JSON besar dan tetap dapat menelusuri versi lama.

FK actor lokal memakai users.id_users. Nilai created_by/updated_by dari database sumber tidak diasumsikan sebagai user lokal; nilai asli tetap di JSON referensi, FK salinan diisi null. Catalog/tarif berasal dari `database/data/boq_catalog_20261025.json`, dengan hash SHA-256 masing-masing SQL. SQL dump hanya diparse sebagai data, tidak dieksekusi mentah. Berkas asli tidak diubah. BOQ contoh proyek QE tidak dimasukkan karena ID proyek tersebut tidak memiliki padanan Work Order yang disetujui.

## Integritas, cakupan dan Revenue berikutnya

- Aritmetika memakai BigDecimal dari brick/math yang sudah terpasang bersama Laravel, tanpa float; qty × harga dibulatkan half-up ke 2 desimal per baris, lalu dijumlahkan. Tampilan Rupiah juga diformat dari string tanpa float.
- Harga master pada saat import dibekukan pada item. Perubahan master setelahnya tidak mengubah BOQ lama. Reimport disengaja dengan revision terbaru menggunakan harga paket yang berlaku; retry request lama dengan file/paket identik tetap no-op.
- Hash isi normalized dan file SHA-256 menangani upload/retry identik. Revisi berbeda mempertahankan satu header, menambah history dan versi item, serta menyimpan sumber lama dalam storage private. Jumlah Revenue masa depan harus mengambil BOQ aktif, bukan menjumlahkan seluruh history/item soft-deleted.
- `grand_total` ready menjadi dasar nilai untuk fitur Revenue berikutnya; **belum** merupakan pendapatan yang diakui, approval keuangan, invoice atau PS. Menu Revenue belum dibuat.
- Policy memakai hak update progress/akses WO yang sama, termasuk TL/HD/Admin Branch sesuai assignment. Preview/download hanya untuk user yang dapat membaca WO. Area dapat bekerja sesuai role progress yang sudah ada; akun nonaktif/Branch lain ditolak.
- Transaksi dan lock WO/BOQ/package, order_revision dan boq_revision mencegah overwrite stale. Pergantian Branch dievaluasi ulang dari WO terkunci, bukan id_branch dari client. Kegagalan audit membatalkan semua perubahan dan membersihkan file baru.
- Import BOQ tidak mengubah sumber BIMA, progress, perangkat, kendala atau revision WO. Import BIMA berikutnya tidak menimpa BOQ; file/riwayat tetap mengikuti otorisasi WO terkini.
- Perbaikan ActiveUser menggunakan header bag Symfony sehingga response stream download/template juga mendapat cache private/no-store. Sebelumnya `.header()` pada StreamedResponse menyebabkan error 500.
- Upload PHP gagal dan HTTP 413 memakai pesan Indonesia; halaman pemulihan BOQ kembali ke Work Order. Alur BIMA tetap memakai Sync Center.

## Migrasi aktual

Migrasi aditif dijalankan pada database aplikasi melalui helper yang mengambil lock inbound dan hanya menjalankan path migration 000017. Hasil:

- **3.418 WO**, digest seluruh kolom/baris sebelum dan sesudah **identik**.
- designator_types=5, designators=724, packages=2, designator_package_prices=1.030.
- qe_boqs=0, qe_boq_items=0, qe_boq_histories=0; tidak membuat transaksi BOQ pelanggan.

Tidak ada env/kredensial/dependency baru. Tabel internal Laravel tidak diubah. Worker restart tidak diperlukan karena import BOQ berjalan melalui request web, bukan perubahan job BIMA. Migration dapat rollback di lingkungan uji; jangan rollback data BOQ produksi tanpa backup.

## File perubahan

- Migration/data: `database/migrations/2026_10_25_000017_create_work_order_boq_catalog.php`, `database/data/boq_catalog_20261025.json`.
- Models: `DesignatorType.php`, `Designator.php`, `Package.php`, `DesignatorPackagePrice.php`, `QeBoq.php`, `QeBoqItem.php`, `QeBoqHistory.php` pada `app/Models/`; relasi BOQ pada `WorkOrder.php`.
- Services/support: `app/Services/Boq/BoqImportService.php`, `app/Support/BoqAmount.php`.
- Controller/policy/routes: `app/Http/Controllers/BoqController.php`, `BranchProgressController.php`, `WorkOrderController.php`; `app/Policies/WorkOrderPolicy.php`, `routes/web.php`.
- Upload/cache: `app/Http/Middleware/ActiveUser.php`, `bootstrap/app.php`, `resources/views/errors/upload-too-large.blade.php`.
- Views baru: `resources/views/boqs/upload.blade.php`, `show.blade.php`, `order-panel.blade.php`; penyesuaian `resources/views/work-orders/progress.blade.php`, `show.blade.php`.
- JS/CSS: `resources/js/jt-boq.js`, `resources/js/app.js`, `resources/css/app.css`.
- Tests: `tests/Feature/BoqImportTest.php`, `BoqCatalogMigrationTest.php`, `tests/JavaScript/jt-boq.test.js`; enam tes rollback lama disesuaikan satu langkah untuk migration tambahan (BranchProgress, BranchRegion, ExcelSourceColumns, Locations, SingleBranch, MigrationRollback).
- Docs/requirement: dokumen ini, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Tidak melakukan reset/commit/push atau menimpa progress branch dari Bima.


## Verifikasi akhir dan batas

- SQLite: **224 tes / 24.492 assertions lulus**, termasuk JT upload/skip, upload belakangan setelah progress berubah, katalog lengkap, CSV/XLSX, harga/qty dan pembulatan, overflow, harga tidak tersedia, tipe tidak cocok/nonaktif, duplikat/formula, privacy/permission, stale revision, retry, audit rollback, file private/download, HTTP 413, sumber BIMA tetap dan BOQ tidak ditimpa import BIMA.
- MariaDB terisolasi: **28 tes / 17.341 assertions lulus** untuk katalog/migration, BOQ, halaman aksi, routing BIMA dan otorisasi. Database sementara dihapus sesudah pengujian.
- JavaScript: **15 tes lulus**, termasuk 2 tes baru JT serta konfirmasi/loading. Intent Upload/Skip tetap dikirim meskipun handler loading menonaktifkan tombol submit; konfirmasi yang dibatalkan tidak mengirim intent.
- Build Vite, kompilasi Blade, Pint dan diff check lulus.
- Belum melakukan upload dokumen RAB pelanggan atau verifikasi browser visual baru; pengujian memakai fixture anonim dan source catalog yang disetujui. Tidak mengklaim E2E Google/Telegram. Tidak ada masalah kredensial yang menghambat fitur ini.

Perintah aktual:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-boq-mysql-tests.php
php /private/tmp/olo-apply-boq-migration.php
node --test tests/JavaScript/form-confirmation.test.js tests/JavaScript/dashboard-tables.test.js tests/JavaScript/jt-boq.test.js
npm run build
php artisan view:cache
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
git diff --check
```

Helper migration menjalankan Artisan migrate --path database/migrations/2026_10_25_000017_create_work_order_boq_catalog.php --force, dengan lock/digest WO sebelum-sesudah. Untuk environment lain, jalankan migration tersebut melalui prosedur deployment normal. Tidak mengubah php.ini global atau .env.

Pembaruan: kedua template DOMPIS Paket 5/10 dari pemilik kini didukung, termasuk volume kolom G, enrichment harga Paket 5 dan upload/preview KML opsional. Lihat [BOQ_TEMPLATES_KML.md](BOQ_TEMPLATES_KML.md) untuk kontrak terbaru, migration000018/000019 dan hasil verifikasinya. Hasil verifikasi di atas adalah baseline tahap000017.


## Pembaruan Revenue dan rumus volume
Rincian nilai material/jasa, formula volume template DOMPIS dan menu Revenue Overview sudah ditambahkan. Lihat [REVENUE_OVERVIEW.md](REVENUE_OVERVIEW.md); hash semantik BOQ tetap kompatibel dan riwayat lama dipertahankan.
