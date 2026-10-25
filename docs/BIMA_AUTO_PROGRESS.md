# Progress otomatis saat import BIMA — 24 Oktober 2026

## Checklist dan arsitektur

- [x] Periksa Laravel, kontrak mapper/import, revision, log task BIMA, progress Branch dan aturan CLOSE yang sudah berjalan.
- [x] Pisahkan 22 aturan SURVEY dan 37 aturan INSTALASI dalam `BimaProgressMapping`, berdasarkan kombinasi utuh `status_order` dari pemilik.
- [x] Integrasikan pada commit baru, berubah dan identik melalui satu service; preview tidak mengubah WO.
- [x] Tambahkan event progress dan audit atomik; pertahankan seluruh riwayat, catatan kendala dan perangkat Branch.
- [x] Jalankan pengujian SQLite, MariaDB terisolasi, lint, Blade dan build; catat hasil aktual.

Aplikasi memakai Laravel 13, Blade, service import Excel dengan allowlist dan hash snapshot, serta adapter Sheets legacy. Perubahan ini memperluas penyesuaian otomatis CLOSE yang sudah ada tanpa mengganti identitas WO, kontrak 13 kolom template atau hash sumber. Tidak mengubah schema; tidak memerlukan migration, env atau dependency baru.

## Aturan dan perilaku

1. Kunci penuh dari `task_bima` + `status_bima` membentuk `status_order`. Daftar baru memakai pencocokan persis/case-sensitive setelah trim; tidak menebak dari kata Survey/Activate atau akhiran COMPLETE/CANCLWORK.
2. 22 kombinasi dalam `App\Support\BimaProgressMapping::SURVEY` → `progress_category=SURVEY`, `operational_status=SURVEY`.
3. 37 kombinasi dalam `App\Support\BimaProgressMapping::INSTALASI` → `progress_category=INSTALASI`, `operational_status=IN_PROGRESS`.
4. Empat belas aturan CLOSE/PS tetap didahulukan melalui `BimaAutoCloseService`. Status di luar daftar tidak mengubah progress. Kombinasi eksplisit `Survey-Ondesk Manual-CANCLWORK` tetap SURVEY; `Pickup NTE from SCM Manual-CANCLWORK` dan `Activate Cross Connect-CANCLWORK` tetap INSTALASI.
5. Aturan SURVEY/INSTALASI tidak membuka ulang progress CLOSE/CANCEL/CLOSE ON DESK maupun status akhir COMPLETED/CANCELLED. Koreksi/pembukaan kembali tetap melalui otorisasi Area. Aturan CLOSE/PS sebelumnya tidak diubah.
6. Penyesuaian dilakukan sesudah konfirmasi import, di dalam transaksi baris yang sama dengan sumber dan log BIMA. Upload dan preview saja tidak menulis WO. Baris ditolak atau konflik tidak menjalankan mapping progress.
7. Sumber identik tetap diperiksa untuk penyesuaian progress. Jika kategorinya sudah sama, revision/waktu/event/audit progress tidak berubah. Jika Branch memperbarui kategori lalu file identik diimpor, aturan otomatis dapat menyesuaikan kategori kembali, dengan menambahkan event; catatan Branch tetap tersedia.
8. Hitungan `Tetap/unchanged` menunjukkan hash sumber identik. Progress tidak dimasukkan ke hash, sehingga hitungan ini tidak berarti progress selalu tidak berubah.

## Jaminan riwayat dan data

- Tidak ada penghapusan atau penggantian `wo_status_events`, `bima_task_logs`, `wo_issues`, maupun `wo_issue_notes`.
- Setiap perubahan progress menambahkan satu event lama→baru, revision, catatan alasan otomatis beserta status sumber, actor operator import dan waktu. Waktu riwayat ditampilkan hingga detik dalam WIB.
- Audit `progress.auto_mapped_from_bima` menyimpan job, actor, status sumber, nilai sebelum/sesudah dan jumlah kendala aktif yang dipertahankan.
- Riwayat task BIMA tetap mencatat perubahan atribut sumber sebelum/sesudah. File identik tidak menghasilkan log task tambahan.
- Kendala/tindak lanjut tidak diselesaikan otomatis. Order tetap masuk daftar kendala aktif meskipun kategori otomatis menjadi SURVEY/INSTALASI; penyelesaian kendala tetap eksplisit.
- Perangkat dan override Branch, bookmark, tanggal PS/selesai serta verifikasi tidak diubah oleh penyesuaian SURVEY/INSTALASI.
- Transaksi dan row lock menjaga sumber/progress/event/audit agar konsisten. Form Branch yang memakai revision sebelum import mendapat 409 dan harus dimuat ulang.
- Gagal menulis event membatalkan seluruh perubahan baris termasuk sumber dan log task; snapshot baris tetap tersedia untuk retry.

## File berubah pada tahap ini

- Baru: `app/Support/BimaProgressMapping.php`, `app/Services/BimaAutoProgressService.php`.
- Integrasi: `app/Services/WorkOrderSourceWriter.php`, `app/Services/ExcelWorkOrderImportService.php`, `app/Services/BimaSheetPullService.php`.
- UI: `resources/views/imports/mapping.blade.php`, `resources/views/sync/show.blade.php`, `resources/views/work-orders/show.blade.php`.
- Tes baru: `tests/Unit/BimaProgressMappingTest.php`, `tests/Feature/BimaAutoProgressTest.php`.
- Penyesuaian ekspektasi/fixture tes lama: `tests/Feature/NewOrderTest.php`, `tests/Feature/WorkOrderActionPagesTest.php`, `tests/Feature/WorkOrderPresentationTest.php`.
- Dokumentasi: file ini, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Perubahan lain yang sudah ada di working tree dipertahankan; tidak melakukan reset, commit, atau push.

## Operasional dan batas

Aturan berlaku pada konfirmasi import berikutnya; tidak menjalankan import/backfill massal atas data produksi. Worker antrean harus memuat kode terbaru. Pada deployment dengan pengelola proses, jalankan `php artisan queue:restart`; pada worker manual, jalankan kembali worker setelah restart. Template XLSX asli tidak diubah. Tidak memerlukan kredensial Google untuk import Excel; tidak mengklaim Google/Telegram sudah diuji end-to-end.

## Pengujian

Semua pemeriksaan berikut lulus:

- SQLite: **201 tes / 6.021 assertions**, termasuk 59 kombinasi, pencocokan persis, preview tanpa mutasi, import baru/berubah/identik, retry, riwayat Branch, kendala/tindak lanjut, perangkat, revision stale, transaksi gagal, otorisasi, terminal dan aturan CLOSE/PS.
- MariaDB terisolasi: **44 tes / 2.401 assertions** untuk import/mapper/status/perangkat, aturan otomatis, halaman aksi dan New Order. Database sementara dibuat terpisah dan dihapus sesudah tes; tidak memakai WO produksi sebagai fixture.
- JavaScript konfirmasi: **7 tes lulus**.
- Build Vite, cache Blade, Pint dan pemeriksaan diff lulus.

Perintah yang dijalankan:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-bima-auto-progress-mysql-tests.php
node --test tests/JavaScript/form-confirmation.test.js
npm run build
php artisan view:cache
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
git diff --check
php artisan queue:restart
php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1
```

Tidak menjalankan upload/import file pelanggan atau backfill produksi pada tahap ini. Verifikasi browser baru tidak dilakukan; UI diperiksa melalui feature tests HTTP dan kompilasi Blade. Tidak ada input tambahan yang diperlukan untuk aturan yang diberikan.

Worker lama yang diperiksa berada di repository dompisprov dan sudah berhenti lewat sinyal restart. Satu worker pengganti dijalankan dengan kode terbaru. Worker repository dompis-qe tetap berjalan dan tidak diubah. Tidak ada file pelanggan yang diimpor oleh sesi ini.
