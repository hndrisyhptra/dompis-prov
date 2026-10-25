# Dompis Prov: identitas, login, grafik bulanan dan kata sandi

## Checklist implementasi

- [x] Inspect pola Blade/SVG dashboard, modal drilldown, login, form/validasi User Management dan tes existing.
- [x] Ubah penyelesaian per bulan order menjadi grafik garis memakai data/scope/definisi PS yang sama.
- [x] Ganti identitas sidebar, mobile header, judul halaman dan login menjadi Dompis Prov WIBS Area Jawa Bali.
- [x] Perbarui login white/dark responsif dengan fitur tampilkan/sembunyikan kata sandi; reuse pada form User Management.
- [x] Ubah validasi backend dan HTML kata sandi menjadi minimal 8 karakter, tanpa kewajiban huruf besar/kecil/angka.
- [x] Catat hasil tes/build dan pemeriksaan akhir.

## Perubahan perilaku

Grafik garis menampilkan Total WO, Progress CLOSE, Sudah PS, Outstanding. Sumbu bulan berjalan dari terlama ke terbaru, berdasarkan TGL ORDER. Titik membuka dialog daftar WO dengan bulan/kategori/scope filter yang sama; fallback tanpa JavaScript tetap menuju daftar Work Order. Tabel numerik tersedia dalam accordion **Lihat data bulanan**. Empty state dan catatan order tanpa tanggal tetap tersedia. Menggunakan SVG dan warna white/dark aplikasi; empat garis dibedakan warna dan pola. Satu bulan tetap tampil sebagai titik. Tidak mengubah query, angka dashboard, status BIMA, definisi PS atau progress.

Login memakai nama Dompis Prov dan subnama WIBS Area Jawa Bali, panel informasi operasional dan form yang fokus. Pada mobile hanya identitas/form utama ditampilkan. Tidak ada gradient, gambar dekoratif, angka demo, atau tombol mode light/dark pada login. Theme existing tetap dihormati; toggle tema hanya topbar authenticated.

Tombol mata menampilkan/menyembunyikan field terkait, mempertahankan nilainya, autocomplete dan form yang sama, dengan aria-label/title/aria-pressed. Tombol bertipe button sehingga tidak mengirim form. Tersedia pada login, password baru User Management dan konfirmasinya. Tanpa JS field tetap password dan form bekerja, toggle tersembunyi.

User Management: create/update minimal 8 karakter, maksimal 255 dan konfirmasi harus sesuai. Huruf kecil saja, huruf besar saja, atau angka saja diterima pada panjang ≥8. Edit dengan kata sandi kosong tetap mempertahankan kata sandi lama. Tidak mereset kata sandi user existing atau memperluas akses User Management.

## File yang diubah

- `resources/views/work-orders/order-month-summary.blade.php`: grafik SVG, legenda, titik drilldown, tabel disclosure.
- `resources/js/dashboard-tables.js`: dukungan href SVGAnimatedString pada titik grafik.
- `resources/views/layouts/app.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/welcome.blade.php`: nama produk dan login.
- `resources/views/components/password-toggle.blade.php`, `resources/views/components/icon.blade.php`, `resources/js/password-visibility.js`, `resources/js/app.js`: tombol mata.
- `app/Http/Requests/SaveUserRequest.php`, `resources/views/admin/users/form.blade.php`: syarat kata sandi 8 karakter dan bantuan form.
- `resources/css/app.css`: login, kontrol password, chart dan white/dark/responsif.
- `tests/Feature/UserManagementTest.php`, `UsernameAndThemeTest.php`, `BimaAutoCloseTest.php`, `AccessAndSyncTest.php`, `DashboardDrilldownTest.php`, `tests/JavaScript/password-visibility.test.js`, `dashboard-tables.test.js`. Tes rekap memeriksa setiap hitungan dan tautan titik SVG melalui label aksesibelnya, lalu mencocokkan jumlah hasil modal.
- Dokumen ini dan tambahan arahan pemilik pada AGENTS.md/SKILLS.md/PRD.

## Migration / environment

Tidak ada migration, dependency atau environment baru. Tidak mengubah data user, hash kata sandi, role/assignment, WO atau histori. Build dengan `npm run build` dan cache Blade dengan `php artisan view:cache`.

## Verifikasi

- Full PHP suite: **253 tes / 24.914 assertions lulus**, termasuk hitungan setiap titik grafik/tautan/modal yang tetap scoped, login/otorisasi, password 8 karakter lowercase/uppercase/angka dan penolakan 7 karakter, edit kata sandi/hash, fitur aplikasi lainnya.
- JavaScript: **20 tes lulus**, termasuk toggle password independen tanpa mengubah nilai, aria state, serta SVGAnimatedString href untuk titik grafik.
- Build Vite, cache Blade, lint syntax 5 view Blade terkompilasi, Pint dan diff check lulus.
- Tidak ada perubahan data/migration/env atau reset kata sandi existing. Tidak melakukan commit/push.

Perintah aktual:

```sh
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
node --test tests/JavaScript/*.test.js
npm run build
php artisan view:cache
vendor/bin/pint --dirty
vendor/bin/pint --dirty --test
git diff --check
```

Pemeriksaan visual browser baru belum dilakukan karena akses browser lokal sebelumnya ditolak; verifikasi melalui feature tests, perilaku JavaScript dan build. Tidak ada klaim integrasi eksternal baru.
