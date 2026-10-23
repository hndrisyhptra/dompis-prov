# Alur operasional Area dan Branch — 20 Oktober 2026

Checklist:
- [x] Antrean WO dengan jumlah sesuai scope/filter, tindakan Progress/Kendala/Close, dan tampilan mobile.
- [x] Form pembaruan terpandu, konteks penutupan dan kendala aktif; aturan backend tetap terjaga.
- [x] Dashboard POV Area dan Branch mengarahkan ke tindak lanjut nyata, import → data terpusat → cabang.
- [x] Feature tests, build, UI light/dark desktop/mobile; dokumentasikan hasil aktual.

Discovery: Laravel 13, Blade/JS/CSS token, MySQL. Import XLSX memakai mapping master STO/branch dan scope user_branches untuk Admin/HD/TL; distribusi tidak membuat salinan WO per user. Backend sudah memiliki revision, transaksi, audit, kendala, CLOSE dan dual-status PS. Saat ini antrean hanya tabel umum, kategori 15 opsi dalam satu form, dan dashboard belum menekankan handoff Area→Branch. Perubahan ini berfokus pada alur kerja dan navigasi; tidak menebak status BIMA, tanggal PS, atau menjadikan perangkat wajib untuk CLOSE.

Default jenis pekerjaan tetap NEW INSTALL dengan filter semua jenis. PS terkonfirmasi, Close cabang, dan menunggu BIMA ditampilkan terpisah sesuai artinya. Semua hitungan dan tautan harus membawa scope/filter yang sama.


## Implementasi

- Data terpusat: import valid tetap upsert satu WO berdasarkan source_system + no_tq_sc_id. Master STO/branch menentukan id_branches; user_branches menentukan siapa yang dapat melihat/mengubah. TL, HD dan Admin Branch memakai policy yang sama; branch tidak memiliki izin import Area. Tidak ada notifikasi yang diklaim terkirim.
- Sync Center menjelaskan empat langkah operasional; hasil import sukses/parsial memberi tautan ke Work Orders dan dashboard Area. Baris konflik/ditolak tetap dijelaskan sebagai belum diterapkan.
- WorkOrderQueueService menyatukan filter antrean dengan hitungannya. Angka mengikuti scope, branch, jenis pekerjaan, region, layanan, pencarian, kategori dan bucket; memilih antrean tidak mengubah angka dasar filter. Antrean Close cabang menghitung kategori CLOSE; PS terkonfirmasi tetap dual-status. Pencarian/filter dibawa dalam tautan antrean. Default jenis pekerjaan NEW INSTALL tetap dapat diganti semua jenis.
- List menyediakan tindakan Update progress, Kendala/Tindak lanjut dan Close. Mobile <=700 px memakai kartu order; desktop tabel. Data di kedua tampilan berasal dari query/pagination yang sama, bukan query lain. Akun lihat-saja mendapat Lihat detail.
- Form memiliki tiga pilihan tindakan. Tautan GET action=progress/issue/close valid dan tetap bekerja tanpa JS; JavaScript mengganti kategori/petunjuk tanpa membuang catatan. Backend tetap memvalidasi 15 kategori progress, notes, revision, scope dan penyelesaian kendala sebelum CLOSE. Order terminal branch hanya dapat ditambah catatan; koreksi kategori oleh Area.
- Close menampilkan status BIMA, jumlah kendala aktif, kelengkapan perangkat (opsional, bukan syarat baru), ringkasan penyelesaian dan tombol Konfirmasi CLOSE. Link perangkat hanya untuk role yang berizin edit. Perangkat tetap dibuka lewat disclosure dan dilindungi dari overwrite import. Tidak mengarang tgl_ps atau menganggap semua CLOSE sudah PS.
- Dashboard Area menampilkan volume terpusat/terpetakan, belum diperbarui dan Close cabang sesuai filter. Dashboard Branch menampilkan fokus belum diperbarui, kendala, Close menunggu BIMA, dan Close cabang; indikator bisa membuka antrean terkait. Grafik PS tetap memakai tanggal dan syarat yang sama.

## File berubah

- app/Services/WorkOrderQueueService.php (baru)
- app/Http/Controllers/WorkOrderController.php; app/Models/WorkOrder.php (scope waitingBima)
- app/Services/AreaAnalyticsService.php; BranchAnalyticsService.php
- resources/views/work-orders/index.blade.php; show.blade.php; progress.blade.php
- resources/views/work-orders/row-actions.blade.php; mobile-card.blade.php (baru)
- resources/views/area/operations-handoff.blade.php (baru); area/dashboard.blade.php; branch/dashboard.blade.php
- resources/views/sync/excel-inbound.blade.php; sync/show.blade.php
- resources/js/app.js; resources/css/app.css
- tests/Feature/OperationsWorkspaceTest.php (baru); BimaWorkOrdersTemplateTest.php (E2E aplikasi tanpa Google); AreaDashboardTest.php (tambahan metrik)
- AGENTS.md; SKILLS.md; PRD_OLO_Command_Center_v2.md; dokumen ini

Migration/environment/dependensi baru: **tidak ada**. Tidak menjalankan migrasi atau mengubah data aktual untuk perubahan ini. Tidak ada commit/push. Google/Telegram tidak diuji E2E pada tahap ini. Mapping sumber TGL PS untuk preset Work Orders tetap input terpisah; grafik tidak menebak tanggal dari Date Modified.

Perintah validasi: `php artisan test --compact`, tes MariaDB pada database random sementara melalui /private/tmp/olo-id-mysql-tests.php, `vendor/bin/pint --dirty --test`, `git diff --check`, `npm run build`, `php artisan view:cache`.


## Hasil aktual

- Full suite SQLite **133 tes / 2.504 assertions lulus**. MariaDB sementara terpisah **131 tes / 2.493 assertions lulus**. Dua tes snapshot Sheets legacy besar dikecualikan hanya pada MariaDB karena packet lokal 1 MiB; seluruh tes XLSX termasuk handoff Area→TL/HD/Admin Branch dan dashboard lulus. Setelah penyempurnaan disclosure filter mobile, SQLite penuh diulang dan tetap 133 / 2.504 lulus.
- Tes mencakup hitungan antrean scoped dan mengikuti q/status, Close cabang vs menunggu BIMA/PS, action GET sesuai form, pembatasan order terminal dan perangkat TL, outsider ditolak, satu WO pusat untuk tiga role cabang, progress/kendala/tindak lanjut/resolve+CLOSE, sumber hash terjaga, metrik Area/Branch dan tanggal PS kosong tidak dikarang.
- Build Vite, kompilasi Blade, Pint dan git diff --check lulus.
- UI aplikasi aktual port 8011 diperiksa pada 1280 px desktop, 768 px tablet, 375 px mobile, light/dark. Kartu mobile tampil tanpa tabel desktop, filter mobile dilipat, dan tidak ada overflow halaman. Pergantian mode menyimpan catatan yang belum disubmit, CLOSE menampilkan konteks tanpa langsung menyimpan. Close tidak memaksa kelengkapan perangkat sebagai syarat baru.
- Dashboard Area menampilkan ringkasan distribusi aktual. Dashboard Branch dipilih MATARAM; tautan Belum diperbarui membuka antrean unupdated dengan filter branch yang sama (2 order). Browser hanya memeriksa UI dan filter, tanpa menyimpan perubahan WO. Catatan percobaan dikosongkan, preferensi kembali light dan viewport override di-reset. Bukti form tanpa data pelanggan: /private/tmp/olo-operations-workspace.png.
- Tidak ada migrasi/data backfill yang diperlukan atau dijalankan pada tahap ini, tidak mengubah environment, user, kredensial, atau sumber workbook asli. Aplikasi tetap aktif. Tidak ada input yang memblokir UI baru, commit/push, atau tes E2E API eksternal.
