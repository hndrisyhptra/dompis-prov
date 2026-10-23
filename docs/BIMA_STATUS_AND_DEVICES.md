# Status BIMA, log task dan perangkat cabang — 20 Oktober 2026

Checklist implementasi:
- [x] Migrasi status_bima, status_order gabungan, mapping_order dan tabel master mapping_order, log task serta ownership perangkat.
- [x] K Status→status_bima; task/status berubah atomik bersama Log Task BIMA, retry tidak menduplikasi.
- [x] Bulan/tahun dari tgl_order; umur harian dan kategori sesuai batas Excel, tidak masuk hash sumber.
- [x] Admin Branch/HD Branch dapat mengedit enam perangkat dengan scope cabang, revisi dan audit; upload menjaga override lokal.
- [x] UI referensi BIMA/log task/perangkat, tests, build dan migrasi aktual dengan backup.

Keputusan: status_order = task_bima-status_bima bila keduanya terisi, atau komponen yang tersedia. Bulan disimpan angka dua digit (10), tahun empat digit (2026). Hari dihitung dalam kalender Asia/Jakarta, tanggal masa depan menghasilkan selisih negatif sebagaimana TODAY()-tgl_order; tanggal kosong menghasilkan NULL. Kategori: >28 "> 28 HARI", >14 "14-28 HARI", >7 "7-14 HARI", lainnya "0-7 HARI". mapping_order belum diklasifikasikan sebelum file aturan diterima. PS lama tetap progress CLOSE + status_bima CLOSE/COMPLETE; mapping_order belum menjadi sumber KPI.

## Alur dan integritas data

- Preset `bima-work-orders-v2` memetakan 12 kolom, termasuk K Status. `status_order` tidak dapat dipilih sebagai target import langsung. AG STATUS-ORDER dan kolom umur dari file diabaikan pada preset.
- `status_bima` menyimpan nilai mentah; `status_order` berupa task-status dengan komponen kosong diabaikan. Panjang status_order menjadi TEXT agar dua atribut 255 karakter tidak terpotong.
- Import pertama menjadi kondisi awal. Perubahan task ATAU status setelahnya menyimpan nilai sebelum/sesudah, pelaku, job dan waktu pada `bima_task_logs`. Log dan WO disimpan dalam transaksi yang sama; baris job dikunci, applied_at dan unique(job,WO) menjaga retry. Card Log Task BIMA menampilkan 20 perubahan per halaman.
- Master `mapping_order` memiliki `id_mapping_order`, `status_order` unik, klasifikasi STUCK/PS/CANCLWORK, dan active. WO menyimpan klasifikasi dan FK `id_mapping_order`. Pencocokan persis atas status gabungan, hanya aturan aktif. Belum ada seed aturan bisnis. Kosong/tidak cocok menghasilkan NULL dan UI “Belum dipetakan”. Aturan sintetis hanya ada pada tes database sementara.
- `device_overrides` mencatat enam atribut yang diubah cabang, termasuk pengosongan. Upload tetap menyimpan nilai sumber mentah/hash namun tidak menimpa atribut tersebut. Perlindungan diperiksa kembali saat commit, sehingga edit setelah preview tetap aman. Tidak ada tombol reset override saat ini.
- Role `branch_hd` (HD Branch) ditambahkan dan tersedia pada User Management. Admin Branch/HD Branch dapat mengedit perangkat hanya pada cabang aktif yang ditugaskan, termasuk order selesai. Superadmin/Admin Area/HD Area juga dapat koreksi. Koordinator, Team Leader, Admin Material dan Bot tidak memiliki izin edit perangkat.
- Form memakai validasi 255 karakter, SN tetap teks, revisi optimistis (409 jika form basi), audit `devices.changed`, dan loading state. Perubahan perangkat tidak mengubah progress, tanggal selesai, source hash atau Log Task BIMA.
- PS dashboard/push tetap mensyaratkan progress_category CLOSE dan status_bima CLOSE/COMPLETE; tanggal harian tgl_ps. Klasifikasi mapping_order PS belum menggantikan aturan tersebut.

## Umur dan operasional

`bulan` disimpan dua digit (01–12), `tahun` empat digit. Nilai umur pada detail dibaca dinamis dari tanggal order sampai hari ini WIB, sehingga tanpa upload tetap berubah. Nilai SQL disegarkan saat menyimpan WO dan melalui `olo:refresh-order-aging`, terjadwal setiap 00:05 WIB. Perintah memakai lock per WO agar tidak menimpa tanggal dari upload yang bersamaan; tidak mengubah updated_at, revisi, progress, atau hash sumber. Tanggal kosong menghasilkan NULL. Hari dari tanggal masa depan negatif, sesuai TODAY()-tanggal; kategorinya tetap 0-7 HARI.

Environment tidak bertambah. Tetap `WO_ORDER_SOURCE=excel`. Untuk penyegaran SQL otomatis di server, Laravel scheduler harus berjalan, misalnya cron tiap menit:

```cron
* * * * * cd /path/to/dompisprov && php artisan schedule:run >> /dev/null 2>&1
```

Pada development bisa menjalankan `php artisan schedule:work`. Scheduler lokal tersebut sudah dijalankan pada verifikasi tahap ini; jadwal terdaftar 00:05 WIB (17:05 UTC). Worker dan scheduler development bergantung pada proses terminal tetap hidup; gunakan process manager/cron untuk server. Jika scheduler berhenti, umur yang ditampilkan Laravel tetap aktual, tetapi nilai tersimpan SQL menunggu penyegaran. Tidak mengubah cron sistem pengguna otomatis.

Perintah deployment:

```sh
php artisan down --retry=60
# Backup terenkripsi database dibuat sebelum migrasi.
php artisan migrate --force
php artisan olo:refresh-order-aging
php artisan queue:restart
php artisan view:cache
npm run build
php artisan up
```

Worker harus dijalankan kembali melalui process manager/development terminal. Preview sebelum kontrak v4 harus diupload ulang; connection hash menolak commit lama. Tidak perlu mengunggah ulang WO hanya untuk umur.

## Migrasi dan rollback

Migrasi `2026_10_20_000011_add_bima_status_task_log_and_mapping.php` membuat mapping_order dan bima_task_logs, menambah empat atribut WO (status_bima, mapping_order, id_mapping_order, device_overrides), serta memperlebar status_order. Status sumber lama dipindahkan ke status_bima, status gabungan/bulan/tahun/umur dihitung ulang. Progress, issue, assignment, revision, source JSON/hash dan timestamp lama dipertahankan. PK/FK tetap mengikuti nama unik per tabel; tabel internal Laravel tidak diubah.

Rollback mengembalikan status_order menjadi status mentah dari status_bima, lalu menghapus tabel log/master dan kolom baru. Rollback menghapus riwayat/aturan/penanda override yang baru: simpan backup sebelum rollback. Nilai bulan/tahun/umur hasil perhitungan tidak dikembalikan ke nilai export lama. Untuk pemulihan persis gunakan backup, bukan rollback saja.

## File berubah pada tahap ini

- app/Models/WorkOrder.php; MappingOrder.php dan BimaTaskLog.php (baru)
- app/Enums/Role.php; app/Policies/WorkOrderPolicy.php
- app/Services/WorkOrderDerivedFields.php, WorkOrderSourceWriter.php, WorkOrderDeviceService.php (baru)
- app/Services/BimaWorkOrdersTemplate.php; ExcelWorkOrderMapper.php; ExcelWorkOrderImportService.php; BimaRowMapper.php; BimaSheetPullService.php
- app/Services/AreaAnalyticsService.php; MonitoringSheetPushService.php
- app/Http/Controllers/WorkOrderController.php; WorkOrderImportController.php; WorkOrderDeviceController.php (baru)
- app/Console/Commands/RefreshWorkOrderAging.php (baru); routes/console.php; routes/web.php
- database/migrations/2026_10_20_000011_add_bima_status_task_log_and_mapping.php (baru)
- resources/views/work-orders/show.blade.php; devices.blade.php dan bima-task-log.blade.php (baru); resources/views/imports/mapping.blade.php; resources/css/app.css
- tests/Unit/WorkOrderDerivedFieldsTest.php; tests/Feature/BimaStatusDevicesTest.php; BimaStatusMigrationTest.php (baru)
- tests/Feature/BimaWorkOrdersTemplateTest.php; ExcelWorkOrderImportTest.php; AreaDashboardTest.php; PsEligibilityTest.php; BranchAnalyticsTest.php
- tests/Feature/BranchProgressMigrationTest.php; BranchRegionMigrationTest.php; LocationsMigrationTest.php; ExcelSourceColumnsMigrationTest.php; SingleBranchMigrationTest.php; WorkOrderColumnNamesMigrationTest.php; MigrationRollbackTest.php (adaptasi regression schema terbaru)
- AGENTS.md; SKILLS.md; PRD_OLO_Command_Center_v2.md; README.md; docs/EXCEL_WORK_ORDER_IMPORT.md; docs/BIMA_WORK_ORDERS_TEMPLATE.md; dokumen ini

Input yang masih diperlukan: file aturan mapping_order dan konfirmasi kolom sumber TGL PS untuk preset Work Orders. Tidak menebak tanggal PS dari Date Modified. Tidak ada tes end-to-end Google/Telegram baru pada tahap ini.


## Hasil verifikasi aktual

- Full suite SQLite: **118 tes lulus, 1.830 assertions**. MariaDB database sementara: **116 tes lulus, 1.819 assertions**. Dua tes snapshot Sheets legacy berukuran besar dikecualikan hanya di MariaDB karena max_allowed_packet lokal 1 MiB; seluruh tes Excel termasuk snapshot per baris >1 MiB total lulus. Setelah pengetatan perbandingan NULL pada refresh umur, tes aging diulang: 1 tes / 20 assertions lulus.
- Pint, git diff --check, build Vite dan kompilasi Blade lulus. Tes memakai data sintetis terpisah dari database aplikasi.
- Contoh Order-OLO-20102026.xlsx dibaca read-only: 13 baris, 12 mapping, K→status_bima, SHA-256 tetap. EXPORT-BIMA.xlsx dan LHPP OLO.xlsx tidak diedit.
- Migrasi 000011 **sudah dieksekusi** ke MariaDB aplikasi. Backup terenkripsi sebelum migrasi: storage/app/private/maintenance/before-bima-status-devices-20261020-070145.json.enc (mode 0600; memerlukan APP_KEY asli). Verifikasi 17 tabel terhadap backup lulus setelah memperhitungkan perubahan kolom turunan dan tipe penyimpanan umur VARCHAR. Seluruh **13 WO**, 1 riwayat progress, 3 user, 16 branch dan 392 STO dipertahankan. Tidak ada WO baru yang diimpor pada tahap ini; mapping_order dan bima_task_logs masih kosong.
- Aplikasi sudah aktif kembali; worker default dijalankan kembali dengan kode terbaru. Preview/import sebelum kontrak v4 tidak dapat di-commit; upload ulang file untuk mengisi kolom K pada order lama yang status sumbernya kosong.
- UI diperiksa di aplikasi lokal port 8011 tanpa menyimpan perubahan WO: desktop 1280 px, tablet 768 px, ponsel 375 px; tidak ada overflow halaman. Enam input menjadi satu kolom pada ponsel; dua kolom pada layar lebih lebar. Light dan dark diperiksa, preferensi dikembalikan ke light dan viewport override di-reset. Card Log Task BIMA menampilkan empty state yang nyata. Bukti form light tanpa data pelanggan: /private/tmp/olo-bima-devices-light.png.
- Tidak ada commit/push atau tes E2E Google/Telegram pada tahap ini. Environment/dependensi tidak berubah.


Pembaruan berikutnya: referensi mapping 178 aturan sudah diterima (termasuk kategori OPEN). Ketentuan menunggu file mapping di atas merupakan kondisi tahap sebelumnya; implementasi dan hasil terbaru ada di [ORDER_MAPPING_REFERENCE.md](ORDER_MAPPING_REFERENCE.md).
