# Import Work Order Excel — diperbarui 20 Oktober 2026

## Hasil implementasi

Nama fisik kolom kini mengikuti label sumber dalam snake_case melalui migrasi 000010. Lihat [daftar kolom dan verifikasi database](WORK_ORDER_COLUMN_NAMES.md).

Koreksi terbaru: **satu BRANCH**, dengan migrasi konsolidasi 000009. Lihat [catatan perubahan 20 Oktober](SINGLE_BRANCH.md) untuk migrasi, pengujian dan manifest file koreksi terbaru.

- [x] Tambah kolom sumber yang belum tersedia; pertahankan PK/FK, data dan progress lama.
- [x] XLSX reader read-only tanpa menjalankan formula/link, dengan batas ZIP/XML/baris/sel.
- [x] Upload → periksa mapping kolom → preview antrean → konfirmasi → upsert BIMA idempotent.
- [x] Snapshot per baris terenkripsi, retry, audit dan lock; import tidak membutuhkan Google.
- [x] Sync Center memakai Excel sebagai inbound utama; monitoring Google tetap outbound terpisah.
- [x] Detail WO menampilkan 28 atribut sumber; pengujian SQLite/MariaDB, build, migrasi aktual dan QA responsif selesai.

Inspeksi: Laravel 13/PHP 8.5, Blade/Tailwind 4/Vite, ekstensi ZipArchive, XMLReader, SimpleXML, libxml dan mbstring tersedia. Tidak menambah paket Composer/NPM. Resolver cabang, RBAC, audit dan kontrak identitas BIMA lama digunakan kembali. Template asli tidak diedit dan tidak diimpor sebagai contoh ke database aktual.

## Cara memakai

1. Login Superadmin, Admin Area atau HD Area, buka **Sync Center**.
2. Upload file **XLSX** hasil download BIMA. Nama sheet opsional; otomatis memilih `Work Orders`, kemudian `OLO AREA-3`, jika tidak sheet terlihat pertama. Pilih nomor baris header (1–50).
3. Pada **Periksa mapping kolom**, cocokkan kolom file dengan kolom tujuan. Nama dan urutan template boleh berbeda. Label pilihan menyertakan huruf kolom untuk membedakan header sama. Saran otomatis berdasarkan header perlu diperiksa, bukan aturan posisi tetap.
4. `NO TQ/SC/ID` wajib. Pilih `STO` atau `BRANCH` untuk routing. Satu kolom file hanya boleh menuju satu atribut. Kolom tanpa mapping tidak menimpa nilai WO lama; kolom yang dipilih dengan sel kosong mengosongkan atribut sumber nullable tersebut.
5. Setujui mapping, buat preview. Halaman menampilkan status antrean, ringkasan baru/berubah/tetap/ditolak/konflik dan alasan per baris; exception dapat diunduh CSV. Work order belum berubah.
6. Periksa lalu konfirmasi import. Preview berlaku 30 menit sampai konfirmasi. Baris valid masuk MySQL; penolakan/konflik dilewati. Baris dengan sumber atau mapping berubah setelah preview harus diperiksa ulang.
7. Order otomatis terlihat oleh cabang yang ditetapkan melalui master STO/mapping. Branch melanjutkan update progress dan kendala di web.

Mapping diikat pada file/job dan operator pemilik. Preset BIMA Work Orders dengan 11 kolom telah tersedia; lihat docs/BIMA_WORK_ORDERS_TEMPLATE.md. Template lain tetap memakai mapping manual. File identik boleh diproses lagi; identitas WO tetap `(source_system=BIMA, no_tq_sc_id)`, sehingga tidak menggandakan order. WO yang tidak ada dalam upload terbaru tidak dibatalkan/dihapus.

## Kolom tujuan work_orders

Hanya ada satu BRANCH (`branch`), total 28 atribut tujuan. Jika file memiliki beberapa header BRANCH, pilih satu kolom sumber; saran otomatis memilih BRANCH pertama. Kolom BRANCH / DISTRICT turunan tidak dimapping otomatis.

Kolom lama digunakan kembali. Migrasi 000008 menambahkan 16 kolom nullable; migrasi 000009 kemudian menghapus source_district setelah mengarsipkan nilainya. Hasil akhir menggunakan 28 atribut, dengan satu BRANCH. Nama PK `id_work_orders`, FK `id_branches`, indeks identitas biner dan tabel internal Laravel tetap seperti sebelumnya.

| Label | Kolom database | Catatan |
|---|---|---|
| TGL ORDER | tgl_order | date, lama |
| BULAN | bulan | baru, string label sumber |
| TAHUN | tahun | baru, empat digit |
| REGIONAL | regional | lama |
| BRANCH | branch | lama, sumber routing |
| STO | sto | lama |
| NO TQ/SC/ID | no_tq_sc_id | lama, string identitas wajib |
| NAMA OLO | nama_olo | lama |
| ALAMAT | alamat | lama, text |
| LAYANAN | layanan | lama |
| JENIS PEKERJAAN | jenis_pekerjaan | lama |
| BW | bw | baru |
| STATUS ORDER | status_order | hasil task_bima-status_bima, tidak diimpor langsung |
| STATUS BIMA | status_bima | status mentah BIMA, K pada preset Work Orders |
| MAPPING ORDER | mapping_order | klasifikasi dari master mapping_order, menunggu aturan |
| TASK BIMA | task_bima | baru |
| TGL PS | tgl_ps | date, lama |
| SID | sid | lama, terpisah dari identitas order |
| KETERANGAN | keterangan | baru, text sumber; bukan catatan progress |
| ODP | odp | baru |
| SN | sn | baru, teks |
| OLT | olt | baru |
| PORT OLT | port_olt | baru, teks |
| METRO | metro | baru |
| PORT METRO | port_metro | baru, teks |
| STATUS FINAL | status_final | lama, hanya OPEN/CLOSE/CANCEL |
| BULAN PS | bulan_ps | baru |
| PS HARI INI | ps_hari_ini | baru, referensi sumber |
| UMUR (HARI) | umur_hari | baru, referensi sumber |
| KATEGORI UMUR | kategori_umur | baru, referensi sumber |

Migrasi 000011 mengubah status_order menjadi TEXT untuk gabungan task-status. Bulan/tahun mengikuti tgl_order; umur dan kategorinya dihitung server, bukan dari export. Penyimpanan umur SQL diperbarui melalui scheduler harian. PS HARI INI masih berupa referensi sumber. PS = progress web **CLOSE** dan `status_bima` **CLOSE/COMPLETE**; tanggal harian memakai `tgl_ps`. STATUS FINAL, TASK BIMA dan flag PS HARI INI tidak menggantikan syarat ini.

## Validasi dan integritas

- Format awal **XLSX**, bukan XLS biner, XLSM atau CSV. Konversi dilakukan dari sumber yang tepercaya; aplikasi tidak memulihkan nol depan yang sudah hilang.
- NO TQ/SC/ID, SID dan SN berisi harus sel teks asli. ID numerik ditolak untuk menghindari kehilangan nol depan; SID kosong tetap boleh. Identitas tidak diambil dari nomor baris atau SID.
- Semua kemunculan NO TQ/SC/ID duplikat dalam file ditolak, termasuk jika salah satu baris juga mempunyai error lain.
- Formula/error pada kolom bisnis ditolak. Tiga kolom referensi PS HARI INI/umur/kategori umur boleh menyimpan cached value formula, tanpa kalkulasi dan tanpa dipakai sebagai KPI. Formula tanpa cache tidak ditebak.
- Tanggal diparse oleh mapper tanggal lama, termasuk serial Excel dan sistem tanggal 1904. Tanggal tidak valid ditolak.
- Mapping cabang menggunakan resolver bersama: explicit mapping sumber/STO dan fallback master service_areas. STO/branch/region tidak aktif atau mapping bertentangan menimbulkan konflik. WO lama tidak dipindah cabang otomatis.
- Allowlist mapper tidak menerima progress_category, operational_status, revision, last_progress_at, branch_completed_at, verifikasi atau kendala. Nilai sumber lama yang tidak dimapping dipertahankan.
- Payload upload/prepared per baris menggunakan cast encrypted:array dan disembunyikan dari serialisasi model. Endpoint JSON status job tidak mengirim snapshot/data pelanggan. Audit mencatat field/ID, bukan nilai pelanggan.
- Global lock inbound mencegah dua import/pull aktif bersamaan; transaksi per baris, pemeriksaan hash sumber dan applied_at melindungi retry serta perubahan setelah preview. Snapshot dipecah per baris untuk menghindari satu query besar melebihi max_allowed_packet lokal 1 MiB.
- Payload per baris dihapus setelah commit, termasuk baris ditolak/konflik. Upload sementara tidak disalin ke folder publik. Retensi snapshot upload yang ditinggalkan/expired masih perlu ditetapkan sebelum produksi.

Batas aplikasi: 10 MB compressed, 10.000 order, 128 kolom, 2.048 bagian ZIP, total uncompressed 64 MiB, satu bagian 24 MiB, 8.192 karakter/sel dan 64 KiB JSON mentah/baris. Workbook macro/koneksi data eksternal, entity/DOCTYPE XML dan struktur rusak ditolak. Tidak ada ekstraksi ZIP ke filesystem atau pengambilan external link. Batas upload PHP/reverse proxy dapat lebih kecil; UI menampilkan batas PHP efektif.

## Migrasi dan environment

Migrasi `2026_10_19_000008_add_excel_work_order_source_columns` menambahkan 16 atribut sumber dan `sync_job_rows.payload` LONGTEXT nullable. Backfill hanya menyalin nilai header JSON sumber lama yang benar-benar tersedia, tanpa menebak nilai turunan, memotong nilai panjang atau mengubah timestamp/progress. Kolom derived yang tidak disimpan oleh importer lama tetap NULL sampai upload memetakannya. Rollback menghapus kolom baru beserta isinya; cadangkan terlebih dahulu bila telah dipakai.

```dotenv
WO_ORDER_SOURCE=excel
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_QUEUE_RETRY_AFTER=300
```

`WO_ORDER_SOURCE` baru tersedia di `.env.example`; default `excel` juga berlaku jika belum ditulis di `.env`. Credential Google tidak diperlukan untuk upload/import. Mode lama `sheets` tersedia secara eksplisit untuk kompatibilitas, tetapi endpoint dan worker pull lama ditolak saat mode Excel aktif. Monitoring outbound tetap memakai konfigurasi Google yang sudah ada; perubahan ini tidak melakukan tes tulis Google atau koneksi Telegram.

Perintah deploy yang digunakan:

```sh
php artisan down --retry=60
php artisan queue:restart
# Cadangkan database dengan penyimpanan privat terenkripsi sebelum migrasi.
php artisan migrate --force
php artisan config:clear
npm run build
php artisan view:cache
php artisan up
php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1
```

Worker harus dimuat ulang setelah perubahan kode/config; timeout job 180 detik, lock 240 detik, retry_after 300 detik. Gunakan supervisor di produksi. Ekstensi PHP ZipArchive/zip, XMLReader/xmlreader, SimpleXML/simplexml, libxml dan mbstring wajib tersedia. Untuk memakai batas aplikasi penuh, atur PHP `upload_max_filesize=10M`, `post_max_size=12M` dan batas proxy yang sesuai, lalu restart layanan PHP. Konfigurasi PHP global tidak diubah dalam pekerjaan ini. Batas awal server lokal 2 MB telah diperbaiki pada 23 Oktober 2026 menjadi 10 MB untuk artisan serve proyek; UI menampilkan batas efektif. Lihat docs/UPLOAD_LIMIT_FIX.md untuk konfigurasi per direktori dan batas verifikasi.

Riwayat migrasi 19 Oktober (sebelum konsolidasi satu BRANCH): migrasi 000008 berhasil. Cadangan terenkripsi dengan izin 0600 tersimpan pada `storage/app/private/maintenance/before-excel-import-20261019-091538.json.enc` (diabaikan Git; membutuhkan APP_KEY untuk pemulihan). Perbandingan sebelum/sesudah pada seluruh kolom lama 13 tabel bisnis/auth menunjukkan data tidak berubah. Tepat sebelum migrasi terdapat **0 work_orders**, 3 users, 16 branches, 3 regions dan 392 service_areas; tidak ada data dummy yang ditambahkan ke aplikasi aktual. Semua 29 kolom tujuan dan payload terenkripsi telah diverifikasi tersedia.

Antrean lama telah diperiksa tanpa menampilkan payload: tersisa 0 pending setelah worker dimulai ulang. Pull preview lama #11 ditolak karena mode berubah. Satu job lama mengacu entitas yang tidak ditemukan (ModelNotFoundException), disimpan sebagai failed job #2; failed job #1 SheetException sudah ada sebelum perubahan. Tidak dihapus atau diretry massal. Keadaan ini tidak menghalangi import baru.

## Pengujian dan QA

Hasil 19 Oktober sebelum konsolidasi: **103 tes SQLite lulus / 1.129 assertions; 101 tes MariaDB lulus / 1.118 assertions**. Pint, build Vite, kompilasi Blade dan git diff --check lulus. Cakupan awal meliputi mapping 29 kolom (sebelum konsolidasi 20 Oktober), dua BRANCH, leading zeros, SID/SN numerik, duplicate ID, formula, tanggal 1904/shared strings, workbook rusak/XXE/koneksi eksternal, batas baris/kolom/sel, RBAC/pemilik, expiry/revocation/lock, stale preview, retry, backfill/rollback, import ulang dan perlindungan progress/kendala/kolom unmapped.

Perintah verifikasi yang dijalankan:

```sh
php artisan test --compact
php /private/tmp/olo-id-mysql-tests.php
vendor/bin/pint --dirty --test
npm run build
php artisan view:cache
git diff --check
```

Runner MariaDB tersebut merupakan skrip lokal sementara, membaca konfigurasi privat tanpa mencetaknya, membuat database dengan nama acak dan menghapusnya dalam finally. Untuk pengulangan di mesin lain, siapkan database testing terpisah, set DB_CONNECTION=mysql/DB_DATABASE ke database tersebut, lalu jalankan artisan test; jangan gunakan database aplikasi karena tes melakukan migrate:fresh/rollback.

Tes MariaDB menggunakan database sementara terpisah yang dihapus setelah selesai. Dua tes legacy Sheets `historical_size_batch` dan `provided_bima_imports` dikecualikan hanya pada MariaDB karena snapshot lama melampaui max_allowed_packet 1 MiB; keduanya tetap berjalan pada SQLite. Tes Excel besar baru tetap berjalan pada kedua engine: 170 WO, payload gabungan lebih dari 1 MiB, query per baris di bawah batas, commit dan replay aman.

Reader baru diuji read-only pada `EXPORT-BIMA.xlsx`: 32 header, 3.298 baris di `OLO AREA-3`. Ini bukan impor nyata template baru. SHA-256 kedua workbook sumber tetap:

- EXPORT-BIMA.xlsx: `303db8cb0474872b2693be1ec3cfe19494bc90f3f83d0ee5ad59ad4e6ae17baa`
- LHPP OLO.xlsx: `2cf8b271038771c1b22879521eec3886d71054e9cca48148686acb5aa0dd33fe`

Browser Sync Center aktual diperiksa pada 375/768/1280 px, light/dark, tanpa overflow halaman. Halaman mapping/preview/commit diuji melalui HTTP feature tests pada database terisolasi; tidak memasukkan data percobaan ke aplikasi aktual. Screenshot tersedia lokal pada `docs/screenshots/excel-sync-{desktop,mobile}-{light,dark}.png` (diabaikan Git). Tidak ada klaim tes end-to-end Google push/Telegram baru.

## Input yang masih diperlukan

- Sumber tgl_ps dan file aturan mapping_order. K Status→status_bima kini disetujui; status_order dihitung dari task_bima-status_bima.
- Kebijakan retensi upload/snapshot yang ditinggalkan dan ukuran file produksi jika melampaui batas server.

## Manifest file berubah

- `.env.example`
- `AGENTS.md`
- `PRD_OLO_Command_Center_v2.md`
- `README.md`
- `SKILLS.md`
- `app/Http/Controllers/SyncController.php`
- `app/Jobs/ProcessSheetSync.php`
- `app/Models/SyncJobRow.php`
- `app/Services/BimaSheetPullService.php`
- `config/olo.php`
- `resources/css/app.css`
- `resources/views/area/dashboard.blade.php`
- `resources/views/branch/dashboard.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/sync/index.blade.php`
- `resources/views/sync/show.blade.php`
- `resources/views/work-orders/index.blade.php`
- `resources/views/work-orders/show.blade.php`
- `routes/web.php`
- `tests/Feature/AccessAndSyncTest.php`
- `tests/Feature/BranchProgressMigrationTest.php`
- `tests/Feature/BranchRegionMigrationTest.php`
- `tests/Feature/LocationsMigrationTest.php`
- `tests/Feature/MigrationRollbackTest.php`
- `tests/Feature/OloTestCase.php`
- `app/Http/Controllers/WorkOrderImportController.php`
- `app/Jobs/ProcessWorkOrderImport.php`
- `app/Services/BimaOrderResolver.php`
- `app/Services/ExcelWorkOrderImportService.php`
- `app/Services/ExcelWorkOrderMapper.php`
- `app/Services/WorkOrderXlsxReader.php`
- `database/migrations/2026_10_19_000008_add_excel_work_order_source_columns.php`
- `docs/EXCEL_WORK_ORDER_IMPORT.md`
- `resources/views/imports/mapping.blade.php`
- `resources/views/sync/excel-inbound.blade.php`
- `resources/views/sync/history.blade.php`
- `resources/views/sync/sheets-inbound.blade.php`
- `tests/Fakes/XlsxFile.php`
- `tests/Feature/ExcelSourceColumnsMigrationTest.php`
- `tests/Feature/ExcelWorkOrderImportTest.php`
- `tests/Unit/ExcelWorkOrderMapperTest.php`
- `tests/Unit/WorkOrderXlsxReaderTest.php`

Screenshot dan backup privat adalah artefak lokal diabaikan Git. Build Vite memperbarui public/build yang juga diabaikan Git. Tidak melakukan commit atau push.
