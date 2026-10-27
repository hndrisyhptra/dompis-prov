# Kelompok CSO dan MSO — 27 Oktober 2026

Checklist: inspect sumber kelompok dan validasi; tambahkan CSO/MSO pada daftar bersama; periksa master, filter dan rekap dengan regresi; dokumentasikan hasil.

`MappingOwnergrup::GROUPS` menjadi sumber pilihan master, validasi CRUD/import referensi, filter Work Order/prioritas, Distribusi Owner Group Area/Branch dan monitoring prioritas. Menambahkan CSO dan MSO di sumber bersama menjaga konsistensi seluruh pemakai.

Tidak memerlukan migration: `kelompok_ownergrup` sudah berupa varchar(80), bukan enum database. Tidak ada env/dependency baru. Tidak mengubah mapping lama atau file sumber Excel. Operator dapat memilih CSO/MSO melalui Master Data → Mapping Owner Grup; pengelompokan mengikuti nama Owner Grup sumber yang dipetakan persis. Monitoring prioritas menampilkan kolom kelompok yang memiliki order sesuai filter, seperti perilaku sebelumnya.

File diubah: `app/Models/MappingOwnergrup.php`, `tests/Feature/OwnerGroupAnalyticsTest.php`, `tests/Feature/OwnerGroupMappingReferenceTest.php`, `tests/Feature/MasterMappingTest.php`, dokumen ini. Saat melanjutkan pemulihan runtime, `resources/views/master-data/mapping-form.blade.php` dilengkapi value kategori eksplisit dan seluruh tes dijalankan kembali.

Hasil 27 Oktober: **304 tes PHP / 26.599 assertions**, **28 tes JavaScript**, build, cache Blade, Pint dan diff check lulus. CSO/MSO tersedia pada pilihan master/filter dan rekap yang memakai sumber kelompok bersama. Tidak mengisi mapping sumber CSO/MSO secara spekulatif. Server lokal/worker dan batas verifikasi aktual dicatat di [RUNNING_LOCAL_20261027.md](RUNNING_LOCAL_20261027.md).
