# Jenis Pekerjaan ringkas dan Mapping Aging Order — 27 Oktober 2026

## Checklist

- [x] Inspect rekap mapping/pekerjaan, umur kalender WIB dan query drilldown.
- [x] Ganti judul menjadi OPEN & STUCK per Jenis Pekerjaan, tabel padat tanpa scroll internal.
- [x] Mapping Aging Order untuk OPEN, subtotal Region dan Branch, umur dari TGL ORDER saat ini.
- [x] Kategori/total/clickthrough persis, tanggal kosong terpisah, scope/filter tetap.
- [x] Uji batas umur, data stale, total, filter, privasi, query budget; build/dokumentasi.

Mengikuti screenshot, tabel aging menghitung mapping_order OPEN. Batas umur mengikuti rumus pemilik yang sudah berlaku (>28, >14, >7, selain itu 0–7), bukan nilai kategori_umur hasil upload yang mungkin stale. Tidak menulis ulang kolom sumber, progress atau tanggal. Tanggal kosong tetap dilaporkan agar tidak hilang dari total.

## Tampilan dan cakupan

- Tabel lama menjadi **OPEN & STUCK per Jenis Pekerjaan**. Pengelompokan dan hitungan pekerjaan tetap mengikuti data BIMA serta filter dashboard.
- Kedua tabel memakai font, padding dan tombol ringkas, lebar kolom mengikuti panel dan teks panjang dapat membungkus. Tidak ada batas tinggi atau scroll di dalam panel; Buka semua menampilkan seluruh Branch dan halaman bertambah panjang sesuai jumlah baris. Tidak menjamin semua data muat dalam satu layar perangkat.
- **Mapping Aging Order** tersedia pada tab **Progress** dashboard Area, untuk order dengan klasifikasi `mapping_order=OPEN`, mengikuti contoh pemilik. Urutan: subtotal OPEN, Region, rincian Branch yang dapat dibuka/tutup, lalu Grand Total.
- Region tanpa master dan order belum ditugaskan tetap diperhitungkan. Region default tertutup; Buka semua/Tutup semua bekerja hanya pada tabel terkait. Empty state muncul bila tidak ada OPEN dalam filter.
- Tidak menambahkan tombol download pada dashboard. Tautan angka membuka modal order dengan aksi Detail; daftar Work Order dan download yang sudah ada mempertahankan filter umur dan scope akses.

## Perhitungan umur

Umur memakai tanggal kalender `tgl_order` sampai tanggal acuan hari ini di Asia/Jakarta. Label mengikuti kontrak pemilik:

| Label | Batas umur |
| --- | --- |
| 0-7 HARI | Sampai 7 hari |
| 7-14 HARI | Lebih dari 7 sampai 14 hari |
| 14-28 HARI | Lebih dari 14 sampai 28 hari |
| > 28 HARI | Lebih dari 28 hari |
| Tanggal belum terisi | `tgl_order` kosong; kolom muncul hanya jika ada datanya |

Tanggal order di masa depan menghasilkan umur negatif. Sesuai rumus pemilik yang telah berlaku, nilai tersebut tetap berada pada cabang selain >7/>14/>28 (label 0-7 HARI); tahap ini tidak memperkenalkan aturan koreksi tanggal atau kategori baru. Tanggal sumber tidak diubah.

Rekap tidak memakai `umur_hari`/`kategori_umur` tersimpan yang dapat tertinggal sejak upload. Setiap klik kategori membawa `aging_bucket` dan `aging_on` yang divalidasi berpasangan; daftar/modal/export menghitung dengan tanggal acuan yang sama sehingga perpindahan tengah malam tidak menggeser isi pilihan. Total seluruh umur tidak memerlukan filter umur. Export tetap memuat nilai fisik kolom database, termasuk nilai umur tersimpan; hanya pemilihan barisnya menggunakan perhitungan umur langsung.

## Query, akses dan integritas

Dimensi umur ditambahkan pada agregasi Mapping Order yang sudah ada, tanpa query tambahan per Region/Branch. Hasil tetap kompatibel dengan `ONLY_FULL_GROUP_BY`; subtotal dan laporan sebelumnya menjumlahkan pecahan agregat yang sama. Pengujian anggaran query dan MariaDB lulus.

Drilldown, daftar, pagination dan export memakai query Work Order yang sama dengan `visibleTo`/scope Branch. Parameter umur tidak memperluas akses. Filter dashboard yang relevan tetap diterapkan; bulan grafik PS tidak mengubah periode laporan kumulatif ini. Definisi PS dan laporan STUCK tidak berubah. Tidak menulis sumber BIMA, progress, kendala, perangkat, log atau audit hanya karena melihat rekap.

## Validasi aktual

- PHPUnit penuh: **345 tes, 30.033 assertions**, lulus.
- MariaDB terisolasi: **50 tes, 6.035 assertions**, lulus; database tes sementara dihapus setelah selesai.
- JavaScript: **28 tes**, lulus.
- Build Vite, kompilasi Blade, Pint dan `git diff --check`: lulus.
- Kasus khusus: batas 7/8, 14/15, 28/29 hari; tanggal kosong/masa depan; umur tersimpan salah; total/angka nol; filter Region/Branch/pekerjaan; order tanpa Region/assignment; konsistensi modal/list/export; tanggal acuan melewati tengah malam; privasi Branch; sumber tidak termutasi.

Verifikasi visual melalui browser/HTTP tidak dilakukan karena izin akses sebelumnya ditolak. Tidak ada klaim screenshot atau pengujian visual pada perangkat nyata.

## Migrasi, environment dan input

Tidak ada migration, perubahan `.env`, dependensi atau kredensial baru. Tidak ada input bisnis tambahan yang menghalangi penggunaan fitur. File contoh pemilik dan perubahan workspace sebelumnya dipertahankan.

## Berkas tahap ini

- `app/Support/OrderAging.php` (baru)
- `app/Services/MappingOrderAnalyticsService.php`
- `app/Services/WorkOrderListQuery.php`
- `app/Http/Requests/WorkOrderListRequest.php`
- `app/Services/Exports/OrderExportService.php`
- `resources/views/area/open-transactions.blade.php`
- `resources/views/area/mapping-aging.blade.php` (baru)
- `resources/views/area/dashboard.blade.php`
- `resources/views/work-orders/index.blade.php`
- `resources/css/app.css`
- `tests/Feature/AreaOrderAgingTest.php` (baru)
- `tests/Feature/AreaOpenTransactionsTest.php`
- `tests/Feature/OrderExportTest.php`
- `docs/OPEN_JOB_TYPES_AGING.md` (baru)
- `docs/OPEN_TRANSACTIONS_ACH_PS.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`
