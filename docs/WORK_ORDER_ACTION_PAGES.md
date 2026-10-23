# Detail dan halaman aksi Work Order

Discovery: Laravel 13/Blade; tabel dan kartu mobile menggunakan partial aksi yang sama, form tiga mode saat ini bercampur pada detail. Backend sudah mempunyai policy Branch, revision, issue ledger, audit dan aturan Close/PS. Pakai service yang sama, tanpa migration/env.

Checklist:
- [x] Detail selalu tersedia; referensi BIMA dan riwayat terpisah dari form aksi.
- [x] Halaman Progress/Kendala/Close mandiri dengan whitelist dropdown dan validasi backend.
- [x] Kendala empat kategori menyimpan detail dan tindak lanjut, Close tetap wajib penyelesaian kendala.
- [x] Scope seluruh Branch/Area, terminal/revision, riwayat dan sumber tetap aman; dashboard mengenali kendala baru.
- [x] Tes, build/lint dan dokumentasi hasil aktual.


## Perilaku baru

Tabel desktop dan kartu mobile memakai partial aksi yang sama: Detail Order selalu tersedia untuk order yang dapat dilihat. Tiga ikon aksi lain membuka halaman mandiri (tetap tooltip dan label aksesibel):

| Halaman | Pilihan tampilan | Nilai SQL/API |
|---|---|---|
| Update Progress | OGP BIMA, Resubmit AO, SURVEY, JT, INSTALASI | OGP BIMA, RESUBMIT AO, SURVEY, JT, INSTALASI |
| Kendala | Cancel Order, HOLD, Butuh QE, Perizinan | CANCEL, HOLD, BUTUH QE, PERIZINAN |
| Close | CLOSE | CLOSE |

GET/PATCH `/work-orders/{workOrder}/actions/{mode}` dengan mode progress/issue/close. Backend menolak kategori di luar halaman, penyisipan operational_status, note kosong, dan resolve_issues dari halaman Kendala. Tautan `?action=progress/issue/close` lama redirect ke halaman baru setelah policy view; halaman baru membutuhkan policy update.

Detail Order tidak lagi berisi dropdown atau form pembaruan progress. Detail tetap menyediakan informasi sumber dalam accordion, Referensi BIMA, Data perangkat, Log Task BIMA beserta pagination, kendala tersimpan secara read-only dan riwayat status. Tombol pembaruan membuka halaman terpisah. Data perangkat tetap mengikuti policy sebelumnya; tautan dari Close membuka accordion perangkat pada detail.

Kendala: detail wajib tersimpan pada issue baru atau note pada issue aktif, sekaligus event progress dan audit. Perizinan baru diberi kategori issue PERMIT; lainnya OTHER. HOLD/Butuh QE/Perizinan dari halaman ini mempunyai operational_status BLOCKED, masuk kelompok Kendala pada rekap Area/Branch. Cancel Order menjadi CANCEL/CANCELLED (bukan PS); catatan alasan dan issue tetap tersimpan, terpisah dari outstanding. Tidak menyelesaikan issue diam-diam.

Progress baru dapat mengonfirmasi issue selesai melalui checkbox; catatan menjadi hasil penyelesaian. Close tetap mensyaratkan semua issue aktif diselesaikan dan mencatat FIELD_DONE, bukan verifikasi Area atau PS otomatis. Catatan tambahan pada CLOSE mempertahankan waktu selesai. Branch tidak dapat membuka kembali status terminal; koreksi Area memakai izin yang sama seperti sebelumnya. Verifikasi Area yang sudah ada tersedia terpisah di halaman Close untuk kondisi yang sesuai.

Definisi PS 14 kombinasi BIMA + progress CLOSE dan TGL PS harian tetap. Tidak mengubah task/status/hash sumber, perangkat, atau log BIMA melalui pembaruan operasional. Riwayat kategori lama dipertahankan tanpa migration/backfill. Endpoint PATCH progress legacy tetap tersedia untuk kompatibilitas, audit dan verifikasi Area; form baru memakai endpoint whitelist mandiri.

## File diubah

- Baru `app/Support/WorkOrderActions.php`: definisi mode/label/whitelist terpusat.
- `app/Http/Controllers/WorkOrderController.php`: halaman aksi dengan policy/terminal guard, redirect tautan lama.
- `app/Http/Controllers/BranchProgressController.php`: validasi dan simpan endpoint per mode.
- `app/Services/BranchProgressService.php`: pencatatan kendala mode baru, PERMIT, transaksi/revision/terminal protection.
- `app/Support/ProgressSummary.php`: HOLD/Butuh QE/Perizinan menjadi kelompok Kendala.
- `routes/web.php`: GET/PATCH halaman aksi.
- `resources/views/work-orders/row-actions.blade.php`, `show.blade.php`, `progress.blade.php`, `index.blade.php`, `mobile-card.blade.php`: tombol Detail, halaman detail tanpa form progress, dropdown spesifik, indikator kendala.
- Baru `resources/views/work-orders/action.blade.php`, `issue-history.blade.php`: layout halaman aksi dan riwayat kendala reusable.
- `resources/js/app.js`, `resources/css/app.css`: hapus pengalihan mode dalam halaman, layout responsif, tooltip di luar tabel, pembukaan accordion perangkat melalui hash.
- Baru `tests/Feature/WorkOrderActionPagesTest.php`; adaptasi `WorkOrderPresentationTest.php`, `OperationsWorkspaceTest.php`, `BranchWorkflowTest.php`, `WorkOrderDetailFlowTest.php`, `BimaWorkOrdersTemplateTest.php` agar memeriksa alur halaman baru. End-to-end lokal Excel→WO→tiga operator Branch→kendala→Close→PS BIMA→dashboard tetap diuji dengan sumber sintetis.
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, dokumen ini: requirement dan hasil aktual.

## Verifikasi

```sh
vendor/bin/pint --dirty
php vendor/bin/phpunit --filter='WorkOrderActionPagesTest|WorkOrderPresentationTest|OperationsWorkspaceTest|BranchWorkflowTest|WorkOrderDetailFlowTest' --no-progress --colors=never
php /private/tmp/olo-work-order-actions-mysql-tests.php
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
npm run build
node --check resources/js/app.js
vendor/bin/pint --dirty --test
php artisan view:cache
php artisan route:list --path=actions
git diff --check
```

Targeted workflow tests: **19 tes / 628 assertion lulus**, baik SQLite maupun MariaDB terisolasi. Helper MariaDB membuat database acak dan menghapusnya dalam finally; database aplikasi tidak dipakai untuk mutasi pengujian. Tes mencakup tujuh role operator, pilihan dropdown persis, data sumber/PS, scope antar Branch, material viewer, akun nonaktif, kategori silang, catatan kosong, resolve issue, stale revision, terminal dan koreksi Area. Regresi lengkap SQLite: **184 tes / 4.736 assertion lulus**, termasuk alur handoff Excel yang sudah disesuaikan ke endpoint halaman baru. Build frontend, sintaks JavaScript, Pint, Blade cache, daftar route dan diff check lulus.

Tidak ada migration, env, paket baru atau mutasi data aplikasi pada tahap ini. Template/credential tetap. Tidak ada kebutuhan input bisnis yang menghalangi implementasi. Pemeriksaan visual browser belum terverifikasi karena izin preview sebelumnya ditolak; tidak mencoba ulang akses tersebut. Light/dark/responsif memakai token existing dan diuji melalui render HTTP/build, bukan klaim pengujian browser. Tidak mengklaim Google/Telegram E2E.
