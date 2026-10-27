# Menjalankan kembali Dompis Prov — 27 Oktober 2026

## Checklist

- [x] Periksa perubahan workspace, port, working directory server dan listener antrean.
- [x] Selesaikan validasi Owner Group CSO/MSO, build dan cache tampilan.
- [x] Aktifkan server Dompis Prov pada port 8011 tanpa mengganggu proyek lain.
- [x] Verifikasi log startup dan dokumentasikan alamat akses serta batas pemeriksaan HTTP/database.

Server port 8000 adalah proyek `dompis-qe`; bukan Dompis Prov. Listener antrean Dompis Prov sudah aktif dengan `queue:listen` dan tetap dipertahankan. Proses proyek lain tidak dihentikan.

Tidak ada migration, kredensial, dependency atau perubahan data order pada pekerjaan pemulihan ini. Progress workspace yang sudah ada dipertahankan.

## Runtime

- Server Dompis Prov dinyalakan pada **http://127.0.0.1:8011**, host loopback. Log `storage/logs/local-web.log` mengonfirmasi `Server running on [http://127.0.0.1:8011]`.
- Listener antrean yang sudah ada dipastikan berasal dari direktori Dompis Prov. Proses port 8000 berasal dari dompis-qe dan tidak diubah.
- Penanda Vite `public/hot` yang menunjuk port 5174 diarsipkan secara reversible ke `storage/app/private/runtime/vite-hot-before-local-build-20261027.txt`. Aplikasi menggunakan aset hasil `npm run build`, tanpa bergantung pada server Vite. Menjalankan `npm run dev` kembali akan membuat penanda pengembangan baru.
- Pilihan kategori pada form Mapping Owner Grup kini menyertakan value eksplisit, konsisten dengan validasi bersama dan pilihan CSO/MSO.
- Permintaan izin pemeriksaan HTTP ke Dompis Prov ditolak; tidak dicoba ulang melalui alat/cara lain. Respons `/up`, login dan koneksi MySQL aktual belum diverifikasi pada pekerjaan pemulihan ini. Pemeriksaan kesehatan port 8000 sebelumnya bukan verifikasi Dompis Prov karena port tersebut merupakan proyek lain.

Tidak menjalankan migration, seeding, import aktual, perubahan akun/password atau pengiriman integrasi eksternal. Tidak ada env baru atau perubahan `.env`. Server lokal dapat dihentikan saat komputer/proses ditutup; jalankan kembali dengan `php artisan serve --host=127.0.0.1 --port=8011` bila diperlukan.

## Berkas

- `resources/views/master-data/mapping-form.blade.php`: value pilihan kategori eksplisit.
- `docs/OWNER_GROUP_CSO_MSO.md`: menyelesaikan dokumentasi validasi perubahan yang telah ada.
- `docs/RUNNING_LOCAL_20261027.md`: checklist, runtime dan batas verifikasi.
- `public/build/*`: aset hasil build (diabaikan Git).
- `public/hot`: dipindahkan ke backup privat sebagaimana di atas (file runtime, diabaikan Git).

Perubahan CSO/MSO pada model dan tes yang telah ada di workspace diteruskan dan diuji tanpa menimpa progress lain.

## Hasil pengujian

- Suite PHP penuh setelah perbaikan form: **304 tes / 26.599 assertions lulus**.
- **28 tes JavaScript lulus**.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, `git diff --check`: lulus.
- Pengujian CSO/MSO mencakup pilihan form, CRUD master, filter Work Order/prioritas, dashboard Owner Group, monitoring dan referensi mapping; tidak membuat mapping sumber baru pada database aktual.
