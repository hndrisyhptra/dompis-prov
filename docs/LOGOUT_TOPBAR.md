# Logout di topbar — 23 Oktober 2026

Discovery: seluruh halaman login memakai satu layout Blade. Logout sudah berupa POST dengan CSRF, invalidate session dan regenerasi token. Perubahan hanya pada penempatan dan konfirmasi UI; tidak perlu migration atau env baru.

Checklist:
- [x] Hapus tombol Keluar di sidebar; Logout dengan ikon di posisi paling kanan topbar, Area maupun Branch.
- [x] Dialog Ya, Logout / Tidak; keyboard dan fallback tanpa JavaScript.
- [x] Gunakan endpoint/session logout yang ada, loading dan token CSRF.
- [x] Build, lint, pemeriksaan template dan auth regression; hasil lengkap dicatat di bawah.

Klik Logout membuka native HTML dialog. Fokus awal pada Tidak, fokus terperangkap secara native dalam dialog, Escape/Tidak menutupnya dan kembali ke pemicu. Ya, Logout melakukan POST ke endpoint yang sudah ada; tombol dinonaktifkan dan label loading tampil untuk mencegah pengiriman berulang. Pembatalan dinonaktifkan saat POST sudah dikirim. Konfirmasi memakai warna/token light dan dark, radius 6px serta ukuran yang menyesuaikan mobile. Tanggal topbar disembunyikan di layar kecil agar tombol mode dan Logout tetap nyaman di kanan.

Tautan pemicu mengarah ke GET `/logout/confirm`, sehingga browser tanpa JavaScript atau tanpa showModal tetap mendapatkan halaman konfirmasi yang tidak mengubah sesi. Memilih Tidak di halaman fallback menuju dashboard sesuai role; POST hanya dikirim lewat Ya, Logout. Endpoint POST lama tidak berubah. Halaman login tidak mempunyai tombol atau dialog Logout.

File diubah:
- `resources/views/layouts/app.blade.php`: pindah tombol ke topbar, modal konfirmasi dengan CSRF.
- `resources/views/components/icon.blade.php`: ikon log-out.
- Baru `resources/views/auth/logout-confirm.blade.php`: halaman konfirmasi fallback.
- `routes/web.php`: route GET konfirmasi dalam middleware auth/ActiveUser.
- `resources/js/app.js`: buka/batalkan dialog dan proteksi saat submit.
- `resources/css/app.css`: topbar/dialog responsif dan dukungan token light/dark.
- `tests/Feature/AccessAndSyncTest.php`: perluas tes sesi yang ada untuk memastikan membuka konfirmasi atau membatalkan melalui navigasi tidak mengakhiri sesi; POST tetap logout.
- Dokumen ini.

Perintah:
```sh
php vendor/bin/phpunit --filter='AccessAndSyncTest|UsernameAndThemeTest|NamedKeysBehaviorTest|MasterDataTest' --no-progress --colors=never
vendor/bin/pint tests/Feature/AccessAndSyncTest.php routes/web.php
php -d memory_limit=512M vendor/bin/phpunit --no-progress --colors=never
npm run build
node --check resources/js/app.js
php artisan view:cache
vendor/bin/pint --dirty --test
git diff --check
```

Tes awal terkait auth/layout: 22 tes / 729 assertion lulus sebelum perluasan assertion konfirmasi. Build, sintaks JS, Blade cache, Pint dan diff check lulus. Regresi lengkap: **184 tes / 4.747 assertion lulus** setelah perluasan pengujian sesi konfirmasi. Tidak ada migration/env/credential/role/data aplikasi yang diubah. Template sumber tetap. Tidak ada input bisnis tambahan yang diperlukan. Interaksi visual browser belum diverifikasi karena izin preview sebelumnya ditolak; tidak mencoba ulang akses tersebut, dan tidak mengklaim screenshot/browser QA.
