# Tabel dashboard dan unduhan Excel

## Checklist dan dependensi

- [x] Inspect Laravel/Blade, layanan analytics, filter, otorisasi, tabel dan pengujian existing.
- [x] Satukan validasi filter dashboard dan query daftar order agar unduhan memakai cakupan yang sama.
- [x] Ekspor XLSX asli untuk progress, owner group, bulan order, jenis pekerjaan, PS harian, antrean Branch dan daftar modal.
- [x] Rapikan tabel, hierarki header/total, baris Region, angka, sticky identity dan toolbar responsif/light/dark.
- [x] Uji hasil XLSX/filter/otorisasi/keamanan teks, tes regresi, build dan dokumentasi hasil aktual.

## Keputusan implementasi

Laravel 13 / Blade dan layanan analytics yang ada dipakai kembali. Tidak ada migration, perubahan data operasional, environment, kredensial atau dependency baru. Ekspor runtime dibuat PHP memakai ZipArchive/XML yang sudah tersedia untuk XLSX di aplikasi, tanpa ketergantungan runtime desktop/Node. Pedoman spreadsheet dipakai untuk tampilan dan pemeriksaan format/angka/tanggal hasil ekspor.

Unduhan satu tabel per file, seluruh baris sesuai filter (termasuk Branch di Region yang sedang terlipat). Label filter, waktu WIB dan pengertian periode dicantumkan di file. Bulan grafik Area hanya memfilter seri PS harian, pilihan hari Branch hanya mengatur seri harian; rekap lainnya tetap akumulatif. Ekspor antrean Branch mengambil seluruh outstanding sesuai filter, sementara dashboard menampilkan enam prioritas teratas. Tabel modal mengekspor seluruh hasil pilihan, bukan hanya halaman aktif. Semua endpoint menerapkan scope akun dan validasi yang sama dengan UI. Teks identitas tetap teks, angka/tanggal bertipe native Excel, tidak mengeksekusi teks sebagai formula.

## Perilaku UI

Setiap panel tabel mempunyai tombol ikon **Download Excel** dengan tooltip/deskripsi aksesibel. Browser menampilkan Menyiapkan saat mengambil file, mencegah klik ganda, lalu memberi hasil siap diunduh atau error yang dapat dicoba ulang. Login kedaluwarsa/response HTML tidak diunduh sebagai Excel; rate limit mempunyai pesan tersendiri. Tautan tetap dapat dipakai tanpa JavaScript.

Header grup On Going/Complete tetap mengikuti kategori yang sudah disepakati. Header tabel melekat pada batas atas area scroll; identitas Region/Branch/No WO melekat di kiri. Angka rata kanan/tabular, zebra ringan, hover/fokus, pemisah kolom total dan Grand Total konsisten. Tabel panjang mempunyai scroll dalam panel, toolbar membungkus pada mobile, seluruh warna memakai token light/dark. Dua tabel Region mempunyai Buka semua/Tutup semua yang hanya memengaruhi tabel terkait; kondisi individual tetap tersinkron.

Grafik cohort tetap grafik garis; data bulanan pada accordion tetap tersedia. Aksi klik angka/modal/detail dan rumus KPI tidak diubah. Pilihan filter yang belum ditekan Terapkan belum menjadi filter unduhan. Download membaca data terkini pada waktu unduhan; pembaruan order sesudah dashboard dibuka dapat mengubah angka, waktu snapshot file dicantumkan dalam WIB.

## Cakupan file dan endpoint

| POV / tabel | Route | Isi |
|---|---|---|
| Area / Progress | area.dashboard.export, table=progress | Region, Branch, 11 metrik, Grand Total |
| Area / Owner Group | area.dashboard.export, table=owners | Semua kelompok + Belum dipetakan, Grand Total |
| Area / Bulan order | area.dashboard.export, table=months | Total WO, Progress CLOSE, Sudah PS, Outstanding per cohort |
| Area / Jenis pekerjaan | area.dashboard.export, table=types | Lima metrik mengikuti jenis pekerjaan yang tampil |
| Area / PS harian | area.dashboard.export, table=daily | Semua tanggal dalam bulan grafik, jumlah PS, penanda hari mendatang |
| Branch / Owner Group, Bulan, Harian | branch.dashboard.export, table=owners/months/daily | Rekap hanya assignment aktif, NEW INSTALL default dan 7/14/30 hari sesuai pilihan |
| Branch / Tindak lanjut | branch.dashboard.export, table=attention | Seluruh outstanding sesuai filter; tidak dibatasi enam baris UI |
| Modal / Daftar order | dashboard.orders.export | Seluruh hasil query pilihan; tidak dibatasi pagination |

URL GET `/dashboard/exports/{table}`, `/branch/dashboard/exports/{table}` dan `/dashboard/orders/export` memerlukan login aktif. Route Area juga memerlukan gate Area; Branch/TL/Admin Material hanya mendapat data assignment aktif, Bot/nonaktif ditolak. Memasukkan Branch lain pada parameter ditolak403, bukan menambah akses. Unduhan menerima filter yang tervalidasi sama dengan halaman: Region/Branch/layanan/jenis/bulan Area; Branch/jenis/hari Branch; queue/bucket/owner_group/order_month/ps_date/status/q dan dimensi unassigned/without_region pada modal. Alias job_type tetap didukung. Table di luar daftar ditolak404. Batas20 unduhan/menit memakai throttle yang ada.

XLSX adalah paket OpenXML asli, satu worksheet per unduhan, dengan AutoFilter, header/identitas frozen, lebar kolom, tanggal eksplisit, jumlah integer dan Grand Total. Ekspor progress/Owner Group diratakan per Branch dengan kolom Region lalu satu Grand Total keseluruhan, tanpa mengulang subtotal Region sehingga tidak menjumlah detail+subtotal dua kali. Hari mendatang mempunyai sel jumlah kosong native, hari berjalan tanpa PS tetap0. No WO/STO/nama/status selalu string termasuk nol depan dan awalan `=`, `+`, `-`, `@`; tidak membuat formula dari input. Karakter kontrol XML ilegal dibuang. Tidak memuat JSON sumber privat, token integrasi atau data bookmark lokal Branch.

Dokumen dibuat melalui stream XML dan lazy query250 baris untuk detail. File sementara privat dihapus setelah response terkirim; gagal membuat/audit juga membersihkan file. Response private/no-store/nosniff. Generate sukses dicatat sebagai `dashboard.exported` dalam audit, hanya actor, jenis tabel, nama filter dan hash filter; isi pencarian/pelanggan tidak dimasukkan audit.

## Manifest file

- Backend baru: `app/Http/Controllers/DashboardExportController.php`; `app/Http/Requests/{DashboardRequest,WorkOrderListRequest}.php`; `app/Services/WorkOrderListQuery.php`; `app/Services/Exports/{DashboardExportService,DashboardXlsxWriter}.php`.
- Backend berubah: `app/Http/Controllers/{AreaDashboardController,BranchDashboardController,WorkOrderController}.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`. Validasi dan filter daftar diekstrak untuk dipakai bersama, tidak mengganti layanan analytics/definisi PS.
- UI baru: `resources/views/components/dashboard-export.blade.php`, `resources/js/dashboard-exports.js`.
- UI berubah: `resources/views/area/{dashboard,progress-matrix}.blade.php`, `resources/views/branch/dashboard.blade.php`, `resources/views/owner-groups/summary.blade.php`, `resources/views/work-orders/order-month-summary.blade.php`, `resources/views/dashboard/orders.blade.php`, `resources/views/components/icon.blade.php`, `resources/css/app.css`, `resources/js/{app,dashboard-tables}.js`.
- Tes baru: `tests/Feature/DashboardExportTest.php`, `tests/Unit/DashboardXlsxWriterTest.php`, `tests/JavaScript/dashboard-exports.test.js`. Perubahan tes JS `tests/JavaScript/dashboard-tables.test.js` untuk Buka semua/Tutup semua.
- Dokumen: file ini serta addendum AGENTS.md, SKILLS.md dan PRD_OLO_Command_Center_v2.md.

## Verifikasi dan batas

Hasil aktual 26 Oktober 2026:

- Suite PHPUnit penuh: **274 tes lulus**. Meliputi modul existing serta akses/filter/role/export, tabel modal seluruh halaman, leading-zero/formula injection dan native XML dari generator1.001 baris.
- MariaDB10.4 pada database sementara terpisah: **18 tes / 2.324 assertion lulus**; database sementara dihapus setelah pengujian, tanpa mengubah database aplikasi.
- JavaScript: **26 tes lulus**, termasuk Buka semua per tabel, unduhan sukses/gagal/rate limit/login kedaluwarsa, retry, pencegahan klik ganda, filter dan pembersihan URL file browser.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check` lulus.
- Pembacaan independen XLSX memverifikasi native number/date, nol vs null mendatang, format tanggal, freeze B7 dan AutoFilter yang mengecualikan Grand Total. Preview file progress/harian berhasil diimport, dirender dan diperiksa, termasuk perbaikan format tanggal menjadi dd mmm yyyy.

Test workbook memakai data sintetis, bukan salinan workbook sumber pemilik atau ekspor pelanggan produksi. Preview workbook diperiksa melalui import/render Artifact Tool dan pembacaan native independen; tidak mengklaim uji klik/save di Microsoft Excel desktop. Screenshot dashboard/browser tidak dijalankan karena izin preview lokal sebelumnya ditolak. Struktur Blade, tautan, data/filter/akses, JS state, native XLSX dan build diuji otomatis; UAT visual browser tetap perlu.

Tidak ada migration/env/kredensial baru. Tidak mengubah WO, material, BOQ, assignment atau progress; audit unduhan adalah satu-satunya penulisan fitur ini. Tidak menghubungi Google/Telegram, tidak mengubah template BIMA/LHPP/BOQ asli. Tidak ada keputusan bisnis/input baru yang menghalangi fitur ini.
