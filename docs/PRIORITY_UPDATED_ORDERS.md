# Cakupan order dengan update progress/kendala — 27 Oktober 2026

## Checklist

- [x] Inspect pembatas issue OPEN pada query tab dan sumber catatan manual.
- [x] Perluas query menjadi kendala aktif ATAU progress manual yang terbukti pada audit/event.
- [x] Sesuaikan teks tabel dan uji order progress tanpa issue, scope, PS, log otomatis dan query budget.
- [x] Build, pengujian dan dokumentasi hasil.

Tab Order Kendala tetap memakai nama yang diminta pemilik, dengan kolom Update Progress/Kendala. Order prioritas yang sudah diperbarui melalui Update Progress dapat tampil tanpa issue OPEN. Order dengan kendala aktif tetap tampil. Isi kolom memakai catatan manual terbaru yang sudah diterapkan sebelumnya; log otomatis BIMA/penugasan tidak membuat order dianggap sudah diperbarui Branch.

Order tetap harus berupa pilihan prioritas dalam scope akun dan belum PS. Tidak mengubah data, progress, issue, bookmark, izin, schema atau env.

## Implementasi

- `PriorityMonitoringService::updatedOrders` menggabungkan issue OPEN atau progress manual di dalam satu predicate berkelompok. Scope prioritas, akses akun, filter dan pengecualian PS tetap berada di query induk. Membership dihitung di SQL sebelum pagination sehingga total dan halaman tidak kehilangan order progress saja.
- Bukti progress manual adalah pasangan audit `progress.changed` dan event dengan order, revision dan actor yang sama serta catatan tidak kosong. BIMA otomatis, penugasan, event tanpa audit yang sesuai dan order tanpa update tidak dimasukkan hanya karena memiliki kategori progress.
- Satu order yang mempunyai bookmark Area/Branch dan progress/kendala tetap dihitung sekali. Order manual tetap tampil saat kendala diselesaikan, memakai catatan progress manual terbaru. Kendala resolved tanpa progress manual tidak masuk. Filter eksplisit `state=issues` tetap membatasi hasil ke kendala aktif.
- Isi kolom mempertahankan pemilihan catatan terbaru, label kategori dan waktu WIB. Kandidat kendala berasal dari issue aktif; riwayat terselesaikan tetap ada di detail order. Audit historis tidak disaring berdasarkan role actor saat ini.
- Subquery ID memakai audit/event yang sudah tersedia; pembacaan catatan tetap dibatasi halaman 25 order dan dilakukan dalam batch. Tidak menambah query per baris atau mengambil seluruh order untuk disaring di PHP.

Ini menggantikan ketentuan membership issue OPEN saja dalam dokumentasi sebelumnya; nama tab Order Kendala dan empat kolom tetap.

## Pengujian dan batas

- PHP penuh: **312 tes / 26.748 assertions lulus**.
- MySQL/MariaDB sementara: **47 tes / 1.987 assertions lulus**, termasuk perbandingan revision JSON audit dengan event. Database pengujian terpisah dihapus setelah selesai.
- Pengujian fokus: **26 tes / 837 assertions lulus**. Cakupan mencakup Admin/HD Branch melalui form update, progress tanpa issue, gabungan issue/progress, provenance tidak cocok, BIMA otomatis, privasi bookmark lokal, filter, PS, kendala resolved dan pagination.
- Query budget pada 5 dan 30 order campuran progress saja/kendala tetap sama, maksimal 12 query per halaman.
- **28 tes JavaScript**, build frontend, kompilasi Blade, Pint dan pemeriksaan whitespace lulus. Build selesai sebelum pengujian fitur.

Tidak ada migration, env, dependency, perubahan data aplikasi atau integrasi eksternal. Tidak ada input bisnis yang masih diperlukan. Verifikasi melalui rendering Blade dan tes; preview HTTP/browser tidak dicoba ulang karena permintaan izinnya sebelumnya ditolak.

## Berkas diubah

- `app/Services/PriorityMonitoringService.php`
- `app/Http/Controllers/PriorityOrderController.php`
- `resources/views/priorities/monitoring-issues.blade.php`
- `tests/Feature/PriorityMonitoringTabsTest.php`
- `docs/PRIORITY_UPDATED_ORDERS.md` (baru), `docs/PRIORITY_PROGRESS_ISSUE_UPDATES.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Progress workspace sebelumnya dipertahankan.
