# Download Excel Work Order dan Order Prioritas

## Checklist

- [x] Inspect query/filter/policy Work Order, prioritas dan implementasi ekspor sebelumnya.
- [x] Hapus tombol/route ekspor dashboard, pertahankan perapian tabel.
- [x] Tombol download pada tabel Work Order dan Order Prioritas; seluruh filter/sort dan scope dipakai bersama dengan tampilan.
- [x] Uji ekspor seluruh halaman, privasi prioritas Branch, transfer assignment, validasi, build dan regresi.
- [x] Arahan lanjutan: ekspor seluruh kolom fisik work_orders, nama/urutan/nilai tersimpan sesuai database untuk kedua menu.

Koreksi pemilik 26 Oktober 2026 menggantikan unduhan pada dashboard. Tidak ada migration/env baru. Writer XLSX dan validasi unduhan yang sudah diuji dipakai kembali. Query prioritas diekstrak agar filter/sumber/status/sort pada tampilan dan unduhan identik; role yang tidak dapat melihat menu prioritas juga tidak boleh mengekspornya. Tabel dashboard tetap rapi, tetapi tidak menyediakan unduhan, termasuk modal.

## Penggunaan

Tekan **Terapkan** pada filter, kemudian **Download Excel** di header tabel Work Order atau Order Prioritas. Tombol berada di luar tampilan desktop/mobile sehingga keduanya mendapat akses yang sama. File berisi seluruh hasil sesuai filter, bukan hanya25 baris halaman aktif. Waktu snapshot dan filter dicantumkan, data dibaca ulang saat download; perubahan sejak halaman dibuka dapat mengubah hasil.

- Work Order: `GET /work-orders/export`, route `work-orders.export`. Memakai WorkOrderListRequest dan WorkOrderListQuery yang sama dengan daftar, termasuk antrean, pencarian No WO/SID/pelanggan, Branch/Region, jenis pekerjaan (NEW INSTALL default), layanan, kategori/status, bulan order, Owner Group, TGL PS, tanpa Region dan belum ditugaskan. Urutan sama dengan daftar: belum diperbarui lebih dahulu, tanggal order terbaru, ID terbaru.
- Order Prioritas: `GET /priority-orders/export`, route `priorities.export`. Memakai PriorityOrderListRequest dan PriorityOrderQuery yang sama dengan monitoring: q, Branch, sumber Area/Branch, status monitoring dan sort selected/age/updated. Semua jenis pekerjaan. Hanya WO yang benar-benar ada pada prioritas dalam akses, deduplikasi satu WO meskipun dipilih Area dan Branch.
- Endpoint lama `/dashboard/exports/{table}`, `/branch/dashboard/exports/{table}`, `/dashboard/orders/export` tidak tersedia. Dashboard Area/Branch dan daftar modal tidak memiliki tombol download; tautan Buka di Work Order tetap dapat dipakai untuk menuju daftar.

## Data dan privasi

Sesuai arahan lanjutan, kedua file memuat **semua kolom fisik work_orders**, dengan header snake_case dan urutan yang dibaca langsung dari schema database. Termasuk PK/FK, identitas order, pelanggan/alamat, seluruh atribut BIMA/perangkat, progress/verifikasi/revisi, tanggal/waktu, Owner Group, mapping, source_bima_fields, source_snapshot_hash dan device_overrides. JSON sumber/override ikut sebagai teks JSON persis yang tersimpan sesuai permintaan pemilik. Tidak membuat pilihan kolom tersembunyi atau hanya mengekspor kolom UI.

Order Prioritas memakai kolom work_orders yang sama, tetapi hanya baris order prioritas dalam filter/akses pengguna. Tabel users/integrasi/riwayat tidak digabung sebagai dump. Field virtual/agregat (misalnya open_issues_count/priority_selected_at) serta selection_token/metadata bookmark tidak ditambahkan karena bukan kolom work_orders. Data bookmark lokal Branch tetap tidak masuk file Area.

Pembacaan memakai getRawOriginal, sehingga `branch` adalah teks sumber tersimpan, bukan nama relasi master; umur_hari/kategori_umur adalah nilai tersimpan, bukan accessor yang dihitung ulang pada saat download; progress_category nullable tetap kosong dan tidak diganti label UI. Timestamp mengikuti nilai UTC database dan ditampilkan sampai detik, sedangkan waktu snapshot pada konteks file tetap WIB. Null tetap kosong, identifier termasuk PK/FK/No WO/SID/SN/port tetap teks untuk menjaga presisi/nol depan. Tanggal native Excel tetap bisa disortir. Nilai teks yang melebihi batas32.767 unit UTF-16 sel Excel ditolak dengan pesan kolom/No WO; tidak memotong JSON/nilai diam-diam atau mengubah database. Pesan validasi tampil pada kontrol download dan pengguna bisa mencoba ulang setelah mempersempit filter/memeriksa order.

Role manusia aktif dapat mengunduh Work Order sesuai scope. Order Prioritas tetap hanya super_admin/area_admin/area_hd/branch_admin/branch_hd/team_leader sesuai izin menu yang sudah ada; area_coordinator/material_admin/bot tidak diberi izin baru. Prioritas lokal Branch tidak masuk baris, metadata pemilih, jumlah atau file Area. Bila assignment pindah, prioritas Area mengikuti WO, prioritas lokal lama tidak ikut berpindah. Akun tanpa assignment mendapat file kosong; Branch lain melalui parameter ditolak403.

XLSX native mempertahankan identifier teks (nol depan), integer/date/datetime sesuai tipe schema, null bukan nol, AutoFilter, frozen headers, baris terbungkus, format tanggal dd mmm yyyy dan timestamp UTC sampai detik. Awalan formula pada teks tidak dieksekusi. File sementara privat dihapus setelah dikirim, response private/no-store/nosniff, throttle20/menit, audit `orders.exported` hanya actor/jenis file/nama filter/hash filter tanpa isi pencarian/JSON sumber. Ekspor tidak mengubah WO/progress/perangkat/bookmark/material/BOQ.

## Manifest file

- Baru: `app/Http/Controllers/OrderExportController.php`; `app/Http/Requests/PriorityOrderListRequest.php`; `app/Services/PriorityOrderQuery.php`; `app/Services/Exports/OrderExportService.php`; `resources/views/components/excel-download.blade.php`; `resources/js/excel-downloads.js`; `tests/Feature/OrderExportTest.php`; `tests/JavaScript/excel-downloads.test.js`.
- Rename awal: DashboardXlsxWriter → `app/Services/Exports/XlsxTableWriter.php`, tes → `tests/Unit/XlsxTableWriterTest.php`. Pada arahan seluruh kolom, format timestamp disesuaikan hingga detik.
- Berubah: `app/Http/Controllers/PriorityOrderController.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, `resources/views/work-orders/index.blade.php`, `resources/views/priorities/index.blade.php`, `resources/css/app.css`, `resources/js/app.js`.
- Tombol dihapus pada `resources/views/area/{dashboard,progress-matrix}.blade.php`, `resources/views/branch/dashboard.blade.php`, `resources/views/owner-groups/summary.blade.php`, `resources/views/work-orders/order-month-summary.blade.php`, `resources/views/dashboard/orders.blade.php`.
- Implementasi khusus dashboard yang tidak dipakai dihapus: DashboardExportController, DashboardExportService, dashboard-export.blade.php, dashboard-exports.js, DashboardExportTest dan tes JS lamanya. Jalur download sekarang terpusat pada dua daftar order.
- Dokumen: file ini, penanda koreksi di docs/DASHBOARD_TABLE_EXPORTS.md, addendum AGENTS.md/SKILLS.md/PRD_OLO_Command_Center_v2.md.

## Hasil dan batas

Hasil aktual setelah arahan seluruh kolom: **278 tes PHPUnit / 25.748 assertion lulus**; **30 tes MariaDB / 2.746 assertion lulus** pada database sementara terpisah yang dihapus setelah tes; **27 tes JavaScript lulus**. Vite build, Blade view cache, Pint dirty check dan git diff check lulus. Tes memeriksa tombol hanya pada dua daftar, route lama404, filter/sort sama dengan UI, seluruh halaman, field native teks/tanggal, formula injection, deduplikasi, privasi pemilih Branch, transfer assignment dan batas akses role.

Pemeriksaan lanjutan membandingkan seluruh header Excel dengan kolom fisik schema, serta nilai setiap kolom dengan data mentah database pada kedua endpoint. Termasuk JSON sumber/override, hash, ID/SID/SN/port dengan nol depan, timestamp UTC sampai detik, branch sumber vs master, umur tersimpan vs accessor, progress kosong vs label UI, penolakan JSON panjang tanpa pemotongan dan tanpa mutasi order. File perubahan tambahan: OrderExportService, OrderExportController, XlsxTableWriter, resources/js/excel-downloads.js, tests/Feature/OrderExportTest.php, tests/JavaScript/excel-downloads.test.js dan dokumen requirement. Schema/DB tidak diubah.

Tidak ada migration/env/dependency baru; tidak menjalankan migrasi, import produksi, atau request Google/Telegram. Tidak mengubah source template. Tampilan browser belum diperiksa manual karena preview lokal sebelumnya ditolak; Blade/link/akses/data XLSX dan status JS diuji otomatis.
