# Mapping Order pada dashboard — 27 Oktober 2026

## Checklist

- [x] Inspect sumber klasifikasi WO, scope Region/Branch, modal daftar dan konvensi tabel.
- [x] Agregasi Mapping Order per Region pada tab Progress Area.
- [x] Rekap Branch dengan rincian STO collapsible dalam scope akun.
- [x] Filter daftar/modal/ekspor yang konsisten untuk mapping dan STO termasuk kosong.
- [x] Uji klasifikasi vs KPI PS, scope, total, drilldown, filter, query budget; build/dokumentasi.

Perhitungan memakai klasifikasi `work_orders.mapping_order` yang telah dipelihara import/master, bukan menebak kategori dari status/progress. PS pada tabel mapping adalah klasifikasi master; definisi KPI PS harian tidak berubah. Region berasal dari relasi Branch ke master Region, bukan teks regional mentah BIMA. Nilai yang belum terpetakan tetap masuk total.

## Hasil

- Dashboard Area, tab **Progress**, menampilkan **Mapping Order Region** sesudah matrix progress. Baris diurutkan menurut nama Region master dari Branch; kolom **PS, CANCLWORK, OPEN, STUCK, Grand Total**, ditambah **Belum dipetakan** bila ada klasifikasi kosong/tidak dikenal. Order tanpa Branch dan Branch tanpa Region masuk Tanpa Region, tetap dihitung sekali.
- Dashboard Branch menampilkan **Mapping Order Branch / STO**. Setiap Branch mempunyai subtotal dan rincian STO collapsible, default tertutup dengan Buka/Tutup semua. Akun dengan beberapa Branch tetap mendapatkan subtotal terpisah, sehingga STO bernama sama pada Branch berbeda tidak tercampur. STO sumber yang belum ada pada master tetap terlihat; NULL/kosong/spasi tepi masuk STO belum terisi. Angka 0 sebagai kode STO tetap teks yang valid. Huruf besar/kecil STO tidak disamakan.
- Rekap mengikuti filter yang sudah berlaku. Area default semua jenis pekerjaan, Branch default NEW INSTALL. Filter bulan Area/hari Branch hanya mengatur grafik PS, bukan membatasi rekap mapping yang akumulatif. Tab Owner Group Area tidak memuat rekap baru.
- Grand Total mencakup semua kategori termasuk order belum dipetakan. PS pada rekap berasal dari `work_orders.mapping_order` tersimpan, bukan dari `psEligible`; kategori PS dan KPI PS harian dapat berbeda. Tidak menyamakan akhiran COMPLETE/CANCLWORK atau progres CLOSE sebagai kategori mapping baru. Import/master tetap memelihara klasifikasi seperti sebelumnya; halaman ini tidak melakukan backfill/mutasi.
- Tabel memakai white/dark, border halus, rounded-md, header/identitas sticky pada panel, angka rata kanan dan sorotan lembut pada OPEN/STUCK. Tidak menambah download di dashboard.

## Query dan drilldown

`MappingOrderAnalyticsService` memakai builder scope/filter yang sama dari Area/BranchAnalyticsService. Klasifikasi dan kunci STO dihitung pada query baris terfilter; agregasi luar mengelompokkan kolom hasil, sehingga kompatibel MariaDB `ONLY_FULL_GROUP_BY` tanpa melonggarkan SQL mode. Master Branch/Region dimuat dalam batch, tanpa query per baris; tidak memuat seluruh WO untuk dihitung di PHP.

Semua angka termasuk nol, subtotal dan Grand Total membuka modal daftar WO yang sudah ada, dengan Detail langsung. Tautan penuh tetap tersedia tanpa JavaScript; loading/error/retry/empty/pagination mengikuti modal yang sudah diuji.

Request daftar WO menambah filter tervalidasi `mapping_order` (empat kategori/unmapped), `sto` dan `sto_missing`. Filter mapping/normalisasi STO memakai ekspresi yang sama dengan agregasi. Kunci STO memakai trim spasi tepi dan perbandingan persis, tanpa menebak master STO atau mengubah sumber. `without_region` menangani kelompok Tanpa Region. Filter disimpan pada antrean, form pencarian, pagination, modal dan download Work Order; konteks ekspor menyebut mapping/STO. Tidak ada penambahan hak akses atau jalur Branch tanpa scope.

Anggaran service dashboard bertambah maksimal 3 query tetap (agregasi + lookup Branch/Region), dengan batas **Area 10 / Branch 11**, tidak tumbuh dengan volume 20→1.000 order. Lookup Region dapat dilewati bila tidak dibutuhkan oleh hasil. Worker, sinkronisasi dan database aplikasi tidak diubah.

## Verifikasi

- Suite PHP penuh versi final: **333 tes / 27.860 assertions lulus**.
- Fokus dashboard/mapping/ekspor/performance: **27 tes / 3.071 assertions lulus**.
- MySQL/MariaDB sementara, termasuk Master Mapping dan referensi aturan: **38 tes / 3.862 assertions lulus** setelah perbaikan query `ONLY_FULL_GROUP_BY`; database pengujian terpisah dihapus setelah selesai.
- **28 tes JavaScript lulus**, mencakup modal/pagination/fallback/error/retry serta kontrol collapsible terisolasi.
- Build frontend, kompilasi Blade, Pint dan whitespace lulus. Suite penuh diulang setelah perbaikan query dan lulus pada versi final.
- Cakupan baru: kategori mapping PS vs KPI PS, empat kategori dan unknown/lowercase, total Region/Branch/STO, source regional yang berbeda dari master, tanpa Region/assignment, STO NULL/kosong/0/spasi/case, beberapa Branch dengan STO sama, semua tautan angka vs modal/list, filter jenis/layanan/Region/Branch, pengaruh periode, privasi, pencabutan assignment, role Area/Branch, ekspor scoped dan filter yang menetap pada navigasi.

Tidak ada migration, env, dependency atau kredensial baru. Tidak ada perubahan pada sumber/progress/kendala/perangkat/audit/prioritas atau template asli; tidak menjalankan ulang import. Tidak ada input bisnis yang diperlukan untuk menggunakan fitur. Preview HTTP/browser tidak dicoba ulang karena permintaan izinnya sebelumnya ditolak; verifikasi memakai rendering Blade/DOM dan pengujian otomatis. Tidak mengklaim Google/Telegram tersambung.

## Berkas diubah

- `app/Services/MappingOrderAnalyticsService.php` (baru)
- `app/Services/AreaAnalyticsService.php`
- `app/Services/BranchAnalyticsService.php`
- `app/Services/WorkOrderListQuery.php`
- `app/Services/Exports/OrderExportService.php`
- `app/Http/Requests/WorkOrderListRequest.php`
- `resources/views/dashboard/mapping-orders.blade.php` (baru)
- `resources/views/area/dashboard.blade.php`
- `resources/views/branch/dashboard.blade.php`
- `resources/views/work-orders/index.blade.php`
- `resources/css/app.css`
- `tests/Feature/DashboardMappingOrderTest.php` (baru)
- `tests/Feature/OrderExportTest.php`
- `tests/Feature/PerformanceBudgetTest.php`
- `docs/DASHBOARD_MAPPING_ORDER.md` (baru)
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Progress workspace sebelumnya tetap dipertahankan.

## Rekap jenis transaksi dan Ach PS — 27 Oktober 2026

Rekap Mapping Order Area kini juga menyediakan dimensi jenis transaksi untuk tabel OPEN/STUCK pada Progress, memakai query/lookup yang sama. Card PS harian dipindahkan ke tab Ach PS; aturan dan hitungan tetap. Perubahan lanjutan serta manifest/hasil: [OPEN_TRANSACTIONS_ACH_PS.md](OPEN_TRANSACTIONS_ACH_PS.md).
