# Template DOMPIS Paket 5 / 10 dan peta KML

## Checklist implementasi dan dependensi

- [x] Memeriksa kedua workbook asli tanpa mengubahnya; reuse reader XLSX aman dan katalog BOQ 000017.
- [x] Adapter template DOMPIS: deteksi header/paket, baca volume, validasi seluruh item, reuse snapshot/revisi/idempotensi BOQ.
- [x] Lengkapi 418 harga Paket 5 yang tidak ada dari template pemilik, tanpa menimpa harga existing (000018).
- [x] Tabel KML berversi, parser XML/GeoJSON, file privat, policy Branch, lock/revision dan audit (000019).
- [x] Halaman upload/preview KML dari Detail Order, upload BOQ dan preview BOQ; Leaflet lokal melalui Vite.
- [x] Tes template asli, seluruh 724 designator/paket, parser/policy/retry/rollback KML dan error UI.
- [x] Catat hasil verifikasi akhir dan eksekusi migration di bagian hasil aktual di bawah.

## Alur pengguna

1. Buka Work Order → Detail → Update Progress → JT. Pilih **Simpan JT & Upload RAB/BOQ**, atau **Skip dulu** dan lengkapi belakangan.
2. Unduh **Template Paket 5** / **Template Paket 10**, atau gunakan workbook yang dikirim pemilik. Isi volume di **G / VOL**, pilih paket yang sama di form, lalu **Import & Preview BOQ**. Default pengaturan sheet/header sudah dapat membaca kedua template asli.
3. Dari Detail Order atau halaman BOQ pilih **Peta Order / KML**, unggah `.kml`, konfirmasi simpan, lalu tinjau peta. KML opsional, berdiri sendiri per WO, tersedia untuk role operasional dengan scope WO yang sama; tidak wajib sudah JT atau memiliki BOQ.
4. Klik objek untuk nama, gunakan zoom atau **Lihat seluruh objek**. Revisi KML disimpan sebagai versi baru; file identik tidak menggandakan riwayat.

KML/BOQ tidak mengubah progress, kendala, data perangkat, sumber BIMA atau penentuan PS. Harga BOQ merupakan snapshot untuk fitur Revenue berikutnya, belum pengakuan Revenue.

## Kontrak template yang diverifikasi

Kedua workbook memiliki sheet `BoQ`, header baris 6–7, baris item 9–732 (724 designator), dan footer 733–736.

| Kolom | Perlakuan |
| --- | --- |
| B / DESIGNATOR | Pencocokan kode master, trim/case-insensitive; hanya item dengan volume positif diproses |
| G / VOL | Angka langsung, positif, maksimal 3 desimal; kosong/nol dilewati |
| E / F | Header E6 memverifikasi Paket-5/Paket-10; nilai harga per item upload tidak menimpa master |
| H / I / J | Rumus/total diabaikan; hitung ulang volume × harga master dengan decimal presisi dan pembulatan half-up per baris |
| Footer | Total MATERIAL/JASA/TOTAL dan rumus pembanding bukan item BOQ |

Formula/error pada volume/designator/header, negatif, presisi berlebih, kode/harga tidak tersedia, atau duplikat item positif menolak seluruh import; tidak ada partial save. File kosong volume menampilkan pesan untuk mengisi G/VOL. XLSX/CSV standar `DESIGNATOR,QTY,TYPE` tetap didukung; aturan QTY standar tidak berubah.

Salinan template distribusi bit-identical dengan sumber, tersedia untuk download melalui endpoint berotorisasi:

- `resources/templates/boq/paket-5.xlsx`: SHA256 `78e130349dc06dcfb8b7ce66963fe7e08c507c13a7e942240d50c8f3dea3ab84`.
- `resources/templates/boq/paket-10.xlsx`: SHA256 `f732b556d545486953cf610837d3de94f9a11c5a549b30f583af524c73c1835d`.

File Downloads asli dan BIMA/LHPP tidak dimodifikasi. Tes menulis volume hanya pada salinan sementara.

## Harga master dan hal yang perlu dikonfirmasi

SQL sebelumnya berisi 724 harga Paket 10 dan 306 harga Paket 5. Migration **000018** menambahkan **418** harga Paket 5 dari E+F template pemilik, sehingga masing-masing paket memiliki 724 harga (total 1.448). Data referensi data-only disertai SHA sumber dan nomor baris. Insert hanya bila pasangan designator/paket belum ada, termasuk tidak mengaktifkan ulang harga terarsip; tidak menimpa harga master atau snapshot BOQ.

Ada tiga perbedaan nyata pada **Paket 10**: `J-Preliminary Project` (baris 583), `J-RENT-BMN` (637), `J-RENT-IBT` (638). Template bernilai **Rp1**, master SQL existing **Rp0**. Implementasi mempertahankan Rp0 sesuai master; koreksi harga tersebut memerlukan keputusan pemilik. Preview memperlihatkan harga satuan aktual yang dipakai. Dengan volume 1 pada seluruh 724 item, total Paket 5 Rp617.477.633 dan Paket 10 berdasarkan master Rp674.405.112 (Rp3 di bawah penjumlahan template Paket 10).

## Kontrak KML dan penyimpanan

- `.kml` XML 2.1 / 2.2 atau tanpa namespace, Point, LineString, Polygon (termasuk lubang), MultiGeometry.
- Longitude,latitude[,altitude] dari file: rentang ±180 / ±90; tidak menebak koordinat. Altitude valid diterima, preview 2D memakai lon/lat. Polygon harus tertutup; geometri tidak valid menggagalkan seluruh file.
- Maksimal **5 MB**, **200 Placemark**, **10.000 koordinat**, kedalaman 40 dan 30.000 elemen; batas efektif upload mengikuti nilai PHP yang lebih kecil. KMZ, NetworkLink, overlay, Model, Track belum didukung dan ditolak dengan pesan.
- DOM memakai NONET, DTD/entity ditolak. Tidak mengevaluasi HTML/Style/Icon URL/deskripsi dari KML, tidak meminta URL yang dibawa file. Nama diubah menjadi teks dan popup memakai `textContent`.
- `work_order_kmls`: PK `id_work_order_kmls`, FK `id_work_orders`, `id_users`, unique WO+revision; GeoJSON tervalidasi, SHA256, metadata sumber, audit. Versi/file lama dipertahankan, latest revision aktif. Riwayat berpagination 10.
- Private local disk: `kml/<UUID>.kml`. Download sumber via attachment dengan scope WO yang sama dan no-store/nosniff; GeoJSON endpoint hanya atribut peta. Upload memakai lock WO, pengecekan ulang user/scope/assignment, order_revision+kml_revision; stale update 409, retry file identik no-op. Kegagalan audit/transaksi membersihkan file baru saja.
- Pembaca berizin dapat preview/download. Upload hanya role dengan izin progress (Area, Branch Admin/HD/TL); akun nonaktif/Branch lain ditolak pada seluruh endpoint.

## UI, dependency dan environment

Leaflet **1.9.4**, di-bundle lokal (lazy load hanya halaman peta). White/dark menggunakan token aplikasi, radius 6px, tinggi peta responsif. Loading data, timeout/error/retry, empty/no-JavaScript, kegagalan library, dan error peta dasar ditangani. Konfirmasi simpan dan loading submit mengikuti mekanisme aplikasi.

Tidak ada credential/API key yang diperlukan. `config/maps.php` memakai default OpenStreetMap; `.env.example` menyediakan opsi:

```dotenv
MAP_TILE_URL="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
MAP_TILE_ATTRIBUTION="&copy; <a href='https://www.openstreetmap.org/copyright'>OpenStreetMap</a> contributors"
```

Tidak perlu mengubah `.env` untuk default. Jika mengganti provider, ganti URL dan atribusi sesuai lisensinya. Browser mengambil tile peta dasar melalui internet; koordinat geometri tetap dari endpoint privat aplikasi. Tidak melakukan geocoding/upload KML ke pihak ketiga; permintaan tile menyingkap area tampilan kepada provider. Atribusi OSM tetap terlihat, tanpa prefetch/offline tile download dan tanpa menonaktifkan cache browser. Default tile service bersifat best effort; produksi dengan trafik besar perlu provider yang sesuai.

Referensi implementasi: [Leaflet 1.9.4](https://leafletjs.com/reference.html), [kebijakan tile OpenStreetMap](https://operations.osmfoundation.org/policies/tiles/).

## Migration / deployment

Katalog BOQ 000017 sudah dijalankan pada tahap sebelumnya. Untuk tambahan ini:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000018_complete_package5_template_prices.php --force
php artisan migrate --path=database/migrations/2026_10_25_000019_create_work_order_kmls.php --force
npm ci
npm run build
php artisan view:cache
```

Tidak ada reset, full-data replacement, atau perubahan tabel internal Laravel. `000018 down` mempertahankan enrichment harga agar tidak menghapus referensi yang mungkin telah digunakan. Rollback katalog 000017 tetap menghapus katalog secara eksplisit; rollback 000019 menghapus tabel metadata KML, sehingga backup wajib mengikuti prosedur deployment bila melakukan rollback berisi data. File private historis tidak dihapus oleh migration.

## File yang berubah pada tahap ini

- Template/parser: `app/Services/Boq/DompisBoqTemplate.php`, `BoqImportService.php`; `app/Http/Controllers/BoqController.php`; `resources/templates/boq/paket-5.xlsx`, `paket-10.xlsx`; `resources/views/boqs/upload.blade.php`, `show.blade.php`.
- KML: `app/Services/Kml/KmlReader.php`, `KmlImportService.php`; `app/Models/WorkOrderKml.php`; relasi `app/Models/WorkOrder.php`; `app/Http/Controllers/WorkOrderKmlController.php`; `app/Policies/WorkOrderPolicy.php`; `routes/web.php`; `resources/views/work-orders/kml.blade.php`, `show.blade.php`.
- DB/data: migrations **000018** dan **000019** di atas; `database/data/boq_package5_template_prices_20261025.json`.
- Frontend/env: `resources/js/kml-map.js`, `resources/js/app.js`, `resources/css/app.css`, `package.json`, `package-lock.json`, `config/maps.php`, `.env.example`.
- Upload error: `bootstrap/app.php`, `resources/views/errors/upload-too-large.blade.php`.
- Tests: `tests/Feature/DompisBoqTemplateTest.php`, `WorkOrderKmlTest.php`, `WorkOrderKmlMigrationTest.php`, `tests/Unit/KmlReaderTest.php`, `tests/JavaScript/kml-map.test.js`; katalog count `BoqCatalogMigrationTest.php`; enam tes rollback lama ditambah dua migration (BranchProgress, BranchRegion, ExcelSourceColumns, Locations, SingleBranch, MigrationRollback).
- Dokumentasi: file ini, `docs/JT_BOQ_IMPORT.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

## Hasil aktual

- **SQLite full suite: 240 tes / 24.633 assertions lulus.** Termasuk 16 tes tambahan untuk kedua template aktual, seluruh designator/paket, volume kosong/nol/negatif/formula, paket salah, exact decimal totals, retry, source download, parsing geometri/XXE/batas, private file, semua akses Branch, stale assignment/revision, audit rollback dan upload413.
- **MariaDB terisolasi: 26 tes / 16.901 assertions lulus**, max_allowed_packet 1 MiB. Mencakup template/BOQ, KML, katalog, migration baru dan rollback business migrations. Database sementara sudah dihapus.
- **JavaScript: 18 tes lulus**, termasuk 3 tes peta (scoped fetch, popup teks aman, fit bounds, tile error, retry, empty/timeout); tes konfirmasi/dashboard/JT tetap lulus.
- Vite production build, kompilasi Blade, Pint dan diff check lulus. Dependency Leaflet terpasang dan lockfile diperbarui; installer melaporkan 0 vulnerability pada dependency yang diperiksa saat pemasangan.
- **Migration 000018 dan 000019 sudah dieksekusi di database aplikasi** pada 25 Oktober 2026. Full-row SHA digest **3.418 Work Order identik** sebelum/sesudah; semua 1.030 baris harga existing dan header BOQ existing juga identik. Sesudah migration: 5 tipe, 724 designator, 2 paket, **1.448 harga**, 0 BOQ/items/history dan 0 KML (belum ada dokumen pengguna diunggah pada aplikasi).
- Tidak mengubah `.env`, php.ini, queue, credential, workbook sumber atau data operasional. Tidak ada commit/push/reset.

Perintah verifikasi aktual:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-template-kml-mysql-tests.php
php /private/tmp/olo-apply-template-kml-migrations.php
node --test tests/JavaScript/*.test.js
npm run build
php artisan view:cache
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
git diff --check
```

Helper migration memakai lock inbound lalu menjalankan hanya path000018 dan000019, dengan digest WO, harga lama dan header BOQ sebelum/sesudah. Helper MariaDB membuat database acak terisolasi, tidak menggunakan database aplikasi sebagai test database, lalu menghapus database sementara dalam finally. Untuk host lain gunakan perintah deployment di atas; tidak memerlukan helper lokal tersebut.

Belum ada KML pekerjaan nyata yang disediakan. Tes menggunakan fixture anonim. Verifikasi visual browser baru belum dilakukan karena akses browser lokal ditolak pada sesi ini; tidak mengklaim preview E2E browser/Google/Telegram.


## Pembaruan: contoh BOQ berumus dan Revenue Overview
Aturan larangan seluruh formula volume pada dokumentasi tahap awal di atas digantikan oleh evaluator subset formula G yang aman pada template DOMPIS. Rincian material/jasa/header snapshot dan Revenue Overview tersedia melalui migration000020. Lihat [REVENUE_OVERVIEW.md](REVENUE_OVERVIEW.md) untuk kontrak terbaru, hasil rekonsiliasi contoh dan pengujian. Batas/keamanan KML tetap.
