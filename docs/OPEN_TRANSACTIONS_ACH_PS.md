# Transaksi OPEN/STUCK dan tab Ach PS — 27 Oktober 2026

## Checklist

- [x] Inspect tab Area, klasifikasi mapping, jenis_pekerjaan, filter dan card PS harian.
- [x] Rekap OPEN/STUCK → Region → Branch menurut jenis transaksi pada tab Progress.
- [x] Tab Ach PS untuk card Perolehan PS harian dengan filter bulan yang sama.
- [x] Angka/modal/total persis, tidak membuang jenis lain, scope/filter dan query budget tetap.
- [x] Uji/build/dokumentasi, tanpa mutasi sumber atau schema.

Sesuai screenshot, OPEN dan STUCK adalah kategori mapping_order tersimpan. Jenis transaksi berasal dari jenis_pekerjaan (CRM Order Type BIMA), mengikuti normalisasi/filter yang sudah berlaku. Tidak mengubah definisi KPI PS. Implementasi untuk dashboard Area; dashboard Branch yang sudah ada dipertahankan.

## Rekap transaksi

Tab **Progress** mendapat tabel **Order OPEN & STUCK per Jenis Transaksi**, sesudah Mapping Order Region. Tabel mempunyai subtotal OPEN dan STUCK, subtotal Region yang dapat dibuka menjadi Branch, kolom New Install/Modify/Disconnect dan Grand Total. Region default tertutup dengan Buka/Tutup semua yang hanya memengaruhi tabel ini. Label dan angka mengikuti tata tabel dashboard white/dark, sticky, radius 6px dan tanpa gradient.

Klasifikasi berasal dari `work_orders.mapping_order` persis, bukan operational outstanding atau akhiran status BIMA. Jenis transaksi adalah `jenis_pekerjaan` yang berasal dari CRM Order Type BIMA; normalisasi UPPER/TRIM dan tanpa jenis sama dengan query daftar yang sudah ada. Jenis lain seperti Suspend, Resume atau nilai tambahan ditampilkan sebagai kolom tambahan agar total tidak hilang; NULL/kosong masuk Tanpa jenis. Jika filter jenis pekerjaan dipilih, hanya kolom jenis terpilih yang tampil, agar klik angka nol tidak memperluas scope filter.

Region dan Branch berasal dari relasi master assignment, termasuk data arsip yang masih direferensikan. Tanpa Region dan belum ditugaskan tetap mempunyai baris serta filter khusus. Semua angka, termasuk nol/subtotal/Grand Total, membuka modal daftar WO scoped beserta Detail; fallback tautan daftar penuh tetap tersedia. Grand Total gabungan memakai filter tervalidasi `mapping_order=open_stuck`, diterapkan sebagai union OPEN/STUCK pada query yang sama. Filter tetap berada di navigasi/antrean/form/pagination/ekspor Work Order, dengan label manusia OPEN & STUCK.

Rekap ditambahkan pada agregasi Mapping Order yang sudah ada dengan dimensi jenis pekerjaan dalam query turunan. Lookup Branch/Region dipakai ulang; **tidak menambah jumlah query dashboard**. Anggaran tetap Area 10/Branch 11 dan diuji dengan pertambahan volume. SQL kompatibel `ONLY_FULL_GROUP_BY` tanpa mengubah SQL mode.

## Tab Ach PS

Navigasi Area menjadi **Progress / Owner Group / Ach PS**. Card **Perolehan PS harian** dipindahkan ke Ach PS, termasuk grafik, jumlah bulanan, pesan kosong dan accordion data harian yang dapat membuka modal. Progress mempertahankan matrix, Mapping Order, rekap transaksi, grafik cohort bulan order dan rekap jenis pekerjaan. Owner Group tetap mempunyai distribusi Owner Group.

Input **Bulan grafik PS** hanya terlihat pada Ach PS; bulan disimpan sebagai field tersembunyi pada dua tab lain dan dibawa bersama filter Region/Branch/layanan/jenis pekerjaan saat berpindah tab. Reset memakai tab aktif. KPI/filter bersama tetap di atas tab. Perolehan harian masih memakai progress CLOSE + 14 status PsEligibility dan TGL PS, WIB, tanpa tanggal masa depan; kategori mapping PS saja tidak cukup. Tidak ada perubahan hitungan atau tanggal sumber. Bulan tidak membatasi rekap OPEN/STUCK yang akumulatif.

## Verifikasi

- Suite PHP penuh versi final: **336 tes / 28.181 assertions lulus**.

- MySQL/MariaDB sementara: **41 tes / 4.183 assertions lulus**; database terpisah dihapus setelah selesai.
- Fokus awal setelah perbaikan rendering Blade: **29 tes / 3.349 assertions lulus**; penambahan konteks ekspor gabungan juga dicakup tes MySQL dan suite final.
- **28 tes JavaScript lulus**, build frontend, kompilasi Blade, Pint dan whitespace lulus. Build selesai sebelum suite fitur.
- Tes baru mencakup normalisasi jenis/null/jenis tambahan, hierarki dan rekonsiliasi total, pengecualian mapping PS/CANCLWORK/unknown, semua tautan nol/subtotal/Grand Total vs modal/list, filter Region/Branch/layanan/jenis, bulan yang tidak memotong transaksi, scope Branch saat membuka union, persistensi tab/bulan, periode PS, tanggal masa depan dan empty state.

Tidak ada migration, env, dependency atau kredensial baru. Tidak ada data/progress/sumber/prioritas/riwayat yang diubah, tidak ada backfill atau perubahan dashboard Branch. Tidak menambah download pada dashboard. Tidak ada input bisnis yang masih diperlukan. Preview HTTP/browser tidak dicoba ulang karena permintaan izinnya sebelumnya ditolak; verifikasi memakai rendering Blade/DOM dan pengujian otomatis. Tidak mengklaim integrasi eksternal terhubung.

## Berkas diubah

- `app/Services/MappingOrderAnalyticsService.php`
- `app/Services/AreaAnalyticsService.php`
- `app/Services/Exports/OrderExportService.php`
- `app/Http/Requests/DashboardRequest.php`
- `app/Http/Requests/WorkOrderListRequest.php`
- `resources/views/area/dashboard.blade.php`
- `resources/views/area/open-transactions.blade.php` (baru)
- `resources/views/area/ps-achievement.blade.php` (baru, card dipindah)
- `resources/views/work-orders/index.blade.php`
- `resources/css/app.css`
- `tests/Feature/AreaOpenTransactionsTest.php` (baru)
- `tests/Feature/AreaDashboardTest.php`
- `tests/Feature/OrderExportTest.php`
- `docs/OPEN_TRANSACTIONS_ACH_PS.md` (baru), `docs/DASHBOARD_MAPPING_ORDER.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Perubahan workspace sebelumnya dipertahankan.

## PS harian per Branch — 27 Oktober 2026

Accordion data PS harian diubah menjadi tabel Branch × tanggal tersendiri pada Ach PS. Default maksimal 15 hari kalender; Detail bulan menampilkan semua tanggal dengan total yang sesuai periode. Implementasi dan hasil lanjutan: [PS_DAILY_BRANCH_MATRIX.md](PS_DAILY_BRANCH_MATRIX.md).

## Judul dan tampilan ringkas — 27 Oktober 2026

Judul terbaru adalah **OPEN & STUCK per Jenis Pekerjaan**. Tabel dipadatkan tanpa scroll internal saat seluruh Region dibuka; halaman mengikuti jumlah baris. Tab Progress juga menambah **Mapping Aging Order** untuk OPEN per Region/Branch, umur langsung dari TGL ORDER hingga tanggal acuan WIB. Detail implementasi, manifest berkas dan validasi terbaru: [OPEN_JOB_TYPES_AGING.md](OPEN_JOB_TYPES_AGING.md).
