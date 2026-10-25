# Laporan material Branch melalui Telegram

## Checklist implementasi dan dependensi

- [x] Inspect Laravel/RBAC, tabel yang ada, placeholder laporan material dan kontrak Bot API resmi.
- [x] Definisikan 45 kolom, parser nullable/angka/koordinat, schema aditif dan model scoped.
- [x] Binding user/chat Telegram, webhook secret + inbox terenkripsi/idempotent, proses queue/audit.
- [x] Adapter Telegram, outbox/retry balasan, template dan koreksi append-only tanpa overwrite WO.
- [x] Halaman laporan/detail/template sesuai Branch dan onboarding .env/CLI.
- [x] Tes unit/feature/fake Telegram, MariaDB, build dan migration aktual dengan pemeriksaan preservasi data.

Arahan terbaru Telegram → MySQL langsung menggantikan placeholder lama Telegram → Sheets → web. Tidak membangun push Google Sheets atau export LHPP final dalam tahap ini. Report merupakan catatan pemakaian yang dilaporkan Branch, bukan saldo stok atau LHPP tervalidasi otomatis. Tidak mengubah BOQ, progress, perangkat WO atau Revenue.

## Status saat disiapkan

Pemilik menyatakan bot belum dibuat: **“Belum, siapkan alurnya dahulu.”** Karena itu konfigurasi asli tidak diubah, bot tetap nonaktif, tidak mendaftarkan webhook nyata dan tidak mengirim pesan Telegram nyata. Tes menggunakan adapter fake/HTTP fake, terpisah dari pengujian end-to-end yang masih diperlukan setelah bot tersedia.

Laravel 13.37 / PHP 8.5.10, Blade/Vite dan RBAC yang ada digunakan kembali, tanpa dependency baru. Master/template BIMA, LHPP, BOQ dan file sumber pemilik tidak diubah.

## Alur Branch

1. Buka **Laporan Material → Template Telegram** untuk menyalin atau mengunduh TXT. Ketika bot aktif, `/material` juga memberikan template yang sama.
2. Kirim pesan baru diawali `/material`, kemudian `Nama kolom: nilai` satu per baris. NO TQ/SC/ID wajib cocok persis dengan WO BIMA yang sudah diimport; SID bukan penggantinya. Branch ditentukan oleh WO dan izin akun, bukan isian pengirim.
3. Semua 44 kolom lain boleh `-`, kosong, atau tidak disertakan. Kosong disimpan NULL, angka 0 tetap nol. Nama pelanggan/alamat/nomor inet tidak ditebak dari BIMA; isi hanya data yang diketahui.
4. Setelah transaksi laporan dan audit berhasil, bot membalas nomor laporan. Laporan muncul di web sesuai akses Area/Branch.
5. Koreksi: kirim `/koreksi NOMOR_LAPORAN` pada baris pertama dan template **lengkap** sesudahnya. Nilai kosong menjadi NULL pada versi baru. Versi lama tetap tersimpan sebagai “Dikoreksi”. Mengedit pesan Telegram lama tidak mengubah database.

Jika belum ada balasan, jangan kirim ulang sebagai pesan baru: administrator memeriksa antrean lebih dahulu. Retry update Telegram yang sama tidak membuat laporan ganda; pesan baru yang berbeda dianggap laporan berbeda, termasuk jika No WO sama (mungkin memang ada beberapa transaksi).

Format pesan maksimal 4.096 karakter; nilai Alamat/Koord Tiang maksimal 1.000, teks lain 255. Alamat panjang ditulis satu baris. Materi bernilai angka menerima 0/positif, maksimal 12 digit bulat dan 3 desimal dengan titik, tanpa pemisah ribuan. `EKSISTING` bukan kuantitas dan tidak ditafsirkan otomatis. Nomor telepon/inet, SN, QR, port dan NIK tetap teks agar nol depan utuh. Koordinat memakai derajat desimal dengan titik, longitude ±180/latitude ±90, maksimal 8 desimal; tidak menebak koordinat. Koord Tiang berupa teks opsional tersendiri.

Daftar 45 label dan urutannya ada di `app/Support/MaterialReportFields.php` dan template web, termasuk seluruh 23 kolom kuantitas material. Kolom SQL snake_case; NO TQ/SC/ID → no_tq_sc_id, S-CLAMP → s_clamp, BC-TR-0.4 → bc_tr_0_4, PC SC-LC 10m → pc_sc_lc_10m.

## Tabel dan akses

Migration `2026_10_25_000021_create_telegram_material_reports.php` menambah:

| Tabel | PK | Fungsi |
|---|---|---|
| material_reports | id_material_reports | 45 kolom, snapshot Branch/WO/pelapor, nomor ULID, versi koreksi, waktu |
| telegram_identities | id_telegram_identities | ID Telegram unik → akun aplikasi aktif |
| telegram_chats | id_telegram_chats | Chat yang diizinkan; grup wajib terikat satu Branch |
| telegram_updates | id_telegram_updates | Inbox terenkripsi, update_id unik, status/error/retry |
| telegram_outbox | id_telegram_outbox | Balasan terenkripsi setelah commit; status/error/retry |

FK mengikuti PK spesifik aplikasi seperti id_users/id_work_orders/id_branches. Tabel internal Laravel tetap. Semua role manusia aktif dapat membuka laporan: empat role Area melihat seluruh laporan, role Branch/TL/Admin Material hanya assignment Branch aktif. Bot/nonaktif ditolak. Laporan memakai Branch saat dilaporkan; bila WO kemudian pindah Branch, laporan lama tetap milik Branch asal untuk jejak pemakaian. Tautan Detail Order hanya muncul jika akun masih berhak melihat WO saat ini. Koreksi juga harus cocok dengan Branch WO saat ini.

Pengguna Telegram dan chat sama-sama harus diizinkan. Grup/supergroup terikat Branch, chat pribadi hanya menerima sender yang sama dengan chat ID, dengan pembatasan Branch opsional. Identitas bot, pesan forward/anonymous/edited, atau order tidak dikenal/di luar akses tidak membuat laporan. `/id` tersedia sebelum binding dan hanya mengembalikan ID pengguna/chat peminta.

## Aktivasi nanti, setelah bot tersedia

Bagian ini **belum dijalankan terhadap Telegram nyata**.

1. Buat bot lewat BotFather (`/newbot`). Simpan token langsung di `.env` lokal/server, jangan chat/Git. Untuk awal, chat pribadi paling mudah. Jika memakai grup, bot perlu bisa menerima pesan command lengkap; gunakan `/material@username_bot` dan `/koreksi@username_bot NOMOR_LAPORAN` bila privacy mode grup aktif. Forum topics/channel belum didukung; gunakan chat pribadi atau grup biasa.
2. Pastikan aplikasi tersedia melalui domain HTTPS publik dengan sertifikat valid. Set `APP_URL` ke URL aplikasi (termasuk subdirektori bila ada), bukan localhost. Pastikan reverse proxy mengirim IP/protokol yang benar sesuai deployment. Webhook POST: `/api/telegram/material/webhook`.
3. Isi konfigurasi berikut. Generate secret acak lokal, misalnya `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`, lalu tempel hasilnya ke `.env` tanpa membagikannya.

```dotenv
TELEGRAM_MATERIAL_ENABLED=true
TELEGRAM_BOT_TOKEN=<token-dari-BotFather>
TELEGRAM_WEBHOOK_SECRET=<secret-acak-64-karakter-hex>
APP_URL=https://domain-dompis-anda
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_QUEUE_RETRY_AFTER=300
```

4. Jalankan konfigurasi/migration dan worker melalui pengelola proses server:

```sh
php artisan config:clear
php artisan migrate --force
php artisan queue:work --tries=5 --timeout=120
```

Jangan mengganti APP_KEY existing: inbox/outbox membutuhkan key itu untuk dekripsi. Backup DB dan key secara aman. Aktifkan scheduler Laravel setiap menit (`php artisan schedule:run` melalui cron server, atau `php artisan schedule:work` untuk lokal), bersama schedule aging yang sudah ada. Default retry_after 300 harus lebih panjang daripada timeout worker. Gunakan cache bersama yang mendukung lock untuk seluruh worker.

5. Daftarkan webhook dengan command berikut; token tidak masuk argumen/history URL. Command memanggil `setWebhook` resmi menggunakan konfigurasi lokal, hanya updates `message`, tanpa membuang pending updates. Pendaftaran bukan bukti laporan/balasan berhasil.

```sh
php artisan olo:telegram-webhook --actor=superadmin
```

6. Dari chat pribadi/grup yang akan digunakan, kirim `/id` atau `/id@username_bot`. Catat numeric user ID dan chat ID; ini bukan username Telegram. Administrator server lalu memetakan akun **existing** dan chat. Ganti seluruh contoh berikut dengan username akun/kode Branch/ID sebenarnya:

```sh
php artisan olo:telegram-bind --actor=superadmin --user=username_hd_branch --telegram-user=123456789
php artisan olo:telegram-bind --actor=superadmin --chat=123456789 --type=private
# Alternatif grup: ID negatif, satu Branch per grup
php artisan olo:telegram-bind --actor=superadmin --chat=-1001234567890 --type=supergroup --branch=KODE_BRANCH
```

Binding tidak memberi role/assignment Branch baru. Pastikan penugasan akun di User Management sudah benar. CLI ini untuk administrator server yang dipercaya; `--actor` adalah atribusi audit kepada akun superadmin aktif, bukan autentikasi tambahan terhadap operator shell. Cabut akses memakai command yang sama dengan `--disable`; untuk nonaktif chat grup, tetap sertakan tipe/Branch. Jika grup berubah menjadi supergroup, ulang binding dengan ID chat baru dan cabut ID lama.

7. Lakukan uji end-to-end: kirim template No WO uji milik Branch; pastikan balasan nomor laporan, data di menu Material benar, Branch lain tidak bisa membukanya, retry update tidak menduplikasi, `/koreksi` menghasilkan versi baru, dan kegagalan kirim balasan dapat dipulihkan. Periksa queue/failed jobs. Baru setelah ini nyatakan bot tersambung.

Dokumentasi resmi: [setWebhook](https://core.telegram.org/bots/api#setwebhook), [sendMessage](https://core.telegram.org/bots/api#sendmessage), [Update](https://core.telegram.org/bots/api#update). Secret header, batas pesan dan retry mengikuti kontrak resmi tersebut.

## Retry dan integritas

Webhook memverifikasi `X-Telegram-Bot-Api-Secret-Token` dengan constant-time comparison, body JSON maksimum 64KiB, throttle 120/menit/IP, hanya endpoint ini dikecualikan CSRF. Inbox disimpan sebelum mengakui request. Payload mentah dan balasan terenkripsi dengan APP_KEY; job hanya membawa ID. API token/error response tidak dicatat ke log. Audit hanya ID dan metadata perubahan, tanpa isi laporan/nomor pribadi.

Database unique update_id dan chat_id+message_id, row lock WO/laporan, transaksi laporan+audit+outbox, dan cache lock job mencegah material ganda pada retry. Outage Telegram tidak menghapus laporan yang sudah tersimpan. Scheduler `olo:telegram-retry` mengantrekan pending/failed jatuh tempo dengan <5 percobaan, inbox setelah dua menit. Job/webhook replay dibatasi 10 percobaan total. Administrator dapat memberi kesempatan tambahan (tetap maksimum 10) dengan:

```sh
php artisan olo:telegram-retry --manual
```

Lihat state/error_code/attempts/next_attempt_at inbox/outbox melalui akses database admin; tidak membuka raw payload pelanggan pada log publik. Error generik `processing_failed` → periksa koneksi/schema/APP_KEY/audit; `delivery_unavailable` → koneksi/token/rate limit Telegram. Job retry tidak memindahkan Branch atau memberi izin baru. Jika total10 habis, perlukan pemeriksaan administrator sebelum tindakan pemulihan berikutnya, tidak reset counter diam-diam. Balasan Telegram bisa terkirim dua kali setelah timeout yang hasilnya tidak pasti; **laporan database tetap satu**.

Belum ada persetujuan material, stok, satuan/harga material, mapping ke 32 kolom LHPP atau publikasi Sheets. Jangan menggunakan laporan mentah ini sebagai LHPP READY atau revenue. Tidak ada tombol manual Retry pada web; pemulihan lewat command admin di atas.

## Manifest perubahan tahap material

- Schema/model: migration000021; `app/Models/{MaterialReport,TelegramIdentity,TelegramChat,TelegramUpdate,TelegramOutbox}.php`.
- Kontrak/parser/adapters: `app/Support/MaterialReportFields.php`, `app/Services/Material/{MaterialReportParser,TelegramMaterialService}.php`, `app/Contracts/TelegramGateway.php`, `app/Services/Telegram/{HttpTelegramGateway,TelegramException}.php`.
- Endpoint/akses: `app/Http/Controllers/{MaterialReportController,TelegramMaterialWebhookController}.php`, `app/Policies/MaterialReportPolicy.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/web.php`.
- Worker/operasional: `app/Jobs/{ProcessTelegramUpdate,DeliverTelegramMessage}.php`, `app/Console/Commands/{BindTelegramMaterialAccess,RetryTelegramMaterial,RegisterTelegramMaterialWebhook}.php`, `routes/console.php`, `config/telegram.php`, `.env.example`.
- UI: `resources/views/materials/{index,show,template}.blade.php`, `resources/views/layouts/app.blade.php`, `resources/views/work-orders/show.blade.php`, `resources/js/material-template.js`, `resources/js/app.js`, `resources/css/app.css`. Placeholder `resources/views/branch/material.blade.php` dan method lama BranchDashboardController dihapus; route lama redirect ke daftar baru.
- Tes: `tests/Unit/MaterialReportParserTest.php`, `tests/Feature/{TelegramMaterialTest,MaterialReportMigrationTest,BranchWorkflowTest}.php`, `tests/Fakes/TelegramGateway.php`, `tests/JavaScript/material-template.test.js`; jumlah step rollback disesuaikan di BranchProgressMigrationTest, BranchRegionMigrationTest, ExcelSourceColumnsMigrationTest, LocationsMigrationTest, SingleBranchMigrationTest, MigrationRollbackTest.
- Dokumen: file ini, README.md, AGENTS.md, SKILLS.md, PRD_OLO_Command_Center_v2.md. Perubahan branding/login/grafik dari tahap sebelumnya tetap dipertahankan; manifest terpisah `docs/DOMPIS_BRANDING_LOGIN_CHART.md`.

## Hasil verifikasi

Hasil aktual 25 Oktober 2026:

- Seluruh suite PHPUnit SQLite: **268 tes, 25.081 assertion, lulus**. Mencakup alur import/progress/master/BOQ/Revenue sebelumnya serta parser, RBAC material, koreksi, transaksi audit, encryption, retry dan HTTP fake Telegram.
- MariaDB10.4 database sementara terpisah: **16 tes, 174 assertion, lulus**, termasuk rollback/reapply dan fitur material. Database sementara dihapus sesudah tes, tidak menghapus database aplikasi.
- JavaScript: **22 tes lulus**, termasuk clipboard success/fallback. Vite build, Blade view cache, Pint dirty check dan `git diff --check` lulus.
- Migration000021 **berhasil dijalankan di database aplikasi dompisprov**, lima tabel baru dan seluruh45 kolom terverifikasi; semuanya masih0 laporan/binding/pesan, tanpa data contoh produksi.
- Fingerprint data bisnis lama sama sebelum/sesudah migrasi: **3.418 WO**,3 users,16 Branch,393 Service Area,1.448 harga master,1 BOQ/11 item/1 history,1 KML,5 bookmark,231 log BIMA,3.251 progress events,7.018 audit, beserta tabel bisnis lainnya utuh. Pemeriksaan lanjutan migration idempotent menghasilkan Nothing to migrate. Pemeriksaan awal terlalu luas dan memasukkan tabel riwayat migration (yang memang bertambah); pemeriksaan ulang dibatasi schema aplikasi dan mengecualikan internal runtime/migration.
- `.env` asli tidak diubah, `telegram.enabled=false`; tidak ada request Google atau Telegram nyata. Aktivasi masih memerlukan bot/token, secret lokal, HTTPS publik, binding user/chat dan uji end-to-end. Tidak ada input tambahan yang diperlukan untuk menyelesaikan persiapan ini.

Tidak ada verifikasi visual browser pada tahap ini karena akses preview browser lokal sebelumnya ditolak; Blade/render feature test, JS dan build digunakan tanpa mengklaim screenshot/UAT visual.
