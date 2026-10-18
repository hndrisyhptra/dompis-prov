# Menghubungkan spreadsheet Bima

Spreadsheet yang ditetapkan: [COPAS WO PROV WIBS AREA3](https://docs.google.com/spreadsheets/d/1GPXyUiUGhMaducpZ1LRMNj99C9Su88WXeOTr-EDelPM/edit).

ID dan path key sudah dipasang di `.env` lokal. Setelah pemilik memberikan akses sharing, **pemeriksaan Google nyata dan preview antrean #5 berhasil**. Header BIMA_RAW sesuai 32 kolom, tetapi saat pemeriksaan terdapat **0 baris order**. Belum ada data WO yang diimpor. Isi private key tetap hanya berada pada file JSON di luar repository.

## Siapkan lokasi paste

Pada pemeriksaan awal, file hanya berisi Dashboard, OLO AREA-3, REKAP ENOM dan Boundry STO. Pemilik kemudian membuat BIMA_RAW, dan keberadaan/header-nya sudah diverifikasi lewat Google API. Tautan gid=0 membuka Dashboard; aplikasi selalu membaca nama tab BIMA_RAW.

1. Tambahkan tab baru bernama **BIMA_RAW**. Tab asli tetap dipertahankan.
2. Salin hasil export `OLO AREA-3` sebanyak 32 kolom **A:AF**, termasuk header pada baris pertama.
3. Set kolom **H** (NO TQ/SC/ID), **Q** (SID), dan ID/serial lain sebagai teks sebelum paste agar nol depan tetap ada.
4. Paste **values only** mulai **A1** pada BIMA_RAW. Jangan paste dashboard/rekap atau mengubah urutan header. Untuk penggantian export berikutnya, pastikan sisa order lama pada area paste ditangani oleh operator sesuai prosedur sumber; aplikasi tidak membersihkan BIMA_RAW.
5. Buat tab **MONITORING_OLO** terpisah jika ingin memakai tombol push. Aplikasi hanya mengelola rentang A:T pada tab tersebut; bukan tab paste sumber.

## Siapkan akses aplikasi Google

Jika file JSON service account sudah ada, cukup berikan path absolutnya di komputer ini. Jangan tempel private key atau isi JSON ke chat dan jangan simpan key di repository/public.

Jika belum ada:

1. Buka [Google Cloud Console](https://console.cloud.google.com/), pilih atau buat project organisasi, lalu aktifkan **Google Sheets API** pada APIs & Services → Library.
2. Buka **IAM & Admin → Service Accounts → Create service account**. Buat akun aplikasi OLO. Hak mengakses file Sheets diberikan lewat sharing file, bukan dengan memberi role project Owner/Editor.
3. Pada service account, buka **Keys → Add key → Create new key → JSON**. Simpan JSON pada lokasi privat di luar repository dan public web.
4. Catat email service account, lalu share spreadsheet sumber kepada email itu. Role **Editor** diperlukan untuk flow read BIMA_RAW + write MONITORING_OLO yang sudah diimplementasikan. Tidak perlu mengubah file menjadi publik.
5. Berikan hanya path absolut file JSON kepada pengelola aplikasi. Setelah dipasang sebagai `GOOGLE_APPLICATION_CREDENTIALS`, clear config dan restart worker.

Petunjuk kredensial mengikuti [dokumentasi resmi Google Workspace](https://developers.google.com/workspace/guides/create-credentials). Pembagian file secara langsung kepada email service account diperlukan untuk mengakses file tersebut.

## Pemeriksaan setelah akses siap

```sh
php artisan config:clear
php artisan olo:sheets:check
php artisan queue:restart
php artisan queue:work --queue=default --tries=5 --timeout=180 --sleep=1
```

`olo:sheets:check` membaca dan memvalidasi BIMA_RAW, melaporkan hitungan format/duplikat tanpa mencetak data pelanggan dan tanpa menulis Sheets/MySQL. Nonzero exit berarti setup/data masih perlu diperbaiki; hasil fake ditandai SIMULASI.

Setelah pemeriksaan nyata berhasil:

1. Isi mapping branch di web sesuai label kolom F dan STO.
2. Buka Sync Center → **Buat preview BIMA_RAW**. Gunakan job baru setelah perubahan spreadsheet/credential, karena job lama terikat konfigurasi koneksi sebelumnya.
3. Review new/changed/unchanged/rejected/conflict; konfirmasi baris valid untuk pull ke MySQL. Progress internal tidak ditimpa.
4. Monitoring push diuji terpisah setelah pull dan rekonsiliasi selesai. Sukses pull tidak berarti push atau Telegram sudah teruji.


## Riwayat pemasangan key sebelum sharing selesai

Pemilik telah membuat tab BIMA_RAW dan mengunduh JSON service account. Key untuk `wibs-area3@monitoring-354105.iam.gserviceaccount.com` ditemukan di folder Downloads dan path-nya sudah dipasang pada `.env` lokal sebagai GOOGLE_APPLICATION_CREDENTIALS. Isi private key tidak dicetak atau disimpan dalam source.

Otorisasi token service account berhasil, tetapi pembacaan spreadsheet melalui Sheets API menghasilkan **HTTP 403 / PERMISSION_DENIED**. Koneksi baca dan pull belum berhasil; tidak ada WO yang diimpor. Pemilik perlu memastikan spreadsheet dibagikan langsung kepada email service account tersebut, sebagai Editor untuk mendukung pull dan monitoring push. Setelah sharing selesai, jalankan ulang `php artisan olo:sheets:check`, lalu preview di web. File JSON harus tetap tersedia pada path yang dikonfigurasi; bila dipindahkan, perbarui GOOGLE_APPLICATION_CREDENTIALS.


## Hasil setelah spreadsheet dibagikan

`php artisan olo:sheets:check` lulus: header 32 kolom sesuai, baris valid 0, ditolak 0, duplikat 0. Akses baca Google sudah diuji nyata; uji tulis/push belum dilakukan.

Preview web #4 sempat ditolak worker yang masih membawa konfigurasi lama. Signal queue:restart dikirim dan worker terbaru dijalankan dengan tries=5, timeout=180, sleep=1. Preview baru **#5** selesai dengan state preview_ready, stats seluruhnya 0. Hasil dapat dilihat pada [preview lokal](http://127.0.0.1:8011/sync/jobs/5). Tidak ada konfirmasi pull pada snapshot kosong.

Langkah pemilik berikutnya: paste **data order tanpa header** sebagai values ke BIMA_RAW mulai **A2**, sebanyak A:AF. Header sudah ada pada baris 1. Jika menyalin seluruh export beserta header, mulai dari A1 dan pertahankan urutannya. Setelah itu buat preview baru; preview #5 adalah snapshot kosong dan tidak berubah otomatis ketika Sheets diedit.
