# Admin Branch: alur kerja, progress, kendala dan analytics

**Pembaruan aturan PS:** arahan dashboard Area terbaru menggantikan definisi PS awal di catatan implementasi ini. PS memerlukan progress CLOSE + STATUS BIMA CLOSE/COMPLETE; tanggal harian memakai TGL PS BIMA. Opsi CLOSE ON DESK lama sudah dihentikan. Lihat [aturan terbaru dan hasil pengujian](AREA_DASHBOARD.md).

## Hasil implementasi — 18 Oktober 2026

- [x] Routing WO dari pull mengikuti `id_branches`; seluruh admin yang ditugaskan lewat `user_branches` melihat order cabangnya tanpa menyalin WO per user.
- [x] Login/beranda role branch mengarah ke Dashboard Branch; navigasi berfokus pada dashboard, order dan informasi laporan material.
- [x] Kelima belas kategori progress tersedia, dengan riwayat, aktor, tanggal complete, audit dan proteksi revisi.
- [x] Kendala memiliki detail awal, catatan tindak lanjut append-only, PIC, target dan hasil penyelesaian.
- [x] Dashboard berisi order hari ini, PS hari ini, outstanding, order berkendala, grafik 7/14/30 hari, antrean prioritas dan waktu data terakhir diterima.
- [x] Daftar/detail order dan dashboard mendukung light/dark, drawer mobile, tabel horizontal, loading, empty, validation dan stale conflict state.
- [x] Backup terenkripsi, migrasi, perbandingan seluruh kolom/data lama dan worker diaktifkan kembali.
- [x] Feature/unit tests, pengujian MariaDB terisolasi, Pint, build, kompilasi Blade dan pemeriksaan browser aplikasi utama.
- [ ] Integrasi material Telegram → Sheets → web. Halaman saat ini hanya menerangkan status belum aktif dan alurnya; belum ada endpoint bot, transaksi material atau impor material yang diklaim berfungsi.

Inspeksi: Laravel 13, PHP 8.5, Blade/Tailwind 4/Vite 8 dan MariaDB 10.4.28. Scope WO, layanan pull, revision lock dan audit yang sudah ada digunakan kembali. Tidak ada repository Git pada workspace saat ini; tidak ada checkout/reset/overwrite branch Bima. Dependensi paket tidak berubah.

## Pemakaian oleh Admin Branch

1. Superadmin menetapkan cabang pada User Management. Lokasi utama region/STO bukan pemberian hak akses; cakupan tetap melalui penugasan cabang. Akun dengan beberapa cabang melihat gabungan cabang tersebut dan dapat memfilter masing-masing.
2. Admin Area melakukan preview dan pull BIMA_RAW yang valid. Mapping branch/STO yang berhasil mengisi `id_branches` membuat order langsung terlihat pada dashboard dan daftar cabang. Tidak perlu tombol pembagian per user. Mapping belum jelas/berkonflik tetap ditahan untuk review; tidak ditebak dari user yang sedang login.
3. Admin Branch membuka antrean dan memilih Update order, mengisi kategori serta catatan. Atribut sumber BIMA tetap dipisahkan dari progress operasional.
4. Pilihan KENDALA membuat kendala pertama dari catatan bila belum ada kendala aktif. Detail awal tidak ditimpa. Tambah catatan tindak lanjut untuk perkembangan berikutnya, lalu Selesaikan kendala dengan hasil penanganan.
5. Selesaikan seluruh kendala aktif sebelum CLOSE/CLOSE ON DESK. Admin Branch dapat menambah catatan pada order tertutup. Koreksi kategori/pembukaan kembali memerlukan Area; semua perubahan menyimpan jejak revisi.
6. Pull ulang mempertahankan progress, kendala, catatan, tanggal complete dan riwayat; push monitoring memproyeksikan kategori terbaru ke kolom Status Operasional tanpa mengubah header atau menulis BIMA_RAW.

Kategori persis sesuai permintaan: **CLOSE, OGP BIMA, RESUBMIT AO, SURVEY, CANCEL, KENDALA, JT, CNDC, PERIZINAN, KONFIRMASI PIC, DELIVERY MATERIAL, HOLD, BUTUH QE, INSTALASI, CLOSE ON DESK**. Tidak menebak kepanjangan JT/CNDC. Kategori baru disimpan terpisah dari status workflow lama; endpoint lama dipertahankan untuk kompatibilitas/verifikasi Area.

## Definisi dashboard dan keputusan awal

- **Order hari ini:** `order_date` BIMA sama dengan tanggal hari ini di Asia/Jakarta; tanggal kosong tidak ditebak sebagai tanggal impor.
- **PS / complete hari ini:** waktu pertama laporan progress CLOSE berada pada hari WIB ini. Ini laporan complete Branch; status VERIFIED dan persetujuan Area tetap terpisah. BIMA CLOSE/TGL PS tidak otomatis membuat PS internal.
- **Outstanding:** kondisi saat ini; tidak memiliki tanggal complete dan belum CLOSE/CANCEL/CLOSE ON DESK/COMPLETED. CANCEL dan penutupan administratif tidak masuk outstanding.
- **Order berkendala:** jumlah WO outstanding yang memiliki sedikitnya satu issue OPEN, bukan jumlah seluruh issue.
- **Grafik:** jumlah WO complete per hari WIB, zero-filled untuk 7/14/30 hari; query hanya scope cabang pengguna dan filter jenis pekerjaan. Timestamp disimpan UTC, pengelompokan WIB tidak memerlukan timezone table MariaDB.
- Filter awal **NEW INSTALL** mengikuti PRD; tersedia Semua jenis dan jenis lain yang terlihat dalam scope pengguna.
- Catatan tambahan pada CLOSE tidak memindahkan tanggal PS. Area membuka kembali order: tanggal complete aktif dihapus, grafik saat ini ikut terkoreksi; riwayat kejadian tidak dihapus. Setelah ditutup lagi, tanggal PS memakai kejadian close baru. Ini grafik kondisi terbaru, bukan arsip snapshot penilaian terkunci.
- **Keputusan sementara yang perlu pemilik tinjau:** PS dihitung dari CLOSE saja. CLOSE ON DESK administratif, tidak masuk PS. Opsi `.env.example` `BRANCH_CLOSE_ON_DESK_COUNTS_AS_COMPLETE=false` mempertahankan default ini. Bila diset true, berlaku saat laporan progress disimpan berikutnya; tidak menghitung ulang tanggal historis tanpa bukti. Setelah mengubah env pada deployment dengan cache, jalankan config:clear/config:cache sesuai prosedur dan restart worker.

## Perubahan skema dan migrasi nyata

Migrasi baru: `database/migrations/2026_10_18_000007_add_branch_progress_and_issue_notes.php`.

- `work_orders.progress_category` nullable string/index; `branch_completed_at` nullable timestamp/index bersama `id_branches`.
- `wo_status_events.old_progress_category`, `new_progress_category` nullable; seluruh status/catatan lama tetap utuh.
- Tabel baru `wo_issue_notes`: PK `id_wo_issue_notes`, FK `id_wo_issues`, FK `id_users`, `note`, `created_at`. Foreign key restrict; tidak mengubah tabel internal Laravel.
- Backfill konservatif: SURVEY → kategori SURVEY; CANCELLED → CANCEL; COMPLETED → CLOSE dengan tanggal `verified_at` yang tersedia. Tidak menebak waktu dari updated_at atau status source BIMA.
- Eksekusi `php artisan migrate --force` berhasil pada MySQL/MariaDB aplikasi. Database tetap memiliki 9 WO, 2 users, 2 penugasan cabang, 2 event progress, 1 issue, 7 sync jobs. Kolom lama pada seluruh 20 tabel sebelum migrasi dibandingkan dengan backup dan sama persis. Hanya satu kategori SURVEY diisi; tidak ada tanggal complete atau notes fiktif.
- Backup encrypted dengan APP_KEY pada `storage/app/private/maintenance/before-branch-workflow-20261018-032527.json.enc`, permission 0600. Jangan publikasikan file atau kunci. Rollback diuji di database terisolasi; rollback tabel baru akan menghapus catatan baru, sehingga backup/export catatan diperlukan sebelum rollback produksi.
- Maintenance sudah dihentikan; aplikasi lokal port 8011 dan worker antrean dengan kode terbaru aktif kembali. Queue worker tetap perlu supervisor/service manager untuk deployment permanen.

Tidak ada kredensial baru yang diperlukan untuk fungsi dashboard/progress/kendala. `.env` asli tidak diubah. Konfigurasi Google yang sudah ada tidak disentuh, tidak melakukan request API Google/Telegram atau menulis Sheets pada iterasi ini.

## Material: arahan terbaru pengguna

Instruksi pemilik terbaru mengarahkan **Telegram → Google Sheets → sync ke web dompisprov**, berbeda dari baseline PRD/SKILLS yang menyimpan MySQL terlebih dahulu. Implementasi berikut harus memakai arahan terbaru ini dengan autentikasi pelapor, ID laporan stabil, idempotency dan jurnal koreksi. Jangan membangun kedua arah sekaligus atau mengimpor output lama sebagai sumber tanpa kontrak baru.

Yang masih diperlukan sebelum integrasi material: token/identitas bot melalui konfigurasi privat, chat/user yang diizinkan, tab dan header laporan material, ID laporan/message yang stabil, referensi NO TQ/SC/ID + branch, item/satuan/jumlah/waktu transaksi dan aturan koreksi/review. Belum ada kredensial bot, kontrak tab material atau tes end-to-end material. Tidak ada laporan material, approval atau export LHPP yang dibuat seolah sudah tersedia.

## Pengujian dan batas yang nyata

Perintah yang dijalankan:

```sh
php artisan test --compact
vendor/bin/pint <daftar file PHP pada manifest di bawah>
npm run build
php artisan view:cache
php artisan migrate --force
php artisan queue:work --sleep=3 --tries=3 --timeout=180
```

- SQLite: **83 tests, 774 assertions, seluruhnya lulus**.
- MariaDB terisolasi: suite menggunakan database sementara dengan konfigurasi koneksi lokal, lalu database tersebut dihapus setelah tes. Dua uji snapshot besar (`historical_size_batch`, `provided_bima_imports`) dikecualikan karena `max_allowed_packet=1048576` pada server. Kedua uji tersebut tetap lulus pada SQLite; batas server belum diubah. Suite MariaDB **81 tests/759 assertions lulus**, kemudian regresi akhir 8 tests/155 assertions lulus setelah perbaikan kendala berulang. Database sementara selalu dihapus setelah pengujian.
- Cakupan baru: dua admin cabang yang sama memperoleh WO yang sama tanpa duplikasi; branch lain/filter branch ilegal ditolak; beranda role branch; 15 kategori; close/tanggal PS; notes tidak menggeser tanggal; hanya Area boleh reopen; KENDALA idempotent dan kendala yang berulang setelah resolved menjadi kejadian baru; catatan append/revision/cross-scope; active issue menghalangi close; pull mempertahankan operasional; CLOSE ON DESK policy; hitungan WIB di batas tengah malam; jenis pekerjaan; grafik/zero days; backfill dengan tanggal yang diketahui saja; FK/rollback/reapply. Regresi markup nama cabang di User Management juga diperbaiki.
- Pint dan produksi Vite build lulus; Blade berhasil dikompilasi.
- Browser pada aplikasi utama sebagai superadmin: filter MATARAM menghasilkan 3 outstanding dan 1 WO berkendala untuk NEW INSTALL; PS 0 menampilkan empty state asli. Ukuran 1280/768/375 px, light/dark, menu drawer/Escape, tabel horizontal serta detail 15 kategori dan form catatan diperiksa tanpa submit perubahan order. Page width = viewport pada tablet/mobile. Tema/ukuran sementara dikembalikan.
- Browser server QA terpisah port 8012 ditolak izin browser dan tidak dicoba lewat jalur lain; server QA dihentikan. Identitas role Branch dan grafik bernilai nonzero diuji feature tests, bukan diklaim terverifikasi browser dengan akun Branch.
- Template BIMA, LHPP dan dua SQL sumber hash-nya tetap sama; tidak ada data uji dimasukkan ke database aplikasi atau spreadsheet.

Bukti screenshot aplikasi utama, bukan akun Branch atau data demo: `docs/screenshots/branch-dashboard-desktop-light.png`, `branch-dashboard-desktop-dark.png`, `branch-dashboard-tablet-dark.png`, `branch-dashboard-mobile-light.png`, `branch-dashboard-mobile-dark.png`, `branch-order-mobile-dark.png`.

## Manifest file iterasi

File baru:

- `app/Enums/ProgressCategory.php`
- `app/Models/WoIssueNote.php`
- `app/Services/BranchOrderScope.php`
- `app/Services/BranchAnalyticsService.php`
- `app/Http/Controllers/BranchDashboardController.php`
- `database/migrations/2026_10_18_000007_add_branch_progress_and_issue_notes.php`
- `resources/views/branch/dashboard.blade.php`
- `resources/views/branch/material.blade.php`
- `tests/Feature/BranchWorkflowTest.php`
- `tests/Feature/BranchAnalyticsTest.php`
- `tests/Feature/BranchProgressMigrationTest.php`
- `docs/BRANCH_WORKFLOW.md` dan enam screenshot di atas.

File diubah:

- `.env.example`, `config/olo.php`, `routes/web.php`
- `app/Models/WorkOrder.php`, `app/Models/WoIssue.php`
- `app/Services/BranchProgressService.php`, `app/Services/MonitoringSheetPushService.php`
- `app/Http/Controllers/BranchProgressController.php`, `WorkOrderController.php`, `AuthController.php`
- `resources/views/layouts/app.blade.php`, `components/icon.blade.php`, `errors/409.blade.php`
- `resources/views/work-orders/index.blade.php`, `show.blade.php`, `progress.blade.php`, `issues.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/css/app.css`, `resources/js/app.js`
- `tests/Feature/MigrationRollbackTest.php`, `LocationsMigrationTest.php`, `BranchRegionMigrationTest.php`
- `AGENTS.md`, `README.md`, `docs/IMPLEMENTATION.md`
- `public/build/manifest.json` dan aset hasil build Vite.
