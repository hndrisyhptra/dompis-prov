# Tabel utuh dan tab dashboard Area — 27 Oktober 2026

## Checklist

- [x] Inspect Blade, CSS, filter, collapse, query dan tes dashboard/monitoring.
- [x] Padatkan tabel Monitoring Order Prioritas tanpa scroll internal; pertahankan semua teks/angka dan tautan.
- [x] Pisahkan tab Progress dan Owner Group pada dashboard Area, dengan filter yang tetap dibawa saat berpindah tab.
- [x] Uji tab, filter/role, tautan angka, collapse, build dan dokumentasikan hasil.

## Keputusan

Tiga tabel monitoring prioritas memakai lebar mengikuti panel, teks membungkus dan tinggi alami agar setiap tabel dapat diambil utuh dengan screenshot halaman. Tidak memangkas isi, menghilangkan baris, atau memaksa seluruh data ke satu layar dengan font yang tidak terbaca. Rekap tetap seluruh hasil filter; rincian Branch tetap 25 order per halaman dengan indikator rentang/jumlah agar scope screenshot jelas.

Tab dashboard Area berupa navigasi GET yang dapat digunakan tanpa JavaScript. Progress menjadi default; Distribusi Owner Group hanya dirender pada tab Owner Group. KPI/filter cakupan tetap tersedia di atas tab. Pilihan tab dan filter dipertahankan ketika berpindah atau menerapkan filter. Klik angka tetap memakai modal/detail Work Order dan scope akun yang sama.

Tidak ada migration, env, dependency atau perubahan data order/prioritas pada pekerjaan ini.

## Implementasi dan verifikasi

- Monitoring prioritas: tiga tabel memakai layout fixed mengikuti lebar panel, font 11px dan padding 4×6px; seluruh label/ID dapat membungkus. Tidak ada batas max-height atau scroll internal, dan header/total tidak sticky. Rincian mulai terbuka melalui `data-region-open`; kontrol Buka semua/Tutup semua mengikuti status aktual sejak awal. Dashboard Area/Branch tetap memulai Region dalam kondisi tertutup seperti sebelumnya.
- Progress merupakan tab default (`?tab=progress`), berisi perhatian, matriks progress, grafik bulan order, rekap jenis pekerjaan dan PS harian. Distribusi Owner Group hanya dirender pada `?tab=owner-group`. KPI dan filter cakupan digunakan bersama. Bulan grafik dipertahankan sebagai hidden input pada Owner Group agar kembali ke Progress tetap memakai bulan pilihan; Reset mempertahankan tab sambil menghapus filter cakupan. Tab divalidasi allowlist, mendukung tautan langsung, keyboard, Back browser dan penggunaan tanpa JavaScript.
- Tidak menambahkan query per baris, menulis data ketika GET, mengubah RBAC, definisi angka, PS, pelepasan prioritas atau kolom unduhan.
- Regression terarah: **26 tes PHP / 2.101 assertions lulus**, termasuk empat role Area, isolasi Branch, pilihan tab, validasi tab, filter/URL/form, pemisahan konten, tautan angka sesuai hitungan dan batas query. **28 tes JavaScript lulus**, termasuk default-open screenshot dan sinkronisasi kontrol expand tanpa mengubah collapse dashboard lain.
- Suite PHP penuh: **304 tes / 26.530 assertions lulus**.
- Build frontend, kompilasi Blade, Pint dan diff whitespace lulus. Tidak mengulang pengujian MariaDB karena tidak mengubah SQL/agregasi/schema; query budget dan hitungan diuji melalui feature test yang sama.

Pemeriksaan visual browser manual belum dilakukan karena izin preview sebelumnya ditolak. Ukuran tinggi tabel tetap bergantung jumlah baris; screenshot halaman penuh dapat digunakan untuk tabel yang lebih tinggi daripada layar. Tidak menyembunyikan data demi memaksakan muat satu viewport. Tidak ada masalah bisnis/credential baru yang membutuhkan input; tidak ada koneksi Google/Telegram yang diuji pada perubahan UI ini.

## File diubah

- `app/Http/Controllers/AreaDashboardController.php`
- `app/Http/Requests/DashboardRequest.php`
- `resources/views/area/dashboard.blade.php`
- `resources/views/priorities/monitoring.blade.php`
- `resources/css/app.css`
- `resources/js/dashboard-tables.js`
- `tests/Feature/AreaDashboardTest.php`
- `tests/Feature/OwnerGroupAnalyticsTest.php`
- `tests/Feature/DashboardDrilldownTest.php`
- `tests/Feature/PriorityMonitoringTest.php`
- `tests/JavaScript/dashboard-tables.test.js`
- `docs/DASHBOARD_TABS_CAPTURE.md` (baru), `docs/PRIORITY_MONITORING.md`, `docs/PRIORITY_PS_RELEASE.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Perubahan workspace dan login dari pekerjaan sebelumnya dipertahankan.
