# OLO Command Center Jawa–Bali


**Pembaruan 20 Oktober 2026:** penerimaan WO sekarang melalui upload XLSX BIMA, mapping kolom, preview dan konfirmasi pada Sync Center. Google tidak diperlukan untuk import. Panduan lama BIMA_RAW di bawah hanya untuk mode legacy `WO_ORDER_SOURCE=sheets`. Lihat [panduan import Excel, schema dan hasil uji](docs/EXCEL_WORK_ORDER_IMPORT.md). Default baru `WO_ORDER_SOURCE=excel`. [Preset export Work Orders](docs/BIMA_WORK_ORDERS_TEMPLATE.md) otomatis memilih 11 kolom sesuai mapping pemilik. Nama fisik kolom telah disesuaikan dengan label sumber, misalnya `tgl_order`, `no_tq_sc_id`, `nama_olo` dan `status_order`; lihat [daftar kolom MySQL](docs/WORK_ORDER_COLUMN_NAMES.md).

Laravel 13.37 / PHP ≥8.3, MySQL, Blade, Tailwind 4, Vite 8. Implementasi pertama mencakup fondasi RBAC, mapping branch, preview/pull Bima dan bagian operasional M3. Requirement produk ada di `AGENTS.md`, `SKILLS.md`, dan `PRD_OLO_Command_Center_v2.md`.

## Yang sudah berfungsi

- Login lewat username atau email, logout dengan regenerasi session, pembatasan percobaan login, akun aktif dan role backend. Multi-branch scope pada daftar/detail/progress/kendala. Identitas bot tidak dapat login web.
- Role `super_admin`, `area_coordinator`, `area_admin`, `area_hd`, `branch_admin`, `team_leader`, `material_admin`, `bot`. Sync hanya super admin, admin Area dan HD Area. Koordinator membaca Area serta memverifikasi progress. Provisioning user melalui CLI terkontrol; belum ada editor role/user di web.
- Branch dan mapping F + STO di web. STO spesifik lebih dahulu, lalu mapping umum. Mapping tidak ditebak dari formula AC.
- Sync Center dengan preview queued, snapshot terenkripsi, konfirmasi, pull queued, retry, status polling, audit dan exception CSV. Preview berlaku 30 menit sebelum konfirmasi. Perubahan sumber/mapping sesudah preview diperiksa ulang.
- Pull idempotent pada `(source_system, external_order_id)` dengan ID teks persis di H, terpisah dari SID di Q. Tidak ada penghapusan karena WO hilang dari snapshot. Progress, revision, verification dan kendala tidak ditimpa.
- Daftar/detail WO, default filter PSB `NEW INSTALL`, progress dengan optimistic revision, timeline immutable melalui API aplikasi, kendala dengan PIC/ETA/resolution. COMPLETED perlu Area, pekerjaan FIELD_DONE/PENDING_VALIDATION, dan tidak ada kendala aktif. Pembukaan kembali status akhir memerlukan Area dan tercatat.
- Monitoring full snapshot push melalui queue: atomic `batchUpdate` untuk **A:T pada MONITORING_OLO**, menghapus sisa baris output lama dalam batch yang sama, lalu verifikasi readback. Tidak menulis BIMA_RAW. Retry memakai snapshot tetap; push berikutnya mengambil kondisi MySQL terbaru. Tidak ada incremental/changed-only push pada versi ini.
- UI putih, primary #315B8C, tombol ikon matahari/bulan di topbar setelah login (tanpa tombol tema pada form login), dark mode tersimpan mengikuti sistem sebelum dipilih, radius 6px, drawer mobile dengan fokus keyboard, tabel scroll, empty/error/loading state dari proses nyata.

**Pembacaan Google Sheets dan preview antrean sudah diuji dengan kredensial nyata: header BIMA_RAW valid, 0 baris order.** Pull dengan data order dan monitoring push belum diuji nyata. Alur material Telegram → MySQL, template 45 kolom, koreksi dan laporan web kini disiapkan, tetapi bot belum dibuat/diaktifkan atau diuji nyata. Ekspor LHPP belum diimplementasikan. Hasil fake tetap dipisahkan dari pemeriksaan nyata. Panduan aktivasi: [Laporan Material Telegram](docs/TELEGRAM_MATERIAL_REPORTS.md).

## Menjalankan proyek

Environment repository sudah mempunyai `vendor` dan `node_modules`; tidak dilakukan bootstrap ulang. Untuk checkout baru:

```sh
composer install
npm ci --ignore-scripts
cp .env.example .env
php artisan key:generate
```

Edit `.env`: gunakan user MySQL khusus dengan hak database aplikasi, isi `APP_URL`, `DB_*`, dan jangan mengganti APP_KEY dari instalasi yang sudah menyimpan encrypted preview. Target document root adalah **public/**, bukan root repository. Gunakan PHP CLI ≥8.3; PHP bawaan XAMPP yang lebih tua tidak dapat menjalankan Laravel ini.

```sh
php artisan migrate
php artisan olo:user admin@example.com --name="Administrator Area" --role=super_admin
npm run build
php artisan serve --host=127.0.0.1 --port=8011
```

Perintah `olo:user` meminta kata sandi secara tersembunyi, minimal 12 karakter + besar/kecil + angka. Tidak ada akun/kata sandi default. Untuk role branch, buat branch pada web, lalu provision:

```sh
php artisan olo:user branch@example.com --name="Admin Branch" --role=branch_admin --branch=KODE
```

`--branch` boleh diulang. Role branch wajib minimal satu branch aktif. Kolom role/active tidak mass assignable dari request. Akun yang sudah ada sebelum migrasi tetap tidak aktif sampai diprovision/direview oleh administrator; tidak otomatis diberi hak Area.

Jalankan worker di terminal/proses terpisah:

```sh
php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1
```

Gunakan `QUEUE_CONNECTION=database`, `CACHE_STORE=database` (atau Redis bersama) dan `DB_QUEUE_RETRY_AFTER=300`. Timeout job 180 detik, lock koneksi 240 detik, visibility/retry_after 300 detik. Shared cache diperlukan untuk lock antar-worker; jangan memakai array cache di production. Worker perlu `queue:restart` setelah deploy/perubahan konfigurasi. Jalankan worker melalui supervisor produksi. Queued yang belum berjalan berarti menunggu worker; bukan sync sukses.

Port 8000 pada sesi pengembangan sudah digunakan aplikasi lain; pengujian OLO memakai 8011 dengan SQLite sementara. Server uji dan akun sintetisnya bukan environment operasional.

## Google Sheets

Variabel yang ditambahkan di `.env.example`:

| Key | Penggunaan |
| --- | --- |
| `SHEETS_DRIVER` | `google` default; `fake` hanya non-production |
| `GOOGLE_SHEETS_SPREADSHEET_ID` | ID file spreadsheet, bukan URL |
| `GOOGLE_APPLICATION_CREDENTIALS` | Path absolut JSON service account di luar repo/public |
| `SHEETS_FAKE_PATH` | Opsional, JSON fixture lokal; default `storage/app/private/bima-demo.json` |
| `DB_QUEUE_RETRY_AFTER` | 300 detik, harus lebih panjang dari timeout job |

Onboarding Google:

1. Aktifkan Sheets API pada project milik organisasi. Simpan key service account di lokasi privat di luar repository, dengan izin baca hanya proses aplikasi/worker.
2. Share spreadsheet kepada email service account sebagai editor. Buat `BIMA_RAW` dan `MONITORING_OLO`; buat tab lain dari PRD saat modulnya tersedia. Lindungi output secara organisasional. Adapter hanya mengelola MONITORING_OLO A:T.
3. Paste **values only** dari `OLO AREA-3` A:AF ke `BIMA_RAW` A:AF, header di baris 1. Set kolom H, Q, SN/ID sebagai teks sebelum paste agar nol depan tidak hilang. Nilai yang sudah dirusak Excel tidak dapat dipulihkan otomatis.
4. Buat mapping branch dari F dan STO. Label mapping di-trim dan di-uppercase untuk pencarian; nilai sumber tetap disimpan. Branch yang tidak terpetakan/inaktif menjadi conflict dan tidak diimpor.
5. Isi env, jalankan `php artisan config:clear` untuk lokal (atau config cache saat deployment), restart worker, lalu buat preview pada Sync Center. Header boleh trim/collapse whitespace; kapitalisasi, urutan dan jumlah 32 kolom wajib identik. Tidak ada silent remap.
6. Periksa ringkasan dan exception CSV lalu konfirmasi. Konfirmasi hanya oleh pembuat preview. Preview membaca Google sekali; pull menggunakan snapshot yang telah dikonfirmasi, bukan membaca data remote baru tanpa review.
7. Setelah rekonsiliasi MySQL, konfirmasi penggantian output monitoring dan push. Sukses baru dicatat setelah API write dan readback cocok. Filter tampilan WO tidak membatasi push: push adalah full Area snapshot untuk seluruh jenis pekerjaan.

Tanggal didukung: serial Excel (termasuk penanganan anomali 1900), ISO YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, serta tanggal+jam format yang terdokumentasi pada mapper. Format ambigu lain ditolak; set tanggal Sheets ke ISO atau simpan serial dari paste values. Google read mengambil formattedValue dan userEnteredValue dalam satu grid snapshot: identitas tampilan dipertahankan dan formula asli tetap terdeteksi. Formula dalam A:AB ditolak. AC:AF adalah derived/optional dan diabaikan dari hash serta KPI. Payload inbound A:AB disimpan sebagai JSON, field utama juga mempunyai kolom terindeks. Batas snapshot 10.000 baris.

ID H duplikat: **seluruh kemunculan** ditolak. ID H harus teks, maksimal 191 karakter dan tanpa spasi tepi. Unique key MySQL memakai kolasi biner untuk menjaga case identity. Sumber H tidak diganti dengan Q. Perubahan label branch atau tujuan mapping pada WO yang sudah ada menjadi conflict; belum tersedia workflow approval pemindahan branch. Koreksi sumber dan preview ulang, atau tunggu modul approval; jangan mengubah assignment langsung melalui Sheets.

Setiap row commit dan row-result/audit berada dalam transaksi yang sama. Retry melanjutkan row yang belum applied, tanpa audit WO ganda. Preview yang sudah sukses tidak dibaca ulang oleh duplicate delivery. Pull/push memakai lock koneksi sama. Failed job dapat dicoba ulang oleh pembuatnya; jika konfigurasi koneksi berubah, buat job baru. Snapshot commit yang gagal dipertahankan terenkripsi untuk resume. Tabel status berisi ringkasan aman, bukan payload Google atau data pelanggan. Pesan provider mentah tidak disimpan pada error sync.

Google adapter memakai JWT service account dan Laravel HTTP, bounded retries 250/1000ms; queue retry 10/30/90/180 detik sampai lima attempt. Token akses sementara disimpan pada cache privat aplikasi. Tidak ada SDK tambahan atau credential dalam source code. Batasi akses database/cache dan lindungi backup APP_KEY. Kebijakan pembersihan preview terenkripsi serta retensi audit/PII masih perlu ditetapkan sebelum produksi.

## Simulasi lokal tanpa credential

Gunakan database lokal khusus uji, jangan mencampur demo dengan data pilot. Set `SHEETS_DRIVER=fake`, lalu:

```sh
php artisan olo:demo-sheet
```

Command membuat tiga WO **sintetis**, tidak membaca data pelanggan dari workbook, dan menolak menimpa fixture yang sudah ada. Pada web buat branch uji dan mapping `DEMO BRANCH`. Jalankan worker, lakukan preview/konfirmasi/pull. Push menulis `monitoring-demo.json` di direktori yang sama dengan fake fixture. Banner simulasi selalu tampil. Fake dilarang pada APP_ENV=production.

## Migrasi

| Migrasi | Perubahan |
| --- | --- |
| `2026_10_18_000001_create_olo_foundation` | users.role/active, branches, user_branches, branch_mappings, work_orders, sync_jobs, sync_job_rows, audit_logs |
| `2026_10_18_000002_create_branch_operations` | wo_status_events, wo_issues |
| `2026_10_18_000003_add_username_to_users` | username unik opsional, email opsional untuk akun username-only |

Ketiganya telah dijalankan pada MySQL lokal. Migrasi username mempertahankan akun email yang ada. Rollback migrasi username ditolak jika masih ada akun tanpa email, agar tidak menghapus atau mengarang identitas akun. Tiga migrasi Laravel awal dipertahankan. Rollback/reapply diverifikasi di SQLite sementara, bukan database operasional. Down migration menghapus tabel bisnis sehingga hanya digunakan pada database disposable atau prosedur deployment dengan backup dan persetujuan data. Jangan memakai migrate:fresh pada database operasional.

## Verifikasi

```sh
php artisan test
vendor/bin/pint --test app config/olo.php config/queue.php database/migrations/2026_10_18_000001_create_olo_foundation.php database/migrations/2026_10_18_000002_create_branch_operations.php database/seeders tests routes
npm run build
php artisan view:cache
php artisan route:list --except-vendor
```

Hasil lengkap, file yang berubah, bukti UI dan batas implementasi: [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md).

Tes memakai SQLite memory + fake gateway/HTTP, tanpa API nyata. Tes source workbook opsional menggunakan file asli secara read-only bila tersedia dan dilewati bila tidak didistribusikan di checkout/CI. Workbook dan private fixture di-ignore karena dapat mengandung data pelanggan. Tes sintetis ukuran historis tetap berjalan tanpa workbook. Tidak ada customer data yang dicopy ke fixture source control.

## Lanjutan dan keputusan pemilik

- M3 masih memerlukan assignment teknisi, dashboard KPI/cutoff resmi, antrian approval konflik branch, UI administrasi user/role, audit browser dan hardening operasional. Kategori issue awal adalah vocabulary teknis sementara (`ACCESS/MATERIAL/NETWORK/CUSTOMER/PERMIT/OTHER`), perlu disahkan.
- M4: alur laporan langsung Telegram → MySQL sudah disiapkan dengan webhook, whitelist, koreksi dan retry; bot tetap nonaktif sesuai arahan pemilik. Aktivasi memerlukan bot/token lokal, HTTPS publik, pemetaan akun/chat dan tes end-to-end. Master satuan, persetujuan/stok dan mapping material ke LHPP belum ditetapkan.
- M5: identitas H vs Q untuk SID/No_SID, tipe transaksi, mandatory fields per job, aturan `-`/`EKSISTING`, koordinat terverifikasi. Tidak ada ekspor berlabel READY saat gate belum tersedia.
- Validasi ID H lintas export, job type backlog, cut-off completion, retensi PII, penanggung jawab Google sharing/production UAT masih perlu keputusan pemilik.

File di `storage/template/EXPORT-BIMA.xlsx` dan `storage/template/LHPP OLO.xlsx` tetap asli. Tidak ada konfigurasi Google, bot token, atau klaim Telegram terhubung.

Kontrak adapter diperiksa terhadap dokumentasi resmi Google: [spreadsheets.get dan field mask](https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets/get), [UpdateCellsRequest](https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets/request#UpdateCellsRequest), serta [batchUpdate](https://developers.google.com/workspace/sheets/api/guides/batchupdate). Verifikasi dokumentasi tidak menggantikan UAT credential nyata.

Perubahan login terbaru: username `superadmin` telah diprovision secara eksplisit pada MySQL lokal sesuai permintaan pemilik. Password hanya tersimpan sebagai hash di database dan tidak disertakan dalam source/seeder/dokumentasi. Halaman login menerima username atau email. Untuk menjalankan server lokal gunakan port 8011, karena 8000 dipakai aplikasi lain.

Spreadsheet Bima lokal sudah diarahkan ke file yang diberikan pemilik. Lihat [panduan koneksi Google Sheets](docs/GOOGLE_SHEETS_SETUP.md) untuk tab paste dan service account; akses baca sudah terverifikasi pada preview #5, dengan header valid dan 0 baris order. Untuk alur saat ini, upload file XLSX di Sync Center; pemeriksaan Google tersebut adalah hasil historis mode Sheets.


### Penamaan ID database bisnis

Primary key menggunakan `id_<nama_tabel>` (contoh `branches.id_branches`, `branch_mappings.id_branch_mappings`) dan foreign key memakai nama primary key tujuan. Tabel internal Laravel tetap standar. Migrasi penyesuaian mempertahankan data yang sudah ada. Lihat [daftar skema, file, hasil tests dan catatan migrasi](docs/ID_RENAMING.md). Server MariaDB lokal saat ini membatasi `max_allowed_packet` ke 1 MiB; pengelola perlu menaikkannya sebelum mencoba snapshot ribuan order.


### Master region dan STO

Tersedia `regions` (3 region) dan `service_areas` (392 STO dari SQL pengguna), dengan relasi region/STO pada users. Master STO ikut menentukan branch saat preview/pull; konflik dan progress WO tetap dilindungi. Lokasi user tidak menambah hak cabang. Lihat [skema, hasil migrasi, perintah admin dan daftar file](docs/REGIONS_AND_STO.md). Buat preview BIMA baru agar master STO terbaru digunakan.


### User Management dan region branch

Superadmin dapat membuka menu **User Management** untuk menambah/mengedit akun, role, status aktif, cabang, region dan STO. Kolom password kosong saat edit mempertahankan password lama. `branches.region_id` telah dibackfill dari master region; pembuatan branch kini memakai pilihan region terstruktur. Detail migrasi, daftar file, aturan keamanan, hasil tests dan screenshot tersedia di [USER_MANAGEMENT.md](docs/USER_MANAGEMENT.md).

### Ruang kerja Admin Branch

Login role Branch kini menuju `/branch/dashboard`. Order hasil import langsung terlihat menurut penugasan Branch; progress, kendala/catatan dan analytics harian tersedia. PS memerlukan progress CLOSE dan salah satu 14 kombinasi status_order yang ditetapkan pemilik; tanggal harian mengikuti TGL PS BIMA. Material mengikuti arahan terbaru Telegram → MySQL langsung; lihat [panduan material terbaru](docs/TELEGRAM_MATERIAL_REPORTS.md). Catatan alur awal tersimpan di [Branch Workflow](docs/BRANCH_WORKFLOW.md), dengan aturan baru pada addendum AGENTS/PRD.

### Dashboard Area

Superadmin, Koordinator Area, Admin Area dan HD Area mendapat beranda `/dashboard`: rekap progress per regional/cabang dengan subtotal/grand total, rekap jenis pekerjaan, grafik PS bulanan, filter dan antrean pengecualian. Dashboard memakai data MySQL sebenarnya. Tidak ada env atau migrasi baru. [Aturan PS, manifest file dan pengujian](docs/AREA_DASHBOARD.md).

### Berkas lokal yang tidak dikirim ke GitHub

Repository hanya memuat source, dependency lockfiles, tests dan master referensi region/STO untuk migration. `.env`, kredensial Google/Telegram, database/dump, backup, log, dependency terpasang, hasil build, workbook asli serta screenshot aplikasi tetap lokal dan diabaikan Git. Screenshot yang dirujuk pada catatan implementasi adalah bukti QA lokal; file tersebut tidak didistribusikan bersama source.

Untuk checkout baru, ikuti petunjuk instalasi di atas dan buat `.env` dari `.env.example`. Jika perlu menjalankan dua pengujian workbook asli, salin secara privat `EXPORT-BIMA.xlsx` dan `LHPP OLO.xlsx` ke `storage/template/`; jangan mengunggah workbook tersebut ke repository. Pengujian referensi workbook otomatis dilewati bila file privat belum tersedia. Pengujian lainnya menggunakan fake adapter dan data terisolasi tanpa kredensial Google.

Status BIMA, Log Task BIMA, perangkat cabang dan umur otomatis: [panduan dan hasil pengujian](docs/BIMA_STATUS_AND_DEVICES.md). Setelah deployment jalankan migrasi, restart worker, dan aktifkan Laravel scheduler untuk menyegarkan nilai umur SQL setiap hari.

Referensi klasifikasi order BIMA (178 aturan): [implementasi dan backfill mapping](docs/ORDER_MAPPING_REFERENCE.md). Setelah migrasi jalankan `php artisan olo:apply-order-mapping --actor=superadmin`.
