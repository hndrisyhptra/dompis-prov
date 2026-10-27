# Mapping BIMA dan optimasi akses

## Checklist
- [x] Inspect repository, requirement terbaru, header upload terakhir secara read-only, query/queue/index/UI dan tests.
- [x] Pulihkan mapping 13 kolom dari header lengkap, termasuk pergeseran Customer Name/Workzone; uji dua format dan ambiguitas.
- [x] Ukur baseline sintetis; gabungkan agregasi hitungan dashboard/antrean/prioritas tanpa mengubah aturan/scoping.
- [x] Kurangi dekripsi berulang serta query/insert berulang saat upload dan preview, tetap transaksi/lock/audit/idempotency.
- [x] Jalankan regression SQLite/MariaDB, query budget, lint/build; dokumentasikan hasil dan batas.

Temuan metadata upload terakhir: Customer Name sekarang N, Workzone O (tanpa witel), dengan Booking ID tambahan di AA; District TIF/Regional TIF tetap AC/AD. Format sebelumnya Customer Name O dan Workzone P. Data pelanggan tidak dibaca untuk diagnosis. 3.418 WO saat inspeksi. Queue database sudah aktif; tidak ada klaim layanan eksternal atau pengujian beban produksi. Login dan perubahan fitur prioritas yang sudah ada dipertahankan.

Optimasi mengikuti query dan aturan bersama, bukan cache lintas akun yang berpotensi bocor atau menyembunyikan pembaruan. Index baru hanya jika diperlukan berdasarkan rencana query; tidak ada penggantian tabel atau reset data.


## Konsep mapping agar tahan pergeseran kolom
Urutan proses: baca header → normalisasi case/spasi/tanda hubung → cocokkan 13 header unik → tampilkan pasangan aktual → preview → konfirmasi import. Nama file dan huruf kolom bukan identitas atribut. Kontrak bawaan tetap pada BimaWorkOrdersTemplate::COLUMNS; angka posisi lama dipertahankan sebagai dokumentasi asal, bukan dipakai untuk menentukan pilihan.

| Header file | Tujuan work_orders |
|---|---|
| Date Created | tgl_order |
| ORDER / ORDER ID / ORDER-ID | no_tq_sc_id |
| Service No. | sid |
| Description | task_bima |
| CRM Order Type | jenis_pekerjaan |
| Owner Group | owner_grup |
| Status | status_bima |
| Product Name | layanan |
| Address | alamat |
| Customer Name | nama_olo |
| Workzone | sto |
| District TIF | branch |
| Regional TIF | regional |

Kolom tambahan tidak otomatis menjadi atribut sumber yang diimpor. Tidak mengimpor status_order gabungan/umur dari formula export; aturan derived tetap. Preset memerlukan semua pasangan unik: header hilang/ganda/alias ID lebih dari satu mengembalikan mode pemeriksaan manual; tidak menebak secara fuzzy. Mapping file generik dan pilihan manual tetap tersedia. Snapshot mapping milik masing-masing upload dan tidak dipakai ulang secara buta untuk file berikutnya. Posisi saja berubah tidak mengubah hash atribut WO.

Verifikasi aktual baca-saja setelah perbaikan: upload tertunda #20 dikenali dengan nama_olo=14 (N), sto=15 (O), branch=29 (AC), regional=30 (AD) dan seluruh 13 pasangan; upload #18/#19 tetap dikenali memakai nama_olo=15 (O), sto=16 (P). Snapshot, baris staging dan WO aktual tidak diubah. Untuk menggunakan: buka ulang halaman mapping, periksa pasangan, lalu buat preview/konfirmasi. Gunakan tombol Gunakan mapping BIMA jika sengaja ingin mengembalikan preset dari pilihan manual. Upload ulang jika sesi 30 menit sudah kedaluwarsa. Tidak melakukan commit import aktual pada pekerjaan ini.

## Optimasi yang diterapkan
- **N+1 izin UI:** Work Order/List Prioritas sudah memakai query visibleTo actor. Role permission dihitung sekali untuk render daftar; pemeriksaan `@can` per baris desktop/mobile tidak mengulang query WO. Di luar daftar scoped, partial tetap memakai Gate; semua endpoint detail/update/bookmark tetap memakai policy aktual. Tidak ada cache lintas request/akun atau penambahan izin.
- **Agregasi SQL:** OrderMetricAggregate menyusun predikat dari scope query yang sudah ada, termasuk PsEligibility, outstanding, issue, ProgressSummary dan filter antrean. Flag dihitung pada query turunan scoped, lalu dijumlahkan; kompatibel MariaDB strict ONLY_FULL_GROUP_BY tanpa menonaktifkan strict mode. Dashboard tidak menghidrasi setiap WO di PHP. Tidak ada floating point untuk Revenue yang diubah.
- **Dashboard Area/Branch:** ringkasan digabung, seri harian dikelompokkan tanggal di database; perhitungan cohort 4 metrik+undated cukup satu query. Definisi PS, tanggal WIB, Cancel/terminal, unknown type, null date, inactive/archived master dan reconciliation tabel tetap diuji.
- **List/antrean:** 9 count scan menjadi 1; ringkasan prioritas 4 count menjadi 1. Paginator.total dipakai ulang, dua query indikator yang tidak digunakan di UI dihapus. Query dasar list dibangun sekali.
- **Tanggal:** filter tanggal tunggal memakai rentang [tanggal, hari berikutnya), bulan memakai rentang awal bulan hingga awal bulan berikutnya. WHERE tidak membungkus kolom tanggal dengan fungsi DATE, sehingga index tanggal yang ada dapat dimanfaatkan. GROUP BY tanggal tetap dibutuhkan untuk seri. Mendukung penyimpanan DATE MySQL maupun tanggal berjam pada fixture SQLite.
- **Monitoring prioritas:** agregasi status/task/Owner Group dilakukan sebelum hydration. MySQL menggunakan binary grouping agar beda case/aksen/nama sumber tidak tergabung keliru. Scope bookmark dan hitungan tetap.
- **Upload:** staging disimpan sebagai insert batch maksimal 100 baris dan 256KiB per statement, memakai casts encrypted:array dari model sebelum insert. Transaksi upload/unique idempotency tetap, bukan insert plaintext. Tes 220 baris memerlukan 3 insert staging, bukan 220.
- **Preview/commit:** metadata job didekripsi sekali per tahap, raw payload sekali per row terkunci; pencarian duplikat membaca ciphertext dalam halaman 100 baris. Cursor commit/preview utama hanya mengambil ID log; payload terkunci dimuat saat diproses. Routing branch/STO berulang memakai cache lokal per tahap yang selalu dibersihkan pada finally; mapping master dibaca ulang pada commit. Tidak meniadakan row lock/transaksi/retry/hash/audit, progress log atau kontrol optimistic conflict.
- **Halaman mapping/log sumber:** contoh sel didekripsi sekali per sample; JSON sumber sebelum/sesudah dibaca sekali sebelum iterasi perubahan.
- **Frontend:** bundle utama tetap 19,24kB (gzip 6,76kB); Leaflet hanya dimuat di halaman peta melalui lazy import. Tidak menambah paket/skrip berat, polling baru atau cache hasil yang bisa stale. Kontrol tabel/dialog/tema/form yang sudah bekerja dipertahankan.

## Pengukuran lokal sintetis
Tidak ada data pelanggan pada benchmark. Volume service 20 dan 1.000 WO; render list 5 dan 30 WO (25 pada halaman pertama). Tidak ada threshold timing yang membuat tes flaky; regression budget memakai jumlah query dan kesetaraan hasil.

| Jalur | Sebelum | Sesudah |
|---|---:|---:|
| Work Order, render 25 baris | 107 query | 7 query |
| List Order Prioritas, render 25 baris | 107 query | 7 query |
| Service dashboard Area | 12 query | 7 query |
| Service dashboard Branch | 23 query | 8 query |
| Ringkasan 9 antrean | 9 query | 1 query |
| Rekap bulan order + tanpa tanggal | 5 query | 1 query |

Jumlah query sesudah tetap sama pada volume kecil/besar di SQLite dan MariaDB. Observasi SQLite 1.000 WO: service Area 34,24ms sebelum → 6,02ms sesudah; Branch 6,66ms → 4,54ms; antrean 2,64ms → 1,72ms. Angka ini timing proses service lokal pada fixture, bukan waktu halaman browser atau SLA produksi. MariaDB sesudah: Area 15,38ms, Branch 19,79ms, antrean 3,96ms, tanpa baseline timing MariaDB sebelum sehingga tidak diklaim persentase peningkatan antar database.

## File pada perubahan ini
- `app/Services/BimaWorkOrdersTemplate.php`
- `app/Http/Controllers/WorkOrderImportController.php`
- `resources/views/imports/mapping.blade.php`
- `app/Services/OrderMetricAggregate.php` (baru)
- `app/Services/AreaAnalyticsService.php`
- `app/Services/BranchAnalyticsService.php`
- `app/Services/OrderMonthAnalyticsService.php`
- `app/Services/WorkOrderQueueService.php`
- `app/Services/BranchOrderScope.php`
- `app/Models/WorkOrder.php`
- `app/Services/PriorityMonitoringService.php`
- `app/Http/Controllers/PriorityOrderController.php`
- `app/Http/Controllers/WorkOrderController.php`
- `app/Support/ScopedOrderListPermissions.php` (baru)
- `resources/views/work-orders/row-actions.blade.php`
- `resources/views/priorities/bookmark.blade.php`
- `app/Services/BimaOrderResolver.php`
- `app/Services/ExcelWorkOrderImportService.php`
- `app/Services/WorkOrderSourceWriter.php`
- `tests/Feature/PerformanceBudgetTest.php` (baru)
- `tests/Feature/BimaWorkOrdersTemplateTest.php`
- `tests/Feature/ExcelWorkOrderImportTest.php`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, dokumen ini.

Perubahan fitur prioritas dari pekerjaan sebelumnya dan perubahan login pengguna tidak ditimpa/dikembalikan. Tidak ada checkout/reset/commit/push branch.

## Validasi dan perintah
- `php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never`: **288 tests / 26.181 assertions, lulus** pada implementasi akhir.
- MariaDB isolated (strict mode, max_allowed_packet 1MiB), 15 kelas terkait mapping/import/routing/auto progress/PS/analytics/drilldown/prioritas/export/Revenue/performance: **76 tests / 5.149 assertions lulus**. Database uji dibuat terpisah dan dihapus pada finally. Kasus idempotency perpindahan layout juga lulus pada full SQLite akhir setelah penambahan assertion tersebut.
- `node --test tests/JavaScript/*.test.js`: **27 tests lulus**.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check`: lulus.
- Diagnosis header/konfigurasi aktual: baca-saja; tidak membuat snapshot/preview/import atau mengubah data aplikasi.

## Migrasi, env, penggunaan dan batas
Tidak ada migration, perubahan index/schema, env atau dependency baru. Queue database tetap digunakan untuk preview/commit; jalankan worker seperti sebelumnya. Bila menggunakan worker daemon `queue:work` yang sudah memuat kode lama, muat ulang secara graceful dengan `php artisan queue:restart` lalu pastikan supervisor/worker aktif kembali. Tidak mengubah APP_DEBUG/mode development lokal atau PHP global; production tetap memakai konfigurasi production dan hasil `npm run build` sesuai runbook deployment.

Belum menjalankan benchmark concurrency/beban produksi atau pengukuran browser desktop/mobile; izin preview browser sebelumnya ditolak. Tidak menjanjikan seluruh kemungkinan bottleneck/server/network hilang. Seluruh jalur utama yang diubah diuji hasil, scope dan query budget; komponen lain dipertahankan bila tidak ditemukan bukti kebutuhan perubahan. Tidak ada input bisnis yang menghalangi format saat ini. Header yang benar-benar berganti nama/arti di masa depan perlu aturan alias yang disetujui atau mapping manual; sistem tidak menebak. File sumber asli, data operasional dan koneksi Google/Telegram tidak disentuh.


## Pembaruan sumber Tanggal PS
Arahan berikutnya menambahkan Date Modified→tgl_ps secara kondisional untuk status PS + Mapping Order PS aktif. Preset menjadi 14 pasangan dan kontrak preview Excel v5; lihat docs/BIMA_PS_DATE.md. Catatan 13 pasangan/tanggal PS belum dipetakan di bagian historis dokumen ini digantikan oleh aturan tersebut. Optimasi/query budget dan perlindungan data tetap berlaku.
