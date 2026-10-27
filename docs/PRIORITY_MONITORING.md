# Monitoring Order Prioritas

Pembaruan tab 27 Oktober: Monitoring kini dipisah menjadi Order Branch, Owner Group dan Order Kendala. Kendala menampilkan detail terbaru dari issue aktif beserta catatan tindak lanjutnya pada order prioritas sesuai scope; aturan PS dan privasi bookmark tetap. Detail: [PRIORITY_MONITORING_TABS.md](PRIORITY_MONITORING_TABS.md).

Pembaruan tampilan lanjutan 27 Oktober: tabel lebih padat, terbuka penuh secara default dan tanpa scroll internal untuk screenshot; menggantikan batas scroll 320/280px. Dashboard Area memiliki tab Progress dan Owner Group. Detail dan hasil: [DASHBOARD_TABS_CAPTURE.md](DASHBOARD_TABS_CAPTURE.md).

## Pembaruan 27 Oktober 2026

Layout dua kolom di bawah telah diganti menjadi **dua tabel vertikal yang ringkas** pada semua ukuran layar. Order yang memenuhi kriteria PS otomatis dilepas dari prioritas Area/Branch dengan audit; CLOSE lapangan yang belum PS tetap dipantau. List, total, monitoring dan ekspor menggunakan aturan yang sama. Detail, berkas, hasil pengujian dan pembersihan data aktual: [PRIORITY_PS_RELEASE.md](PRIORITY_PS_RELEASE.md). Bagian 26 Oktober di bawah adalah riwayat implementasi sebelumnya.

## Perapian dua kolom — 26 Oktober 2026
Checklist perubahan UI:
- [x] Inspect Blade, CSS, kontrol collapse, tests dan progress workspace.
- [x] Dua panel rekap sejajar pada desktop, bertumpuk pada layar kecil; scrollbar berada di panel masing-masing.
- [x] Rapikan heading/toolbar, identitas order, angka dan Grand Total pada seluruh tabel monitoring, memakai token light/dark.
- [x] Jalankan regression monitoring, JavaScript, build, Blade dan lint; catat hasil serta file berubah.

Perubahan ini hanya layout. Query, filter, hak akses, definisi angka, bookmark, pagination dan download List tetap mengikuti implementasi yang sudah ada. Tidak ada migration/env baru.

Desktop mulai 1200px: Status BIMA × Owner Group dan Rincian Task per Owner Group memakai grid dua kolom sama lebar, heading sejajar dan panel dengan scroll terpisah (maksimal 480px). Di bawah 1200px bertumpuk; mobile menggunakan heading ringkas, identitas matriks lebih ramping dan batas scroll 400px. Total kolom kanan dan identitas kiri matriks sticky. Tabel Owner Group memakai kolom task yang dapat membungkus teks dan angka rata kanan. Seluruh panel mempunyai toolbar Buka semua/Tutup semua dan jumlah real sesuai filter. Rincian Branch tetap lebar penuh, No WO/aksi Detail sticky saat scroll horizontal, tombol Detail kecil bertooltip, serta rentang/nomor halaman eksplisit. Styling scoped pada Monitoring, memakai token white/dark, radius 6px tanpa gradient.

File diubah pada perapian ini: `resources/views/priorities/monitoring.blade.php`, `resources/css/app.css`, `docs/PRIORITY_MONITORING.md`. Perubahan lain yang sudah ada di workspace, termasuk login pengguna, dipertahankan.

Validasi perapian: `php -d memory_limit=512M vendor/bin/phpunit --filter 'PriorityMonitoringTest|PriorityOrdersTest|PerformanceBudgetTest' --no-progress --colors=never` lulus **14 tes / 489 assertions**; `node --test tests/JavaScript/*.test.js` lulus **27 tes**; `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check` lulus. Tidak menambah tes yang hanya menyalin implementasi CSS. Tidak ada SQL/migration/env atau integrasi eksternal yang diubah, sehingga tidak mengulang pengujian MariaDB. Pemeriksaan visual browser manual belum dilakukan karena izin preview sebelumnya ditolak; rendering Blade dan perilaku collapse diuji melalui regression yang ada.

## Checklist implementasi
- [x] Inspect Laravel 13, pola Blade/CSS, RBAC, query bookmark, mapping Owner Group dan tests.
- [x] Hapus card Operasional Area ke Branch; buat submenu Monitoring dan List.
- [x] Gunakan query prioritas bersama untuk rekap, rincian, filter dan download List.
- [x] Rekap status BIMA × Owner Group, Owner Group → task, Branch → task → No WO.
- [x] Uji scope/privasi, deduplikasi, perpindahan Branch, mapping, filter, pagination dan ekspor; build/lint.

## Keputusan
Screenshot adalah referensi susunan, bukan sumber angka. Rekap membaca `status_bima`, `task_bima` dan lookup aktif `mapping_ownergrup` secara persis. COMPLETE adalah status task BIMA, bukan otomatis PS. Aturan PS/progress tidak berubah. Nama kelompok mengikuti Master Data; sumber kosong, tidak dikenal atau mapping nonaktif tetap masuk Belum dipetakan.

Arsitektur: query shared PriorityOrderQuery memakai whereHas dan WorkOrderPriority::visibleTo, satu WO dihitung sekali walaupun dipilih Area dan Branch. Rekap dan rincian tetap pada scope akun. Bookmark lokal Branch tidak ditampilkan ke Area dan tidak ikut pindah Branch. Halaman read-only; tidak ada migration/env/dependency baru. Perubahan login yang sudah ada milik pengguna tidak disentuh.

## Perilaku dan UI
- Menu parent terbuka otomatis pada halaman prioritas; submenu aktif diberi aria-current. URL List yang lama tetap kompatibel.
- Filter pencarian, Branch, sumber Area/Branch, status monitoring dan Owner Group digunakan bersama. Tautan angka membawa kombinasi status/task/Owner Group yang persis ke List, termasuk nilai kosong; filter tetap pada download Excel List. Hapus filter rincian/Reset disediakan.
- Tabel pertama adalah status task BIMA (COMPLETE/STARTWORK/apa pun yang tersimpan), bukan status progress Branch. Kedua tabel rekap menghitung seluruh hasil filter, bukan hanya halaman rincian.
- Tabel ketiga menampilkan maksimal 25 No WO per halaman, dikelompokkan Branch/task. Jumlah pada header Branch adalah jumlah di halaman itu; Grand Total mencakup seluruh halaman. Tombol Detail membuka Work Order melalui policy yang sudah ada.
- Semua kelompok dapat dibuka/tutup dan Buka semua/Tutup semua hanya memengaruhi tabel terkait. Tanpa JS, seluruh rincian tersedia. Tabel memakai scroll, sticky header/identitas dan token light/dark yang sudah ada. Loading mengikuti submit filter; validasi memakai pesan aplikasi; hasil kosong dan halaman pagination di luar rentang mempunyai pesan/pemulihan nyata.
- Kelompok yang tidak memiliki data disembunyikan dari kolom pivot agar ringkas; semua kelompok master tetap tersedia pada filter. TDE/SDI AREA 2 dari screenshot tidak dibuat/ditetapkan secara spekulatif.
- Tidak ada download Excel pada tiga rekap ini. Download tetap pada Work Order/List Order Prioritas, seluruh kolom fisik work_orders.

## File diubah
Backend:
- `app/Http/Controllers/PriorityOrderController.php`
- `app/Http/Requests/PriorityOrderListRequest.php`
- `app/Services/PriorityOrderQuery.php`
- `app/Services/PriorityMonitoringService.php` (baru)
- `app/Services/OwnerGroupAnalyticsService.php` (join mapping yang sama dipakai ulang)
- `app/Services/Exports/OrderExportService.php` (konteks filter BIMA)
- `routes/web.php`

UI:
- `resources/views/layouts/app.blade.php`
- `resources/views/area/dashboard.blade.php`
- `resources/views/area/operations-handoff.blade.php` (dihapus)
- `resources/views/priorities/index.blade.php`
- `resources/views/priorities/monitoring.blade.php` (baru)
- `resources/views/priorities/filters.blade.php` (baru)
- `resources/views/components/priority-count.blade.php` (baru)
- `resources/css/app.css`

Tests/dokumentasi:
- `tests/Feature/PriorityMonitoringTest.php` (baru)
- `tests/Feature/PriorityOrdersTest.php`
- `tests/Feature/OrderExportTest.php`
- `tests/Feature/OperationsWorkspaceTest.php`
- `tests/Feature/MonitoringPushTest.php`
- `tests/Feature/AccessAndSyncTest.php` (tes form/autentikasi tidak bergantung pada copy tombol login)
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, dokumen ini.

`resources/views/auth/login.blade.php` sudah berubah sebelum pekerjaan ini dimulai dan tidak diedit oleh implementasi ini.

## Verifikasi
- `php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never`: 282 tests / 26.060 assertions, lulus.
- Setelah menambahkan empty state untuk pagination di luar rentang: `php -d memory_limit=512M vendor/bin/phpunit --filter PriorityMonitoringTest --no-progress --colors=never`: 4 tests / 209 assertions, lulus.
- MariaDB database sementara terpisah: PriorityMonitoringTest, OrderExportTest, PriorityOrdersTest, XlsxTableWriterTest, AreaDashboardTest, BranchAnalyticsTest, OwnerGroupAnalyticsTest, DashboardDrilldownTest: 34 tests / 3.054 assertions, lulus. Database sementara dihapus; database aplikasi tidak dimigrasi/diganti.
- `node --test tests/JavaScript/*.test.js`: 27 tests lulus, termasuk kontrol collapse/expand tabel yang dipakai ulang.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check`: lulus.
- Regression baru memeriksa rekonsiliasi total tiga tabel, nilai kosong/0, case-sensitive mapping dan status/task, seluruh tautan angka vs jumlah List, perubahan mapping langsung, privasi dan deduplikasi Area/Branch, transfer WO, pencabutan assignment, role/nonaktif/guest, validasi dan pagination. Ekspor diuji dengan filter Owner Group/status/task baru.

## Migrasi, env, dan batas
Tidak ada perubahan schema, migration, env atau paket; tidak perlu menjalankan migrasi. Tidak ada query yang menulis progress, sumber BIMA atau bookmark saat membuka monitoring. Tidak ada masalah yang memerlukan input untuk memakai fitur ini. Jika kelompok tambahan seperti TDE diperlukan, aturan Master Data perlu ditentukan pemilik lebih dahulu. Pemeriksaan visual browser manual belum dilakukan (izin preview sebelumnya ditolak); pengujian rendering Blade/DOM, kontrol JS dan build berhasil. Tidak ada pengujian end-to-end Google/Telegram pada perubahan ini.

## Branch per Owner Group — 27 Oktober 2026

Tab Owner Group kini mempunyai tabel tambahan Branch per Owner Group di atas dua rekap status/task yang sudah ada. Seluruh angka mengikuti scope/filter prioritas dan membuka List dengan filter yang sama, termasuk order belum ditugaskan. Manifest dan hasil terbaru: [PRIORITY_BRANCH_OWNER_GROUP.md](PRIORITY_BRANCH_OWNER_GROUP.md).
