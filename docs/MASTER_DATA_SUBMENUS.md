# Master Data: submenu dan pengelolaan mapping

Discovery: Laravel 13/Blade; master lokasi sudah memakai SoftDeletes. mapping_order dan mapping_ownergrup sudah tersedia dengan PK bernama unik, kunci sumber case-sensitive, kategori dan status aktif. Mapping Order disimpan juga sebagai klasifikasi turunan pada WO; Owner Grup dibaca langsung saat rekap. Tidak perlu migration atau paket baru.

Checklist:
- [x] Sidebar Master Data dengan lima submenu; hilangkan tiga tab pada konten.
- [x] CRUD dua mapping, validasi kategori dan kunci persis, versi edit, transaksi/lock/audit.
- [x] Rekonsiliasi klasifikasi Order tanpa menyentuh sumber/progress; lindungi mapping terpakai dari delete/perubahan kunci.
- [x] Ikon kecil Edit/Delete dengan accessible label dan tooltip.
- [x] Feature/regresi tests, build/lint, dokumentasi hasil dan batas visual.


## Hasil

Master Data di sidebar memakai parent `details/summary` yang dapat dibuka dengan keyboard; submenu aktif terbuka otomatis dan ditandai `aria-current`. Lima halaman terpisah tersedia di `/master-data/regions`, `/branches`, `/service-areas`, `/mapping-orders`, `/mapping-owner-groups` (semuanya di bawah prefix `/master-data`). Halaman tambah/edit/hapus berada di submenu yang sama. `/master-data` mengarah ke Region. Tidak ada lagi tab dalam konten. Sidebar dapat digulir jika menu panjang; fokus drawer mobile mengabaikan link submenu yang tertutup.

Mapping Order: kunci `status_order` (maksimal 511), kategori OPEN/STUCK/PS/CANCLWORK, active. Mapping Owner Grup: kunci `owner_grup` (maksimal 255), kelompok DISTRICT/PMD/ED REGIONAL/SDI REGIONAL/FF SO 3/FF SO 4/DCS, active. Kunci mempertahankan huruf besar/kecil dan karakter dari sumber, hanya whitespace tepi dibuang. Tidak ada inferensi kategori atau perubahan sumber untuk mencocokkan aturan. List memiliki cari/filter kategori/status dan pagination; form mempunyai validasi per field dan loading submit.

Simpan Mapping Order merekonsiliasi klasifikasi WO BIMA yang terkait (kunci lama/baru, legacy task kosong seperti -COMPWORK, atau FK mapping). Hanya `id_mapping_order` dan `mapping_order` diubah, termasuk mengosongkannya ketika aturan dinonaktifkan. Tidak mengubah task/status sumber, hash, progress, PS, perangkat, revision, timestamp WO atau log BIMA. Audit mencatat sebelum/sesudah rule dan ID WO yang direkonsiliasi. Ini tidak memberi status PS otomatis hanya karena kategori mapping adalah PS. Mapping Owner Grup mengikuti lookup aktif saat rekap sehingga tidak perlu menulis ulang WO.

Delete meminta konfirmasi checkbox dan fingerprint versi. Tiga master lokasi tetap soft delete; kedua tabel mapping yang sudah ada tidak memiliki soft delete, sehingga hanya mapping yang belum digunakan dapat dihapus permanen dengan audit nilai sebelum penghapusan. Referensi diperiksa ulang di transaksi. Mapping yang dipakai WO (FK atau kunci sumber) tidak dapat dihapus atau diganti kuncinya, termasuk setelah dinonaktifkan; kategori dan active tetap dapat diperbarui. Data sumber WO tidak dihapus atau dipindahkan. Status Nonaktif adalah pilihan untuk menghentikan aturan yang masih digunakan.

Tombol aksi tabel pada kelima submenu berupa ikon pensil/trash kecil dengan tooltip **Edit** / **Delete**, `aria-label` berisi tindakan dan kunci record. Tooltip hover/fokus, Escape dan native fallback mengikuti komponen sebelumnya; mobile memiliki target 40px. Tombol Delete membuka halaman konfirmasi, bukan langsung menghapus.

## File diubah

- `app/Services/MasterDataService.php`: definisi lima tipe, CRUD mapping, uniqueness, usage guard, rekonsiliasi turunan dan audit.
- `app/Http/Controllers/MasterDataController.php`: pencarian/filter/form/validasi untuk dua mapping.
- `resources/views/layouts/app.blade.php`, `routes/web.php`: submenu sidebar dan redirect root.
- `resources/views/master-data/index.blade.php`, `form.blade.php`, `delete.blade.php`: halaman tanpa tab, ikon aksi, konfirmasi sesuai jenis penghapusan.
- Baru `resources/views/master-data/mapping-form.blade.php`; hapus view `tabs.blade.php` yang sudah tidak digunakan.
- `resources/css/app.css`, `resources/js/app.js`: submenu responsif, ukuran ikon, scroll sidebar dan fokus drawer.
- `tests/Feature/MasterDataTest.php`: RBAC lima submenu dan adaptasi pengujian lokasi.
- Baru `tests/Feature/MasterMappingTest.php`: CRUD empat role untuk kedua mapping, validasi/unik case-sensitive, filter, audit, empty-task, klasifikasi, sumber tetap, stale version, konfirmasi, lock import, referensi terpakai, ikon dan submenu.
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, `docs/MASTER_DATA.md`, dokumen ini: requirement dan hasil aktual.

## Verifikasi

```sh
vendor/bin/pint app/Services/MasterDataService.php app/Http/Controllers/MasterDataController.php tests/Feature/MasterDataTest.php tests/Feature/MasterMappingTest.php routes/web.php
php vendor/bin/phpunit --filter='MasterDataTest|MasterMappingTest' --no-progress --colors=never
php /private/tmp/olo-master-submenus-mysql-tests.php
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
npm run build
node --check resources/js/app.js
vendor/bin/pint --dirty --test
php artisan view:cache
git diff --check
```

Tes Master Data + Mapping: **15 tes / 939 assertion lulus**, baik SQLite maupun MariaDB. Database MariaDB tes dibuat terpisah dan dihapus dalam finally; tidak mengubah database aplikasi. Regresi lengkap SQLite: **179 tes / 4.408 assertion lulus**. Build frontend, pemeriksaan sintaks JavaScript, Pint, Blade view cache, dan git diff whitespace check lulus. Kegagalan awal pada pembandingan fixture snapshot tes telah diperbaiki dengan membaca ulang seluruh default kolom sebelum membandingkan; seluruh tes kemudian dijalankan ulang dan lulus.

Tidak ada migration atau env baru, tidak ada data template/credential/progress/role akun nyata diubah. Tidak ada keputusan bisnis tambahan yang menghalangi fitur. Pemeriksaan browser interaktif belum diverifikasi karena izin preview pada tahap sebelumnya ditolak; tidak mencoba ulang melalui browser/port lain. Markup/submenu/ikon/tooltip diuji melalui render HTTP, bukan klaim visual browser. Perubahan kategori didesain berlaku pada klasifikasi terkini; tidak membuat snapshot historis rekap sebelum kategori berubah. Menjalankan ulang command referensi Excel lama dapat mengembalikan aturan dari file tersebut; gunakan dengan sengaja setelah mengelola aturan manual.
