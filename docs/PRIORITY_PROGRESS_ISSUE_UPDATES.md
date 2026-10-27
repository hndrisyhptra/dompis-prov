# Update Progress/Kendala pada prioritas — 27 Oktober 2026

> Koreksi lanjutan pemilik: order dengan progress manual juga masuk walaupun tanpa issue OPEN. Ketentuan membership di bawah merekam tahap sebelumnya; implementasi terbaru dan hasil tes ada di [PRIORITY_UPDATED_ORDERS.md](PRIORITY_UPDATED_ORDERS.md).

## Checklist

- [x] Inspect sumber progress, kendala, catatan, audit dan query halaman prioritas.
- [x] Ganti kolom menjadi Update Progress/Kendala dan pilih pembaruan manual terbaru.
- [x] Pastikan log otomatis BIMA/penugasan tidak menggantikan catatan operasional Branch.
- [x] Uji urutan, scope, audit, pagination dan anggaran query; build dan dokumentasikan hasil.

Tab Order Kendala tetap berisi order prioritas yang memiliki kendala aktif. Perubahan ini memperluas isi kolom dari detail kendala saja menjadi catatan terbaru antara Update Progress manual dan kendala/tindak lanjut aktif. Status order tetap sumber BIMA. Riwayat asli tidak diubah dan tidak disalin ke kolom sumber.

## Implementasi

- Kolom bernama **Update Progress/Kendala**, berisi kategori progress pada saat pembaruan atau label Kendala, catatan terbaru dan waktu detik WIB.
- `operationalUpdates` membaca audit dan event untuk ID order pada halaman yang sudah diotorisasi. Event progress manual harus mempunyai audit `progress.changed` dengan order, revision dan actor yang sesuai. Log auto mapping/CLOSE BIMA dan penugasan tidak dianggap update manual, tanpa menebak dari awalan teks catatan.
- Kandidat kendala tetap berasal dari issue OPEN beserta latestNote. Pemilihan kandidat menggunakan waktu pembaruan; ID audit menentukan urutan apabila waktunya sama, dengan fallback deterministik untuk issue lama tanpa audit. Membandingkan seluruh kandidat, sehingga progress sesudah kendala tampil, lalu tindak lanjut kendala yang lebih baru dapat menggantikannya lagi.
- Audit actor historis dipakai tanpa menyaring role user saat ini. Riwayat valid dari Branch/TL atau koreksi Area yang diizinkan tetap terbaca jika actor kemudian berubah role; tidak memberikan hak akses baru. Event legacy tanpa bukti audit manual tidak ditebak sebagai catatan Branch.
- Lookup tambahan dibaca dalam batch untuk halaman 25 order, bukan query per baris. Issue kosong tetap mempunyai pesan yang jelas; seluruh isi di-escape dan dibungkus sesuai tampilan screenshot yang sudah ada.

Ini menggantikan ketentuan lama bahwa catatan progress umum tidak menggantikan detail kendala. Pemilik kini secara eksplisit meminta gabungan Update Progress dan Kendala.

## Pengujian

- PHP penuh: **310 tes / 26.707 assertions lulus**.
- MariaDB sementara terpisah: **45 tes / 1.946 assertions lulus**; database sementara dihapus setelah pengujian.
- Regression tab: **6 tes / 105 assertions lulus**, termasuk progress lebih baru dari kendala, kendala setelah progress, urutan audit pada detik sama, pengecualian log BIMA/penugasan, resolved, timestamp/ID, XSS, privasi, PS, filter dan pagination.
- Query budget diuji pada 5 dan 30 order dengan beberapa catatan dan progress manual: jumlah query tetap sama dan maksimal 12, termasuk dua lookup batch tambahan.
- **28 tes JavaScript**, build frontend, kompilasi Blade, Pint dan diff whitespace lulus. Build diselesaikan sebelum menjalankan pengujian fitur/penuh.

Tidak ada migration, env, dependency, perubahan data aplikasi atau integrasi eksternal. Tidak ada input bisnis/credential yang menghalangi pemakaian. Verifikasi melalui rendering Blade dan tes; HTTP/preview browser tidak dicoba ulang karena permintaan izinnya sebelumnya ditolak.

## Berkas diubah

- `app/Services/PriorityMonitoringService.php`
- `app/Http/Controllers/PriorityOrderController.php`
- `resources/views/priorities/monitoring-issues.blade.php`
- `tests/Feature/PriorityMonitoringTabsTest.php`
- `docs/PRIORITY_PROGRESS_ISSUE_UPDATES.md` (baru), `docs/PRIORITY_MONITORING_TABS.md`
- `AGENTS.md`, `SKILLS.md`, `PRD_OLO_Command_Center_v2.md`

Progress workspace sebelumnya dipertahankan.
