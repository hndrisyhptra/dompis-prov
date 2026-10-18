# Region dan Service Area (STO)

## Inspeksi dan keputusan awal

Laravel 13, model Eloquent dengan primary key `id_<tabel>`, MySQL/MariaDB, dan SQLite untuk tests. RBAC cabang tetap melalui `user_branches`. Integrasi baru menambahkan master lokasi tanpa memberi hak akses dari relasi lokasi saja.

Sumber pengguna: `regions.sql` (3 region) dan `service_areas (1).sql` (392 STO unik, ID 1–393 dengan gap 317). SQL berasal dari database lain dan hanya dibaca sebagai data; perintah SQL dump tidak dieksekusi langsung. Dump merujuk 16 branch, tabel areas, dan user lama. Database lokal terkini sudah memiliki 16 branch; areas dan identitas user asal belum tersedia. Relasi branch dicocokkan dengan keberadaan ID dan label regional sumber; bila tidak cocok tetap null. Identitas audit user lama tidak diasumsikan sama dengan akun lokal.

Checklist dependensi:

- [x] Buat snapshot data referensi terstruktur, pertahankan file SQL asli.
- [x] Tambahkan migrasi master region/STO serta foreign key nullable pada users.
- [x] Tambahkan model/relasi dan pemetaan STO saat preview/pull tanpa mengubah hak akses cabang.
- [x] Sediakan perintah penetapan mapping branch dan lokasi user dengan validasi/audit.
- [x] Uji import, relasi, penolakan foreign key, scope, konflik, preview lama, rollback, lalu lint/build.
- [x] Cadangkan data lokal, jalankan migrasi, periksa jumlah/master/data lama, hidupkan worker kembali.

Mapping branch menggunakan master yang sudah ada bila ID dan label regional cocok; mapping yang belum cocok memerlukan penetapan eksplisit. Referensi area/user lama disimpan sebagai metadata sumber, tanpa membuat area atau akun rekaan.


## Struktur dan relasi

- `regions`: primary key `id_regions`, `code` unik, `name`, `is_active`, timestamp, soft delete, `id_users_created_by`, `id_users_updated_by`, dan `source_metadata`.
- `service_areas`: primary key `id_service_areas`, `workzone` unik (kode STO), `name`, `id_branches`, `id_regions`, `is_active`, timestamp, soft delete, kedua kolom audit user, dan `source_metadata`.
- `users`: tambahan `id_regions` dan `id_service_areas`, nullable dengan foreign key RESTRICT. Relasi model `region()` dan `serviceArea()` tersedia; lokasi utama bersifat tunggal dan opsional. `user_branches` tetap mengatur hak akses multi-branch.
- `Branch::serviceAreas()`, `Region::serviceAreas()`, `Region::users()`, `ServiceArea::branch()`, `ServiceArea::region()`, `ServiceArea::users()`, serta relasi creator/updater tersedia dengan primary key khusus.
- Dua foreign key ke users memakai `id_users_created_by`/`id_users_updated_by` agar perannya berbeda. Referensi audit asal tidak dihubungkan hanya berdasarkan angka ID, melainkan tetap berada dalam metadata. Tidak ada akun rekaan dibuat.
- `area_id` pada dump region dipertahankan dalam `source_metadata`. Tabel `areas` tidak disediakan dan belum dibuat; tidak ada foreign key ke tabel yang tidak ada.

## Data referensi dan aturan impor

Snapshot `database/data/olo_locations_20261018.json` menyimpan seluruh record dari kedua SQL (3 + 392). Primary key asal dipertahankan nilainya dengan nama kolom baru. ID STO 317 memang tidak ada di dump; tidak diisi dengan data rekaan. Semua nama, kode, status, timestamp dan nilai asal lengkap tetap tersedia dalam metadata.

Master branch lokal terkini berjumlah 16: ID 2–17. Migrasi menghubungkan STO hanya bila ID branch asal tersedia dan `branches.regional` sama persis dengan nama region yang dirujuk sumber. Hasil lokal: 392 dari 392 STO terhubung; tidak ada branch baru dibuat atau branch lama ditimpa. Pada instalasi kosong atau referensi yang berbeda, `id_branches` tetap null hingga penetapan eksplisit oleh admin.

Migrasi menolak bila tabel master baru sudah ada, sehingga tidak menimpa tabel buatan pihak lain. Menjalankan `migrate` kembali melewati migrasi yang sudah tercatat dan tidak menggandakan master. Batch insert 100 record digunakan agar data referensi jauh di bawah batas paket lokal.

File sumber tidak diubah:

- `regions.sql`: SHA-256 `058e1d922d233bfffde659cc08257c8f3aa5749057f1a3217287e6e3a0d8196f`
- `service_areas (1).sql`: SHA-256 `b7e1e83e64f9f096b74fd293c7b1c062157190aa8374611da288794fdc7f8aae`

## Pemakaian mapping STO pada pull

Preview/pull tetap membaca BIMA kolom F dan G. Mapping branch Bima yang eksplisit tetap didukung; master `service_areas.workzone` menambahkan kepemilikan branch berdasarkan STO. Jika kedua sumber mapping aktif berbeda, baris ditandai konflik. STO/region/branch tidak aktif atau dihapus ditolak. STO yang belum memiliki branch tidak memberikan routing otomatis; mapping Bima eksplisit yang tersedia tetap dapat digunakan bila master STO aktif.

WO yang sudah ada tetap diperiksa branch dan identitas sumbernya. Perubahan mapping setelah preview atau perubahan branch WO menghasilkan konflik; progress, revisi, kendala dan histori tidak dipindahkan/ditimpa. Preview lama yang sudah tersimpan tidak otomatis dihitung ulang: buat preview baru di Sync Center setelah penambahan master ini.

## Perintah admin lokal

Perintah berikut merupakan contoh penggunaan, bukan penugasan user yang dijalankan otomatis:

```bash
php artisan olo:sto-map DMO SBY
php artisan olo:user-location NAMA_USER --region=JATIM --sto=DMO
php artisan olo:user-location NAMA_USER --region=JATIM
php artisan olo:user-location NAMA_USER --clear
```

`olo:sto-map` memerlukan STO, region dan branch aktif, serta label regional branch yang sesuai. Setiap perubahan tercatat di audit. Remap STO yang sedang dipakai lokasi user ditolak sampai penugasan user direview. Perintah ini tidak memindahkan WO lama.

`olo:user-location` menerima username atau email. STO harus sudah mempunyai branch aktif; region diisi dari STO bila hanya `--sto` yang diberikan. Region dan STO yang berbeda ditolak. Untuk role branch, lokasi harus berada dalam `user_branches` yang aktif; perintah tidak menambah hak cabang. Role Area dapat memiliki lokasi utama tanpa membatasi atau memperluas role Area yang sudah ada. `--clear` menghapus lokasi utama saja. Audit menyimpan nilai sebelum/sesudah tanpa password.

Belum ada daftar penugasan lokasi user dari pemilik, jadi `users.id_regions` dan `users.id_service_areas` tetap null, termasuk akun superadmin. Pembatasan per-STO belum dijadikan aturan RBAC baru; akses tetap mengikuti branch sesuai PRD.

## Migrasi, backup dan hasil aktual

- Migrasi baru: `2026_10_18_000005_create_regions_and_service_areas.php`.
- `php artisan migrate --force` berhasil pada MariaDB 10.4.28 database `dompisprov` (sekitar 245 ms).
- Aplikasi dibuat maintenance dan worker proyek dihentikan sebelum perubahan. Cadangan terenkripsi: `storage/app/private/maintenance/before-locations-20261018-021029.json.enc`, permission 0600. APP_KEY diperlukan untuk membukanya; jangan commit backup atau key.
- Semua 18 tabel lama dibandingkan terhadap cadangan: nilai kolom lama tetap sama, termasuk 16 branch, akun/password superadmin, 8 audit, 6 sync job dan 27 detail baris. Users hanya mendapat dua kolom baru bernilai null. Semua tabel internal Laravel tetap sama; tabel migrations mendapat satu catatan normal.
- Ketiga region, 392 STO, seluruh metadata sumber, kepemilikan branch/regional, dan foreign key diperiksa langsung. Tidak ada referensi yatim, tidak ada data dummy atau penugasan user otomatis.
- `php artisan up` berhasil. Worker `php artisan queue:work --tries=5 --timeout=180 --sleep=1` aktif kembali.
- SQLite: **63 tests lulus, 433 assertions**. Mencakup seluruh regresi sebelumnya dan 7 tests baru untuk sumber/master, foreign key/unique, routing/conflict, inactive/deleted, user location/scope, perintah admin, data lama dan rollback.
- MariaDB pada database uji terisolasi: **61 tests lulus, 422 assertions**. Dua tests snapshot ribuan baris tetap dikecualikan karena `max_allowed_packet` server lokal 1 MiB sebagaimana catatan sebelumnya. Database uji dihapus setelah selesai; tidak ada `migrate:fresh` pada database aplikasi.
- `vendor/bin/pint --test`, `npm run build`, dan `php artisan view:cache`: lulus. Tests rollback bisnis diperbarui menjadi lima migrasi. Tidak ada desain UI yang diubah.
- Tidak ada env atau dependency baru. Tidak ada request Google/Telegram atau perubahan workbook/Google Sheets dalam pekerjaan ini.

Rollback `php artisan migrate:rollback --step=1 --force` telah diuji terisolasi: menghapus dua kolom lokasi user dan kedua tabel master baru, sambil mempertahankan nilai kolom user dan branch lama. Lakukan hanya saat maintenance dan bersama pengembalian source yang sesuai. Data/penugasan lokasi yang ditambahkan setelah migrasi perlu dicadangkan sebelum rollback.

## File baru

- `database/migrations/2026_10_18_000005_create_regions_and_service_areas.php`
- `database/data/olo_locations_20261018.json`
- `app/Models/Region.php`
- `app/Models/ServiceArea.php`
- `app/Console/Commands/AssignUserLocation.php`
- `app/Console/Commands/MapStoBranch.php`
- `tests/Feature/RegionAndStoTest.php`
- `tests/Feature/LocationsMigrationTest.php`
- `docs/REGIONS_AND_STO.md`

## File diubah

- `app/Models/User.php`
- `app/Models/Branch.php`
- `app/Services/BimaSheetPullService.php`
- `tests/Feature/MigrationRollbackTest.php`
- `README.md`
- `docs/IMPLEMENTATION.md`

Skema lama dan workbook/SQL asli tidak ditulis ulang. Tidak ada repository `.git` di workspace; tidak ada reset/checkout/commit atau perubahan branch Bima.
