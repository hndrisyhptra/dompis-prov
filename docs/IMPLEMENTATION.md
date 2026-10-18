# Implementasi OLO Command Center

## Preflight

- Skeleton Laravel **13.37.0**, PHP CLI **8.5.10**, Tailwind **4**, Vite **8**, Node **24**. Belum ada auth, modul bisnis, Livewire/Alpine atau pola UI selain welcome bawaan. Dependency yang ada dipertahankan; interaksi Blade memakai JavaScript kecil dengan progressive enhancement, tanpa kebutuhan paket baru.
- MySQL lokal sudah mempunyai tiga migrasi Laravel (users, cache, jobs). Migrasi bisnis bersifat aditif, tidak mengganti tabel/data awal. Tes terisolasi memakai SQLite memory; verifikasi MySQL dicatat terpisah.
- Direktori belum mempunyai `.git`; tidak ada commit/branch yang dibuat.
- Semua requirement AGENTS.md, SKILLS.md dan PRD v2 dibaca. Workbook sumber hanya dibaca, tidak ditulis.
- Bima `OLO AREA-3`: 3343 × 32, 3298 ID H terisi/unik. Formula AC/AE/AF. Header R mempunyai trailing space. LHPP `Sheet1`: 5 × 32. Tidak menebak SID maupun koordinat.

## Checklist dan dependensi

- [x] M1: role, multi-branch scope, login/logout, provisioning aman, policy, layout light/dark.
- [x] M2 schema: branch mapping, WO, encrypted preview snapshot, sync job/rows, audit.
- [x] M2 adapter: Google service-account read/write dan fake tanpa credential.
- [x] M2 alur: queued preview → konfirmasi snapshot → queued pull, lock, idempotency, conflicts dan log.
- [x] M2 UI: Sync Center, mapping branch, detail log, state loading/empty/error.
- [x] M1/M2: unit/feature tests, build, migration/rollback dan UI verification.
- [x] M3 bagian operasional: progress revision + events/issues dan monitoring push aman berbasis key.
- [ ] M4/M5: dikerjakan setelah kontrak material, identity Telegram dan mapping/mandatory LHPP dikonfirmasi; tidak mengklaim tersedia sebelum alur lengkap teruji.

## Integritas template

SHA-256 awal:

- EXPORT-BIMA.xlsx: `303db8cb0474872b2693be1ec3cfe19494bc90f3f83d0ee5ad59ad4e6ae17baa`
- LHPP OLO.xlsx: `2cf8b271038771c1b22879521eec3886d71054e9cca48148686acb5aa0dd33fe`

## Hasil iterasi 18 Oktober 2026

M1/M2 selesai sebagai alur fungsional yang dapat dijalankan tanpa credential Google. M3 dilanjutkan sampai progress, revision lock, timeline, kendala dan full monitoring push. Tidak ada M4/M5 yang diklaim selesai.

- [x] Login/logout, active-user middleware, role enum, policy dan multi-branch scope.
- [x] Provisioning akun melalui CLI dengan password tersembunyi; tidak ada default account/seed password.
- [x] Dua migrasi bisnis, model WO dan status/source terpisah, audit, encrypted preview snapshot.
- [x] Adapter Google service-account + fake lokal + HTTP fake pada tes. Read grid mengambil formatted value dan formula asli dalam snapshot yang sama; validasi tidak dibypass oleh hasil formula.
- [x] Preview queued → diff/exception → konfirmasi pembuat preview → queued transactional pull.
- [x] Idempotency request dan row commit, shared lock, bounded retry, pemeriksaan baseline/mapping setelah preview, late duplicate queue failure tidak mengubah terminal success.
- [x] Sync Center, mapping branch, work order list/detail, CSV exceptions; loading/error/empty nyata.
- [x] Branch progress optimistic revision, status events, kendala/PIC/ETA/penyelesaian, verifikasi complete Area dan audit reopening.
- [x] Full Area monitoring push atomic managed output + readback verification, resume snapshot ketika outage; tidak pernah menulis BIMA_RAW.
- [x] Tes unit/feature/fake HTTP, workbook kontrak aktual, MySQL smoke, migration rollback, build dan visual QA.
- [ ] Editor users/roles di web, assignment teknisi/tim, dashboard KPI lengkap, approval branch conflict dan audit browser.
- [ ] M4 Telegram/material/outbox, M5 completeness/validation/export LHPP; perlu kontrak bisnis dan implementasi vertikal terpisah.

### Keputusan implementasi

1. Framework dan dependency awal dipertahankan; tidak menginstal ulang Laravel. Blade dengan progressive JavaScript dipakai untuk form, tema, drawer dan polling. Livewire/Alpine belum ditambahkan; ini deviasi stack frontend dari target PRD yang dicatat, bukan dependency yang dianggap sudah tersedia.
2. Branch mapping wajib eksplisit. Unknown/inactive/moved branch tidak diimpor, ditandai conflict. WO existing tidak dipindah branch secara otomatis. Belum ada approval pemindahan; koreksi sumber atau tindak lanjuti pada milestone approval.
3. Preview disimpan terenkripsi pada sync_jobs, berlaku 30 menit untuk konfirmasi. Commit bekerja dari snapshot ini; progress branch di MySQL boleh berubah selama review dan tidak akan ditimpa. Perubahan source hash/mapping membuat conflict.
4. Identitas H exact string, Q terpisah; kasus ID numerik literal di fake ditolak. Pembacaan Google mempertahankan formattedValue. Set ID sebagai teks sebelum paste, karena nol yang sudah hilang dari input tidak dapat ditebak kembali.
5. Kolom AC:AF diabaikan sebagai derived. Tanggal dan atribut A:AB diparse terkontrol; tanggal tidak valid/ID kosong/formula atribut/duplicate ID dicatat per baris. Tidak ada whole-data replacement BIMA_RAW.
6. Monitoring memakai full snapshot Area semua jenis pekerjaan, disortir berdasarkan key; tidak menawarkan incremental push semu. A:T adalah range output yang dikelola, kolom lain tidak ditulis. Batch Google memakai stringValue untuk mencegah formula injection pada output, lalu readback diverifikasi.
7. Row mutation + applied marker + audit adalah satu transaksi; retry tidak menggandakan WO atau audit insert/update. Detail preview retry boleh dibangun ulang hanya selama belum commit. API aplikasi tidak menyediakan edit/delete audit maupun event timeline.
8. Timestamp disimpan UTC, ditampilkan WIB. Official cutoff completion/reopening masih keputusan bisnis. Completed memerlukan Area setelah field done/pending validation, tanpa kendala aktif; status Bima CLOSE tidak otomatis completed internal.

### Verifikasi yang dilakukan

- `php artisan --version`: Laravel 13.37.0. `php -v`: CLI 8.5.10. `node -v`: v24.15.0.
- `php artisan migrate:status`: tiga migrasi awal sudah terpasang sebelum perubahan.
- `php artisan migrate --force`: kedua migrasi baru berhasil pada MySQL lokal dompisprov. Tidak menjalankan migrate:fresh atau rollback database operasional.
- MySQL smoke memakai synthetic account/branch/WO dalam outer transaction: encrypted preview, insert satu WO, progress IN_PROGRESS, pull ulang unchanged dan progress tetap. **Lulus, seluruh write test di-rollback**. Ini bukan seluruh suite feature pada MySQL.
- SQLite disposable: migrate → rollback dua migrasi bisnis → migrate. Awalnya ditemukan indeks users_role_index yang perlu dihapus sebelum drop role; diperbaiki dan dimasukkan regression test. Putaran terakhir lulus.
- `php artisan test`: lihat ringkasan final di bawah. Termasuk 3298 synthetic WO dua kali, workbook Bima asli 3298 ID dua kali di SQLite memory, preserved progress/missing WO, duplicate/blank ID, invalid date/header/formula, branch conflicts, stale mapping, RBAC, HTTP Google read/formula/write/JWT/readback failure, retry and revision, migration rollback.
- Workbook source test read-only memeriksa headers LHPP serta hash sumber. Jika workbook tidak disertakan di checkout/CI, dua source test ditandai skipped; synthetic regression tetap berjalan.
- `vendor/bin/pint ...` kemudian `--test`: style PHP diverifikasi pada file terkait, tanpa `--dirty` karena direktori bukan Git repository.
- `npm run build`: Vite production build lulus. Peringatan optional fontaine dari konfigurasi awal diatasi dengan optimizedFallbacks:false, tidak memasang dependency baru.
- `php artisan view:cache`: semua template Blade terkompilasi.
- `php artisan route:list --except-vendor`: route auth/WO/sync/mapping/progress/issues tersedia dan perubahan bisnis berada dalam middleware auth/active + policies/gates.
- Pencarian `gradient|rounded-2xl|rounded-3xl|rounded-full` pada resources/app: tidak ada kecocokan. Radius spinner saja berbentuk lingkaran karena indikator proses.
- SHA-256 kedua template akhir identik dengan preflight. Tidak ada template source yang diedit/dipublikasikan.

### Visual dan browser QA

Browser sungguhan pada server uji 127.0.0.1:8011, SQLite **sementara** dan akun/data sintetis; environment MySQL/credential operasional tidak diubah menjadi fake. Port 8000 ternyata aplikasi lain dan tidak dimodifikasi.

- Login/logout UI tersedia; login temporary account berhasil.
- Create branch → mapping F → queue preview → worker database → preview_ready → konfirmasi → worker → success 3 WO.
- Monitoring push confirmed → worker → success 3 rows pada file fake output lokal. Tidak ada request Google nyata.
- UI 1280×900, 768×1024, 375×812: lebar dokumen sama dengan viewport, tabel mempunyai scroll internal, desktop sidebar dan tablet/mobile drawer sesuai breakpoint.
- Mobile drawer terbuka, fokus masuk ke nav, Escape menutup dan mengembalikan fokus. Sidebar tertutup inert agar tidak masuk urutan Tab. Focus trap saat drawer terbuka.
- Tema light/dark tersimpan; sesudah reload tetap light sesuai pilihan. Console error/warn browser saat pemeriksaan awal kosong.

Bukti tampilan, semuanya data simulasi:

- [Desktop light](screenshots/sync-desktop-light.png)
- [Desktop dark](screenshots/sync-desktop-dark.png)
- [Tablet dark](screenshots/sync-tablet-dark.png)
- [Mobile light](screenshots/sync-mobile-light.png)
- [Mobile dark](screenshots/sync-mobile-dark.png)

### File yang ditambahkan/diubah

File awal yang diubah:

- `.env.example`, `.gitignore`, `README.md`, `vite.config.js`.
- `app/Models/User.php`, `app/Providers/AppServiceProvider.php`.
- `config/queue.php`, `routes/web.php`, `database/seeders/DatabaseSeeder.php`.
- `resources/css/app.css`, `resources/js/app.js`, `resources/views/welcome.blade.php`.
- `tests/Feature/ExampleTest.php` (welcome kini redirect ke aplikasi).

File baru:

- `config/olo.php`.
- `database/migrations/2026_10_18_000001_create_olo_foundation.php`.
- `database/migrations/2026_10_18_000002_create_branch_operations.php`.
- `app/Enums/Role.php`, `app/Contracts/GoogleSheetGateway.php`.
- `app/Models/Branch.php`, `BranchMapping.php`, `WorkOrder.php`, `SyncJob.php`, `SyncJobRow.php`, `AuditLog.php`, `WoStatusEvent.php`, `WoIssue.php`.
- `app/Policies/WorkOrderPolicy.php`, `app/Http/Middleware/ActiveUser.php`.
- `app/Http/Controllers/AuthController.php`, `BranchMappingController.php`, `SyncController.php`, `WorkOrderController.php`, `BranchProgressController.php`.
- `app/Services/BimaRowMapper.php`, `BimaSheetPullService.php`, `BranchProgressService.php`, `MonitoringSheetPushService.php`.
- `app/Services/Sheets/GoogleSheetsGateway.php`, `FakeGoogleSheetGateway.php`, `SheetException.php`.
- `app/Jobs/ProcessSheetSync.php`.
- `app/Console/Commands/CreateOloUser.php`, `DemoSheet.php`.
- `resources/views/layouts/app.blade.php`, `auth/login.blade.php`, `components/icon.blade.php`.
- `resources/views/sync/index.blade.php`, `show.blade.php`; `admin/mappings.blade.php`.
- `resources/views/work-orders/index.blade.php`, `show.blade.php`, `progress.blade.php`, `issues.blade.php`.
- `tests/Fakes/SheetGateway.php`, `tests/Unit/BimaRowMapperTest.php`.
- `tests/Feature/OloTestCase.php`, `AccessAndSyncTest.php`, `BimaPullTest.php`, `BranchOperationsTest.php`, `MonitoringPushTest.php`, `GoogleGatewayTest.php`, `SourceTemplateTest.php`, `MigrationRollbackTest.php`.
- `docs/IMPLEMENTATION.md`, lima gambar `docs/screenshots/sync-*.png`.

Artefak generated lokal: public/build, Blade cache, perubahan schema MySQL sesuai migrasi. .env operasional dan composer/package lock tidak diubah. Tidak ada Git commit karena repository metadata belum ada.

### Env dan onboarding

Lihat README untuk perintah lengkap. Key baru: SHEETS_DRIVER, GOOGLE_SHEETS_SPREADSHEET_ID, GOOGLE_APPLICATION_CREDENTIALS, SHEETS_FAKE_PATH (opsional), DB_QUEUE_RETRY_AFTER. Database dan shared cache menggunakan koneksi environment yang ada. Service-account key wajib di luar repo/public. Google ID/key asli belum disediakan; API hanya diuji dengan Http::fake dan fake lokal.

### Risiko nyata dan input yang masih dibutuhkan

- Service account, spreadsheet ID, akses edit/protected output, dan UAT end-to-end Google belum ada. Jangan menandai integrasi live sebelum hasil real berhasil.
- Akun operasional harus dibuat dengan olo:user, tidak disediakan default credentials. Admin UI roles/users belum tersedia. Akun lama tidak otomatis diaktifkan/diberi role Area.
- Daftar branch/mapping nyata dan siapa approver perubahan assignment belum disahkan. Mapping demo hanya dipakai dalam test sementara.
- Vocabulary kendala awal, izin verifikasi final, definisi KPI, cutoff dan aturan koreksi harian perlu approval pemilik. Tidak mengklaim policy operasional final sudah disahkan.
- Worker supervisor, queue heartbeat/alert, pembersihan encrypted preview yang expired, retention audit/PII, export evidence dan deployment HTTPS/backup perlu M6 sebelum produksi. Preview expired tetap terenkripsi di database sampai retention ditentukan; aksesnya dibatasi role sync.
- Manual retry diperlukan bila retry budget habis. Job sangat lama/worker crash mengikuti retry_after dan lock TTL; pilot 3.3k perlu mengukur waktu pada server produksi. Belum mengukur SLA Google nyata.
- M3 assignment teknisi, complete dashboards, conflict approval, audit browser dan changed-only push belum tersedia. Current push adalah full Area snapshot sesuai kontrak UI.
- M4 butuh grammar/allowlist Telegram, unit/master material, review/correction. M5 butuh mapping SID/No_SID, mandatory per job, verifikasi koordinat dan EKSISTING policy. Template sumber tetap referensi, bukan data ready resmi.

### Ringkasan final terverifikasi

- Suite akhir: **40 tes lulus, 191 assertions, 0 gagal, 0 skipped** (workbook referensi tersedia pada mesin ini), sekitar 15,6 detik.
- Pint --test: lulus. Vite build, Blade compilation dan 19 routes: lulus.
- MySQL smoke transaction: lulus dan rollback. Kedua migrasi bisnis tetap terpasang pada MySQL lokal.
- SQLite rollback/reapply: lulus setelah perbaikan urutan drop index; regression test termasuk suite akhir.
- Sesi browser sementara sudah logout dan ditutup; server uji di port 8011 dihentikan setelah QA. Server aplikasi lain di port 8000 tidak disentuh.
- Template hashes akhir sama persis. Tidak ada Google/Telegram real end-to-end test.


## Perubahan lanjutan: username superadmin dan ikon tema

Checklist dependensi: username schema → login handler/form → provisioning akun MySQL → ikon topbar/JavaScript → tests/build/browser. Seluruh langkah selesai.

- Akun `superadmin` aktif dengan role `super_admin`, diprovision pada database lokal sesuai permintaan eksplisit. Password ditangani lewat input CLI tersembunyi, di-hash oleh model dan diverifikasi melalui auth provider; tidak ditambahkan ke source, seeder, fixture atau dokumentasi. Akun berhasil login di browser dan sudah logout kembali.
- Login menerima username atau email; kontrak request email sebelumnya tetap didukung. Akun nonaktif/password salah tetap ditolak. Tidak perlu alamat email rekaan untuk akun username-only.
- Migrasi baru `2026_10_18_000003_add_username_to_users.php` berhasil di MySQL. Menambah username unik nullable dan mengizinkan email null. Rollback menolak bila akun tanpa email masih ada, agar tidak ada penghapusan atau perubahan identitas diam-diam. Tes rollback/reapply domain diperbarui menjadi tiga migrasi.
- Tombol tema berbentuk ikon bulan/matahari hanya di topbar setelah login. Tooltip/aria-label dinamis, dark preference tetap tersimpan. Tombol sidebar dan halaman login dihapus.
- UI diverifikasi pada desktop 1280px dan mobile 375px: satu tombol tema di topbar, icon berganti, tema tetap dark setelah reload, pageWidth sama dengan viewport. Browser login asli menggunakan akun yang diminta berhasil. Server aplikasi lokal 127.0.0.1:8011 dibiarkan tersedia pada akhir perubahan ini; aplikasi lain di port 8000 tidak disentuh.
- `php artisan test`: **44 lulus, 215 assertions, 0 gagal**, termasuk empat feature tests baru untuk login username/email, penolakan inactive/wrong password, dan posisi tombol tema.
- `npm run build`, Pint pada file PHP yang diubah, `php artisan view:cache`: lulus. Tidak ada env baru atau perubahan integrasi.

File yang berubah pada tindak lanjut ini: `app/Http/Controllers/AuthController.php`; `resources/views/auth/login.blade.php`; `resources/views/layouts/app.blade.php`; `resources/views/components/icon.blade.php`; `resources/js/app.js`; `resources/css/app.css`; `tests/Feature/MigrationRollbackTest.php`; `README.md`; `docs/IMPLEMENTATION.md`.

File baru: `database/migrations/2026_10_18_000003_add_username_to_users.php`; `tests/Feature/UsernameAndThemeTest.php`; `docs/screenshots/theme-topbar-desktop-light.png`; `docs/screenshots/theme-topbar-mobile-dark.png`; `docs/screenshots/login-username.png`.

Bukti terbaru: [Desktop dengan ikon topbar](screenshots/theme-topbar-desktop-light.png), [Mobile dark](screenshots/theme-topbar-mobile-dark.png), [Login tanpa tombol tema](screenshots/login-username.png). Screenshot Sync Center sebelumnya adalah dokumentasi baseline sebelum perubahan posisi tombol ini.


## Tindak lanjut: spreadsheet sumber Bima

- ID spreadsheet dari tautan pemilik telah disimpan pada `.env` lokal; driver Google dipilih. Kredensial lain tidak diubah. Tidak ada migrasi baru.
- Browser dapat membuka file COPAS WO PROV WIBS AREA3. Tab yang tampak: Dashboard, OLO AREA-3, REKAP ENOM, Boundry STO. BIMA_RAW belum terlihat; tidak ada tab/file sumber yang diedit atau dihapus dalam tindak lanjut ini.
- Sync Center menampilkan tautan file sumber dan petunjuk paste A:AF ke BIMA_RAW. Preview/push dinonaktifkan ketika konfigurasi/file credential tidak tersedia, dengan status penjelasan yang nyata.
- Ditambahkan `olo:sheets:check` untuk membaca/validasi tanpa impor/publish atau data pelanggan di output. Test fakes tidak dianggap Google nyata.
- Diagnostik nyata saat ini **gagal karena file kredensial service account belum tersedia**, sebelum request API. Tidak ada klaim Google API terhubung atau WO sudah masuk dari file ini. Pertanyaan path JSON telah disampaikan kepada pemilik; masih menunggu input.
- `php artisan test`: **48 lulus, 232 assertions**; Pint, npm run build, view:cache lulus. Empat tes onboarding mencakup target link/status credential, pemeriksaan tanpa perubahan, duplicate/header, dan penanda simulasi.
- File baru: `app/Console/Commands/CheckGoogleSheets.php`, `tests/Feature/SheetsOnboardingTest.php`, `docs/GOOGLE_SHEETS_SETUP.md`. File diubah: `.env` lokal (tidak untuk source control), `app/Http/Controllers/SyncController.php`, `resources/views/sync/index.blade.php`, `README.md`, `docs/IMPLEMENTATION.md`.
- Langkah tersisa: pemilik menyiapkan tab BIMA_RAW dan JSON service account dengan sharing file yang tepat, memasang path kredensial, lalu diagnostic nyata → preview → review mapping → konfirmasi pull. Panduan rinci tersedia pada GOOGLE_SHEETS_SETUP.md.


### Pemasangan key Google dan hasil pemeriksaan akses

- Pemilik menyatakan key sudah diunduh dan tab BIMA_RAW sudah dibuat.
- Key terkait wibs-area3 ditemukan pada Downloads; hanya jenis key dan email service account yang diperiksa, private key tidak dicetak. `.env` lokal GOOGLE_APPLICATION_CREDENTIALS sudah diarahkan ke file tersebut dan config cache dibersihkan.
- `php artisan olo:sheets:check` dengan akses jaringan/cache MySQL lokal menghasilkan **HTTP 403**. Pembacaan metadata minimal berikutnya memastikan **PERMISSION_DENIED**. Token service account berhasil diperoleh, tetapi akses spreadsheet belum lolos; tidak ada impor MySQL atau perubahan Google Sheets yang dilakukan.
- Langkah pengguna: Bagikan spreadsheet kepada `wibs-area3@monitoring-354105.iam.gserviceaccount.com` sebagai Editor, kemudian pemeriksaan diulang sebelum preview/pull.
- Perubahan hanya `.env` lokal dan dua dokumen ini. Tidak ada migrasi, perubahan source aplikasi, atau pengulangan suite tests/build karena kode tidak berubah. Diagnostik eksternal ini dicatat sebagai belum berhasil, terpisah dari hasil fake pada tests.


### Verifikasi Google setelah akses dibagikan

- Pemeriksaan nyata `php artisan olo:sheets:check` **lulus**: BIMA_RAW dapat dibaca, header 32 kolom benar, valid format 0, rejected 0, duplicate 0. Ini memverifikasi akses baca, bukan keberhasilan impor WO atau push monitoring.
- Preview #4 dari web sempat ditolak karena worker memiliki konfigurasi lama. Hash job cocok dengan konfigurasi terbaru ketika diperiksa lewat CLI; antrean saat itu kosong. Queue restart disinyalkan dan worker baru dijalankan dengan `php artisan queue:work --tries=5 --timeout=180 --sleep=1`.
- Preview web baru **#5** diproses melalui antrean ke state **preview_ready**, attempts 1 dan semua hitungan 0. Tidak ada order untuk diimpor; konfirmasi pull tidak dijalankan. Sumber Sheets tidak ditulis.
- UI kini menampilkan waktu pembacaan BIMA_RAW terakhir yang berhasil untuk koneksi yang sama. Snapshot kosong mempunyai pesan paste order dan tidak menawarkan konfirmasi. Commit kosong juga ditolak backend dengan 409, sehingga tidak tercatat sebagai pull sukses semu.
- `php artisan test`: **50 lulus, 243 assertions**. Ditambah tes snapshot kosong tidak dapat commit dan read status tidak tercampur koneksi lain. Pint, npm run build dan view:cache lulus.
- File diubah: `app/Http/Controllers/SyncController.php`, `resources/views/sync/index.blade.php`, `resources/views/sync/show.blade.php`, `tests/Feature/SheetsOnboardingTest.php`, `README.md`, `docs/GOOGLE_SHEETS_SETUP.md`, `docs/IMPLEMENTATION.md`. Screenshot baru: `docs/screenshots/google-bima-preview-empty.png`. Tidak ada migrasi atau env baru pada tindak lanjut ini.
- Langkah tersisa: pemilik paste order sebenarnya ke BIMA_RAW (data tanpa header mulai A2); kemudian preview baru, mapping/review dan konfirmasi pull. Snapshot kosong #5 tidak berubah otomatis ketika data Google diedit. Push monitoring/material/Telegram belum diuji nyata.


## Penamaan primary key dan foreign key bisnis

Selesai sesuai instruksi pengguna: 10 tabel bisnis memakai `id_<nama_tabel>`, seluruh foreign key terkait disesuaikan, 8 tabel internal Laravel tetap standar. Migrasi `2026_10_18_000004_name_table_primary_and_foreign_keys` berhasil pada MySQL/MariaDB lokal; seluruh data dibandingkan dengan cadangan terenkripsi dan tetap sama. Aplikasi/worker aktif kembali, sesi superadmin lama serta halaman mapping/detail sinkronisasi berfungsi. Tidak ada env baru, perubahan credential, API Google/Telegram, atau perubahan workbook.

Hasil akhir: SQLite 56 tests/356 assertions lulus; MariaDB terisolasi 54 tests/345 assertions lulus dengan dua uji snapshot besar dikecualikan karena batas paket server 1 MiB. Tests migrasi/kompatibilitas MariaDB khusus 7/122 lulus. Pint, Vite build, Blade compilation lulus. Batas paket server perlu dinaikkan oleh pengelola sebelum pilot ribuan order; konfigurasi global belum diubah.

Daftar kolom, file yang berubah, bukti data, backup, langkah rollback dan batas server lengkap tersedia di [ID_RENAMING.md](ID_RENAMING.md).


## Master region dan Service Area / STO

Migrasi `2026_10_18_000005_create_regions_and_service_areas` berhasil dieksekusi pada database lokal: 3 region, 392 STO, seluruhnya terhubung ke 16 branch yang kini tersedia dengan kecocokan ID dan regional. Users mendapat `id_regions` dan `id_service_areas` nullable; tidak ada penugasan atau hak akses baru otomatis. Model/relasi, routing STO dengan konflik aman, serta perintah admin lokal untuk mapping/penetapan lokasi telah ditambahkan dan diaudit. Referensi areas/user asal disimpan dalam metadata karena master asal tidak disediakan.

Data 18 tabel lama telah dibandingkan terhadap cadangan terenkripsi dan tetap utuh; aplikasi/worker kembali aktif. SQLite 63 tests/433 assertions lulus; MariaDB terisolasi 61 tests/422 assertions lulus (dua uji snapshot besar dikecualikan karena batas server 1 MiB). Pint/build/Blade lulus, tanpa env/dependency baru atau panggilan API eksternal. Daftar file, skema, perintah, sumber dan hasil lengkap: [REGIONS_AND_STO.md](REGIONS_AND_STO.md).


## Region branch dan User Management

Migrasi `2026_10_18_000006_add_branch_regions_and_user_revision` telah diterapkan: 16/16 branch mempunyai region (Jatim 6, Jateng–DIY 6, Balnus 4), 392 STO tetap konsisten; seluruh kolom lama pada 20 tabel dibandingkan dengan backup dan tetap sama. Users mendapat revisi manajemen default 0 untuk edit bersamaan. Aplikasi/worker aktif kembali.

User Management khusus superadmin menyediakan daftar/search/filter/pagination, tambah/edit identitas, role, aktif, multi-branch dan lokasi. Transaksi+audit, validasi scope/lokasi, perlindungan akun sendiri/superadmin terakhir, stale form 409, password hash/blank-preserve, reset token dan pencabutan sesi sudah diuji.

SQLite **75 tests/619 assertions** dan MariaDB terisolasi **73 tests/608 assertions** lulus (dua uji snapshot besar dikecualikan karena batas paket 1 MiB yang sudah diketahui). Pint/build/Blade lulus. UI light/dark diperiksa di 1280/768/375px, tanpa overflow; QA browser tidak membuat akun dummy atau mengubah akun asli. Tidak ada env/dependency baru atau panggilan API eksternal. Daftar file dan bukti lengkap: [USER_MANAGEMENT.md](USER_MANAGEMENT.md).


## Ruang kerja Admin Branch dan analytics

Login role Branch kini menuju dashboard dengan order hari ini, PS/complete hari ini, outstanding, order berkendala, grafik harian 7/14/30 hari dan antrean prioritas. Semua query/filter mengikuti cabang aktif yang ditugaskan. Pull mengisi branch berdasarkan mapping/STO sehingga setiap admin cabang langsung memperoleh akses pada WO yang sama tanpa duplikasi. Form mendukung 15 kategori permintaan pemilik, detail/notes kendala append-only, resolusi, optimistic lock, audit, penutupan dan koreksi Area. Push monitoring menampilkan kategori progress terbaru; pull tidak menimpa operasional.

Migrasi `2026_10_18_000007_add_branch_progress_and_issue_notes` sudah diterapkan: kategori/tanggal complete WO, metadata kategori timeline dan tabel `wo_issue_notes` dengan PK/FK khusus nama tabel. Seluruh kolom lama pada 20 tabel dibandingkan dengan backup terenkripsi sebelum browser QA dan tetap sama (9 WO, 2 users, 2 penugasan, 2 event, 1 issue); hanya kategori SURVEY yang diketahui di-backfill. Tidak ada dummy order/user pada database aplikasi. Aplikasi port 8011 dan worker baru aktif kembali. Workbook/SQL sumber tidak diubah.

Hasil akhir: SQLite **83 tests/774 assertions**, MariaDB terisolasi **81 tests/759 assertions**, ditambah regresi final MariaDB untuk alur/analytics/migrasi **8 tests/155 assertions**, semuanya lulus. Dua tes snapshot besar tetap dikecualikan pada MariaDB karena batas paket server 1 MiB; keduanya lulus di SQLite. Pint, Vite build dan Blade compilation lulus. Browser aplikasi utama diperiksa sebagai superadmin pada 1280/768/375px, light/dark, filter branch, detail kategori/catatan dan drawer. Tabel mobile 980px bergeser di kontainer 341px, sementara page tetap 375px. Server browser QA port 8012 ditolak izin, dihentikan dan tidak diakses lewat jalur lain; branch-role/nonzero chart diuji melalui feature tests.

PS awal adalah progress CLOSE (WIB), terpisah dari verifikasi Area; CLOSE ON DESK administratif. `.env.example` menyediakan opsi `BRANCH_CLOSE_ON_DESK_COUNTS_AS_COMPLETE=false`; tidak ada perubahan credential atau `.env` asli. Arahan material terbaru Telegram → Sheets → sync web dicatat sebagai pengganti arah baseline. Bot/material import/LHPP belum diaktifkan atau diuji end-to-end; perlu identitas/token bot privat, allowlist dan kontrak tab laporan. Tidak ada panggilan API Google/Telegram pada iterasi ini. Definisi metrik, perilaku reopen, skema, perintah, batas, bukti UI dan manifest seluruh file: [BRANCH_WORKFLOW.md](BRANCH_WORKFLOW.md).


## Dashboard Area dan pembaruan aturan PS

Dashboard `/dashboard` khusus empat role Area sudah berfungsi dan menjadi beranda/menu sesuai role. Tampilan mengikuti struktur referensi dengan desain white/dark, aksen #315B8C, tanpa gradient: rekap kategori per regional/cabang, subtotal/grand total, rekap pekerjaan, KPI, antrean kendala/data yang perlu ditinjau dan grafik PS bulanan. Angka rekap dapat dibuka ke daftar WO dengan filter yang sama.

PS kini wajib progress web CLOSE + STATUS BIMA CLOSE/COMPLETE; tanggal harian/grafik berasal dari TGL PS BIMA. STATUS FINAL, tanggal pull, waktu laporan Branch dan opsi CLOSE ON DESK tidak menggantikan syarat tersebut. Dashboard Branch dan daftar Complete/PS hari ini diselaraskan. Data/status/riwayat asli tidak di-backfill atau diubah; konfirmasi BIMA sesudahnya memengaruhi metrik melalui query tanpa overwrite progress. Tidak perlu migrasi/env/paket/kredensial baru.

SQLite 89 tests/913 assertions serta MariaDB terisolasi 87 tests/902 assertions lulus; dua snapshot besar hanya dikecualikan pada MariaDB karena batas paket 1 MiB. Regresi akhir filter/jumlah cabang/jenis kosong: 13 tests/276 assertions lulus. Pint, build Vite dan kompilasi Blade lulus. Browser aplikasi utama memverifikasi 9 WO, 0 PS dan 1 WO berkendala, filter MATARAM/BALNUS, drill-down Survey 1 WO, interaksi grafik/keyboard, light/dark dan 1280/768/375px tanpa overflow halaman. Tidak membuat data dummy atau melakukan request Google/Telegram.

Manifest file, skema metrik, perintah, batas dan bukti screenshot: [AREA_DASHBOARD.md](AREA_DASHBOARD.md).
