# Order Prioritas — 24 Oktober 2026

Discovery: Laravel 13.37 / Blade / Vite. Satu master WO dengan scope user_branches, policy view/update dan komponen aksi desktop/mobile. Belum ada bookmark. Gunakan tabel terpisah, tanpa menyalin WO atau menulis progress/sumber BIMA. Area dan Branch mempunyai daftar bersama terpisah; Branch lokal tidak boleh bocor melalui list, eager load, hitungan, filter atau tombol ke Area.

Checklist implementasi:
- [x] Tabel named PK/FK, unique scope per order, model/query visibility.
- [x] Gate/CRUD bookmark idempotent dengan transaksi, lock, audit dan token stale delete.
- [x] Tombol bookmark dekat No WO pada Work Order dan detail, tanpa mengubah source/progress.
- [x] Menu monitoring responsif, filter, ringkasan, latest progress/issue dan sumber pemilih.
- [x] Uji privasi Area/Branch, role, replay/revocation, sumber tetap dan migration; build/lint.
- [x] Eksekusi migration aditif dan dokumentasikan hasil aktual.

Keputusan awal: pembuat Prioritas Area adalah super_admin/area_admin/area_hd; TL/HD/Admin Branch mengelola Prioritas Branch bersama tim Branch yang sama. Koordinator Area, Admin Material dan Bot tidak diberi akses baru secara implisit. Area hanya melihat pilihan Area; Branch melihat Area sesuai assignment order dan daftar lokalnya. Default semua jenis pekerjaan agar bookmark tidak tersembunyi karena filter NEW INSTALL. Order Close tidak otomatis dihapus dari pantauan.


## Konsep dan hasil

Dua sumber prioritas dalam satu menu, ditandai **Prioritas Area** dan **Prioritas Branch**. Tidak membuat salinan WO. Klik bookmark dekat No WO pada tabel desktop/kartu mobile Work Order atau pada detail. Area memilih prioritas bersama Area; Branch memilih prioritas lokal sesuai Branch WO saat ini. Menekan bookmark yang sudah dipilih melepas pilihan sendiri setelah konfirmasi Ya/Tidak; WO, perangkat, kendala, source hash dan riwayat tetap.

| Actor | Dapat memilih/melepas | Yang terlihat pada menu |
|---|---|---|
| Superadmin, Admin Area, HD Area | Prioritas Area | Hanya pilihan Area, seluruh Branch |
| TL, Admin Branch, HD Branch | Prioritas Branch sesuai assignment aktif | Pilihan Area untuk Branch-nya + pilihan lokal tim Branch-nya |
| Koordinator Area, Admin Material, Bot, akun nonaktif | Tidak diberi akses baru | Menu dan endpoint ditolak |

Privasi diterapkan pada query list, ringkasan, filter, relasi eager pada Work Order/detail, bookmark control dan mutasi; bukan sekadar menyembunyikan badge. Actor Branch tidak dapat melepas pilihan Area atau Branch lain. Scope selalu dihitung server dari role dan Branch WO; input scope/id_branch/actor/order di body ditolak. Semua pengguna Branch dalam tim yang sama berbagi pilihan lokal (termasuk multi-Branch sesuai penugasan). Promosi/perubahan hak akses tidak memberi akses ke prioritas lokal melalui role Area.

Satu order yang dipilih oleh Area dan Branch tampil satu kali di monitoring Branch dengan kedua penanda, pemilih dan waktu masing-masing. Bookmark Area mengikuti assignment WO; bookmark lokal Branch tetap melekat pada Branch pemilih. Jika assignment WO berubah, bookmark lokal lama disembunyikan dari Branch baru dan lama yang tidak lagi berhak atas WO. Tidak memindahkannya otomatis atau membocorkannya ke Area. Area dapat menandai WO yang belum terpetakan; Branch baru melihatnya setelah assignment valid.

Monitoring menyediakan pencarian No WO/SID/pelanggan, filter Branch, sumber Area/Branch untuk tim Branch, status dan sort prioritas terbaru/umur/update. Default semua jenis pekerjaan; ringkasan total/tindak lanjut/kendala/menunggu BIMA mengikuti pencarian, Branch dan sumber sebelum filter status. Tabel menampilkan status progress, status BIMA, jumlah kendala aktif, umur, update terakhir, sumber pemilih dan waktu WIB. Detail serta aksi Progress/Kendala/Close memakai policy dan halaman yang sudah ada. Tidak menambah widget PS terkonfirmasi atau mengganti aturan PS.

Tombol **Perbarui** membaca keadaan terkini dengan filter tetap; waktu pemeriksaan halaman ditampilkan. Tidak memakai angka demo atau mengklaim push realtime/polling otomatis. Close/CANCEL tidak menghapus bookmark otomatis agar tetap bisa ditinjau; pelepasan dilakukan eksplisit. Tidak ada fitur catatan prioritas, SLA atau notifikasi otomatis pada tahap ini.

UI menggunakan token white/dark, aksen #315B8C, radius 6px, kartu ringkas tanpa garis aksen atas, tooltip, empty/error/loading nyata dan layout kartu mobile. Bookmark dan unbookmark mengikuti dialog konfirmasi global yang sudah ada.

## Penyimpanan dan integritas

Migration `2026_10_24_000016_create_work_order_priorities.php`: PK `id_work_order_priorities`; FK `id_work_orders`, `id_branches` nullable (null=Area), `id_users`; `scope_key` dihitung server (`area` atau `branch:{id}`); selection_token UUID unik; timestamps; unique `(id_work_orders,scope_key)` dan index scope/Branch/waktu. FK restrict, tanpa cascade penghapusan sumber.

POST memilih secara idempotent, DELETE melepas secara idempotent dalam scope actor. Transaksi mengunci actor terkini dan WO, memeriksa ulang policy/assignment, unique constraint menahan duplikasi. Token UUID mencegah permintaan hapus lama melepas bookmark yang sudah ditambahkan ulang; stale ditolak 409. Audit `priority.selected/priority.removed` mencatat scope, actor dan WO tanpa menyentuh row WO. Bookmark lokal yang tersimpan pada Branch lama tetap dilindungi sebagai referensi saat master Branch hendak dihapus. Pemeriksaan tersebut bersifat internal: halaman master tidak menampilkan jumlah/identitas bookmark Branch ke role Area; penolakan delete memakai pesan referensi umum yang sama.

## File tahap ini

- Baru `database/migrations/2026_10_24_000016_create_work_order_priorities.php`, `app/Models/WorkOrderPriority.php`, `app/Services/PriorityOrderService.php`, `app/Http/Controllers/PriorityOrderController.php`.
- `app/Enums/Role.php`, `app/Providers/AppServiceProvider.php`, `app/Policies/WorkOrderPolicy.php`: capability/gate/policy spesifik.
- `app/Models/WorkOrder.php`, `app/Http/Controllers/WorkOrderController.php`: relasi dan eager load yang menjaga privasi.
- `app/Services/MasterDataService.php`, `resources/views/master-data/delete.blade.php`: perlindungan referensi bookmark pada Branch lama tanpa menampilkan count/identitas prioritas Branch kepada Area.
- `routes/web.php`, `resources/views/layouts/app.blade.php`, `resources/views/components/icon.blade.php`: route/menu/ikon.
- Baru `resources/views/priorities/{index,bookmark,selection-meta,monitoring-status,empty}.blade.php`.
- `resources/views/work-orders/{index,mobile-card,show}.blade.php`, `resources/css/app.css`: bookmark dekat No WO dan tampilan responsif.
- Baru `tests/Feature/PriorityOrdersTest.php`, `PriorityOrdersMigrationTest.php`.
- Enam tes rollback historis: `ExcelSourceColumnsMigrationTest`, `LocationsMigrationTest`, `BranchRegionMigrationTest`, `BranchProgressMigrationTest`, `SingleBranchMigrationTest`, `MigrationRollbackTest`: jumlah step disesuaikan dengan satu migration tambahan, skenario preservasi tetap.
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, dokumen ini: requirement, scope dan hasil aktual.

## Verifikasi dan eksekusi

```sh
php vendor/bin/phpunit --filter='PriorityOrdersTest|PriorityOrdersMigrationTest' --no-progress --colors=never
php /private/tmp/olo-priority-orders-mysql-tests.php
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
node --test tests/JavaScript/form-confirmation.test.js
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
npm run build
php artisan view:cache
git diff --check
```

- Targeted SQLite dan MariaDB: **9 tes / 242 assertion lulus** pada masing-masing engine. MariaDB menggunakan database acak terisolasi dan dihapus dalam finally.
- Regresi lengkap SQLite: **193 tes / 4.989 assertion lulus**. Tujuh tes JavaScript konfirmasi juga lulus.
- Skenario mencakup privasi list/count/detail/indikator/filter, seluruh enum role, replay, stale token, lintas Branch, scope spoof, pencabutan assignment, pemilih tim, perpindahan WO, dedup dua scope, semua jenis pekerjaan, Close tetap dipantau, filter/pagination, sumber/progress tetap dan migration reversible/unik/FK restrict.
- Build frontend, Pint, Blade cache, pemeriksaan route dan diff check lulus.

Migration aplikasi **sudah dijalankan** memakai helper `/private/tmp/olo-apply-priority-migration.php`, yang memanggil:

```sh
php artisan migrate --path=database/migrations/2026_10_24_000016_create_work_order_priorities.php --force
```

Hasil: migration DONE; **3.414 WO** sebelum/sesudah, digest seluruh row WO **tidak berubah**. Tabel bookmark awal kosong (0 pilihan), tidak menandai order nyata untuk pengujian. Hanya tabel baru dan catatan migration ditambah. Tidak ada env/paket baru, tidak mengubah internal Laravel, source template, credential, progress branch atau assignment WO. Tidak commit/push maupun mengganti branch kerja.

Belum melakukan pemeriksaan visual/interaksi browser karena izin preview sebelumnya ditolak; tidak mencoba ulang lewat browser/port lain. Render HTTP dan build terverifikasi, bukan klaim browser QA atau integrasi Google/Telegram E2E. Tidak ada keputusan bisnis tambahan yang menghalangi penggunaan. Bookmark lokal yang assignment-nya berubah disimpan untuk riwayat/privasi dan tidak dipindahkan; belum ada UI arsip/cleanup bookmark demikian atau dashboard histori snapshot prioritas.
