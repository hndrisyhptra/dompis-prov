# Handoff dan Batas Implementasi

## Hasil

Satu aplikasi frontend dengan 11 menu dan semua tahap utama terhubung. Data sintetis berasal dari satu service/store, persist di browser. PP/BOQ/negosiasi/SPK/termin/approval/APM/pembayaran/BA PP/ledger/lanjutan dapat dijalankan tanpa backend. Lima skill reusable berada di direktori root `skills/area3-*`.

## Batas yang Disengaja

- Frontend prototype, bukan layanan transaksi produksi. Demo role switcher bukan autentikasi; localStorage dapat diedit pengguna, tidak encrypted, tidak multi-user/server-authoritative atau cocok untuk data keuangan nyata.
- Impor Excel masih simulasi terlabel, memuat 6 row sintetis `DEMO-*`; belum membaca workbook resmi. Data 14-item referensi tidak direkayasa.
- Preview dokumen HTML/cetak browser. Tidak mengklaim DOCX/PDF server, e-signature, atau bukti tanda tangan telah diverifikasi; referensi bukti diisi manual.
- APM dan pembayaran merupakan pencatatan demonstrasi; tidak terhubung bank/ERP/Telegram/Sheets/Bima. Tidak ada uang dikirim.
- Workflow configuration hanya preview sesuai brief. User CRUD tidak mengubah hak autentikasi aktual karena belum ada autentikasi.
- Form draft menyimpan browser secara lokal; perubahan pada tab/browser berbeda belum memiliki lock/revision/concurrency arbitration. Gunakan satu tab untuk demonstrasi. Terdapat peringatan bila storage utama penuh/tidak tersedia; draft tidak dirancang sebagai backup.
- Uang memakai Number dan pembulatan 2 digit untuk demo, volume 3 digit. Production wajib decimal/rational dan precision/rounding policy yang dikonfirmasi. Penyajian menampilkan pecahan harga yang benar, tidak menyembunyikan setengah rupiah.
- PP schedule memakai total HK sebagai durasi dan batas kalender minimum/maksimum tahap. Kalender kerja/libur/overlap resmi perlu definisi pemilik sebelum backend.
- KHS yang pernah direferensikan tidak dihapus agar referensi historis tetap utuh; Superadmin dapat nonaktifkan. Menghapus secara paksa membutuhkan retention policy formal.
- Export CSV bekerja; tidak ada export XLSX resmi atau import backup JSON di UI. Pagination utama ada di list PP; list sekunder kecil memakai scroll, belum dirancang untuk volume produksi.
- Kebijakan koreksi SPK terbit/Sah, pembatalan APM predecessor yang sudah membuka next-term, reopening Final/Reject dan pengembalian alokasi continuation belum ada. Tindakan tersebut diblokir, memerlukan alur koreksi formal.

## Integrasi Backend Berikutnya

1. Pertahankan project terpisah sampai cakupan integrasi dengan Dompis Prov disetujui. Jangan rename tabel atau menyisipkan role prototype ke produksi secara otomatis.
2. Ganti centralized mutations dengan API server: RBAC/policy, scope organisasi, optimistic revision dan response error terstruktur. Semua otorisasi/perhitungan/gating diperiksa ulang server.
3. Modelkan PP parent/root, BOQ item/component, KHS version, assignment, negotiation snapshot, BA KH, SPK, term actual, approval history, APM/payment, BA PP, release dan allocation dengan FK/unique constraints.
4. Simpan ledger append-only per root/item/component, gunakan row lock + transaksi untuk reservation, issuance, approval acceptance, release dan continuation. Tambah idempotency keys pada operasi ulang/retry.
5. Import XLSX memerlukan kontrak workbook aktual, validasi identifier/komponen/unit, formula/error policy, preview, hash dan commit; Master KHS tetap authority.
6. Dokumen privat dengan evidence upload/version/hash, template resmi dan penandatangan nyata. Jangan mengarang tanda tangan. Pisahkan attestation dari tiga approval termin.
7. Pakai queue/outbox hanya untuk integrasi eksternal nyata. Tidak perlu `.env` bisnis/kredensial untuk prototype ini; nama environment/kontrak baru ditetapkan setelah penyedia nyata dipilih.
8. Port invariant tests ke backend dengan fake adapter, audit identity UTC + WIB presentation, constraints, authorized scope, replay dan concurrency tests.

## Verifikasi Akhir

Pada 1 November 2026:

- `npm run build`: TypeScript strict dan Vite production build lulus.
- `npm test`: 31/31 lulus (26 workflow/ledger, 2 CSV, 3 preview dokumen termasuk PP belum tersimpan, BA PP kumulatif dan PP lanjutan).
- Kelima SKILL.md lulus validator bawaan skill-creator; parser YAML hanya dipasang sementara di `/private/tmp/area3-skill-validator`, tidak mengubah Python global atau dependensi aplikasi.
- Browser: semua 11 menu; A review/finalisasi/reload; B negosiasi dengan batas harga langsung; C generate/attest BA KH dan SPK; D aktual M100/J80 → Rp2.422.500 → PM/Manager/Finance → APM → next-term eligible tanpa pembayaran; E BA PP → pelepasan M100/J70 → lanjutan M60/J30 = Rp1.485.000 → reload.
- PP baru manual dan draft formulir bertahan setelah reload. Browser search mobile, drawer, modal Escape, white/dark dan lebar 375/768/1440px diperiksa. Console saat pemeriksaan tidak menunjukkan error/warning.
- Laporan Outstanding filter Surabaya menghasilkan satu baris dengan tagihan45825200, terbayar10000000, outstanding35825200; isi CSV diperiksa dari dialog preview. In-app browser tidak mengembalikan event unduhan Blob pada uji terikat 5 detik, sehingga penyimpanan file melalui browser tersebut belum dikonfirmasi. Tautan Blob Unduh CSV dan isi untuk salin disediakan; serialisasi file teruji otomatis. Cetak/PDF native dan hasil clipboard belum diverifikasi manual.
- Screenshot hasil tersedia di `qa/dashboard-desktop.jpg`, `qa/dashboard-mobile.jpg`, `qa/dashboard-tablet-dark.jpg` dan `qa/csv-preview.jpg`.
- Suite Laravel utama tidak dijalankan karena semua perubahan aplikasi berada pada project frontend terisolasi. Empat perubahan pengguna yang sudah ada dipertahankan.

Tidak ada migration, perubahan database, deploy publik atau perubahan source template Dompis Prov selama tugas ini.
