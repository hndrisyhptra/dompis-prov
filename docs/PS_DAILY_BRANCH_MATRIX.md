# Tabel PS harian per Branch — 27 Oktober 2026

## Checklist

- [x] Inspect agregasi PS, card Ach PS, filter/list/modal dan scope Branch.
- [x] Pisahkan tabel Branch × tanggal dari accordion grafik, default maksimal 15 hari kalender.
- [x] Detail bulan menampilkan semua tanggal bulan terpilih, filter tetap.
- [x] Total dan drilldown mengikuti tanggal yang tampil; PS masa depan tidak dihitung.
- [x] Uji periode, filter, privasi, total, ekspor, query budget; build/dokumentasi.

Pilihan periode: bulan berjalan memakai maksimal 15 hari sampai hari ini WIB; bulan historis memakai 15 hari terakhir bulan itu. Bulan yang belum berjalan menampilkan 15 tanggal awal dengan penanda belum berjalan. Detail bulan menampilkan seluruh tanggal, termasuk hari tanpa PS; tanggal masa depan tidak dianggap perolehan. Grand Total menjumlahkan tanggal yang ditampilkan, bukan menyembunyikan total bulan di bawah tabel 15 hari.

## Tampilan dan periode

Tabel **PS harian per Branch** menjadi panel tersendiri di tab Ach PS, setelah card grafik. Accordion lama Lihat data PS harian dihapus. Kolom identitas Branch diikuti tanggal kalender dan Grand Total. Tombol **Detail bulan** membuka mode seluruh tanggal melalui GET `ps_view=month`; tombol **Ringkas 15 hari** kembali ke `recent`. Pilihan bulan/Region/Branch/layanan/jenis pekerjaan tetap dibawa, termasuk saat pindah tab atau menerapkan filter. Tautan mengarah ke judul tabel agar detail mudah ditemukan.

Ringkasan memakai maksimal 15 tanggal sampai hari ini WIB dalam bulan berjalan, 15 tanggal terakhir untuk bulan historis, atau 15 tanggal awal bertanda belum berjalan bila bulan masih di masa depan. Awal bulan yang baru berjalan kurang dari 15 hari menampilkan hari yang sudah berjalan saja. Detail menampilkan 28–31 tanggal kalender sesuai bulan, termasuk tahun kabisat. Hari tanpa PS bernilai nol; tanggal masa depan bertanda —, tanpa tautan perolehan dan tidak dihitung.

Grand Total baris/kolom hanya menjumlahkan tanggal yang tampil. Total seluruh bulan ditulis terpisah dalam keterangan dan tetap cocok dengan grafik. Branch yang memiliki PS pada bulan tersebut tampil, termasuk jika perolehannya berada di luar jendela 15 hari (total ringkas baris dapat nol). Branch diurutkan alfabetis, assignment kosong tampil Belum ditugaskan dan nama master arsip tetap terbaca. Assignment yang digunakan adalah assignment WO terkini, konsisten dengan dashboard yang ada.

UI mengikuti white/dark, panel rounded-md, tabel kompak, angka rata kanan, header/Branch sticky dan scroll horizontal yang aksesibel pada layar sempit/mode seluruh bulan. Tidak menambah download pada dashboard.

## Query, filter dan integritas

Agregasi PS bulanan yang sudah ada diperluas menjadi `(id_branches, DATE(tgl_ps))`. Seri grafik dijumlahkan lintas Branch, bukan ditimpa oleh Branch terakhir pada tanggal sama. `DailyPsTableService` memetakan hasil agregasi itu ke kalender tanpa query tambahan, tanpa memuat seluruh WO, tanpa N+1; master Branch yang sudah dimuat dipakai ulang. Tabel hanya dibentuk pada tab Ach PS; query budget service Ach PS tetap maksimal 9 dan sama pada 5/30 order.

Tetap memakai scope/filter Area yang sama dan `psEligible` (progress CLOSE + salah satu 14 status BIMA), TGL PS dan batas hari ini WIB. Tidak memakai mapping_order PS sebagai pengganti, tidak mengarang tanggal, tidak mengubah sumber/progress atau melakukan backfill.

Seluruh angka nyata/nol/subtotal membuka modal daftar scoped dengan Detail Order; tautan daftar penuh tetap tersedia. Sel tanggal memakai `ps_date` yang sudah ada; total periode memakai pasangan baru `ps_from`/`ps_to`, tervalidasi bersama, urutan tanggal benar dan tidak boleh dicampur dengan ps_date. Query periode menerapkan PS eligibility dan membatasi akhir hingga hari ini WIB, sehingga total masa depan tetap nol. Query tanggal tunggal yang sudah ada tidak diubah, termasuk untuk pemeriksaan tanggal masa depan melalui flow lama.

Filter periode dipertahankan pada antrean, pencarian, pagination, modal dan ekspor Work Order; konteks ekspor menjelaskan tanggal dan batas hari ini. Tidak memberikan izin Area pada role Branch. Jika role Branch memakai tautan daftar periode, visibleTo/assignment aktif tetap menjadi pembatas backend.

## Hasil pengujian

- **341 tes PHP / 29.332 assertions lulus** pada suite penuh versi final.
- **46 tes MySQL/MariaDB / 5.334 assertions lulus**, database sementara terpisah dihapus setelah pengujian.
- Fokus awal: **29 tes / 3.975 assertions lulus**.
- **28 tes JavaScript lulus**, mencakup modal/loading/error/retry/pagination, fallback tautan dan konfirmasi aplikasi.
- Build frontend, kompilasi Blade, Pint dan pemeriksaan whitespace lulus. Build selesai sebelum suite fitur.
- Tes baru mencakup 15 hari kalender, awal bulan, bulan lampau/masa depan, Februari kabisat, beberapa Branch pada tanggal sama, arsip/unassigned, tanggal kosong, progress/status belum memenuhi PS, perbandingan total bulan vs jendela, semua angka vs daftar/modal, filter Region/layanan/jenis/Branch, detail/ringkas, validasi periode, privasi Branch, persistensi antrean, ekspor scoped dan query budget.

Tidak ada migration, env, dependency, kredensial, perubahan data aplikasi atau masalah yang membutuhkan input bisnis. Template/sumber asli dan integrasi eksternal tidak disentuh. Preview HTTP/browser tidak dicoba ulang karena permintaan izinnya sebelumnya ditolak; hasil diverifikasi melalui rendering Blade/DOM dan pengujian otomatis.

## Berkas diubah

- `app/Services/DailyPsTableService.php` (baru)
- `app/Services/AreaAnalyticsService.php`
- `app/Services/WorkOrderListQuery.php`
- `app/Services/Exports/OrderExportService.php`
- `app/Http/Requests/DashboardRequest.php`
- `app/Http/Requests/WorkOrderListRequest.php`
- `resources/views/area/ps-daily-table.blade.php` (baru)
- `resources/views/area/ps-achievement.blade.php`
- `resources/views/area/dashboard.blade.php`
- `resources/views/work-orders/index.blade.php`
- `resources/css/app.css`
- `tests/Feature/AreaDailyPsTableTest.php` (baru)
- `tests/Feature/OrderExportTest.php`
- `docs/PS_DAILY_BRANCH_MATRIX.md` (baru), `docs/OPEN_TRANSACTIONS_ACH_PS.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Perubahan workspace sebelumnya tetap dipertahankan.
