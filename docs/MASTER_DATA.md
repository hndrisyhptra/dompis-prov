# Master Data Branch, Region, Service Area — 23 Oktober 2026

Discovery: Laravel 13/Blade, ketiga master sudah mempunyai PK bernama unik, status aktif dan SoftDeletes. Branch.region_id adalah relasi Region, ServiceArea.id_regions/id_branches adalah mapping STO. Referensi dipakai user, WO dan routing import. Tidak perlu migration baru.

Checklist:
- [x] Gate dan menu untuk super_admin/area_coordinator/area_hd/area_admin aktif, termasuk penolakan endpoint untuk role lain.
- [x] List/pencarian/filter status, tambah, edit dan konfirmasi hapus untuk tiga master.
- [x] Validasi kode unik, parent aktif/konsisten, transaksi/row lock, versi edit, audit dan lock inbound.
- [x] Hapus sebagai arsip/soft delete, tolak data yang masih direferensikan; jangan cascade delete sumber/user/WO.
- [x] Feature tests CRUD/RBAC/relasi/konkurensi dan build.
- [ ] UI light/dark/mobile secara visual; izin browser ditolak, belum diklaim lulus.

Nama dan relasi master tidak mengubah atribut sumber BIMA maupun memindahkan WO otomatis. Akses diperluas sesuai permintaan lanjutan: Superadmin, Koordinator Area, HD Area dan Admin Area aktif dapat mengelola ketiga master. Role Branch dan akun nonaktif tetap ditolak. Data sumber asli dan credential tetap.

## Hasil implementasi

- Gate `manage-master-data` dan sidebar untuk **Superadmin (`super_admin`), Koordinator Area (`area_coordinator`), HD Area (`area_hd`) dan Admin Area (`area_admin`) aktif**. Endpoint list, tambah, edit, konfirmasi dan hapus memakai gate yang sama; role lain mendapat 403. Perluasan ini mengikuti arahan terbaru pemilik; User Management tetap dengan izin terpisah.
- `/master-data` mengarah ke tab Branch. Tiga tab menyediakan pencarian kode/nama, filter aktif/nonaktif, filter Region/Branch yang sesuai dan pagination 25 data. Form menyediakan status, kode, nama dan relasi induk. Pilihan Branch pada Service Area disaring mengikuti Region.
- Kode dinormalisasi kapital, unik termasuk arsip. Validasi panjang mengikuti kolom SQL. Service Area aktif harus mempunyai Branch/Region aktif yang sesuai. Region/Branch dengan anak aktif tidak dapat dinonaktifkan sebelum anaknya disesuaikan.
- Penghapusan membutuhkan halaman konfirmasi, checkbox dan versi data. Penghapusan memakai SoftDeletes yang sudah tersedia. Seluruh referensi (termasuk anak master yang diarsipkan), user, penugasan, routing dan WO diperiksa kembali dalam transaksi; tidak ada cascade atau pemindahan otomatis. Kode STO yang sudah digunakan tidak dapat diganti. Perubahan mapping yang berbenturan dengan user/WO ditolak.
- Penyimpanan memakai transaksi, row lock, pemeriksaan ulang role aktif, fingerprint versi data untuk mencegah overwrite edit bersamaan, lock inbound bersama import, dan audit `master_data.created/updated/deleted` dengan nilai sebelum/sesudah. Rename Region hanya menyelaraskan label legacy `branches.regional`, tanpa mengubah atribut sumber atau progress WO.
- Styling memakai token light/dark yang ada, radius 6px, form dua kolom menjadi satu di layar kecil dan tabel dapat digeser. Loading submit, empty, validation error, disabled dan confirmation state nyata tersedia.

## File tahap ini

- `app/Enums/Role.php`, `app/Providers/AppServiceProvider.php`: capability dan gate.
- `app/Services/MasterDataService.php`: transaksi CRUD, relasi, perlindungan, audit dan konkurensi.
- `app/Http/Controllers/MasterDataController.php`, `routes/web.php`: validasi, list/filter/pagination, endpoint CRUD.
- `resources/views/layouts/app.blade.php`: menu bersyarat.
- `resources/views/master-data/{tabs,index,form,delete}.blade.php`: antarmuka tiga master.
- `resources/css/app.css`, `resources/js/app.js`: layout responsif dan pilihan Branch sesuai Region.
- `tests/Feature/MasterDataTest.php`: 7 feature tests untuk RBAC seluruh role, CRUD tiga master, validasi, audit/arsip, dependensi, order tidak berubah, stale version, lock import, pencabutan akses, pencarian/filter dan pagination.
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, `docs/MASTER_DATA.md`: aturan pemilik dan hasil aktual.

## Verifikasi

Perintah yang dijalankan dari root repository:

```sh
vendor/bin/pint app/Enums/Role.php app/Providers/AppServiceProvider.php app/Http/Controllers/MasterDataController.php app/Services/MasterDataService.php routes/web.php tests/Feature/MasterDataTest.php
php vendor/bin/phpunit --filter=MasterDataTest --no-progress --colors=never
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-master-data-mysql-tests.php
npm run build
vendor/bin/pint --dirty --test
git diff --check
php artisan route:list --path=master-data
php artisan view:cache
```

- Tes regresi SQLite: **171 tes, 3.765 assertion lulus**.
- Master Data SQLite dan MariaDB: **7 tes, 296 assertion lulus** pada masing-masing engine. Helper MariaDB membuat database acak terisolasi dan menghapusnya dalam `finally`; database aplikasi tidak diubah oleh tes.
- Build frontend berhasil.
- Pemeriksaan visual browser **belum terverifikasi**: izin membuka preview lokal data sintetis ditolak oleh kebijakan browser setelah permintaan izin. Tidak dilakukan percobaan ulang melalui port/browser lain. Tidak ada screenshot hasil UI yang diklaim. Preview tidak mengubah database aplikasi dan server preview sudah dihentikan.

## Migration, env, batas

Tidak ada migration maupun env baru: tabel `branches`, `regions`, `service_areas`, soft delete dan audit sudah tersedia. Tidak melakukan backfill/mutasi data aplikasi, perubahan role user nyata, commit atau push Git. Template asli tidak disentuh.

Data berelasi yang dilindungi harus disesuaikan referensinya sebelum dapat dihapus; arsip tidak memiliki UI restore pada tahap ini. Perubahan master berlaku sebagai referensi untuk operasi berikutnya, bukan redistribusi massal WO historis. Referensi routing legacy tetap dipertahankan; jika routing legacy dan mapping STO berbeda, import tetap meminta review. Tidak ada keputusan bisnis tambahan yang menghalangi penggunaan fitur; UAT visual pada browser pengguna masih perlu dilakukan.


## Pembaruan akses dan UI — permintaan lanjutan 23 Oktober 2026

Checklist implementasi: perluas capability empat role Area → rapikan list/form/tombol → uji CRUD role tambahan dan markup tooltip → build/lint/regresi → dokumentasi. Arsitektur tetap Blade + CSS/JS yang ada; tidak menambah paket atau skema, tidak mengubah progress/WO/credential/akun nyata.

- Superadmin dan Koordinator Area kini dapat list, tambah, edit dan hapus pada ketiga master dengan perlindungan relasi/audit/versi yang sama. Test mencakup CRUD nyata kedua role tersebut di database pengujian, penolakan seluruh role lain, dan akun nonaktif dari empat role Area.
- Navigasi tiga master memakai ikon, deskripsi singkat dan jumlah data dari database. Header daftar, pencarian/filter, badge status, aksi, empty state dan pagination dipisah dengan hierarki yang lebih jelas. Tabel tetap dapat digeser di mobile.
- Form memisahkan identitas, lokasi dan status, menampilkan panduan di samping (bertumpuk di tablet/mobile), serta pesan error di dekat input yang ditandai `aria-invalid`. Label tombol tetap terlihat pada perangkat sentuh.
- Komponen `resources/views/components/master-action.blade.php` memberikan label, ikon dan tooltip untuk tombol Tambah, Terapkan, Reset, Edit, Hapus, Batal dan Simpan. Native `title` menjadi fallback tanpa JS; JS menampilkan satu tooltip `role=tooltip` di luar tabel agar tidak terpotong, menghubungkannya melalui `aria-describedby`, membatasi posisi dalam viewport, mendukung fokus keyboard dan Escape, serta menyembunyikannya saat scroll/resize/klik.
- File tambahan pada pembaruan ini: `resources/views/components/master-action.blade.php`, perubahan ikon di `resources/views/components/icon.blade.php`. File yang diubah: `app/Enums/Role.php`, keempat view `resources/views/master-data/`, `resources/css/app.css`, `resources/js/app.js`, `tests/Feature/MasterDataTest.php`, dokumen ini, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`. Controller/service/gate tetap menggunakan capability terpusat; tidak perlu mengubah route atau menambahkan izin Superadmin global.

Verifikasi pembaruan: `php vendor/bin/phpunit --filter=MasterDataTest --no-progress --colors=never` lulus **9 tes / 534 assertion**; `npm run build`, `vendor/bin/pint --dirty --test`, `git diff --check` dan `php artisan view:cache` lulus. Verifikasi markup pada response Blade mencakup tooltip setiap tombol Master Data dan error input; ini bukan klaim pengujian browser interaktif. Regresi lengkap SQLite lulus **173 tes / 4.003 assertion**. `node --check resources/js/app.js` juga lulus. Tes MariaDB 7/296 di bagian sebelumnya adalah hasil tahap awal; tidak diulang untuk pembaruan akses/UI ini. Tidak ada migration, env, atau input bisnis tambahan yang diperlukan. Batas pemeriksaan visual browser dari tahap sebelumnya tetap berlaku; tidak mencoba ulang akses yang sudah ditolak.


## Perubahan terbaru: submenu dan dua mapping
Navigasi tiga tab telah diganti dengan lima submenu Master Data: Region, Branch, Service Area, Mapping Order dan Mapping Owner Grup. Detail kontrak CRUD, perlindungan sumber/relasi, daftar file dan hasil tes terbaru ada di [MASTER_DATA_SUBMENUS.md](MASTER_DATA_SUBMENUS.md). Rekap dan verifikasi tahap sebelumnya di atas adalah histori implementasi.
