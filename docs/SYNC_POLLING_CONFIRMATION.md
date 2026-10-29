# Status sinkronisasi dan akun konfirmasi — 29 Oktober 2026

## Checklist

- [x] Pertahankan konfirmasi hanya oleh pengunggah, tampilkan alasan blokir yang spesifik.
- [x] Gunakan URL polling pada origin situs dan response tanpa cache untuk HTML/JSON.
- [x] Bedakan sesi/akses/server/timeout, sediakan retry dan backoff tanpa berhenti diam-diam setelah10kegagalan.
- [x] Uji frontend serta akses/pemilik/expiry/kontrak dan dokumentasikan hasil sebenarnya.

Pengguna mengonfirmasi preview dibuka dengan akun lain. Itu menjelaskan tombol konfirmasi yang tidak muncul; izin tidak dilonggarkan. Penyebab kegagalan request polling di server belum diketahui karena statusHTTP/log belum tersedia.

## Perubahan

Halaman preview_ready selalu menyediakan bagian pemeriksaan konfirmasi untuk inbound import/pull. Nama pengunggah dan tanggal/jam berlaku lengkap dalam WIB ditampilkan. Alasan terpisah: kontrak kolom berubah, mode penerimaan berubah, akun berbeda, preview kedaluwarsa/tanggal tidak tersedia, atau hasil baca kosong. Bila seluruh syarat lolos, checkbox dan tombol lama muncul. Akun berbeda diminta masuk dengan akun pengunggah, tanpa dipaksa membuat preview baru hanya karena berganti akun. Bila preview memang kedaluwarsa, unggah ulang dan buat preview baru; tidak memperpanjang expiry atau menjalankan commit otomatis.

Guard backend konfirmasi tetap seperti sebelumnya: Gate sync aktif, pengunggah yang sama, kontrak/mode sesuai, belum kedaluwarsa, baris terbaca dan konfirmasi pengguna. Job stage commit tetap idempotent. Menggunakan akun Area lain tidak memberi hak mengambil alih preview. Perbaikan cast integer id_users dari tahap sebelumnya dipertahankan.

Polling lama memakai URL absolut, menggabungkan seluruh kegagalan menjadi pesan koneksi dan berhenti setelah10kegagalan tanpa kontrol retry. Kini:

- URL pada markup relatif ke situs aktif. Fetch menolak origin lain; tidak membawa alamat backendHTTP/hostAPP_URL yang berbeda ke halamanHTTPS.
- Response HTML dan JSON detail mempunyai Cache-Control private/no-store dan Vary Accept/Cookie; fetch juga memakai no-store. Ini membantu mencegah proxy/browser memakai status lama atau menukar HTML dengan JSON, tanpa mengklaim konfigurasi proxy server sudah diperiksa.
- Validasi jenis response, statusHTTP, state dan ID job sebelum menampilkan status/reload. JSON tidak memuat snapshot/payload.
- Pesan khusus untuk sesi401/419, akses403, job404, pembatasan429, server5xx, HTML/redirect login, JSON rusak, balasan job berbeda, jaringan dan timeout20detik.
- Gangguan sementara dicoba ulang dengan backoff3–30detik, tidak berhenti setelah10kegagalan. Untuk429, Retry-After numerik dihormati dengan batas30–120detik. Sesi/akses atau balasan tidak sesuai menghentikan polling otomatis dan menyediakan Coba perbarui status/Muat ulang halaman.
- Hanya satu request berjalan, timeout/pagehide membatalkan request status saja; tidak membatalkan job worker. Setelah balasan status nonbusy yang valid, halaman direload satu kali agar hasil/konfirmasi terbaru terbaca.

Tidak ada polling yang mengubah state job, mengimpor WO, menambah retry worker, menghapus staging atau melewati konfirmasi. Pesan kegagalan status tidak menyatakan worker/import pasti gagal. Tampilan memakai gaya white/dark dan tombol compact yang sudah ada; tombol retry tersembunyi sampai diperlukan.

## Tindakan di server

1. Untuk preview milik akun lain, login dengan akun pengunggah dan buka Detail yang sama. Konfirmasi hanya muncul jika preview masih berlaku, kontrak/mode cocok dan hasil baca tidak kosong. Bila akun yang sama masih diblokir, baca alasan spesifik; jangan mengganti id_users atau state job langsung di database.
2. Deploy PHP/Blade, module JavaScript dan asset production yang memuat perubahan ini. Build Vite wajib karena logika polling sudah dipindah ke module baru; jangan hanya menyalin PHP/Blade atau membawa public/hot development.

   ```sh
   npm run build
   php artisan optimize
   php artisan queue:restart
   ```

   Asset boleh dibangun di CI lalu kirim seluruh public/build beserta manifest. Queue restart mengikuti process manager server; reload PHP-FPM/Apache bila OPcache deployment memerlukannya. Tidak perlu migration/env/dependency baru, clear seluruh cache saat job berlock, menghapus job atau mengulang commit secara manual.
3. Muat ulang penuh halaman browser. Jika masih bermasalah, catat statusHTTP GET /sync/jobs/{id} dan pesan yang baru. HTTP403 mengarah pemeriksaan akun/role/sesi, HTML/401/419 ke sesi atau redirect, 5xx ke log aplikasi/proxy, timeout ke respons server/jaringan. Jangan menganggap akun berbeda sebagai penyebab polling gagal: endpoint status bisa dibaca operator lain dengan izin sync, walaupun operator tersebut tidak boleh commit.

Belum ada akses, pengukuran requestHTTP atau deployment langsung ke server pengguna. Diagnosis tombol berdasarkan konfirmasi pengguna dan guard kode; penyebab polling produksi masih memerlukan statusHTTP/log sesungguhnya. Konfigurasi trusted proxy/HTTPS, cache edge dan versi asset yang terpasang perlu diperiksa bila pesan mengarah ke sana; tidak melonggarkan trusted proxy/CSRF/otorisasi secara spekulatif.

## Validasi aktual

- **59tes SQLite /1.351assertions lulus**: pemilik vs operator lain, expiry/null expiry, mode/kontrak/hasil kosong, commit ditolak dan WO tidak berubah, URL relatif walau absolute root salah, HTML/JSON no-store dan status terbaru; regresi import, worker recovery, tanggal PS, Sheets legacy, penutupan push dan cast ID tetap lulus.
- **35tes JavaScript lulus**, termasuk6tes polling: HTTPS/origin, credential/cache, recovery melewati12gangguan, request tunggal, sesi/akses/HTML/job salah, retry manual, HTTP500/429/Retry-After, timeout dan pagehide.
- Vite build, Pint dan git diff --check lulus. JS utama sekitar21,61kB /gzip7,53kB.
- Tes tambahan MariaDB **tidak dijalankan**, karena permintaan izin akses database ditolak. Tidak mencoba jalur lain atau mengklaim hasil MariaDB. Suite penuh tidak diulang untuk perubahan terbatas ini; catatan kegagalan branding lama pada tahap sebelumnya tetap berlaku.
- Tidak melakukan verifikasi visual browser baru; izin browser/HTTP sebelumnya ditolak. Tidak mengubah data aplikasi aktual, template, progress, BOQ atau integrasi eksternal.

```sh
php -d memory_limit=512M vendor/bin/phpunit --filter 'SyncPollingViewTest|SyncMappingAccessTest|AccessAndSyncTest|ExcelWorkOrderImportTest|ImportWorkerRecoveryTest|BimaWorkOrdersTemplateTest|BimaPsDate|SheetsOnboardingTest|MonitoringPushTest|NamedKeysBehaviorTest|WebPerformanceTest' --no-progress --colors=never
node --test tests/JavaScript/*.test.js
npm run build
vendor/bin/pint --dirty --test
git diff --check
```

## Berkas diubah

- `resources/views/sync/show.blade.php`: URL relatif, retry/fallback dan alasan konfirmasi spesifik.
- `app/Http/Controllers/SyncController.php`: header no-store/Vary untuk detail HTML/JSON.
- `resources/js/sync-polling.js` (baru), `resources/js/app.js`: polling teruji dan pemasangannya.
- `resources/css/app.css`: hidden state tombol retry.
- `tests/Feature/SyncPollingViewTest.php`, `tests/JavaScript/sync-polling.test.js` (baru).
- Dokumen ini dan addendum `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Perubahan ownership403 dan optimasi login/upload dari tahap sebelumnya pada working tree dipertahankan. Tidak ada commit/push/deployment otomatis.
