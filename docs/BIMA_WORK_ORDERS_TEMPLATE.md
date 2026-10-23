# Template export BIMA Work Orders — 20 Oktober 2026

Checklist:
- [x] Periksa workbook secara read-only; jangan salin data pelanggan ke fixtures/log.
- [x] Preset 13 kolom sesuai mapping pemilik, validasi header dan kolom D ORDER-ID/ORDER ID.
- [x] Pilih sheet Work Orders otomatis, abaikan baris format kosong, dukung Date Created dengan milidetik.
- [x] Mapping, preview, hash skip/update, perlindungan progress dan tes lintas database.
- [x] Verifikasi contoh asli tanpa menulis ke database aplikasi; dokumentasikan hasil dan input yang belum dipetakan.

Inspeksi: workbook memiliki sheet Pivot, Work Orders dan Mapping-Order. Data sumber berada pada Work Orders, header baris 1; header D aktual ORDER-ID (padanan ORDER ID yang disebut pemilik). Ada 13 ORDER ID unik, serta baris format kosong hingga baris 20121. Date Created berformat YYYY-MM-DD HH:MM:SS.mmm. Ukuran file 891070 byte. SHA-256 sumber: 4cd05e4a05f1de8241c918320fd5e85e8fe8b599bfe7e6744caa597320309716.

Pembaruan terbaru: migrasi 000011 menambahkan status_bima, status_order gabungan, mapping_order dan log task. K Status kini dipetakan sesuai arahan pemilik. Tanggal PS belum diberikan mapping dan tidak ditebak dari Date Modified. Environment/dependensi tetap. File contoh tidak dikonfirmasi masuk database aktual sebagai bagian konfigurasi template.

## Mapping preset

| Kolom | Header | work_orders |
|---|---|---|
| A | Date Created | tgl_order |
| D | ORDER / ORDER-ID / ORDER ID | no_tq_sc_id |
| G | Service No. | sid |
| H | Description | task_bima |
| I | CRM Order Type | jenis_pekerjaan |
| J | Owner Group | owner_grup |
| K | Status | status_bima |
| L | Product Name | layanan |
| M | Address | alamat |
| O | Customer Name | nama_olo |
| P | Workzone | sto |
| AC | District TIF | branch |
| AD | Regional TIF | regional |

Preset hanya dikenali jika seluruh 13 header cocok pada posisi yang ditentukan. Case, whitespace dan tanda hubung ORDER-ID/ORDER ID ditoleransi. Nama file tidak menjadi dasar pengenalan. AG STATUS-ORDER tidak diimpor; status_order dihitung dari task_bima-status_bima. Umur AI/AJ diabaikan; umur dihitung dari tgl_order sampai hari ini. Kolom yang tidak dipetakan mempertahankan nilai lama; untuk WO baru tetap NULL/default. Operator tetap memeriksa mapping, preview dan konfirmasi.

## Perubahan reader dan alur

- Sheet otomatis: Work Orders → OLO AREA-3 → sheet terlihat pertama. Nama sheet tetap dapat diisi manual.
- Baris berformat kosong diabaikan. Batas 10.000 order menghitung baris berisi data; bukan nomor baris terakhir. Nomor baris sumber tetap dipertahankan pada log. Reader tetap membatasi 100.000 elemen row, nomor baris Excel valid, ZIP/XML dan ukuran lain.
- Date Created dengan tiga digit milidetik dibaca lalu disimpan sebagai DATE tgl_order. Tidak menebak tanggal dari Date Modified atau tanggal PS.
- ID/SID teks dipertahankan. Hash data per WO tetap menentukan skip/update; Date Modified dan kolom yang tidak dipetakan tidak ikut mengubah hash. Progress/kendala/revisi cabang tetap dilindungi.
- Snapshot metadata menyimpan identitas preset bima-work-orders-v3 secara terenkripsi. UI mapping menampilkan preset terdeteksi, K Status dan tanggal PS yang belum dipetakan.

## Verifikasi

Contoh asli dibaca tanpa mengubahnya: 13 baris valid, 13 ORDER ID unik, seluruh 13 STO berhasil di-resolve ke master cabang, 0 konflik mapping. Pemeriksaan ini tidak membuat WO/job import pada database aplikasi. File/data pelanggan tidak disalin ke fixtures atau log.

Tes memakai data sintetis dengan header yang sama, mencakup 12 atribut, leading zeros, tanggal milidetik, preset yang tidak cocok, sheet Pivot sebelum Work Orders, format kosong pada baris 20121, kolom yang tidak dipetakan, idempotency dan update data tanpa overwrite progress. Hasil akhir: **110 tes SQLite lulus / 1.593 assertions; 108 tes MariaDB lulus / 1.582 assertions**. Dua tes snapshot Sheets legacy dikecualikan hanya di MariaDB karena batas packet 1 MiB, seperti sebelumnya. Pint, build Vite, kompilasi Blade dan git diff --check lulus.

Hasil verifikasi di atas adalah baseline sebelum pembaruan status/perangkat; hasil terbaru ada di docs/BIMA_STATUS_AND_DEVICES.md. Pembaruan terbaru membutuhkan migrasi 000011; environment tetap. Worker perlu dimuat ulang agar parser tanggal terbaru dipakai. Tidak ada tes API Google/Telegram baru dan tidak ada commit/push.

## File berubah

- app/Services/BimaWorkOrdersTemplate.php (baru)
- app/Services/ExcelWorkOrderMapper.php
- app/Services/WorkOrderXlsxReader.php
- app/Services/BimaRowMapper.php
- app/Http/Controllers/WorkOrderImportController.php
- resources/views/imports/mapping.blade.php
- resources/views/sync/excel-inbound.blade.php
- tests/Feature/BimaWorkOrdersTemplateTest.php (baru)
- tests/Unit/WorkOrderXlsxReaderTest.php
- docs/BIMA_WORK_ORDERS_TEMPLATE.md (baru)
- AGENTS.md, SKILLS.md, PRD_OLO_Command_Center_v2.md, README.md, docs/EXCEL_WORK_ORDER_IMPORT.md

Masih perlu arahan: sumber tgl_ps dan file aturan mapping_order. K Status sudah ditetapkan sebagai status_bima; status_order berasal dari task_bima-status_bima. AG STATUS-ORDER dan Date Modified tidak menggantikan aturan ini.

Perintah verifikasi: `php artisan test --compact`, `php /private/tmp/olo-id-mysql-tests.php` (database sementara terpisah), `vendor/bin/pint --dirty --test`, `npm run build`, `php artisan view:cache`, baca/map workbook asli secara read-only, resolve STO terhadap master tanpa penulisan, dan pemeriksaan SHA-256 sebelum/sesudah.


Pembaruan 21 Oktober: J Owner Group→owner_grup (000014), hash dan log mengikuti field baru. Mapping lama tetap kompatibel dan tidak diubah diam-diam. Hasil validasi terbaru: docs/OWNER_GRUP.md.


Pembaruan 23 Oktober: export terbaru memakai D ORDER, diterima hanya dalam preset lengkap dengan seluruh 13 posisi/header sesuai. Upload tertunda dideteksi ulang di halaman mapping tanpa menulis sumber. UI menampilkan pasangan utama dan nama SQL, atribut tambahan tetap opsional. Hasil tes terbaru dan file berubah: docs/BIMA_MAPPING_RESTORE.md.
