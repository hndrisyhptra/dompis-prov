# Dashboard dan riwayat sinkronisasi — 25 Oktober 2026

Checklist: inspeksi struktur/controller/service/scope yang ada → matrix kategori/Region dan agregat Owner Group → riwayat terbaru/halaman penuh → modal dengan pagination dan detail → tests PHP/JS, build, dokumentasi.

Laravel 13 dengan Blade/JS/CSS yang sudah ada, tanpa dependency, migration atau env baru. Data sumber, progress dan riwayat tidak dimigrasikan atau diubah oleh tampilan ini. New Order Hari ini pada matrix berarti TGL ORDER hari ini dalam WIB; berbeda dari widget New Order yang memakai tujuh status Review TSQ. Complete adalah judul kelompok Cancel/CLOSE, bukan perubahan definisi PS.

- [x] Periksa implementasi dashboard/scope/filter/riwayat.
- [x] Implementasikan agregat, matrix dan halaman riwayat.
- [x] Implementasikan modal/kolaps dan verifikasi interaksi.
- [x] Jalankan tes dan catat hasil aktual.

## Perilaku akhir

Sync Center menampilkan lima job terbaru dengan tombol **Detail riwayat**. `/sync/history` menampilkan semua job, pagination 20 per halaman, dengan izin Sync Center yang sama. Membuka riwayat tidak menjalankan ulang job.

Matrix Area memiliki susunan berikut:

- **On Going:** Survey, OGP BIMA (termasuk RESUBMIT AO), Instalasi, Kendala, JT, Lainnya.
- **Complete:** Cancel, CLOSE.
- **New Order Hari ini**, **PS Hari ini**, **Total Order**.

CNDC masuk Lainnya; URL lama `bucket=cndc` tetap dapat dipakai. Kendala mencakup KENDALA/HOLD/BUTUH QE/PERIZINAN. Delapan kategori progress saling eksklusif dan menjumlah ke Total Order. Dua kolom harian merupakan dimensi tambahan, tidak dijumlahkan lagi ke Total Order. Label Complete tidak berarti semua order di dalamnya adalah PS; PS tetap mengikuti progress CLOSE, 14 Status BIMA, dan TGL PS untuk tanggal harian.

Matrix progress dan Distribusi Owner Group menampilkan baris ringkasan Region terlebih dahulu. Tombol Region membuka/menutup Branch melalui mouse atau Enter/Space, dengan `aria-expanded` dan `aria-controls`. Jumlah Region ditampilkan pada baris ringkasan atas; baris Grand Total per Region di bawah rincian Branch dihapus sesuai koreksi pemilik. Order tanpa Region/Branch tetap memiliki kelompok dan filter tersendiri; tidak hilang dari total.

Angka tabel (termasuk nol, subtotal, Grand Total, rekap jenis pekerjaan, cohort bulan order, serta tabel PS harian Area/Branch) membuka modal. Dialog menampilkan No WO, Branch/STO, Nama OLO, Status Progress, Status BIMA dan tombol **Detail** langsung ke halaman order. Daftar dipaginasi 25 per halaman; **Buka di Work Order** membuka daftar penuh dengan filter yang sama. Klik dengan Ctrl/Command tetap membuka link biasa.

Modal menggunakan query/validasi/scope Work Order yang sama dengan daftar penuh melalui `modal=1`; tidak menyediakan API lintas Branch. Filter baru `without_region`, `unassigned` dan `ps_date` berdiri sendiri sehingga bisa digabung dengan progress/owner/date/Branch. Cakupan dan filter dipertahankan pada pagination serta tautan daftar penuh. Fragment diberi cache private/no-store, isi di-escape oleh Blade; JSON sumber, token bookmark dan riwayat privat tidak dikirim dalam modal.

Loading, hasil kosong, error jaringan/sesi, tombol Coba lagi, pembatalan request sebelumnya dan pemulihan fokus saat menutup modal ditangani. Browser tanpa dukungan dialog tetap mengikuti link Work Order. Styling memakai token light/dark yang ada, radius 6px dan tabel horizontal scroll; tanpa gradient atau dependency baru.

## File berubah pada tahap ini

- Controller/routes: `app/Http/Controllers/SyncController.php`, `app/Http/Controllers/WorkOrderController.php`, `routes/web.php`.
- Agregasi/filter: `app/Services/AreaAnalyticsService.php`, `app/Services/OwnerGroupAnalyticsService.php`, `app/Services/BranchOrderScope.php`, `app/Support/ProgressSummary.php`.
- View baru: `resources/views/sync/all-history.blade.php`, `resources/views/area/progress-matrix.blade.php`, `resources/views/components/order-count.blade.php`, `resources/views/dashboard/order-dialog.blade.php`, `resources/views/dashboard/orders.blade.php`.
- View disesuaikan: `resources/views/sync/history.blade.php`, `resources/views/area/dashboard.blade.php`, `resources/views/branch/dashboard.blade.php`, `resources/views/owner-groups/summary.blade.php`, `resources/views/work-orders/order-month-summary.blade.php`, `resources/views/work-orders/index.blade.php`.
- Interaksi/styles: `resources/js/dashboard-tables.js` (baru), `resources/js/app.js`, `resources/css/app.css`.
- Tes: `tests/Feature/DashboardDrilldownTest.php` (baru), `tests/JavaScript/dashboard-tables.test.js` (baru), `tests/Feature/AreaDashboardTest.php`.
- Requirement/docs: `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, dokumen ini.

Progress working tree sebelumnya dipertahankan. Tidak melakukan reset/commit/push, perubahan workbook asli, import produksi atau perubahan data operasional.

## Hasil pengujian aktual

- Seluruh **211 tes PHP / 8.092 assertions lulus** pada SQLite.
- **30 tes / 2.762 assertions lulus** pada MariaDB terisolasi, meliputi dashboard Area/Branch, modal, Owner Group, Sync Center, otorisasi, aksi dan prioritas. Database uji dihapus sesudah selesai.
- **13 tes JavaScript lulus**, termasuk 6 tes baru untuk Region, loading, error/retry, response terlambat, close/fokus, pagination, tautan Detail dan fallback.
- Build Vite, cache Blade, Pint dan diff check lulus.
- Feature test memeriksa setiap link angka pada dashboard fixture terhadap total hasil modal, termasuk nilai nol, tanpa Region, belum ditugaskan, Owner Group, jenis pekerjaan, bulan dan tanggal PS. Pengujian WIB melintasi batas tengah malam UTC.
- Tidak melakukan verifikasi visual browser baru pada tahap ini. Responsive/light/dark diperiksa melalui implementasi CSS, struktur DOM, feature tests dan kompilasi, tanpa mengklaim screenshot/browser E2E.

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-dashboard-mysql-tests.php
node --test tests/JavaScript/form-confirmation.test.js tests/JavaScript/dashboard-tables.test.js
npm run build
php artisan view:cache
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
git diff --check
```

Tidak ada migration, env, kredensial, backfill atau worker restart yang diperlukan. Tidak ada integrasi Google/Telegram baru yang diklaim. Tidak ada input tambahan yang diperlukan untuk perubahan tampilan ini.

## Penyederhanaan subtotal Region — 25 Oktober 2026

Checklist: periksa baris subtotal kedua tabel → hapus subtotal duplikat di bawah Branch → jalankan tes dashboard dan kompilasi/build.

Baris Grand Total per Region (JATENG DIY, JATIM, BALNUS dan Region lainnya) di bawah rincian Branch dihapus pada matrix progress dan Distribusi Owner Group, termasuk tampilan Branch yang menggunakan partial yang sama. Ringkasan Region di atas, kemampuan collapse, angka/modal dan Grand Total keseluruhan tetap tersedia. Tidak ada perubahan perhitungan, data, migration atau env.

File tahap ini: `resources/views/area/progress-matrix.blade.php`, `resources/views/owner-groups/summary.blade.php`, dokumen ini. Verifikasi memakai tes dashboard yang sudah ada; tidak menambah tes khusus untuk perubahan markup ini.

Hasil tahap penyederhanaan: 11 tes dashboard / 1.739 assertions lulus; cache Blade, build Vite dan diff check lulus. Tidak ada input tambahan yang diperlukan.
