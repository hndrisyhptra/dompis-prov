# Dashboard Area dan aturan PS

Checklist implementasi — 18 Oktober 2026:

- [x] Dashboard khusus Superadmin, Koordinator Area, Admin Area dan HD Area; beranda/navigation sesuai role.
- [x] Rekap progress regional/cabang, subtotal/grand total, rekap jenis pekerjaan dan grafik PS bulanan dari MySQL.
- [x] PS hanya progress order CLOSE + STATUS BIMA CLOSE/COMPLETE; TGL PS BIMA untuk tanggal harian, tidak menebak tanggal kosong.
- [x] Metrik PS konsisten di dashboard Branch dan daftar order; status/progress/history lama dipertahankan.
- [x] Filter/drill-down terlindungi scope, mobile/light/dark, loading/empty/error state.
- [x] Feature/unit/MariaDB tests, build/lint/Blade, QA browser aplikasi utama dan manifest hasil aktual.

Inspeksi: Laravel 13, PHP 8.5, Blade/Tailwind 4/Vite 8. Role Area sudah tersedia, tetapi beranda sebelumnya menuju daftar WO dan belum ada dashboard Area. Semua field yang diperlukan sudah ada (`progress_category`, `bima_status`, `bima_ps_date`); tidak memerlukan migrasi atau backfill data. Perhitungan dilakukan saat query/report dan tidak mengubah snapshot BIMA, progress Branch, audit atau template asli. Paket/kredensial tidak perlu diubah.

Keputusan mengikuti arahan terbaru: CLOSE berarti kategori progress operasional web; BIMA CLOSE/COMPLETE berarti kolom N STATUS (`bima_status`), bukan STATUS FINAL, TASK BIMA atau formula PS HARI INI. PS total tidak mensyaratkan tanggal; PS harian/grafik memakai TGL PS kolom P yang valid. Semua ringkasan order akumulatif; pemilih bulan membatasi grafik saja. Dashboard Area default semua jenis mengikuti cakupan referensi; dashboard Branch tetap NEW INSTALL dengan pilihan semua jenis.


## Fitur yang selesai

- `/dashboard` menjadi beranda setelah login untuk empat role Area. Guest menuju login; role Branch/TL/Material tetap mendapat beranda cabang dan tidak dapat membuka dashboard Area. Menu mengikuti role. Koordinator tetap tidak mendapatkan hak sinkronisasi.
- Empat KPI dari MySQL: total order, PS terkonfirmasi, PS hari ini dan outstanding. Waktu data terakhir diterima ditampilkan sesuai scope, bukan mengklaim request Google baru.
- Rekap per regional/cabang, subtotal dan grand total; kategori Survey, Proses BIMA, CNDC, Kendala, JT, Lainnya, Cancel dan Close. Kolom PS hari ini merupakan subset Close, bukan kelompok tambahan yang dijumlahkan ke total.
- Proses BIMA menggabungkan OGP BIMA dan RESUBMIT AO. Lainnya menampung kategori sisanya serta order yang belum di-update; seluruh WO tetap masuk tepat satu kelompok. Cabang aktif dengan nol order tetap terlihat; order tanpa mapping, cabang nonaktif dan soft deleted yang mempunyai order tidak hilang dari rekap.
- Rekap lima jenis utama NEW INSTALL/MODIFY/DISCONNECT/SUSPEND/RESUME dan jenis tambahan yang benar-benar ada. Nilai kosong dikelompokkan sebagai TANPA JENIS, tidak diubah di database.
- Filter regional, cabang, jenis pekerjaan, layanan dan bulan grafik. Angka cabang/jenis/KPI menuju daftar order dengan scope/filter yang sama; filter tambahan terlihat dan dapat dihapus. Layanan dan regional tetap diterapkan saat memfilter/pindah antrean.
- Perlu perhatian: order outstanding dengan kendala OPEN, CLOSE menunggu BIMA, PS tanpa tanggal, PS bertanggal masa depan dan WO belum terpetakan. Kartu hanya muncul ketika kasus tersebut benar-benar ada. Kendala OPEN dihitung distinct WO, terpisah dari distribusi kategori progress KENDALA.
- Grafik garis PS bulanan, titik dapat dipilih dengan mouse/keyboard, data harian tersedia dalam tabel. Tanggal sebelum hari ini yang tidak memiliki PS bernilai 0; tanggal setelah hari ini belum dihitung pada grafik. Tidak mengisi angka dari contoh gambar.

## Aturan PS yang berlaku

PS terkonfirmasi: `progress_category = CLOSE` **dan** `bima_status IN (CLOSE, COMPLETE)`. Huruf besar/kecil dan spasi ASCII di tepi ditoleransi saat membaca tanpa mengubah nilai asli. Token lain, substring INCOMPLETE, COMPLETED, CLOSE ON DESK, STATUS FINAL CLOSE, TASK BIMA, timestamp laporan Branch atau tanggal verifikasi tidak menggantikan syarat tersebut.

- PS total menghitung kedua status yang cocok walaupun tanggal kosong.
- PS hari ini dan grafik memakai `bima_ps_date` (kolom P TGL PS), bukan waktu pull atau timestamp laporan Branch. Tanggal kosong tetap kosong; kasusnya muncul dalam antrean pemeriksaan. Tanggal masa depan ditandai dan tidak diplot sebagai perolehan yang sudah terjadi.
- Konfirmasi BIMA yang datang belakangan melalui pull langsung memengaruhi hasil query PS, menggunakan TGL PS sumber. Tidak menimpa kategori/catatan Branch, waktu CLOSE, revisi, audit atau status verifikasi.
- CLOSE menunggu BIMA tidak dihitung PS; karena sudah dilaporkan ditutup oleh Branch, ia berada dalam antrean menunggu konfirmasi, terpisah dari outstanding operasional. CANCEL/CLOSE ON DESK bukan outstanding dan bukan PS.
- Dashboard Branch, queue Complete/PS hari ini dan dashboard Area menggunakan aturan PS yang sama. Pembukaan kembali oleh Area mengeluarkan WO dari PS aktif; timeline tetap disimpan. Grafik merupakan kondisi terkini, bukan snapshot penilaian historis yang dikunci.
- Opsi lama `BRANCH_CLOSE_ON_DESK_COUNTS_AS_COMPLETE` dihapus dari `.env.example` dan config; apabila masih tertulis pada env lokal/deployment, nilainya tidak lagi mengubah aturan PS. `branch_completed_at` tetap menyimpan kejadian laporan selesai Branch dan bukan penentu tunggal KPI PS.

## Skema, data dan integrasi

Tidak ada migrasi baru, perubahan struktur tabel, backfill atau kebutuhan env/kredensial baru. Tidak menjalankan reset/migrate:fresh pada database aplikasi. `.env` asli tidak diubah. Workbook referensi dan file SQL sumber tidak disentuh. Pengujian menggunakan SQLite in-memory dan MariaDB sementara yang terpisah, lalu database MariaDB uji dihapus.

Dashboard membaca MySQL; tidak memanggil/menulis Google Sheets atau Telegram. Tidak ada perubahan hak akses ke integrasi, material atau LHPP. Tidak ada akun/order dummy ditambahkan untuk browser QA. Workspace tidak memiliki Git, sehingga tidak ada checkout/reset branch Bima.

## Hasil pengujian aktual

- Suite SQLite: **89 tests / 913 assertions lulus**; regresi akhir setelah perbaikan jumlah cabang/filter jenis kosong: **13 tests / 276 assertions lulus**.
- Suite MariaDB terisolasi: **87 tests / 902 assertions lulus**. Dua uji snapshot besar (`historical_size_batch`, `provided_bima_imports`) dikecualikan karena batas server `max_allowed_packet=1048576`; kedua uji tetap dijalankan/lulus pada SQLite. Tidak mengubah konfigurasi global server.
- Kasus baru: empat role Area/guest/role terlarang/nonaktif; root/login/menu; subtotal dan grand total; kelompok progress tanpa duplikasi; jenis pekerjaan; region/branch/service/month; bulan invalid; tanggal PS kosong/masa depan; dua status exact; status final tidak menggantikan status BIMA; normalisasi case/spasi; konfirmasi BIMA belakangan tanpa overwrite operasional; cross-branch filtering; archived/unmapped/jenis kosong; query PS konsisten antara Area/Branch/daftar order.
- Pint, Vite produksi dan kompilasi Blade lulus.
- Browser aplikasi utama sebagai superadmin: 9 WO, 0 PS terkonfirmasi, 1 WO berkendala. Filter BALNUS/MATARAM menghasilkan 3 WO; klik Survey menghasilkan tepat 1 WO dengan konteks regional/branch/bucket tetap terlihat. Tidak submit perubahan operasional.
- QA 1280/768/375px light/dark, filter GET dengan loading, tautan angka, tombol titik grafik/Enter, tabel horizontal lewat ArrowRight, data harian dan empty state. Desktop matrix 962px muat di kontainer 962px; tablet page 768px; mobile page 375px dengan tabel 960px di kontainer 341px. Scroll tabel berjalan 80px tanpa melebarkan halaman. Tema/viewport dikembalikan setelah QA. Hak role lain dan chart nonzero diuji feature tests; browser memakai data asli dengan PS nol.

Perintah aktual:

```sh
php artisan test --compact
php artisan test --compact --filter='AreaDashboard|PsEligibility|BranchAnalytics|BranchWorkflow'
php /private/tmp/olo-id-mysql-tests.php
vendor/bin/pint <file PHP pada manifest>
npm run build
php artisan view:cache
```

Runner MariaDB sementara hanya membaca konfigurasi koneksi lokal, membuat database uji unik, menjalankan suite dan menghapus database uji dalam blok finally. Tidak mencetak password atau credential. Runtime/paket aplikasi tidak berubah.

## File baru

- `app/Http/Controllers/AreaDashboardController.php`
- `app/Services/AreaAnalyticsService.php`
- `app/Support/ProgressSummary.php`
- `resources/views/area/dashboard.blade.php`
- `tests/Feature/AreaDashboardTest.php`
- `tests/Feature/PsEligibilityTest.php`
- `docs/AREA_DASHBOARD.md`
- Screenshot: `docs/screenshots/area-dashboard-desktop-light.png`, `area-dashboard-desktop-dark.png`, `area-dashboard-tablet-dark.png`, `area-dashboard-mobile-dark.png`, `area-dashboard-mobile-light.png`, `area-dashboard-overview-light.png`.

## File diubah

- `routes/web.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/AuthController.php`
- `app/Models/WorkOrder.php`, `app/Enums/ProgressCategory.php`
- `app/Services/BranchOrderScope.php`, `app/Services/BranchAnalyticsService.php`, `app/Http/Controllers/WorkOrderController.php`
- `resources/views/layouts/app.blade.php`, `resources/views/branch/dashboard.blade.php`
- `resources/views/work-orders/index.blade.php`, `resources/views/work-orders/show.blade.php`
- `resources/css/app.css`, `resources/js/app.js`, `config/olo.php`, `.env.example`
- `tests/Feature/BranchAnalyticsTest.php`, `tests/Feature/BranchWorkflowTest.php`, `tests/Feature/UsernameAndThemeTest.php`, `tests/Feature/ExampleTest.php`
- `AGENTS.md`, `README.md`, `docs/BRANCH_WORKFLOW.md`, `docs/IMPLEMENTATION.md`
- `public/build/manifest.json` dan aset hasil build Vite.

Tidak ada input baru yang menghalangi penggunaan dashboard. Interpretasi field/date yang dipakai dicatat eksplisit di atas; apabila definisi bisnis nantinya memakai STATUS FINAL BIMA sebagai status order, itu merupakan kebijakan berbeda dan tidak disamakan diam-diam dengan progress operasional web.
