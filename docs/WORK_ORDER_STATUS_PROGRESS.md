# Status Progress, Status Bima dan aturan PS — 23 Oktober 2026

Arahan lanjutan pada tanggal yang sama mengizinkan CLOSE otomatis dari 14 Status Bima beserta backfill historis. Bagian di bawah mendokumentasikan tahap tampilan awal; ketentuan penulisan progress terbaru dan hasil nyata ada di [BIMA_AUTO_CLOSE.md](BIMA_AUTO_CLOSE.md).

Discovery: Laravel 13/Blade, partial aksi digunakan list WO dan tabel tindak lanjut Branch. Progress operasional memakai progress_category, status_order menyimpan gabungan task-status BIMA. PS sebelumnya memakai status_bima CLOSE/COMPLETE umum. Arahan baru menggantikan syarat BIMA dengan 14 kombinasi penuh; CLOSE cabang hanya instalasi lapangan selesai.

Checklist:
- [x] Satu definisi PS untuk query dan instance, termasuk pasangan task kosong -COMPWORK.
- [x] Scope PS/menunggu BIMA, analytics Area/Branch/list konsisten; tanggal tetap tgl_ps.
- [x] Tabel tujuh kolom seragam dan kartu mobile: Status Progress, Status Bima lengkap, tanpa BIMA/timestamp pada progress.
- [x] Tiga tombol ikon kecil, tooltip dan label aksesibel, Close membuka form instalasi lapangan.
- [x] Tes aturan baru, regresi, build/lint dan UI light/dark/mobile.

Tidak ada migration, backfill atau env/dependensi baru. Definisi PS bersifat baca-saja; tidak mengubah status_order, raw status_bima, progress, tanggal PS, source hash, assignment atau riwayat. Data lama yang status_order=COMPWORK dengan task kosong dan raw status_bima=COMPWORK dikenali sebagai -COMPWORK tanpa menulis ulang sumber. Pencocokan memakai kombinasi utuh, toleran kapitalisasi dan spasi tepi biasa; bukan semua suffix COMPLETE. Close on desk, legacy operational COMPLETED tanpa progress CLOSE dan status lain tidak menjadi PS. Tanggal harian tetap tgl_ps dan tidak ditebak dari waktu close.

Daftar resmi berada pada App\Support\PsEligibility::STATUSES (14 kombinasi). PS terkonfirmasi tetap tidak memiliki kartu/tab tersendiri. Menunggu BIMA berarti progress CLOSE tetapi status_order belum memenuhi daftar PS.


## Validasi aktual

- `php artisan test --compact --filter='PsEligibilityTest|WorkOrderPresentationTest|BranchWorkflowTest'`: 10 tes, 233 assertions lulus.
- `php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never`: seluruh 153 tes, 3.130 assertions lulus pada SQLite. Batas memori hanya untuk proses tes (fixture snapshot Sheets historis besar); tidak mengubah konfigurasi PHP aplikasi.
- `php /private/tmp/olo-status-progress-mysql-tests.php`: 151 tes, 3.119 assertions lulus pada database MariaDB terisolasi; database dihapus dalam finally. Helper sementara menggunakan konfigurasi koneksi lokal tanpa mencetak credential, membuat database acak, lalu menjalankan PHPUnit dengan memori 512M. Dua tes snapshot Sheets historis (`historical_size_batch|provided_bima_imports`) dikecualikan pada MariaDB karena max_allowed_packet lokal 1 MiB; keduanya lulus pada SQLite. Tidak menaikkan konfigurasi server dan tidak menulis database aplikasi.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check`: lulus.
- Browser lokal: desktop 1600px light, mobile 375px dark, tablet 768px; lebar halaman mengikuti viewport tanpa overflow halaman. Mobile memakai kartu dengan status BIMA utuh dan target aksi 40px. Tooltip muncul saat hover/fokus keyboard; label aksesibel tersedia. Close membuka kategori CLOSE dan penjelasan instalasi lapangan, tanpa mengirim form pada order aktual. Tes feature memverifikasi penyimpanan Close, lalu import approval BIMA menghasilkan PS tanpa mengubah revisi progress/hash atau mengarang tanggal PS.
- Bukti tampilan tabel: `/private/tmp/olo-work-order-status-progress.png` (bagian status dan aksi, tanpa identitas pelanggan).

## File pada perubahan ini

Logika: `app/Support/PsEligibility.php` (baru), `app/Models/WorkOrder.php`, `app/Services/AreaAnalyticsService.php`.

UI: `resources/views/work-orders/index.blade.php`, `mobile-card.blade.php`, `row-actions.blade.php`, `progress.blade.php`, `show.blade.php`; `resources/views/components/icon.blade.php`; `resources/views/area/dashboard.blade.php`, `resources/views/branch/dashboard.blade.php`, `resources/views/imports/mapping.blade.php`; `resources/css/app.css`, `resources/js/app.js`.

Tes: `tests/Feature/WorkOrderPresentationTest.php` (baru), `PsEligibilityTest.php`, `AreaDashboardTest.php`, `BranchAnalyticsTest.php`, `BranchWorkflowTest.php`, `BimaStatusDevicesTest.php`, `NewOrderTest.php`.

Dokumentasi: file ini, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`. Perubahan sebelumnya pada branch tetap dipertahankan.

## Batas yang tetap berlaku

Tidak ada input tambahan yang diperlukan untuk permintaan ini. PS harian tetap membutuhkan tanggal `tgl_ps` yang tersedia dari sumber; Close tidak mengisi tanggal tersebut otomatis. File sumber, progress, kendala, assignment, riwayat task, dan credential tidak diubah oleh pemeriksaan browser. Tidak ada migration/env baru dan tidak ada klaim koneksi Google/Telegram end-to-end pada tahap ini.
