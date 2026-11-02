# Peta Route

Semua route memakai shell bersama dan History API. Detail yang ID-nya tidak ditemukan menampilkan empty state; hosting perlu SPA fallback ke index.html.

| Route | Isi / akses |
|---|---|
| `/`, `/dashboard` | KPI dihitung, filter Branch, progress, inbox sesuai role, volume dan aktivitas |
| `/project-plan` | Cari/status/Branch/tanggal, pagination, detail/edit |
| `/project-plan/new` | Wizard 6 bagian, draft, validasi, preview/pengesahan Admin/privileged |
| `/project-plan/:id` | Header, langkah lanjut, ledger per komponen, Parent/Root/source |
| `/project-plan/:id/edit` | Edit draft; PP Sah terkunci |
| `/boq` | Daftar BOQ terkait PP |
| `/boq/:projectId`, `/boq/:projectId/import` | Upload simulasi, matching, review, finalisasi; snapshot lanjutan tanpa impor ulang |
| `/ba-kh` | Daftar negosiasi/BA KH |
| `/ba-kh/new?project=:id` | Penugasan, SPH, penawaran, kesepakatan, dokumen, preview/sahkan |
| `/ba-kh/:id` | Detail wizard; Terbit/Sah terkunci; konteks Buat SPK |
| `/spk` | Daftar SPK |
| `/spk/new?deal=:id` | Form dari BA KH Sah, harga/volume read-only, preview dan pengesahan |
| `/spk/:id` | Snapshot, lampiran, commitment, aksi buat termin |
| `/termin` | Daftar pengajuan, approval/APM/pembayaran terpisah |
| `/termin/new?spk=:id` | Pilih SPK, cek eligibility, buat draft |
| `/termin/:id` | Aktual per komponen, Parsial/Final, historis, preview, submit; APM/payment Finance |
| `/inbox?term=:id` | Menunggu Saya, Sudah Diproses, Dikembalikan, Riwayat; tiga role approval |
| `/ba-pp` | BA PP dari Final approved |
| `/ba-pp/:id` | Rekonsiliasi kumulatif, bukti/sahkan, selected release, form lanjutan |
| `/master-khs` | Search/status, edit/version Admin, create/delete Superadmin, read semua role |
| `/laporan?tab=Outstanding+Payment` | Sembilan jenis laporan, filter, link sumber, ekspor CSV |
| `/pengaturan` | Profil, permission, workflow, activity, backup/reset |
| `/pengaturan?tab=users` | User CRUD hanya Superadmin |
| `/pengaturan?tab=activity` | Riwayat event dengan link record |

PP lanjutan dibuat melalui dialog `/ba-pp/:id`, bukan membuat header baru yang terputus dari sumber volume. Skenario memilih checkpoint dan menavigasi ke langkah yang relevan.
