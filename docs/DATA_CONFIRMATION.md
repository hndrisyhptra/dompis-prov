# Konfirmasi simpan/hapus dan kartu dashboard

Discovery: form Blade memakai listener loading bersama; semua operasi mutasi melewati POST/PATCH/PUT/DELETE dengan CSRF dan validasi server yang sudah ada. Logout sudah memiliki dialog terpisah. Garis aksen atas kartu Area berasal dari `.area-kpis .stat`; Branch memakai border tipis standar.

Checklist:
- [x] Dialog Ya/Tidak bersama untuk form mutasi dalam aplikasi; filter/login/logout tidak mendapat dialog ganda.
- [x] Validasi sebelum konfirmasi, batal tanpa request/data hilang, Ya mengirim satu kali dengan submitter asli dan loading sesudah persetujuan.
- [x] Hapus aksen border atas dashboard; struktur/konten/metrik tetap.
- [x] Tes kontrol pengiriman, regresi server, build/lint dan dokumentasi aktual.


## Hasil

Dialog konfirmasi bersama berlaku pada semua form mutasi di konten aplikasi (`.page`, POST/PUT/PATCH/DELETE): master lokasi dan kedua mapping, user management, progress/kendala/Close, perangkat, catatan/penyelesaian kendala, referensi Branch legacy, upload/mapping preview, commit dan retry import. Filter GET tidak diintersep. Login tidak memiliki dialog ini, sedangkan Logout mempertahankan konfirmasi tersendiri (termasuk halaman fallback) sehingga tidak mendapat dialog ganda.

Form divalidasi sebelum dialog muncul. **Tidak** atau Escape menutup dialog tanpa request dan tanpa reset input/file/catatan. **Ya, Simpan** / **Ya, Hapus** mengirim form asli melalui requestSubmit dengan submitter asli, validasi ulang, CSRF, method override, file dan revision tetap pada form. Persetujuan berlaku satu kali dan tidak disimpan lintas submit. Form lain tidak dapat mengganti operasi yang sedang dikonfirmasi. Form yang sudah dilepas dari DOM, tombol yang dinonaktifkan atau form yang sedang mengirim tidak dikirim ulang. Loading yang ada baru dijalankan sesudah persetujuan; submit awal yang dibatalkan tidak membuat tombol terkunci.

Dialog membedakan pesan simpan/hapus dan menampilkan nama tindakan. Fokus awal Tidak, fokus modal dan Escape memakai perilaku native dialog. Browser yang tidak menyediakan showModal/requestSubmit memakai dialog confirm bawaan. JavaScript aktif diperlukan untuk intersepsi konfirmasi ini; ini lapisan UX, bukan pengganti validasi/otorisasi backend. Pemeriksaan relasi, checkbox konfirmasi penghapusan/import, audit, policy dan revision server yang ada tetap berlaku.

Kartu dashboard Area tidak lagi memiliki aksen biru border-top 2px. Kartu Area dan Branch menggunakan border tipis netral yang sama dari token light/dark, tanpa mengubah isi, angka, filter atau tautan KPI. Border struktur tabel/grafik tidak diubah.

## File

- `resources/views/layouts/app.blade.php`: satu dialog konfirmasi simpan/hapus untuk halaman terautentikasi.
- `resources/views/auth/logout-confirm.blade.php`: penanda pengecualian konfirmasi Logout yang sudah ada.
- Baru `resources/js/form-confirmation.js`: kontrol persetujuan satu kali, validasi, batal, submitter dan fallback.
- `resources/js/app.js`: inisialisasi kontrol dan loading menghormati event yang dibatalkan.
- `resources/css/app.css`: hapus override aksen border atas kartu Area; gaya dialog memakai token yang sudah tersedia.
- Baru `tests/JavaScript/form-confirmation.test.js`: tujuh tes state dan pengiriman dengan Node test runner tanpa dependensi/browser.
- `docs/DATA_CONFIRMATION.md`: keputusan, checklist, hasil dan batas aktual.

## Verifikasi

```sh
node --test tests/JavaScript/form-confirmation.test.js
node --check resources/js/app.js
npm run build
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
php artisan view:cache
vendor/bin/pint --dirty --test
git diff --check
```

**7 tes JavaScript lulus**: simpan satu kali/submitter asli, Tidak/Escape, hapus dan form lain, invalid/removed/disabled form, validasi ulang, pengecualian filter/logout, duplikasi dan fallback. Pengujian menggunakan kontrol DOM sintetis untuk menguji kontrak event, bukan pengujian browser nyata. Build frontend, sintaks JS, Blade cache, Pint dan diff check lulus. Regresi server lengkap: **184 tes / 4.747 assertion lulus**.

Tidak ada migration, env, paket, perubahan data/credential/role aplikasi atau template asli. Tidak perlu input bisnis tambahan. Tidak mencoba ulang akses browser/preview yang sebelumnya ditolak; visual dan interaksi browser nyata belum diklaim terverifikasi. Tidak ada pengujian koneksi Google/Telegram baru pada tahap ini.
