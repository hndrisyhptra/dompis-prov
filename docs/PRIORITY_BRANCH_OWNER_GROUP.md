# Branch per Owner Group pada prioritas — 27 Oktober 2026

## Checklist

- [x] Inspect rekap Owner Group, filter/scope prioritas dan komponen tautan jumlah.
- [x] Tambahkan pivot Branch × kelompok Owner Group pada tab Owner Group.
- [x] Pertahankan total penuh, scope, deduplikasi, PS dan tautan angka termasuk belum ditugaskan.
- [x] Uji agregasi, privasi/filter, mapping, tautan, pagination dan query budget; build/dokumentasi.

Screenshot menjadi referensi susunan, bukan sumber angka atau aturan mapping baru. Kelompok mengikuti Master Data yang berlaku; kelompok tanpa data disembunyikan seperti rekap prioritas sebelumnya. Sumber kosong/tidak dikenal/nonaktif tetap masuk Belum dipetakan. Tidak mengarang mapping DISTRICT AREA 2 dari screenshot.

## Tampilan dan perhitungan

Tabel **Branch per Owner Group** berada di atas Status BIMA × Owner Group dan Rincian Task per Owner Group pada tab Owner Group. Baris Branch diurutkan alfabetis; kolom kelompok dinamis mengikuti kelompok yang memiliki data pada filter aktif. Tersedia total per baris, total kolom dan Grand Total. Semua baris tampil utuh, font/padding ringkas, label membungkus dan tanpa scroll internal untuk screenshot, memakai tampilan white/dark yang sama. Tidak menambah download pada tabel rekap.

Controller menggunakan `OwnerGroupAnalyticsService::summary` yang sudah dipakai dashboard, dengan builder `PriorityOrderQuery::filtered` yang sama. Data adalah seluruh hasil filter, bukan hanya 25 order pada halaman rincian. Satu WO dihitung sekali walaupun memiliki bookmark Area dan lokal Branch. PS tetap dikecualikan; privasi lokal Branch, filter sumber, status monitoring, pencarian dan status/task BIMA tetap. Rekap baru hanya dimuat di tab Owner Group.

Mapping mengikuti lookup aktif/persis `mapping_ownergrup`. Tidak menebak dari substring atau akhiran task. Unknown, kosong dan mapping nonaktif masuk kolom Belum dipetakan; order tanpa assignment muncul pada baris Belum ditugaskan, sehingga tidak hilang dari Grand Total.

Setiap angka termasuk nol, total baris, total kolom dan Grand Total membuka List Order Prioritas dengan filter yang tepat. Pemilihan Branch/Owner Group ditambahkan pada filter aktif; `tab` tidak dikirim ke List. Untuk baris tanpa assignment digunakan `unassigned=1`, bukan menghapus filter Branch sehingga membuka semua order. Request prioritas kini memvalidasi filter boolean tersebut; Area mempunyai checkbox Hanya belum ditugaskan. Branch tertentu dan unassigned tidak boleh dipilih bersamaan. Filter mengikuti pagination, tab, List dan ekspor List yang sudah ada; scope Branch tetap membatasi hasil.

## Pengujian dan batas

- Suite PHP penuh: **327 tes / 27.078 assertions lulus**.
- Fokus monitoring, prioritas, mapping dan ekspor: **38 tes / 1.603 assertions lulus**.
- MySQL/MariaDB sementara: **65 tes / 2.452 assertions lulus**. Database pengujian terpisah dihapus setelah selesai; database aplikasi tidak diubah.
- Tes baru mencakup total melampaui pagination, nol, unassigned, rekonsiliasi baris/kolom, semua tautan angka vs jumlah List, kombinasi filter, mapping case-sensitive/nonaktif/berubah, PS, privasi, deduplikasi dan perpindahan assignment.
- Query budget pada 5/30 order tetap sama, maksimal 12 query pada halaman Owner Group; tab lain tidak memuat rekap Branch baru.
- **28 tes JavaScript lulus**, build, kompilasi Blade, Pint dan pemeriksaan whitespace lulus. Build diselesaikan sebelum suite fitur/penuh.

Tidak ada migration, env, dependency, kredensial atau perubahan data aplikasi. Tidak mengubah sumber/progress/kendala/bookmark maupun definisi PS. Tidak ada input yang menghalangi penggunaan tabel. Nama kelompok tambahan dari screenshot belum menjadi mapping baru; kelola aturan yang diketahui melalui Master Data. Verifikasi melalui rendering Blade/DOM dan pengujian; preview HTTP/browser tidak dicoba ulang karena permintaan izinnya sebelumnya ditolak.

## Berkas diubah

- `app/Http/Controllers/PriorityOrderController.php`
- `app/Http/Requests/PriorityOrderListRequest.php`
- `resources/views/priorities/monitoring-branch-owner-groups.blade.php` (baru)
- `resources/views/priorities/monitoring-owner-groups.blade.php`
- `resources/views/priorities/filters.blade.php`
- `resources/css/app.css`
- `tests/Feature/PriorityBranchOwnerGroupTest.php` (baru)
- `tests/Feature/PriorityMonitoringTest.php`
- `docs/PRIORITY_BRANCH_OWNER_GROUP.md` (baru), `docs/PRIORITY_MONITORING.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Progress workspace sebelumnya tetap dipertahankan.
