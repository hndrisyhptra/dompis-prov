# Tab Monitoring Order Prioritas — 27 Oktober 2026

Koreksi pemilik berikutnya: kolom Kendala menjadi **Update Progress/Kendala** dan menampilkan catatan terbaru antara progress manual dengan kendala aktif. Ini menggantikan pengecualian catatan progress biasa pada bagian di bawah. Detail berkas, logika dan pengujian terbaru: [PRIORITY_PROGRESS_ISSUE_UPDATES.md](PRIORITY_PROGRESS_ISSUE_UPDATES.md).

## Checklist

- [x] Inspect query prioritas, sumber kendala/catatan, scope, pagination dan UI.
- [x] Tambahkan tab Order Branch, Owner Group dan Order Kendala dengan filter bersama.
- [x] Sajikan satu baris per order kendala aktif, dengan catatan terbaru dari issue/catatan tindak lanjut.
- [x] Uji privasi, urutan pembaruan, resolved, PS, filter/pagination, query budget dan build.
- [x] Dokumentasikan hasil dan daftar berkas.

## Keputusan

Order Branch adalah tab default, berisi Rincian Order per Branch. Owner Group memuat Status BIMA × Owner Group dan Rincian Task per Owner Group. Order Kendala menampilkan hanya work order prioritas dalam cakupan/filter yang memiliki kendala OPEN. Status order berasal dari `work_orders.status_order`, bukan label progress atau keterangan BIMA.

Kendala dibaca dari `wo_issues.description` dan `wo_issue_notes.note` pada issue yang masih OPEN; pilih pembaruan terbaru di seluruh issue aktif per order. Catatan progress biasa maupun kendala yang sudah RESOLVED tidak menggantikan detail kendala aktif. Data diperbarui ketika membuka/Perbarui halaman, tanpa klaim realtime. Seluruh riwayat asli tetap tersimpan. Satu order tampil sekali walaupun memiliki beberapa issue/bookmark.

Tab berbasis GET tervalidasi, bisa dibuka langsung/tanpa JavaScript dan membawa filter. Tabel tetap ringkas, teks membungkus, tanpa scroll internal, serta 25 order per halaman dengan rentang eksplisit. Tidak ada migration, env, dependency, perubahan data atau izin baru.

## Implementasi

- URL `/priority-orders/monitoring?tab=branch|owner-group|issues`, default branch. Filter/reset/tab/pagination membawa pilihan tab; tautan angka dan List menghapus parameter presentasi tab sebelum menuju daftar. Tab yang tidak dikenal ditolak validasi.
- Tabel Order Kendala memiliki tepat empat kolom: Branch, No WO / TQ-SC-ID, Status order, Kendala. No WO membuka Detail Order. Waktu pembaruan detik WIB ditampilkan di dalam sel Kendala; teks multiline dibungkus dan di-escape.
- `whereHas` issue OPEN diterapkan pada query prioritas shared, sehingga tidak ada duplikasi karena banyak kendala/bookmark dan tidak ada kebocoran pilihan lokal Branch. PS tetap dikecualikan dari semua tab sesuai aturan pelepasan prioritas. Cancel/HOLD/QE/Perizinan yang masih mempunyai issue aktif tetap masuk jika tidak dikecualikan filter status monitoring pengguna.
- Issue aktif beserta satu catatan terakhir per issue di-eager-load hanya untuk order pada halaman. Relasi `latestNote` memakai max(created_at), kemudian ID sebagai pengurut deterministik. Pemilihan lintas issue membandingkan waktu, ID issue, ID note; tidak menganggap ID note yang lebih besar pasti lebih baru daripada timestamp. Catatan issue yang sudah selesai tidak mengganti issue aktif. Pelaku tidak disaring berdasarkan role saat ini agar riwayat valid dari Branch/TL/Area yang berubah role tetap terbaca.
- Data dibaca ulang saat membuka/Perbarui, tanpa perubahan sumber, progress, issue, riwayat, bookmark atau akun. Rekap global tetap menunjukkan seluruh prioritas sesuai filter; jumlah dalam tabel Kendala hanya order yang mempunyai issue OPEN.

## Verifikasi

- Suite PHP penuh final: **308 tes / 26.686 assertions lulus**.
- Empat tes baru: **84 assertions lulus**, mencakup update kendala nyata lewat endpoint Branch, catatan tindak lanjut vs progress biasa, waktu WIB, tampilan empat kolom, tab/form/URL, banyak issue dengan timestamp berbeda, note ID lebih besar bertanggal lebih lama, XSS, kendala resolved, scope/bookmark Area/Branch, pindah assignment, PS, nonprioritas, hak akses, filter, empty dan pagination.
- Query halaman Kendala diuji pada 5 dan 30 order dengan beberapa catatan masing-masing: jumlah query tetap sama, maksimal 10; data ditampilkan 25 order per halaman.
- MariaDB sementara terpisah: **43 tes / 1.925 assertions lulus**. Database sementara dihapus setelah tes; database aplikasi tidak dimigrasi atau diisi ulang.
- **28 tes JavaScript**, build frontend, kompilasi Blade, Pint dan diff whitespace lulus. Build dijalankan sebelum pengujian penuh final agar penggantian manifest tidak berbenturan dengan render tes login.

Tidak mengulang pemeriksaan HTTP atau visual browser karena izinnya sebelumnya ditolak. Verifikasi UI melalui rendering Blade/DOM dan kontrol JS. Tidak ada input bisnis atau credential tambahan yang diperlukan; tidak ada koneksi Google/Telegram yang dikonfigurasi atau diuji pada pekerjaan ini.

## File diubah

- `app/Http/Controllers/PriorityOrderController.php`
- `app/Http/Requests/PriorityOrderListRequest.php`
- `app/Models/WoIssue.php`
- `app/Services/PriorityMonitoringService.php`
- `resources/views/priorities/monitoring.blade.php`
- `resources/views/priorities/monitoring-branches.blade.php` (baru, pemisahan tabel yang sudah ada)
- `resources/views/priorities/monitoring-owner-groups.blade.php` (baru, pemisahan dua tabel yang sudah ada)
- `resources/views/priorities/monitoring-issues.blade.php` (baru)
- `resources/views/priorities/filters.blade.php`
- `resources/views/components/priority-count.blade.php`
- `resources/css/app.css`
- `tests/Feature/PriorityMonitoringTest.php`
- `tests/Feature/PriorityMonitoringTabsTest.php` (baru)
- `docs/PRIORITY_MONITORING_TABS.md` (baru), `docs/PRIORITY_MONITORING.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Perubahan workspace dari pekerjaan sebelumnya tetap dipertahankan.

## Koreksi isi Status BIMA pada rincian Branch — 27 Oktober 2026

Kolom **Status BIMA** pada Rincian Order per Branch kini membaca `work_orders.status_order` penuh (contoh `Site Survey-STARTWORK`), sesuai permintaan pemilik. Label kolom tetap Status BIMA; nilai NULL/kosong memakai pesan Status BIMA belum tersedia. Pengelompokan Branch/task, filter, akses, pagination dan rekap Owner Group tetap mengikuti implementasi yang ada.

Berkas diubah: `resources/views/priorities/monitoring-branches.blade.php` dan `docs/PRIORITY_MONITORING_TABS.md`. Tidak ada migration atau env baru, mutasi data, dependency maupun input bisnis yang diperlukan. Pengujian monitoring yang sudah ada lulus **12 tes / 360 assertions**; build, kompilasi Blade dan pemeriksaan whitespace lulus. Preview browser tidak dijalankan.

## Order Kendala per Branch dan bookmark massal — 27 Oktober 2026

Order Kendala kini dikelompokkan Branch, default tertutup dengan kontrol satu Branch/Buka semua/Tutup semua pada halaman aktif. Isi dan scope tetap sesuai aturan update progress/kendala terbaru. Bookmark massal maksimal 50 entri tersedia melalui input → preview → konfirmasi, mengikuti izin/scope prioritas dan pengecualian PS. Implementasi, daftar berkas dan hasil pengujian terbaru: [PRIORITY_BULK_BOOKMARK.md](PRIORITY_BULK_BOOKMARK.md).
