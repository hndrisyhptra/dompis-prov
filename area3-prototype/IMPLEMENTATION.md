# Checklist implementasi it's Area 3

Aplikasi Laravel 13/Blade yang ada memiliki alur dan database Dompis Prov tersendiri. Prototipe ini merupakan aplikasi React/TypeScript/Vite terisolasi di `area3-prototype`, tanpa migrasi, perubahan route Laravel, akses database atau integrasi eksternal. Perubahan pengguna yang sudah ada dipertahankan.

- [x] Fondasi: shell, router, role, service terpusat, persistensi dan seed koheren.
- [x] PP enam tahap, BOQ empat tahap, review KHS dan snapshot final.
- [x] Negosiasi enam tahap, harga per komponen, SPK dan ledger.
- [x] Termin maksimal tiga, approval berurutan, APM dan pembayaran terpisah.
- [x] BA PP, bukti dokumen, pelepasan volume dan PP lanjutan.
- [x] Master KHS, laporan CSV, pengaturan dan skenario A–E.
- [x] Uji invariant bisnis, build, navigasi browser dan responsive.
- [x] README, peta route, QA manual, batas backend serta lima skill.

Dependensi: final BOQ → BA KH sah → SPK sah → termin → PM → Manager → Finance → APM; final approved + BA PP sah + otorisasi Admin → pelepasan → reservasi PP lanjutan. Pengesahan dokumen terpisah dari approval termin.
