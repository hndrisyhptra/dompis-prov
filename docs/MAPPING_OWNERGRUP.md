# Referensi mapping Owner Group — 22 Oktober 2026

Laravel 13/Blade memakai master mapping_ownergrup dan lookup aktif langsung untuk rekap Area/Branch. File pemilik sudah diterima dan diterapkan. Kolom **kelompok_ownergrup** adalah hasil mapping (fungsi yang dimaksud mapping_grup); tidak perlu menambah kolom duplikat pada master atau work_orders.

Checklist:
- [x] Periksa file sumber tanpa mengubahnya: header, nilai, duplikat dan formula.
- [x] Simpan 24 aturan referensi dengan hash sumber dan tambahkan kelompok DCS.
- [x] Preview, transaksi, lock inbound, audit dan penerapan idempotent.
- [x] Backup dan penerapan pada database aplikasi, verifikasi data WO tetap utuh.
- [x] Tes SQLite/MariaDB, lint/build serta UI desktop/mobile/light/dark.

## Kontrak sumber dan kolom

File mapping-ownergrup.xlsx, **Sheet1!A1:B25**, SHA-256 `fd00a4fa1fe70d23111957cb6ee184823849733593813b5d758769280220e0db`.

- A Owner Group → mapping_ownergrup.owner_grup, kunci unik sumber.
- B Mapping → mapping_ownergrup.kelompok_ownergrup, kelompok tujuan rekap.
- PK id_mapping_ownergrup; active dan timestamps tetap.
- Contoh: TIF PMDA → PMD. Nilai J pada upload BIMA tetap disimpan asli di work_orders.owner_grup; rekap melakukan lookup ke master tanpa menyimpan salinan kelompok di WO.

Ada **24 pasangan unik**, tidak ada formula, kunci kosong atau duplikat. Distribusi aturan: DISTRICT 12, PMD 4, SDI REGIONAL 3, ED REGIONAL 2, FF SO 3 1, FF SO 4 1, **DCS 1**. DCS tidak ada pada contoh gambar awal tetapi ada dalam file resmi, sehingga ditambahkan pada rekap dan filter. Aturan tidak ditentukan dari substring atau akhiran nama. Rule DISTRICT MAKASSAR tidak membuat cabang baru atau memindahkan assignment WO.

Referensi terverifikasi tersimpan di database/data/owner_group_mapping_20261021.json (nama snapshot mengikuti tanggal UTC saat pekerjaan dimulai). File asli tetap di lokasi pengguna dan tidak ditulis ulang.

## Penerapan

`php artisan olo:apply-owner-group-mapping --actor=superadmin --dry-run` menampilkan rencana tanpa menyimpan. Hapus --dry-run untuk menerapkan. Actor wajib aktif dan memiliki izin sync Area. Tidak ada API publik mutasi master baru.

Service memvalidasi seluruh referensi sebelum menulis, menolak nama kosong/spasi tepi/lebih dari 255 karakter, kategori di luar daftar, dan kunci duplikat. Apply memperoleh lock olo:work-order-inbound, menjalankan transaksi dan upsert berdasarkan owner_grup. ID rule lama dipertahankan; rule identik tidak mengubah timestamps. Rule tidak tercantum tidak dihapus otomatis. Reaktivasi/perubahan kelompok dicatat before-after pada audit owner_group_mapping.applied dengan actor, hash dan nama sumber.

Rekap/list memakai join dengan perbandingan binary yang sama untuk membedakan nama sumber secara konsisten di MariaDB dan SQLite. Master unik memastikan satu WO masuk satu kolom. Tidak memerlukan migration/backfill baru: migration 000015 sudah menyediakan kelompok_ownergrup. Source hash, timestamps, status/progress, perangkat, history dan cabang WO tetap. Setelah import BIMA mengubah Owner Group sumber, rekap berikutnya langsung mengikuti referensi aktif.

Rekap menampilkan seluruh order sesuai filter dashboard: Area default semua jenis; Branch default NEW INSTALL. Angka membawa filter branch/region/layanan/jenis dan owner_group ke list. Tidak menambahkan syarat outstanding/PS/tanggal. Kolom Belum dipetakan tetap masuk Grand Total. Header tabel dan colspan empty state mendukung tujuh kelompok.

## Hasil aktual

- Preview aktual: **24 aturan baru, 0 update, 0 identik**. Apply aktual: **24 inserted** dan audit tercatat atas superadmin.
- Backup terenkripsi diverifikasi sebelum apply: storage/app/private/maintenance/before-owner-group-reference-20261021-221123.json.enc, permission 0600; pemulihan memerlukan APP_KEY yang sama. Skrip pemeliharaan /private/tmp/olo-owner-reference-apply.php memanggil command di atas dan membandingkan semua tabel bisnis lama selain master/audit yang memang berubah.
- **15 tabel bisnis lainnya tetap identik**, termasuk seluruh data work_orders, source hash, timestamps, perangkat, history dan assignment. Hash workbook asli sebelum/sesudah tetap sama.
- Data aktual **3.368 WO**, **3.299 terpetakan**, **69 belum dipetakan**. Total: DISTRICT 135, PMD 2.903, ED REGIONAL 40, SDI REGIONAL 22, FF SO 3 108, FF SO 4 2, DCS 89, Belum dipetakan 69. Semua angka berasal dari database aktual.
- `php artisan test --compact --filter='OwnerGroupMappingReferenceTest|OwnerGroupAnalyticsTest'`: **6 tes / 171 assertions lulus**.
- `php artisan test --compact`: **151 tes / 3.033 assertions lulus**, SQLite.
- Tes MariaDB sementara terpisah melalui /private/tmp/olo-id-mysql-tests.php: **149 tes / 3.022 assertions lulus**; database tes dibersihkan. Dua tes snapshot Sheets legacy besar (`historical_size_batch`, `provided_bima_imports`) dikecualikan khusus MariaDB karena max_allowed_packet lokal 1 MiB, sama dengan baseline. Semua tes XLSX, mapping Owner Group dan migration lulus.
- Tes mencakup 24 aturan, seluruh kelompok termasuk DCS, idempotency/timestamps/ID tetap, read-only preview, perubahan/nonaktif diaktifkan kembali dengan audit, rule di luar referensi terjaga, validasi duplikat/kategori/panjang/kunci, actor nonaktif/branch/missing ditolak, lock inbound, semua nilai WO tidak berubah, rekap/list scoped dan total yang cocok.
- `vendor/bin/pint --dirty`, `vendor/bin/pint --dirty --test`, `git diff --check`, `npm run build`, `php artisan view:cache`: lulus.
- Browser aktual port 8011 diperiksa pada 1600/768/375 px, light/dark. Tabel memiliki tujuh kelompok, Belum dipetakan dan Grand Total, tanpa notice master kosong. Klik DENPASAR/DCS membuka 31 WO; Branch SEMARANG/Semua jenis menunjukkan 436 total, klik DCS membuka 18 WO dengan branch=9 dan owner_group=DCS. Tidak ada overflow halaman; tabel mobile tetap dapat digeser. Akun superadmin digunakan untuk dua POV, pembatasan tiga role cabang tercakup feature tests. Browser tidak mengubah WO. Mode kembali light dan viewport override di-reset. Screenshot tanpa data pelanggan: /private/tmp/olo-owner-reference-applied.png.

## File berubah pada penerapan referensi

- app/Services/OwnerGroupMappingReferenceService.php (baru).
- app/Console/Commands/ApplyOwnerGroupMappingReference.php (baru).
- database/data/owner_group_mapping_20261021.json (baru).
- app/Models/MappingOwnergrup.php — tambahkan DCS dari Excel.
- resources/views/owner-groups/summary.blade.php — colspan dinamis.
- tests/Feature/OwnerGroupMappingReferenceTest.php (baru); tests/Feature/OwnerGroupAnalyticsTest.php.
- AGENTS.md; dokumen ini.

Migration/environment/dependensi baru: **tidak ada**. Tidak mengubah file Excel asli, tidak melakukan commit/push, tidak mengklaim tes API Google/Telegram. Master dan rekap awal dibangun pada migration 000015; backup baseline sebelum tabel dibuat tetap tersedia pada storage/app/private/maintenance/before-mapping-ownergrup-20261021-134521.json.enc.

## Owner Group yang belum tercantum pada referensi

69 WO masih berada pada 11 nama sumber berikut. Tidak menebak kelompok dari awalan nama; perlu tambahan aturan pemilik untuk memetakan sisanya.

| Owner Group sumber | WO |
| --- | ---: |
| TIF NON FBB FFM DISTRICT JEMBER | 2 |
| TIF NON FBB FFM DISTRICT PEKALONGAN | 9 |
| TELKOM RWS 5 | 7 |
| TDE DELIVERY | 30 |
| TIF MSO-5 FULFILLMENT | 3 |
| TELKOM RWS 4 | 1 |
| TIF ED REGIONAL JATENG DIY | 9 |
| TIF NON FBB FFM DISTRICT LAMONGAN | 2 |
| TIF NON FBB FFM DISTRICT FLORES | 1 |
| TIF NON FBB FFM DISTRICT PURWOKERTO | 4 |
| TIF NON FBB FFM DISTRICT BONE | 1 |
