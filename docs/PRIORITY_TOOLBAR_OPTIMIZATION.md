# Toolbar dan optimasi List Order Prioritas — 28 Oktober 2026

## Checklist implementasi

- [x] Tempatkan Reset Bookmark di samping Download Excel, menggunakan toolbar responsif yang sudah ada; pertahankan penjelasan cakupan dan modal konfirmasi.
- [x] Gunakan ulang query scoped dan hasil hitungan ringkasan untuk pagination; jangan mengubah filter, sorting, scope atau isi export.
- [x] Baca snapshot reset sebagai enam atribut mentah tanpa hidrasi model, dengan lock/digest/transaksi/audit tetap berlaku.
- [x] Verifikasi pagination/filter/status, posisi tombol pada DOM, privasi dan reset/retry; ukur query pada fixture kecil/besar SQLite dan MariaDB terisolasi.
- [x] Build/lint dan dokumentasikan hasil aktual, berkas, migration/env serta batas verifikasi.

Tidak ada reset bookmark aktual, migration, dependency atau env baru pada perubahan ini.

## Hasil implementasi

Pada **List Order Prioritas**, toolbar tabel berurutan **Download Excel → Reset Bookmark** dengan tombol padat, ikon dan tooltip. Toolbar memakai flex-wrap/responsive dan token warna white/dark yang sudah ada. Jumlah bookmark yang dapat direset serta penjelasan bahwa reset mencakup semua halaman dan tidak mengikuti filter berada di bawah judul tabel, ditautkan ke tombol melalui aria-describedby. Modal Ya/Tidak, kondisi nonaktif saat kosong dan status loading tetap memakai mekanisme sebelumnya. Download tetap mengikuti filter; reset mengikuti scope actor yang dijelaskan dalam modal.

Controller membentuk base query berotorisasi sekali, lalu menggunakannya untuk agregasi dan daftar. PriorityOrderQuery::orderedFrom melakukan clone sebelum memasang eager load/sorting sehingga base tidak dimutasi. Export tetap memakai ordered dengan jalur filter/otorisasi yang sama seperti sebelumnya. Seluruh state monitoring yang diminta termasuk closed dihitung dalam satu agregasi; pagination memakai total state tersebut dan tidak menjalankan COUNT tambahan. Ringkasan tetap memiliki empat key sebelumnya, tidak ikut dipersempit state daftar.

Preview dan commit reset membaca enam kolom identitas/key/token sebagai baris database mentah; tidak menghidrasi model bookmark atau memuat payload sumber WO. Bentuk/urutan atribut digest sama seperti sebelumnya. Pemilihan scope, snapshot terenkripsi, stale check, lock actor→WO→bookmark, delete berdasarkan ID terkunci, audit batch dan replay idempotent dipertahankan. Tidak ada cache hasil lintas akun dan tidak mengendurkan pemeriksaan otorisasi.

## Pengujian aktual

| Pemeriksaan | Hasil |
| --- | --- |
| SQLite: prioritas, monitoring, bulk, PS release, reset, export dan performance budget | 63 tes / 2.383 assertions lulus |
| MariaDB terisolasi: kelompok tes yang sama | 63 tes / 2.383 assertions lulus; database sementara dihapus |
| JavaScript | 29 tes lulus |
| Vite build, Pint dan git diff --check | Lulus |
| List Prioritas pada fixture 5 dan 30 WO | 8 → **7 query**, sama pada SQLite/MariaDB |
| Work Order pada fixture 5 dan 30 WO | Tetap 7 query |
| Prioritas dengan filter Branch, seluruh state dan halaman kedua | Maksimal 8 query, total dan isi tetap sesuai scope; Area tidak menghitung pilihan lokal Branch |

DOM test memastikan tombol reset satu kali dan berada di toolbar yang sama setelah Download Excel; cakupan reset, kondisi kosong, konfirmasi wajib, stale token, actor/role, assignment berubah, replay dan rollback audit tetap diuji. Tes pagination memakai data Area/lokal/Branch asing, semua state, pencarian, tiga mode sorting dan hasil kosong. Pengukuran sintetis ini membuktikan query tambahan dihapus dan tidak tumbuh per baris, bukan SLA atau uji beban server.

```sh
php -d memory_limit=512M vendor/bin/phpunit --filter 'Priority|PerformanceBudgetTest|OrderExportTest' --no-progress --colors=never
php /private/tmp/olo-priority-toolbar-mysql.php
node --test tests/JavaScript/*.test.js
npm run build
vendor/bin/pint --dirty --test
git diff --check
```

Helper MariaDB bersifat lokal, membuat database acak untuk tes dan menghapusnya setelah selesai; bukan kebutuhan deployment. Suite penuh tidak diulang untuk perubahan ini. Satu kegagalan ekspektasi branding login Wibsy yang sudah didokumentasikan pada tahap sebelumnya tidak diubah. Verifikasi visual browser belum dilakukan karena izin akses browser/HTTP sebelumnya ditolak; DOM dan build terverifikasi. Tidak ada input bisnis tambahan yang diperlukan atau integrasi eksternal yang diklaim terhubung.

## Berkas diubah pada tahap ini

- `resources/views/priorities/index.blade.php`: toolbar dan keterangan cakupan reset.
- `resources/views/priorities/reset.blade.php`: form mandiri, ukuran compact dan deskripsi aksesibel.
- `app/Http/Controllers/PriorityOrderController.php`: base query dan total pagination digunakan ulang.
- `app/Services/PriorityOrderQuery.php`: ordering pada query scoped yang sudah terbentuk.
- `app/Services/PriorityResetService.php`: snapshot/commit tanpa hidrasi model bookmark.
- `tests/Feature/PriorityResetTest.php`: posisi tombol dan disabled state spesifik.
- `tests/Feature/PerformanceBudgetTest.php`: budget tujuh query, pagination/filter/status serta privasi Area/Branch.
- Dokumen ini dan addendum `docs/BOQ_RENAME_PRIORITY_RESET.md`, `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`.

Perubahan BOQ, branding dan progress branch pengguna sebelumnya dipertahankan. Tidak melakukan commit, push, deployment atau reset bookmark pada data aktual.
