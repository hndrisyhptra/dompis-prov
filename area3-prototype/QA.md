# Pengujian Prototipe

Data demo dapat direset dari Skenario demo. Semua pengujian dilakukan dalam aplikasi terisolasi ini, tidak menyentuh database Dompis Prov.

## Otomatis

`npm test` memeriksa seed/relasi/ledger, review BOQ, role, nomor PP unik, batas harga per komponen, double reservation, snapshot SPK, aktual, urutan approval, return/reject, APM, maksimal tiga termin, kalkulasi kumulatif, pembayaran, bukti/pengesahan BA PP, pelepasan, alokasi lanjutan, siklus SPK lanjutan, KHS dan user CRUD, JSON roundtrip.

`npm run build` menjalankan TypeScript strict dan Vite production build.

## Checklist Manual

- [ ] Klik sebelas menu, breadcrumb, link detail, aksi edit dan browser back/forward; refresh route detail langsung.
- [ ] Cari PP/SPK/proyek lewat pencarian global; hasil menuju record yang benar.
- [ ] Filter PP berdasarkan status/Branch/tanggal, search tanpa hasil, pagination bila >8 PP.
- [ ] Buat PP manual unik; isi 6 bagian, tambah/hapus anggota tim, HK/jadwal, biaya/margin; simpan draft dan refresh sebelum sahkan.
- [ ] Validasi PP kosong/duplikat/anggaran nol/jadwal terbalik diblokir. Role Finance/PM tidak dapat menyimpan PP.
- [ ] A: buka BOQ; Finalisasi disabled saat unresolved. Review DEMO-FO-12 menunjukkan selisih Rp500/unit ×800m = Rp400.000.
- [ ] Catatan review kosong ditolak. Gunakan KHS → Finalisasi → refresh: Final dan total Rp134.780.000 tetap.
- [ ] B: pilih mandor, nomor BA KH, Material Only/Jasa Only/keduanya per item; unavailable component disabled.
- [ ] Simpan SPH dan penawaran terpisah; harga kesepakatan M > KHS M harus invalid walau J lebih rendah; demikian sebaliknya.
- [ ] Periode mandor di luar jadwal/lebih dari total HK diblokir. Lengkapi FO/Jointer/signatory dan bukti, generate/sahkan BA KH.
- [ ] C: buat SPK dari BA KH sah; isi header dan bukti. Pastikan rincian = total BA KH dan SPK, harga/volume read-only.
- [ ] Generate mengunci snapshot, hanya bukti pengesahan dapat diisi. Sahkan memindah reserved ke committed.
- [ ] D: buat termin 1 Parsial. Isi M=100m dan J=80m pada DEMO-FO-24 → Rp2.422.500. Submit lalu pastikan aktual terkunci.
- [ ] PM level 1 → Manager level 2 → Finance level 3. Counter inbox dan history berpindah. Role salah/Superadmin tidak boleh approve.
- [ ] Return/reject tanpa alasan ditolak; return dapat diedit dan diajukan ulang, history lama tetap. Reject terkunci.
- [ ] Setelah approved tetapi APM belum valid: next term diblokir. Finance catat APM unik valid → next term aktif meski terbayar Rp0.
- [ ] Bayar sebagian; outstanding berkurang tepat. Nominal berlebih ditolak. Status pembayaran tidak mengganti approval.
- [ ] Termin 3 wajib Final. Final pada termin1/2 memblokir termin berikutnya. Riwayat aktual lama tidak diedit/dihitung lagi.
- [ ] E: rekonsiliasi BA PP menunjukkan accepted75% dan total kumulatif Rp85.922.250, berbeda dari tagihan Final saja.
- [ ] Release disabled sebelum BA PP sah. Nomor dan bukti asli wajib. Setelah sah, pilih komponen; volume melebihi sisa/accepted/already released ditolak.
- [ ] Lepas Material100m/Jasa70m DEMO-FO-24; buat No PP Baru manual dengan M60m/J30m → subalokasi Rp1.485.000.
- [ ] Detail PP baru menampilkan Parent/Root/source dan ledger M: available40/reserved60/committed200/accepted900; J:40/30/230/900.
- [ ] Refresh: PP baru/lineage/ledger tetap. Lengkapi/sahkan PP baru, negosiasi dan SPK dari alokasi; tidak ada volume baru di root.
- [ ] Edit KHS: versi dan before/after history bertambah; BOQ draft butuh review, kontrak terbit/historis tetap.
- [ ] Semua 9 dokumen dapat dipreview/print; BA PP memakai kumulatif, lampiran negosiasi memuat KHS/SPH/offer/agreement; tanda tangan tidak dibuat.
- [ ] Laporan 9 tab, filter dan export CSV benar; cek subtotal uang, tidak menjumlah volume beda satuan, konsolidasi root tanpa lanjutan.
- [ ] Superadmin tambah/edit/hapus pengguna demo, role lain hanya akses yang sesuai.
- [ ] Pengaturan cadangan JSON menghasilkan file; reset menampilkan konfirmasi lalu mengembalikan seed serta menghapus draft.
- [ ] Light/dark, sidebar ringkas, mobile drawer, modal Escape/fokus, keyboard focus; layar 375/768/1440px tanpa page overflow.

## Bukti yang sudah dijalankan

- 31 uji otomatis lulus: 26 workflow/ledger, 2 CSV dan 3 preview dokumen; TypeScript strict serta build production lulus.
- Browser: A review + finalisasi + reload; D aktual terpisah + tiga approval + APM + next-term eligible tanpa pembayaran; E BA PP + selected release + continuation + reload telah dijalankan.
- Semua 11 menu, PP draft manual/reload, negosiasi → BA KH Terbit/Sah → SPK Terbit/Sah, pencarian mobile, drawer dan Escape modal telah diperiksa. Viewport 375/768/1440px tidak memiliki page overflow; white/dark telah diperiksa.
- Checklist di atas adalah panduan UAT, bukan klaim seluruh variasi form/print telah diuji manual.
