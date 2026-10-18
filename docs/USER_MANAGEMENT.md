# Branch region dan manajemen user

Checklist dependensi:

- [x] Tambah `branches.region_id` (sesuai nama yang diminta), FK ke `regions.id_regions`, backfill berdasarkan label/kode regional yang cocok persis.
- [x] Tambah relasi model dan gunakan region terstruktur saat membuat branch/pemetaan STO.
- [x] Buat daftar/tambah/edit user khusus superadmin, validasi role/lokasi/cabang dan audit transaksi.
- [x] Lindungi akun superadmin terakhir, perubahan hak sendiri, password dan sesi login.
- [x] Buat UI responsif light/dark, pencarian/filter, loading/empty/error state.
- [x] Jalankan tests SQLite dan MariaDB terisolasi, lint/build dan QA browser.
- [x] Cadangkan database lokal, jalankan migrasi/backfill, verifikasi data, aktifkan worker dan dokumentasikan hasil.

Arsitektur awal: Laravel 13, Blade/Tailwind dengan token CSS light/dark, Eloquent primary key khusus dan `user_branches` untuk RBAC. Master berisi tiga region dan 392 STO. User memiliki region/STO opsional. Label lama `branches.regional` dipertahankan sebagai referensi; `region_id` menjadi relasi terstruktur. Tabel internal Laravel, template dan credential tidak diubah.

Backfill tidak menebak label yang tidak dikenal. Akun bot tidak dikelola lewat form login web. Relasi lokasi tidak menggantikan scope multi-branch.


## Hasil migrasi dan backfill

Migrasi `2026_10_18_000006_add_branch_regions_and_user_revision.php` berhasil dieksekusi pada MariaDB 10.4.28 lokal (`dompisprov`) melalui `php artisan migrate --force`, sekitar 53 ms.

- `branches.region_id`: unsigned bigint nullable, FK RESTRICT ke `regions.id_regions`, beserta index. Nama `region_id` mengikuti permintaan eksplisit pengguna; primary key master tetap `id_regions`.
- Backfill mencocokkan nama atau kode region setelah trim/case normalization. Hanya kecocokan tunggal yang diisi; label tidak dikenal tetap null. Tidak membuat region rekaan.
- Hasil lokal: **16/16 branch terisi**, JATIM 6, JATENGDIY 6, BALNUS 4; seluruh **392 STO** memiliki region yang sama dengan branch-nya.
- Label `branches.regional` serta nilai kolom lama/timestamp dipertahankan. Pembuatan branch lewat aplikasi sekarang mewajibkan pemilihan region dan mengisi label lama dari master.
- `users.management_revision`: unsigned integer default 0 untuk mencegah penyimpanan formulir user yang sudah kedaluwarsa. Penetapan lokasi melalui CLI juga menaikkan revisi.
- `Branch::region()` dan `Region::branches()` tersedia. Validasi memakai region_id bila tersedia; data legacy yang belum dipetakan tetap didukung melalui label persis. Konflik region branch/STO ditolak saat pemetaan/pull dan penetapan user.
- Dua puluh tabel lama dibandingkan dengan cadangan terenkripsi: seluruh nilai kolom lama tetap sama, termasuk akun/password superadmin, 16 branch, 3 region, 392 STO, 8 audit, 6 sync job, dan 27 detail baris. Tabel internal Laravel tetap standar; migrations mendapat satu catatan baru.

Backup sebelum perubahan: `storage/app/private/maintenance/before-user-management-20261018-022629.json.enc`, permission 0600, dienkripsi memakai APP_KEY lokal. Backup dan key tidak boleh di-commit. Aplikasi dibuat maintenance dan worker proyek dihentikan selama penerapan; `php artisan up` dan worker antrean sudah diaktifkan kembali setelah verifikasi.

Rollback satu migrasi menghapus `branches.region_id` dan `users.management_revision` saja. Nilai label regional, akun, penugasan, dan master lama tetap dipertahankan. Lakukan dalam maintenance bersama pengembalian source yang sesuai; jangan rollback schema ketika source masih memakai kolom baru.

## User Management di aplikasi

Menu **User Management** tersedia pada sidebar untuk superadmin aktif. Route web memakai autentikasi, pemeriksaan akun aktif, Gate `administer`, dan CSRF; controller/service memeriksa izin kembali. Role lain tidak dapat membaca atau mengubah akun melalui endpoint ini.

- Daftar user dengan pencarian nama/username/email, filter role/status, pagination 15 per halaman, dan empty state.
- Tambah/edit nama, username, email, role, aktif/nonaktif, multi-branch, region utama, dan STO utama.
- Minimal username atau email diperlukan. Username disimpan lowercase, memakai huruf/angka/titik/garis bawah/tanda hubung; identitas login unik. Akun bot tidak ditampilkan atau dikelola melalui halaman ini.
- Akun baru memerlukan kata sandi terkonfirmasi minimal 12 karakter, huruf besar/kecil dan angka. Saat edit, kedua kolom password kosong mempertahankan hash sebelumnya. Password tidak pernah dipasang sebagai nilai input atau dimasukkan ke audit/flash input.
- Role cabang memerlukan penugasan branch; akun aktif harus mempunyai minimal satu branch aktif. Region/STO utama harus sesuai cabang aktif yang dipilih. Role Area tetap mempunyai scope seluruh Area. Memilih lokasi saja tidak memberikan akses cabang.
- Pilihan STO pada form mengikuti role, region, dan branch. Sebagai contoh aktual, SURABAYA + JATIM menampilkan 35 STO; DMO dipetakan ke DARMO/SURABAYA. Tanpa JavaScript, backend tetap memvalidasi seluruh relasi.
- Perubahan user dan pivot cabang berada dalam satu transaksi, disertai audit actor/entity, akses sebelum/sesudah, revisi, dan penanda password berubah. Nilai password, hash, token, nama/email tidak disalin ke metadata audit.
- Akun sendiri tidak dapat dinonaktifkan atau diturunkan dari superadmin. Lock pada baris superadmin menjaga minimum satu superadmin aktif saat perubahan akses. Admin lain dapat melakukan perubahan akun sesuai otorisasi.
- Pengiriman edit dengan revisi lama mendapat **409** dan halaman untuk memuat ulang data terbaru. Kegagalan audit membatalkan transaksi user/pivot.
- Perubahan password/identitas/akses merotasi remember token dan mencabut sesi database lain milik target; sesi aktif aktor sendiri dipertahankan. Verifikasi email direset bila alamat berubah; token reset password lama dicabut bila email/password berubah.
- Tidak ada penghapusan akun, pemberian password default, atau pengiriman email otomatis dalam fitur ini.

## Verifikasi otomatis dan browser

Perintah yang dijalankan: `php artisan test --compact`, `vendor/bin/pint --test`, `npm run build`, `php artisan view:cache`, `php artisan migrate --force`, lalu pembandingan data dengan backup. Tests MariaDB dijalankan dalam database sementara acak melalui runner lokal, kemudian database uji dihapus. Tidak ada `migrate:fresh` pada database aplikasi.

- **SQLite: 75 tests lulus, 619 assertions.** Termasuk 11 tests User Management dan satu test backfill/rollback baru, di samping regresi sebelumnya.
- **MariaDB terisolasi: 73 tests lulus, 608 assertions.** Dua tests snapshot ribuan baris tetap dikecualikan karena `max_allowed_packet=1 MiB` pada server lokal; batas ini tidak menghalangi fitur user/backfill.
- Tests mencakup izin seluruh role, user baru dapat login, hash password, edit tanpa reset password, multi-branch/lokasi, penolakan FK/duplikat/role bot, akun sendiri/last admin, stale form, kegagalan audit atomik, pencabutan sesi/token, reset verifikasi, pencarian/filter/pagination, dan backfill yang tidak menebak.
- Pint, Vite build, Blade compilation lulus.
- Browser lokal memakai sesi superadmin yang sudah ada. Daftar, tambah dan edit user terbuka setelah migrasi; tidak membuat akun dummy atau mengubah akun asli untuk QA. Alur simpan diuji dengan data terisolasi pada feature tests.
- Desktop **1280px**, tablet **768px**, mobile **375px** diperiksa. Page width sama dengan viewport; form ditumpuk pada tablet/mobile. Light dan dark memakai token desain yang sama, radius 6px, tanpa gradient. Password edit kosong dan tidak required; satu tombol tema tetap di topbar. Viewport/tema sementara dikembalikan setelah QA.
- Loading state pada submit, error validasi, empty state, dan konflik 409 tersedia. Tabel lebar tetap dapat digulir pada mobile.

Bukti tampilan:

- [Daftar user desktop light](screenshots/users-desktop-light.png)
- [Tambah user desktop light](screenshots/users-create-desktop-light.png)
- [Tambah user mobile dark](screenshots/users-create-mobile-dark.png)
- [Edit user tablet dark](screenshots/users-edit-tablet-dark.png)
- [Edit user desktop light](screenshots/users-edit-desktop-light.png)

Tidak ada env/dependency baru, perubahan credential, panggilan Google/Telegram, atau perubahan file SQL/workbook sumber. Tidak ada keputusan pemilik yang menghalangi fitur ini. Daftar pengguna dan penugasan nyata dapat diisi superadmin melalui menu baru; hanya akun yang sudah ada dipertahankan selama implementasi.

## File baru

- `database/migrations/2026_10_18_000006_add_branch_regions_and_user_revision.php`
- `app/Http/Controllers/UserManagementController.php`
- `app/Http/Requests/SaveUserRequest.php`
- `app/Services/UserManagementService.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/admin/users/form.blade.php`
- `resources/views/errors/409.blade.php`
- `tests/Feature/UserManagementTest.php`
- `tests/Feature/BranchRegionMigrationTest.php`
- `docs/USER_MANAGEMENT.md` dan lima screenshot di atas.

## File diubah

- `app/Models/Branch.php`, `app/Models/Region.php`, `app/Models/User.php`
- `app/Enums/Role.php`
- `app/Http/Controllers/BranchMappingController.php`
- `app/Services/BimaSheetPullService.php`
- `app/Console/Commands/MapStoBranch.php`, `app/Console/Commands/AssignUserLocation.php`
- `routes/web.php`
- `resources/views/layouts/app.blade.php`, `resources/views/components/icon.blade.php`, `resources/views/admin/mappings.blade.php`
- `resources/css/app.css`, `resources/js/app.js`
- `tests/Feature/MigrationRollbackTest.php`, `tests/Feature/LocationsMigrationTest.php`, `tests/Feature/NamedKeysMigrationTest.php`, `tests/Feature/RegionAndStoTest.php`
- `README.md`, `docs/IMPLEMENTATION.md`

Migrasi lama tidak ditulis ulang; tests rollback mengikuti urutan dependensi terbaru (enam migrasi bisnis). Workspace tidak memiliki `.git`; tidak ada checkout/reset/commit atau penimpaan branch Bima.
