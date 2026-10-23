# Pemulihan mapping export BIMA — 23 Oktober 2026

Discovery: upload tertunda terbaru memakai sheet Work Orders dengan kolom D bernama ORDER, sedangkan preset semula hanya menerima ORDER ID/ORDER-ID. Template menjadi tidak dikenali dan fallback hanya mengusulkan owner_grup/status_bima. Data sumber pelanggan tidak dibaca untuk diagnosis; hanya metadata header.

Checklist:
- [x] Kenali ORDER di D hanya jika seluruh 12 header/posisi lain memenuhi kontrak preset.
- [x] Deteksi ulang upload yang masih tertunda saat halaman mapping dibuka, tanpa menulis snapshot/order pada GET.
- [x] Tampilkan 13 pasangan utama dalam urutan kolom file dan nama fisik tabel SQL; atribut tambahan tetap opsional dan tertutup.
- [x] Sediakan Gunakan mapping BIMA untuk mengembalikan pilihan bawaan; pilihan tersimpan/error tetap dihormati sampai operator memilihnya.
- [x] Tes mapping, preview/import, build/lint dan pemeriksaan UI baca-saja.

Tetap memakai mapping A/D/G/H/I/J/K/L/M/O/P/AC/AD sebelumnya. Tidak mengimpor AG STATUS-ORDER atau menebak TGL PS dari Date Modified/Status Date. Kolom derived tetap dihitung Laravel. Tidak ada migration/env atau perubahan nama kolom SQL. File sumber asli tidak diubah. Tidak menjalankan upload browser atau konfirmasi import pada order aktual.


## Mapping bawaan

| Kolom export | Header | Tujuan SQL work_orders |
|---|---|---|
| A | Date Created | tgl_order |
| D | ORDER / ORDER ID / ORDER-ID | no_tq_sc_id |
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

Alias ORDER tidak ditambahkan pada fallback file generik: hanya diterima sebagai D pada preset lengkap dengan seluruh pasangan posisi/header lain cocok. Nama file tidak menentukan mapping. Tidak mengubah versi payload/snapshot karena aturan tujuan tetap sama dan upload tertunda harus kompatibel.

Mapping upload lama dengan template=NULL dideteksi ulang saat GET. Metadata/pilihan tersimpan tidak ditulis ulang; pilihan kosong/sengaja diubah tetap dipertahankan jika sudah tersimpan. Tombol Gunakan mapping BIMA menampilkan ulang default (termasuk mengabaikan old input validasi saat tombol dipilih) dan tetap membutuhkan konfirmasi operator untuk menyimpan mapping/preview. Tidak mengeksekusi preview/import otomatis lewat GET.

## Validasi aktual

- `php artisan test --compact --filter='BimaWorkOrdersTemplateTest|ExcelWorkOrderImportTest|WorkOrderUploadTest'`: 19 tes / 531 assertions lulus sebelum penambahan kasus ORDER terbaru.
- `php artisan test --compact --filter=BimaWorkOrdersTemplateTest`: 6 tes / 336 assertions lulus, termasuk 13 selected options independen, upload tertunda template=NULL, GET tanpa perubahan snapshot/WO, pilihan manual tersimpan, reset eksplisit dengan old input, preview dan commit terisolasi.
- `php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never`: seluruh 164 tes SQLite / 3.469 assertions lulus.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check`: lulus.
- Browser GET upload tertunda aktual memperlihatkan seluruh 13 pasangan terpilih, termasuk D ORDER. Tidak melakukan upload atau klik konfirmasi preview/import pada database aktual. UI diperiksa desktop 1280, mobile 375 light/dark dan tablet 768; lebar halaman mobile/tablet sesuai viewport setelah perbaikan grid agar select panjang tidak mendorong layout. Contoh isi pelanggan tidak disalin ke bukti gambar.
- Bukti `/private/tmp/olo-bima-mapping-restored.png`: bagian kolom mapping saja.

## File berubah

- `app/Services/BimaWorkOrdersTemplate.php`: alias ORDER dalam kontrak preset lengkap.
- `app/Http/Controllers/WorkOrderImportController.php`: deteksi ulang upload tertunda, pemisahan field utama/tambahan dan pemulihan default pilihan view.
- `resources/views/imports/mapping.blade.php`, `resources/views/imports/mapping-field.blade.php` (baru): 13 pasangan utama, nama SQL, atribut tambahan dalam accordion dan tombol default BIMA.
- `resources/css/app.css`: label SQL terpisah dan lebar grid/select mobile.
- `tests/Feature/BimaWorkOrdersTemplateTest.php`.
- Dokumentasi ini, `docs/BIMA_WORK_ORDERS_TEMPLATE.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Tidak ada migration/env/dependency baru; tidak ada perubahan order, hash, progress, assignment atau file asli. Tidak diperlukan input tambahan untuk 13 pasangan ini. Sumber TGL PS tetap belum ditetapkan dan tidak ditebak.
