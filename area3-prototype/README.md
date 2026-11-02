# it's Area 3 — Interactive Frontend Prototype

Prototipe administrasi konstruksi dan monitoring pembayaran mandor. React 19, TypeScript dan Vite; UI berbahasa Indonesia dengan primary `#9FA1FF`, white/dark, tanpa gradient.

Proyek ini terpisah dari aplikasi Laravel Dompis Prov di direktori induk. Tidak menggunakan database, sesi, kredensial, route, template, atau integrasi produksi Dompis Prov.

## Jalankan

Gunakan Node.js 22.12+ atau 24 LTS yang kompatibel dengan Vite 8.

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/dompisprov/area3-prototype
npm ci --ignore-scripts
npm run dev
```

Buka **http://127.0.0.1:5178/dashboard**. Bila port telah terpakai, gunakan alamat yang dicetak Vite. Tidak perlu akun/login.

```sh
npm test
npm run build
npm run preview
```

Build tersimpan di `dist/`. Hosting statis membutuhkan fallback semua URL aplikasi ke `index.html`. Folder hasil build dan node_modules diabaikan Git. Paket terkunci dalam `package-lock.json`.

## Mulai mencoba

Pemilih **Peran demo** di atas mengubah aksi dan Inbox Approval. Peran Admin Konstruksi mengelola dokumen, PM/Manager/Finance menyetujui termin secara berurutan. Superadmin mengelola pengguna dan master serta diotorisasi untuk tindakan operasional, tetapi tidak mengambil alih tiga level approval.

Tombol **Skenario demo** menyediakan checkpoint terhubung:

| Skenario | Titik mulai | Langkah utama |
|---|---|---|
| Ringkasan | Beberapa PP, dua SPK, tiga termin, satu PP lanjutan | Jelajahi dashboard, laporan dan ledger |
| A | PP sah, BOQ Review | Review DEMO-FO-12 dengan catatan → Finalisasi |
| B | PP sah, BOQ Final | Pilih Mandor/No BA KH, komponen/volume → SPH → penawaran → kesepakatan → dokumen → sahkan |
| C | BA KH sah | Lengkapi SPK, bukti dokumen → sahkan |
| D | SPK sah | Buat draft termin → aktual → ajukan → ganti PM → Manager → Finance → APM |
| E | Dua termin approved, terakhir Final; BA PP Draft | Bukti dan sahkan BA PP → pilih sisa volume → lepas → masukkan No PP Baru dan alokasi |

Pergantian skenario/reset memerlukan konfirmasi dan mengganti data demo serta draft browser ini. Gunakan Pengaturan → Data Demo → Unduh Cadangan JSON untuk menyimpan salinan sebelum reset. Pengembalian cadangan JSON belum tersedia di UI.

PP baru menggunakan nomor manual, tidak pernah dibuat otomatis. Wizard dapat menyimpan draft dan melanjutkan setelah berpindah halaman/reload. PP lanjutan dibuat dari BA PP agar lineage dan volume sah; lengkapi narasi/tim/jadwal/risiko pada PP baru itu, sahkan, lalu lanjutkan BA KH/SPK/Termin.

## Perilaku penting

- BOQ Owner dihitung dari Master KHS dan snapshot final. Harga Excel hanya pembanding, semua selisih harus direview dengan catatan.
- Impor diberi label **Simulasi Import Demo**. Memilih file XLSX hanya menampilkan nama file; isinya tidak diparsing. Tombol memuat enam baris data terstruktur.
- `DEMO-*` adalah data sintetis eksplisit. BOQ root seed v1: Material Rp90.800.000 + Jasa Rp43.980.000 = Rp134.780.000. Bukan sample resmi 14 item/Rp140.056.789 yang tidak dilampirkan sebagai workbook.
- Penugasan/volume/harga Material dan Jasa independen. Komponen tidak tersedia = N/A. Harga kesepakatan masing-masing komponen ≤ KHS terkait; diskon silang tidak mengompensasi kelebihan.
- Draft BA KH mereservasi volume. SPK Sah memindahkan reserved → committed. Snapshot dokumen Terbit terkunci kecuali pencatatan bukti untuk pengesahan; Sah terkunci.
- Termin harus memiliki aktual positif, maksimal tiga, nomor 3 wajib Final; Final lebih awal menghentikan siklus berikutnya. Volume historis disetujui tidak bisa diedit/ditagih ulang.
- Approval hanya PM → Manager Konstruksi → Finance. Return/reject wajib alasan. Return memulai ulang chain saat resubmit, riwayat sebelumnya tetap.
- Termin berikutnya membutuhkan predecessor Parsial approved 3 level + APM valid. Pembayaran belum lunas tidak menghalangi pembukaan termin.
- APM, status pembayaran, approval, pekerjaan dan dokumen berbeda. Finance dapat mencatat pembayaran sebagian sampai batas outstanding.
- BA PP menggunakan nilai/volume accepted kumulatif seluruh termin approved, bukan hanya termin Final.
- Pelepasan memerlukan BA PP Sah + Final approved 3 level + otorisasi Admin. Hanya sisa komponen dipilih yang dilepas; accepted tidak dapat dipakai ulang.
- PP lanjutan mereservasi released-but-unallocated volume dan mempertahankan Parent/Root/sumber BA PP. Budget lanjutan adalah subalokasi, tidak menambah budget root konsolidasi.
- Ledger root per item/komponen: **available + reserved + committed + accepted = volume root**. Satuan fisik berbeda tidak dijumlahkan.
- KHS berubah → BOQ draft terkait perlu review ulang. BOQ final dan harga kontrak historis tidak direprice.
- Dokumen adalah preview HTML yang bisa dicetak/disimpan lewat dialog browser. Tidak ada tanda tangan otomatis, DOCX generator, atau PDF server.
- Ekspor CSV menyiapkan isi file seluruh baris terfilter dalam dialog preview dengan tautan Unduh CSV dan alternatif Salin CSV, bukan hanya halaman. Angka tetap numerik; teks diawali formula dinetralisasi. Tidak ada tombol Excel palsu.

## Arsitektur

| Sumber | Tanggung jawab |
|---|---|
| `src/domain.ts` | Tipe record, aturan/permission, ledger, uang dan semua service transaksi bisnis |
| `src/store.ts` | Transaksi clone → validasi → persist; subscription React; form draft dan reset |
| `src/seed.ts` | Enam checkpoint koheren; kontrak/termin seed melewati service normal |
| `src/App.tsx` | Shell, router History API, search, role/scenario, notifikasi dan dashboard |
| `src/projects.tsx` | List/detail/wizard PP, alokasi lineage dan empat tahap BOQ |
| `src/contracts.tsx` | Negosiasi enam tahap, BA KH, penerbitan/pengesahan SPK |
| `src/payments.tsx` | Aktual termin, approval master/detail, APM, pembayaran, BA PP, release dan lanjutan |
| `src/management.tsx` | Master KHS/version, laporan/filter/CSV, profil, pengguna dan audit |
| `src/ui.tsx` | Primitif reusable, dialog/fokus, feedback, wizard, preview dokumen, ekspor |
| `tests/workflow.test.ts` | Invariant, transisi, permission, kalkulasi dan continuation cycle |

localStorage `its-area3-prototype-v1` menyimpan data transaksi; `area3-draft-*` menyimpan draft formulir; `area3-theme` preferensi tampilan. Setiap mutasi memakai salinan state; kegagalan validasi tidak dipersist. Tidak ada request ke backend bisnis. Font Inter menggunakan Google Fonts jika jaringan tersedia, dengan fallback sistem.

Panduan lanjutan: [peta route](ROUTES.md), [pengujian manual](QA.md), [catatan backend/limit](HANDOFF.md). Lima skill reusable tersedia pada direktori `../skills/area3-*`.
