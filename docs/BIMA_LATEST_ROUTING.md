# STO dan Branch mengikuti import terbaru — 24 Oktober 2026

## Checklist implementasi

- [x] Periksa resolver, mapper Excel/Sheets, sumber hash, scope Branch, history dan bookmark.
- [x] Izinkan perubahan STO/Branch dan assignment dari import yang dikonfirmasi; klasifikasikan perubahan assignment meskipun hash sumber sama.
- [x] Catat perubahan sumber/assignment, increment revision saat berpindah Branch, pertahankan riwayat/perangkat/kendala.
- [x] Uji alur Excel, akses Branch lama/baru, prioritas, idempotency dan konflik sesudah preview.
- [x] Jalankan suite, MariaDB terisolasi, build dan dokumentasikan hasil.

Arsitektur tetap Laravel 13, service import Excel dan adapter Sheets legacy, identitas `(source_system,no_tq_sc_id)`, satu WO terpusat. Tidak mengganti schema atau membuat WO salinan. Tidak ada migration/env/dependency baru.

## Aturan yang berlaku

- Identitas tetap No WO/NO TQ-SC-ID, bukan SID. Pada konfirmasi import, atribut STO, BRANCH dan atribut BIMA lain yang dipetakan mengikuti snapshot yang disetujui.
- Branch sumber dicari melalui alias legacy (mapping khusus STO didahulukan), kemudian kode/nama master Branch yang cocok setelah trim dan normalisasi huruf. Nama yang ambigu tidak dipilih otomatis. Branch harus aktif dan tidak diarsipkan.
- Jika Branch sumber dikenali, Branch tersebut menjadi penugasan WO walaupun master kepemilikan STO masih menunjuk Branch lain. Master Service Area tidak diubah, sehingga satu file tidak memindahkan semua order/user yang memakai STO yang sama.
- Bila Branch sumber belum dikenali, routing STO tetap menjadi fallback yang sudah ada. Jika tidak ada tujuan valid, atau STO/Region tidak aktif/diarsipkan maupun referensi STO tidak konsisten, baris tetap conflict dengan alasan validasi master.
- Perbedaan dengan Branch order lama kini menjadi update normal. Jika hanya mapping penugasan berubah, baris tetap menjadi berubah meskipun hash sumber sama; sesudah diterapkan, upload identik kembali menjadi unchanged.
- Kolom yang tidak dipilih pada mapping tidak mengosongkan/mengganti nilai lama. Aturan progress otomatis SURVEY/INSTALASI/CLOSE yang telah disetujui tetap berjalan secara terpisah.
- Preview tidak memindahkan order. Perubahan mapping, sumber atau assignment yang terjadi setelah preview tetap menahan commit dan membutuhkan preview baru. Ini mencegah snapshot lama menimpa import lebih baru.

## Riwayat dan otorisasi

- Pergantian atribut Branch/STO tercatat sebelum/sesudah dalam Log Task BIMA yang sudah ada. Pergantian penugasan turut mencatat `id_branches`, sehingga perubahan routing dengan sumber identik juga memiliki log.
- Audit `work_order.routing_updated_from_bima` mencatat job, actor, routing sebelum/sesudah dan revision.
- Perpindahan `id_branches` menambah revision dan satu event penugasan dengan nama Branch lama→baru, STO, actor dan waktu. Kategori/status progress tetap sama pada event ini; `last_progress_at` tidak dibuat seolah-olah Branch memperbarui progress.
- Seluruh riwayat progress, kendala/tindak lanjut, perangkat/override, tanggal selesai/PS dan verifikasi tetap tersimpan. Aturan otomatis progress dapat menambah event berikutnya jika Status BIMA memenuhi daftar yang disetujui.
- Tidak menambahkan assignment Branch pada user. User Branch lama kehilangan akses ke order, user yang sudah ditugaskan pada Branch baru mendapat akses. User yang mencakup kedua Branch tetap harus memuat ulang form karena revision berubah.
- Bookmark Area tetap mengikuti WO ke Branch baru. Bookmark lokal Branch asal tetap disimpan sebagai riwayat, tidak tampil untuk Branch baru atau Area. Tidak menghapus/memindahkan bookmark lokal.
- Retry/unggah identik tidak membuat WO, task log, event penugasan atau audit routing ganda.

## Penggunaan untuk file yang sebelumnya conflict

Upload ulang file BIMA terbaru, periksa mapping, buat preview baru, lalu konfirmasi import. Job lama yang sudah partial_success/conflict tidak dijalankan ulang otomatis. Perubahan hanya diterapkan pada baris yang valid dan dikonfirmasi.

## File diubah pada tahap ini

- `app/Services/BimaOrderResolver.php`: menerima perubahan Branch, mendahulukan sumber Branch yang dikenali, fallback kode/nama master dan klasifikasi perubahan assignment.
- `app/Services/WorkOrderSourceWriter.php`: revision, event penugasan, log before/after dan audit routing.
- `app/Services/ExcelWorkOrderImportService.php`, `app/Services/BimaSheetPullService.php`: snapshot assignment dan validasi perubahan sesudah preview.
- `resources/views/imports/mapping.blade.php`, `resources/views/sync/show.blade.php`: penjelasan perilaku terbaru sebelum konfirmasi.
- `tests/Feature/BimaRoutingImportTest.php` (baru), `tests/Feature/BimaPullTest.php`, `tests/Feature/RegionAndStoTest.php`: alur terbaru dan perlindungan yang tetap berlaku.
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`, dokumen ini: arahan pemilik menggantikan penolakan Branch lama.

Working tree sebelumnya dipertahankan. Tidak melakukan reset, commit, push, perubahan template asli atau import/backfill data pelanggan.

## Hasil verifikasi

- SQLite: **207 tes / 6.216 assertions lulus**.
- MariaDB terisolasi: **50 tes / 2.497 assertions lulus** untuk routing, import, auto progress/CLOSE, master STO, prioritas dan halaman aksi. Database uji terpisah dihapus sesudah tes; tidak menggunakan data pelanggan sebagai fixture.
- JavaScript: **7 tes lulus**.
- Build Vite, cache Blade, Pint dan diff check lulus.

Perintah yang dijalankan:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php /private/tmp/olo-bima-routing-mysql-tests.php
node --test tests/JavaScript/form-confirmation.test.js
npm run build
php artisan view:cache
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
git diff --check
php artisan queue:restart
php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1
```

Tidak menjalankan ulang file/job pelanggan atau backfill produksi. Tidak ada migration atau env baru; tidak membutuhkan kredensial eksternal. Verifikasi browser baru tidak dilakukan; tampilan diuji dengan feature tests dan kompilasi Blade. Tidak mengklaim koneksi Google/Telegram end-to-end.

Worker dompisprov lama berhenti dengan sinyal restart dan worker pengganti dijalankan dengan kode terbaru. Tidak menghentikan worker repository lain.
