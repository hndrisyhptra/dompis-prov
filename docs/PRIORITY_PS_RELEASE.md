# Prioritas otomatis selesai ketika PS — 27 Oktober 2026

Pembaruan UI berikutnya mengganti tinggi scroll 320/280px dengan tabel utuh tanpa scroll internal; aturan pelepasan PS tetap sama. Lihat [DASHBOARD_TABS_CAPTURE.md](DASHBOARD_TABS_CAPTURE.md) untuk tampilan screenshot dan tab dashboard Area.

## Checklist

- [x] Inspect alur bookmark, scope Area/Branch, definisi PS, import dan progress.
- [x] Implementasikan pelepasan bookmark dengan audit, lock dan perlindungan pemilihan ulang PS.
- [x] Samakan list, monitoring, jumlah dan ekspor; pertahankan CLOSE lapangan yang belum PS.
- [x] Susun dua tabel rekap vertikal dengan ukuran ringkas dan light/dark responsif.
- [x] Uji transisi import/Branch, data lama, idempotensi, akses dan query; build.
- [x] Terapkan pembersihan bookmark PS lama pada database aplikasi dan catat hasil.

## Perilaku yang dituju

PS mengikuti definisi bersama `PsEligibility`: progress CLOSE dan salah satu dari 14 status BIMA PS yang telah disetujui. Tidak membutuhkan tanggal PS untuk melepas prioritas. COMPLETE pada task lain maupun CLOSE lapangan dengan BIMA belum PS tetap dapat diprioritaskan.

Pelepasan berlaku pada seluruh bookmark Area dan lokal Branch untuk order yang telah PS, termasuk bookmark Branch lama sebelum transfer. Work order, riwayat progress, kendala, perangkat, dan sumber BIMA tetap tersimpan. Setiap bookmark dilepas dengan audit sistem, tanpa memperlihatkan pilihan privat Branch ke Area. Jika order kemudian dikoreksi menjadi belum PS, bookmark tidak muncul kembali otomatis; pengguna dapat memilih ulang.

Tidak ada migration/env/dependency baru.

## Implementasi

`PriorityOrderService::releaseForPs` memeriksa ulang status aktual di bawah lock work order, mengunci bookmark menurut ID, mencatat `priority.auto_released_ps` per bookmark, lalu menghapusnya dalam transaksi yang sama. Alur update progress Branch dan auto CLOSE BIMA memanggilnya setelah perubahan tersimpan dalam transaksi induk. Import Excel dan Sheets, termasuk sumber identik dengan progress sudah CLOSE, memakai alur auto CLOSE bersama. Preview import belum melepas prioritas. Retry tidak menggandakan audit. Pemilihan bookmark menolak PS berdasarkan record terkunci; tombol bookmark disembunyikan untuk PS.

`WorkOrder::notPsEligible` menjadi komplemen kriteria PS pada shared PriorityOrderQuery. Data lama yang belum dibersihkan juga langsung dikecualikan dari list, total, monitoring dan ekspor tanpa write pada GET. Predikat menjaga null/empty dan perbandingan binary pada MySQL, sehingga progress mirip seperti CLOSÉ tidak dianggap CLOSE.

Perintah rekonsiliasi membutuhkan username operator Area aktif dengan izin sync dan lock inbound. Tidak menerima scope/actor dari form pengguna:

```sh
php artisan olo:release-ps-priorities --actor=superadmin --dry-run
php artisan olo:release-ps-priorities --actor=superadmin
```

Status BIMA × Owner Group dan Rincian Task per Owner Group disusun **satu kolom pada semua ukuran layar**. Heading dibuat satu baris fleksibel, font tabel 12px, padding 7×10px, batas scroll 320px desktop/280px mobile. Header/identitas/total sticky, klik angka, collapse per kelompok dan Buka semua/Tutup semua tetap tersedia. Memakai token light/dark, radius 6px tanpa gradient. Ini menggantikan layout sejajar pada 26 Oktober.

## Hasil aktual dan pengujian

- Dry run database aplikasi: **3 order PS / 3 bookmark**. Eksekusi dengan actor superadmin: ketiganya dilepas dan diaudit. Pemeriksaan ulang: **0 order PS / 0 bookmark** tersisa untuk dilepas. Tidak mengubah data work order atau riwayat progres/sumber/kendala.
- Listener antrean Dompis Prov ditemukan tidak aktif; listener lokal dinyalakan kembali memakai `queue:listen --queue=default --tries=5 --timeout=180 --sleep=1` dan diverifikasi aktif. Setiap job memuat kode terbaru. Proses proyek lain tidak diubah.
- PHP penuh: **303 tes / 26.815 assertions lulus**.
- MariaDB terpisah: **51 tes / 2.208 assertions lulus**. Database sementara dibuang setelah pengujian; database aplikasi tidak dimigrasi.
- **27 tes JavaScript lulus**; build frontend, Pint, kompilasi Blade, dan diff whitespace lulus.
- Cakupan regresi: seluruh 14 status PS, CLOSE lapangan belum PS, COMPLETE task non-PS, legacy COMPWORK, null/empty dan collation MySQL, dua scope bookmark, larangan memilih ulang dari form lama, koreksi Area tanpa kebangkitan bookmark, rollback transaksi, recheck record aktual, import Excel/Sheets preview vs commit dan unchanged retry, audit idempotent, guard perintah, scope/list/tautan angka/ekspor dan batas query.

Verifikasi tampilan melalui rendering Blade/DOM dan regression kontrol JavaScript. Preview visual browser manual tidak dijalankan karena izin preview sebelumnya ditolak. Tidak ada input bisnis tambahan yang menghalangi pemakaian. Listener lokal perlu dinyalakan kembali setelah komputer dihentikan; production tetap membutuhkan pengelola proses. Tidak ada kredensial atau koneksi Google/Telegram baru.

## File diubah pada pekerjaan ini

- `app/Console/Commands/ReleasePsPriorities.php` (baru)
- `app/Models/WorkOrder.php`
- `app/Services/PriorityOrderService.php`
- `app/Services/PriorityOrderQuery.php`
- `app/Services/BranchProgressService.php`
- `app/Services/BimaAutoCloseService.php`
- `resources/views/priorities/bookmark.blade.php`
- `resources/views/priorities/index.blade.php`
- `resources/views/priorities/monitoring.blade.php`
- `resources/css/app.css`
- `tests/Feature/PriorityPsReleaseTest.php` (baru)
- `tests/Feature/BimaPsDateTest.php`
- `tests/Feature/OrderExportTest.php`
- `docs/PRIORITY_PS_RELEASE.md` (baru), `docs/PRIORITY_MONITORING.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Perubahan workspace dari pekerjaan sebelumnya, termasuk login pengguna, tetap dipertahankan.
