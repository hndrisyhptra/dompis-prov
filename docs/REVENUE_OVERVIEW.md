# Revenue Overview dan perhitungan BOQ

## Catatan implementasi

Laravel 13, Blade dan token UI yang ada dipertahankan. Katalog, header BOQ aktif per WO, item snapshot/soft delete dan riwayat versi sudah tersedia. Contoh BOQ terbaru memakai rumus volume pada G; parser sebelumnya menolak seluruh formula. Penyesuaian menghitung subset rumus yang benar-benar dibutuhkan di server dan memisahkan nilai material/jasa dari tipe master, tanpa mempercayai cached total Excel.

Revenue Overview adalah pemantauan nilai BOQ, bukan pencatatan invoice/pembayaran atau pengakuan akuntansi. Semua role manusia aktif memakai scope Work Order yang sama: Area seluruh Area, Branch/TL/Admin Material hanya Branch yang diassign. Bot tidak memperoleh akses. Pengukuran PS tetap memakai aturan 14 status BIMA + progress CLOSE yang telah disetujui.

## Checklist dan dependensi

- [x] Inspect requirement, schema/RBAC/layout dan contoh workbook secara read-only.
- [x] Evaluator volume aman (angka, G9:G732, + − × ÷ %, kurung, ROUND) dan rekonsiliasi contoh.
- [x] Pemisahan material/jasa, snapshot total, preview rinci dan migrasi aditif/backfill terverifikasi.
- [x] Menu Revenue Overview, filter, KPI, perbandingan Branch, status PS/belum PS/Cancel, daftar order berpagination dan tautan Detail/BOQ.
- [x] Uji hitungan presisi/revisi/idempotensi, seluruh role/scope/filter/empty, migrasi, build dan dokumentasi hasil aktual.

## Hasil rekonsiliasi file contoh

Workbook contoh dibaca langsung tanpa modifikasi dan tanpa diimpor ke WO produksi. Sheet `BoQ`, Paket 5. SHA256 sumber `064dbc651c1deb8cf48d6c91c5ddba0c37a38115782e2010193cffc9a7385599` tetap identik setelah pemeriksaan. Data lokasi/proyek dari contoh tidak disalin ke fixture/repository.

| Hasil perhitungan aplikasi | Nilai |
| --- | ---: |
| Item volume positif | 11 |
| Material | Rp1.010.302,00 |
| Jasa | Rp770.401,00 |
| Grand Total | **Rp1.780.703,00** |

Hasil tersebut cocok dengan subtotal workbook pada G733/H733 (material), G734/H734 (jasa), G735/H735 (total). Angka dihitung ulang dari G dan harga master, bukan menyalin subtotal atau nilai cache file.

| Satuan | Volume material | Volume jasa |
| --- | ---: | ---: |
| meter | 74,000 | 74,000 |
| pcs | 4,000 | 4,000 |
| core | 0,000 | 6,000 |

Volume tidak dijumlah lintas satuan. Baris material dan jasa yang berpasangan tetap dipisahkan agar 38 meter kabel material + 38 meter jasa pemasangan tidak dianggap 76 meter kabel terpasang.

## Kontrak perhitungan

- Pada template DOMPIS saja, G boleh angka langsung atau subset rumus: referensi G9:G732 pada sheet yang sama, termasuk `$G$53`, angka, tanda +/−/×/÷, persen, kurung dan `ROUND(angka,digit)` dengan digit −6 sampai 6. Blank yang direferensikan bernilai nol sebagaimana contoh. Hasil item maksimum 12 digit bulat dan 3 desimal; negatif/lebih presisi/overflow/division zero/circular ditolak secara utuh.
- Rumus volume dihitung ulang menggunakan rational/decimal presisi di server. Cache G dapat salah/kosong dan tidak dipakai sebagai hasil. Harga dan total H/I/J serta footer tidak dievaluasi. Tidak ada `eval`, macro, executable Excel, akses URL/file atau fungsi eksternal.
- Shared formula, cross-sheet/workbook, range dan fungsi lain (misalnya SUM/IF) belum didukung; pesan menyebut G/baris dan menyarankan simpan volume sebagai angka pada salinan file upload. Batas: 1.024 karakter, 256 token per rumus, 64 tingkat dan 20.000 operasi per workbook. Formula designator/header/error tetap ditolak. CSV/XLSX standar DESIGNATOR/QTY tetap hanya menerima angka langsung.
- `WorkOrderXlsxReader::read(..., includeFormulas: true)` dipakai hanya oleh parser BOQ untuk mengambil ekspresi. Default reader BIMA tetap tidak menyertakan ekspresi dan perilakunya tidak berubah.
- Harga snapshot tetap dari master paket. MATERIAL masuk `material_total`; JASA/INSTALASI/PENGUKURAN/DISMANTLE masuk `labor_total`. Tipe tidak dikenal ditolak, tidak menebak dari prefix kode. `total_price = volume × unit_price`, half-up dua desimal per item, grand_total jumlah nilai item; seluruh perhitungan memakai Brick Math, tanpa float.
- Header aktif menyimpan material_total + labor_total = grand_total. Harga/snapshot/revisi lama tidak ditimpa. Hash semantik lama tetap memakai items+grand_total+package_id agar penambahan breakdown tidak membuat revisi palsu pada file identik. Histori baru memuat breakdown; histori lama dipertahankan apa adanya.

## Revenue Overview

Menu **Revenue Overview** (`GET /revenue`) tersedia untuk seluruh role manusia aktif yang sudah dapat melihat WO: Superadmin/Koordinator/Admin/HD Area melihat Area; Admin/HD Branch, TL, Admin Material mengikuti user_branches aktif. Bot, akun nonaktif dan filter Branch/Region di luar scope ditolak backend. Tidak membuka izin update BOQ bagi role pembaca.

- Filter bulan **TGL ORDER** (kosong = seluruh periode/termasuk tanggal kosong), Region, Branch, paket, kelompok order, No WO/SID. Semua jenis pekerjaan dihitung; tidak membatasi diam-diam NEW INSTALL.
- Widget nilai material, jasa dan total; nilai BOQ kelompok PS/belum PS/Cancel; cakupan jumlah BOQ terhadap WO dalam filter. Klik kelompok membuka daftar yang difilter.
- PS persis `WorkOrder::isPsEligible` / scope resmi: progress CLOSE + salah satu 14 status BIMA. Close lapangan saja belum PS. Jika sumber PS cocok, aturan PS yang berlaku didahulukan. Cancel memakai progress CANCEL/operational_status CANCELLED; sisanya Belum PS.
- Nilai Cancel **termasuk Total BOQ** dan dipisahkan secara eksplisit. PS + Belum PS + Cancel = Total. Ini nilai BOQ yang dimonitor, belum pengakuan Revenue, invoice, tagihan atau pembayaran. Tidak mengasumsikan aturan pajak, profit/margin atau approval keuangan yang belum disediakan.
- Rekap Branch (urut nilai), distribusi nilai menurut bulan order, daftar WO dengan status sumber/progress, paket, material/jasa/total serta Detail Order/Preview BOQ berikon dan tooltip. Hanya header BOQ aktif dihitung sekali; revisi lama/items terarsip dan header soft-deleted tidak dihitung.
- Sum monetary lintas BOQ memakai decimal string di PHP agar SQLite dan MySQL tidak melakukan SUM float; nilainya dapat melebihi batas satu BOQ tanpa kehilangan sen. Metadata/query scoped dibaca per chunk 500, daftar berpagination 20; relasi di-eager-load tanpa membaca semua item/history untuk dashboard.
- White/dark, kartu radius 6px tanpa gradient/border aksen atas. Desktop memakai tabel, mobile kartu order; filter bertumpuk dan detail BOQ wide memakai horizontal scroll. Empty/zero, validasi filter, konfirmasi/loading upload dan pemuatan GET mengikuti mekanisme aplikasi. Tidak menampilkan demo revenue atau refresh realtime palsu.

## Migration dan environment

**`2026_10_25_000020_add_boq_value_breakdown.php`** menambah dua kolom decimal(18,2) pada `qe_boqs`: `material_total`, `labor_total`. Backfill berasal dari snapshot item aktif per header (bukan harga master baru). Validasi grand total dilakukan sebelum DDL dan saat transaksi/lock header; mismatch menghentikan migration untuk rekonsiliasi. Tidak mengubah grand_total, content/file hash, revision, timestamps, items/history atau WO. MySQL DDL tidak transaksional, sehingga kolom dibuat hanya jika belum ada agar retry setelah gangguan backfill aman. Down menghapus dua kolom turunan saja; nilai total/item/history tetap tersimpan.

```sh
php artisan migrate --path=database/migrations/2026_10_25_000020_add_boq_value_breakdown.php --force
npm run build
php artisan view:cache
```

**Tidak ada env, credential atau dependency baru.** Menggunakan Brick Math yang sudah tersedia. Migrasi 000017–000019 dari tahap sebelumnya tetap prasyarat. Backup dan prosedur deployment normal tetap berlaku. Tidak mereset database, user atau progress.

## File perubahan pada tahap ini

- Formula/import: `app/Services/Boq/BoqVolumeFormula.php`, `BoqBreakdown.php`, `BoqImportService.php`, `DompisBoqTemplate.php`, `app/Services/WorkOrderXlsxReader.php`.
- Model/migration: `app/Models/QeBoq.php`, `database/migrations/2026_10_25_000020_add_boq_value_breakdown.php`.
- Revenue: `app/Services/RevenueOverviewService.php`, `app/Http/Controllers/RevenueOverviewController.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`.
- BOQ controller: `app/Http/Controllers/BoqController.php` (preview breakdown dan pesan migration belum siap).
- UI: `resources/views/revenue/index.blade.php`, `filters.blade.php`, `orders.blade.php`, `mobile-orders.blade.php`; `resources/views/boqs/show.blade.php`, `upload.blade.php`; `resources/views/layouts/app.blade.php`, `resources/views/components/icon.blade.php`, `resources/css/app.css`.
- Tests baru: `tests/Unit/BoqVolumeFormulaTest.php`, `tests/Feature/BoqCalculationTest.php`, `BoqValueMigrationTest.php`, `RevenueOverviewTest.php`, `tests/Fakes/DompisBoqFile.php` (anonim, cache sengaja salah untuk membuktikan hitung ulang).
- Tests penyesuaian: `DompisBoqTemplateTest.php` kini hanya menolak formula tak didukung; `BoqCatalogMigrationTest.php` mengikuti dependensi migration000020; enam tes rollback BranchProgress/BranchRegion/ExcelSourceColumns/Locations/SingleBranch/MigrationRollback ditambah satu langkah.
- Docs: file ini, `docs/BOQ_TEMPLATES_KML.md`, `docs/JT_BOQ_IMPORT.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

## Hasil verifikasi aktual

- Full SQLite suite **252 tes / 24.815 assertions lulus**. Mencakup 12 tes baru: subset formula, hasil contoh, cache tidak dipercaya, tepat 11 item, volume/material/jasa/total, revisi/hash/idempotence, migration/backfill/retry/rollback/mismatch, role/filter/stage/coverage/pagination/assignment aktif dan nilai besar sampai sen.
- MariaDB terisolasi **29 tes / 16.978 assertions lulus**, max_allowed_packet 1 MiB; database sementara dihapus. Verifikasi import BOQ, contoh, katalog, Revenue, migration dan rollback.
- **18 tes JS lulus** (konfirmasi, JT, dashboard, KML); tidak ada JS baru untuk Revenue karena memakai GET server-rendered. Build Vite, kompilasi Blade, Pint dan diff check lulus.
- Pemeriksaan file asli read-only memakai harga master database aplikasi menghasilkan 11 item/material1010302/jasa770401/total1780703 dan hash sumber unchanged.
- **Migration000020 sudah dieksekusi pada database aplikasi 25 Oktober 2026**. Full-row digest sebelum/sesudah: **3.418 WO**, **1.448 harga**, **3 user**, items/history/KML tidak berubah; field header BOQ existing juga identik. Saat eksekusi ada 0 header BOQ dan belum ada import contoh ke order produksi.
- Belum ada verifikasi visual browser baru karena akses browser lokal ditolak pada sesi ini; tidak mengklaim upload/Revenue browser E2E. Pemeriksaan halaman/policy dilakukan melalui feature tests dan kompilasi Blade. Tidak mengklaim integrasi Google/Telegram aktif.

Perintah aktual:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-revenue-mysql-tests.php
php /private/tmp/olo-revenue-source-check.php
php /private/tmp/olo-apply-revenue-migration.php
node --test tests/JavaScript/*.test.js
npm run build
php artisan view:cache
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
git diff --check
```

Helper lokal MariaDB membuat/menghapus database acak terisolasi; helper migration menjalankan hanya path000020 di bawah lock inbound dan membandingkan digest data; tidak diperlukan pada deployment host lain.
