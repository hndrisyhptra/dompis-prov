# Bookmark massal dan kelompok Branch — 27 Oktober 2026

## Checklist

- [x] Inspect scope prioritas, lock, audit, konfirmasi form dan komponen collapsible.
- [x] Kelompokkan Order Kendala per Branch, default tertutup, kontrol Buka/Tutup semua.
- [x] Input maksimal 50 No WO, preview terotorisasi, status per nomor dan konfirmasi aman.
- [x] Simpan idempotent dengan validasi ulang scope/PS/assignment, transaksi dan audit.
- [x] Uji batas input, privasi, token, perubahan setelah preview, retry, collapsible dan build.

Alur: List Order Prioritas → Bookmark massal → tempel satu No WO per baris (juga menerima tab/koma/titik koma) → Periksa daftar → konfirmasi bookmark yang siap. Tidak mencocokkan SID, substring atau menebak nomor. Preview bukan penyimpanan. Maksimal 50 entri sebelum deduplikasi; duplikat ditandai dan hanya diproses sekali. Nomor di luar akses dan tidak ditemukan memperoleh pesan yang sama. Pilihan Area mengikuti scope bersama Area; Branch hanya menambah pilihan lokal Branch. PS tidak ditambahkan.

## Pengalaman pengguna

- Tombol **Bookmark massal** tersedia pada List Order Prioritas dan Monitoring Order Prioritas.
- Tempel nomor dari kolom Excel tanpa header. Pemisah newline, whitespace/tab, koma dan titik koma diterima; nol di depan dan huruf besar/kecil dipertahankan. Batas 50 diterapkan sebelum deduplikasi, bukan 50 nomor setelah membuang duplikat.
- Preview memperlihatkan No WO, Branch yang diizinkan dan hasil: Siap, Sudah dipilih (scope akun saat ini), Sudah PS, Duplikat input, Tidak ditemukan dalam akses Anda, atau Nomor ambigu. SID dan substring tidak dipakai sebagai alternatif. Jika satu No WO ada pada beberapa sumber yang terlihat, operator harus memeriksanya terlebih dahulu.
- Ringkasan menampilkan jumlah siap/dilewati. Preview tidak menulis bookmark; tombol Periksa daftar tidak meminta konfirmasi simpan. Tombol Simpan membuka modal verifikasi Ya/Tidak yang sudah dipakai aplikasi.
- Form mempertahankan input setelah error. Preview/loading, error validasi, kosong/tidak ada nomor siap dan hasil sukses tersedia. Tampilan dua kolom desktop menjadi satu kolom pada layar sempit, memakai token white/dark yang ada.
- Order Kendala dikelompokkan per Branch pada halaman aktif; kelompok tertutup setelah JavaScript diinisialisasi, dapat dibuka satu per satu atau Buka semua/Tutup semua. Tanpa JS, isi tetap terbaca. Pagination 25 order tetap; jumlah per Branch diberi label **di halaman ini**, Grand Total menghitung seluruh hasil filter. Ini menggantikan default terbuka khusus tabel Order Kendala; tabel monitoring lainnya tetap mengikuti pengaturan sebelumnya.

## Integritas dan akses

`PriorityBulkRequest` dan middleware memakai izin prioritas yang sudah ada: Superadmin, Admin/HD Area, TL serta Admin/HD Branch aktif. Koordinator Area/Admin Material/Bot tidak mendapat izin baru. Branch tidak dapat mencari order di luar assignment aktif; nomor tidak ditemukan dan di luar akses memiliki hasil yang sama. Area tidak memuat bookmark lokal Branch dalam preview/pengecekan bookmark existing.

Preview menghasilkan token terenkripsi terautentikasi berumur 15 menit yang terikat actor, role, UUID batch, ID order siap dan Branch saat preview. Commit memakai token tersebut; input tampilan tidak menentukan ID/scope. Scope ditentukan server: Area bersama atau lokal Branch assignment order.

Saat commit, lock actor dan order berurutan berdasarkan PK, periksa lagi role/akses/assignment/PS. Bila salah satu order siap berubah Branch, menjadi PS, hilang atau kehilangan akses, seluruh batch ditolak tanpa perubahan dan operator diminta memeriksa ulang. Perubahan progress lain tidak ditimpa. Bookmark yang telah ditambahkan rekan sesudah preview dilewati secara idempotent.

Insert bookmark dan audit per bookmark dibatch di dalam transaksi yang sama, ditambah audit `priority.bulk_selected` untuk hasil dan UUID batch. Audit batch di bawah lock actor membuat retry token sukses tidak membuat bookmark/audit ganda, termasuk apabila bookmark kemudian dilepas manual. UUID token bookmark tetap memakai kontrak lama. Bila penyimpanan audit gagal, seluruh batch rollback. Tidak mengubah WO/progress, source/hash, kendala, perangkat, log BIMA maupun riwayat.

Preview membaca kolom yang diperlukan dan lookup Branch secara batch; 50 nomor diuji maksimal 3 query pada service preview dan 14 query pada service commit, termasuk transaksi. Tidak melakukan policy/query per baris atau memanggil Google/Telegram. POST dibatasi 30 permintaan/menit per akun dan memakai CSRF; hasil preview diberi `Cache-Control: no-store`.

## Hasil pengujian

- **322 tes PHP / 26.913 assertions lulus** pada seluruh suite.
- **57 tes MySQL/MariaDB / 2.152 assertions lulus**, database sementara terpisah dibuat dan dihapus setelah selesai.
- Fokus prioritas: **34 tes / 956 assertions lulus**, termasuk batas 50, exact case/nol depan, input duplikat, ambigu lintas sumber, privasi Area/Branch, PS, token rusak/kedaluwarsa/akun-role berubah, assignment dan akses berubah sesudah preview, replay setelah pelepasan bookmark, rollback audit, query budget dan grouping Branch.
- **28 tes JavaScript lulus**, termasuk isolasi kontrol collapsible dan konfirmasi Ya/Tidak aplikasi.
- Build, kompilasi Blade, Pint dan pemeriksaan whitespace lulus. Build selesai sebelum suite fitur.

Tidak ada migration, env, dependency atau kredensial baru. Tidak ada data aplikasi aktual yang diubah oleh implementasi/pengujian ini. Tidak ada input bisnis yang menghalangi penggunaan. Verifikasi melalui rendering Blade serta pengujian otomatis; preview HTTP/browser tidak dicoba ulang karena permintaan izinnya sebelumnya ditolak. Fitur memakai refresh halaman/form biasa, bukan klaim realtime.

## Berkas diubah

- `app/Http/Controllers/PriorityBulkController.php` (baru)
- `app/Http/Requests/PriorityBulkRequest.php` (baru)
- `app/Services/PriorityBulkBookmarkService.php` (baru)
- `routes/web.php`
- `resources/views/priorities/bulk.blade.php` (baru)
- `resources/views/priorities/index.blade.php`
- `resources/views/priorities/monitoring.blade.php`
- `resources/views/priorities/monitoring-issues.blade.php`
- `resources/css/app.css`
- `tests/Feature/PriorityBulkBookmarkTest.php` (baru)
- `tests/Feature/PriorityMonitoringTabsTest.php`
- `docs/PRIORITY_BULK_BOOKMARK.md` (baru), `docs/PRIORITY_MONITORING_TABS.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Perubahan workspace sebelumnya tetap dipertahankan.
