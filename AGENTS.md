# AGENTS.md — OLO Command Center

## Read first
Read `PRD_OLO_Command_Center_v2.md` and `SKILLS.md` completely. These are project requirements and implementation discipline; resolve conflicts by preserving data integrity, authorizations, and the provided Bima/LHPP file contracts.

## Product constraints
- Area Jawa–Bali OLO, 11–25 branch; manual HD Area export Bima→paste Google Sheets `BIMA_RAW`; web Pull Sheets→MySQL; branch update progress/issues in web; web Push MySQL→Sheets `MONITORING_OLO`.
- Telegram material report must persist MySQL then automatically publish `MATERIAL_LOG`, with manual retry/sync support; web can view material and export template-compatible LHPP.
- Bima sheet `OLO AREA-3` has A:AF mapping; external ID in H `NO TQ/SC/ID`; `SID` in Q may differ.
- LHPP 32 columns A:AF; do not change header order or infer missing coordinates.
- MySQL owns operational statuses, issues, material, approval, audit; BIMA_RAW owns inbound Bima attributes only.

## Mandatory UI style
**Clean white first. Dark mode equally polished.** Primary `#315B8C`. NO gradients, NO AI-slop decoration, NO oversized rounded pills; use `rounded-md` baseline for cards/buttons/inputs, subtle borders, compact spacing, modern and responsive layouts.

## Agent workflow
1. Discover repo state, current Laravel patterns, tests, styles. Reuse existing convention.
2. Write implementation checklist with dependencies; start coding immediately in sensible stages.
3. Make minimal coherent diffs, preserve existing behavior, add tests.
4. Do not pretend credentials or integrations exist. Ship adapters with fakes plus `.env.example` and onboarding docs.
5. Run automated tests/build after each milestone; fix failures caused by your changes.
6. Document actual result, known risks, unresolved business approvals.

## Guardrails
No secret commit. No unscoped branch API. No destructive full-data replacement of BIMA_RAW. No silent overwrites of progress during pull. No row-number-only matching during push. No duplicate material on webhook retry. No unofficial Ready LHPP when validation fails. Use queue/job locks and audit. Never alter user source templates.

## Primary milestones
M1 Core/RBAC; M2 Bima Pull; M3 Branch Progress + Monitoring Push; M4 Telegram Material + Material Sheets; M5 LHPP validation/export; M6 UAT/production readiness.

## Latest owner direction — 18 Oktober 2026
Admin Branch memakai 15 kategori progress dan dashboard scoped cabang. Detail implementasi, definisi PS awal, dan alur terbaru material tersedia pada `docs/BRANCH_WORKFLOW.md`. Arahan pengguna terbaru untuk material adalah Telegram → Google Sheets → sync web; ini menggantikan arah material MySQL-first pada baseline PRD/SKILLS. Integrasi material belum dibangun/dikonfigurasi; pertahankan otorisasi, ID laporan stabil, idempotency, audit dan correction ledger saat melanjutkan.

## Latest PS rule and Area dashboard
Dashboard Area untuk Superadmin, Koordinator Area, Admin Area dan HD Area kini tersedia di `/dashboard`. PS mensyaratkan kategori progress web CLOSE DAN kolom N STATUS BIMA CLOSE/COMPLETE. PS harian memakai kolom P TGL PS; jangan menebak tanggal kosong atau menganggap STATUS FINAL/TASK BIMA sebagai STATUS. Aturan berlaku konsisten Area/Branch/list, menggantikan PS CLOSE-only dan opsi CLOSE ON DESK lama. Detail ada pada `docs/AREA_DASHBOARD.md`.


## Perubahan pemilik — 19 Oktober 2026: inbound Excel
Penerimaan work order saat ini: HD Area download BIMA → upload XLSX ke dompis prov → periksa mapping berdasarkan header aktual → preview → konfirmasi import ke MySQL. Ini menggantikan paste/pull BIMA_RAW sebagai default (WO_ORDER_SOURCE=excel); adapter Sheets lama dipertahankan sebagai opsi legacy. Daftar 29 kolom tujuan tidak menetapkan posisi template upload baru yang belum dikirim. Dua BRANCH disimpan terpisah sebagai sumber cabang dan referensi kedua; arti kolom kedua perlu dikonfirmasi bersama template. Identitas tetap NO TQ/SC/ID, bukan SID; import tidak menimpa progress/kendala/revisi cabang. STATUS ORDER memakai bima_status. PS dashboard tetap progress CLOSE dan status sumber CLOSE/COMPLETE, tanggal TGL PS. PS HARI INI/UMUR/KATEGORI UMUR dari file adalah referensi, bukan sumber KPI. Monitoring outbound dan LHPP tetap mengikuti kontrak masing-masing. Detail implementasi, batas dan pengujian: docs/EXCEL_WORK_ORDER_IMPORT.md.


## Koreksi pemilik — 20 Oktober 2026: satu BRANCH
Gunakan hanya satu atribut BRANCH pada import dan detail work order: source_branch_name. Ini menggantikan pemisahan dua BRANCH pada arahan 19 Oktober; total atribut tujuan menjadi 28. source_district dihapus melalui migrasi 000009; nilai lamanya diarsipkan dalam JSON sumber (_legacy_source_district), tanpa menimpa cabang atau assignment. Jika file memiliki beberapa header BRANCH, operator memilih satu kolom; usulan awal memakai BRANCH pertama. BRANCH / DISTRICT turunan tidak dipilih otomatis. Snapshot import v1 perlu diupload ulang karena kontrak berubah. Tidak ada perubahan environment atau kontrak LHPP/Sheets lama.


## Koreksi nama fisik kolom — 20 Oktober 2026
28 atribut sumber work_orders memakai nama snake_case sesuai daftar pemilik: tgl_order, bulan, tahun, regional, branch, sto, no_tq_sc_id, nama_olo, alamat, layanan, jenis_pekerjaan, bw, status_order, task_bima, tgl_ps, sid, keterangan, odp, sn, olt, port_olt, metro, port_metro, status_final, bulan_ps, ps_hari_ini, umur_hari, kategori_umur. Migrasi 000010 mengganti nama fisik lama, bukan hanya label UI. PK/FK dan kolom operasional/audit tetap. Identitas unik menjadi (source_system,no_tq_sc_id), SID tetap berbeda. PS memakai progress_category=CLOSE dan status_order CLOSE/COMPLETE, tanggal tgl_ps. Source branch teks adalah branch; relasi master model adalah assignedBranch. Adapter lama dan baru wajib memakai nama fisik terkini, snapshot kontrak lama ditolak. Detail: docs/WORK_ORDER_COLUMN_NAMES.md.


## Template export BIMA Work Orders — 20 Oktober 2026
Pemilik menetapkan mapping preset: A Date Created→tgl_order, D ORDER-ID/ORDER ID→no_tq_sc_id, G Service No.→sid, H Description→task_bima, I CRM Order Type→jenis_pekerjaan, L Product Name→layanan, M Address→alamat, O Customer Name→nama_olo, P Workzone→sto, AC District TIF→branch, AD Regional TIF→regional. Default membaca sheet Work Orders (header baris 1), bukan Pivot. Jangan mengisi status_order/tgl_ps atau atribut lain dari kolom yang belum dipetakan; nilai lama tetap terjaga. Tanggal export dapat memiliki milidetik. Baris format kosong bukan order. Preset dikenal hanya setelah seluruh header/posisi sesuai. Detail docs/BIMA_WORK_ORDERS_TEMPLATE.md.


## Status, umur dan perangkat — arahan pemilik 20 Oktober 2026
K Status dari Work Orders→status_bima. status_order adalah gabungan task_bima-status_bima (contoh Approval E2E Testing Manual-COMPLETE). Task/status berubah pada import dicatat atomik di bima_task_logs, tampil pada card Log Task BIMA; retry/identical upload tidak menambah log. Admin Branch dan HD Branch (role branch_hd) mengedit ODP/SN/OLT/PORT OLT/METRO/PORT METRO dalam scope cabangnya; override lokal dilindungi dari inbound berikutnya. Bulan/tahun otomatis dari tgl_order; umur kalender WIB sampai hari ini, kategori >28 "> 28 HARI", >14 "14-28 HARI", >7 "7-14 HARI", lainnya "0-7 HARI". Umur tidak ikut hash sumber. mapping_order master (PK id_mapping_order), FK dan klasifikasi STUCK/PS/CANCLWORK disediakan; master kosong sampai file aturan pemilik diterima, jangan menebak. PS dashboard tetap progress CLOSE + status_bima CLOSE/COMPLETE, tanggal tgl_ps. Ini menggantikan definisi status_order/umur lama. Detail docs/BIMA_STATUS_AND_DEVICES.md.


## Referensi mapping order diterima — 20 Oktober 2026
Pemilik memberikan MAPPING-ORDER.xlsx, Sheet1 A1:B179: 178 aturan STATUS-ORDER→MAPPING, kategori OPEN (106), STUCK (30), PS (14), CANCLWORK (28). OPEN ditambahkan ke master melalui migrasi 000012. Ikuti kunci persis; jangan menebak dari akhiran COMPLETE/CANCLWORK karena ada pengecualian eksplisit. Task kosong mengenali kunci -COMPWORK/-STARTWORK tanpa mengubah tampilan status gabungan yang sudah ada. Referensi tersimpan di database/data/order_mapping_20261020.json dengan hash sumber. Terapkan/backfill idempotent melalui olo:apply-order-mapping --actor=superadmin, memakai lock inbound, transaksi dan audit. Tidak mengubah progress, perangkat, timestamp WO, hash sumber, atau log task. Aturan di luar referensi tidak dihapus. KPI PS tetap progress CLOSE + status_bima CLOSE/COMPLETE; mapping_order PS hanya klasifikasi. Detail docs/ORDER_MAPPING_REFERENCE.md.


## Penyederhanaan detail Work Order — 20 Oktober 2026
Label menu/list cabang menjadi Work Order. Card Kendala & tindak lanjut dihapus dari detail; catatan kendala baru/tindak lanjut ditulis melalui progress KENDALA. Kendala lama tetap tersimpan dan dapat dilihat dalam accordion di form progress. Penyelesaian kendala dikonfirmasi lewat checkbox saat memilih progress setelah kendala; CLOSE tetap mensyaratkan kendala selesai. Form Data perangkat dibuka melalui tombol dan membuka kembali pada error validasi; Detail sumber order berupa accordion tertutup. Log Task BIMA menyimpan perubahan task/status/atribut inbound beserta nilai sebelum-sesudah, actor dan waktu detik dalam WIB. Diff terenkripsi (000013), riwayat lama tidak direkayasa; hash identik/retry tidak menambah log, perubahan progress/perangkat tetap pada audit masing-masing. Detail docs/WORK_ORDER_DETAIL_FLOW.md.


## Alur operasional Area → Branch — 20 Oktober 2026
Alur UI: HD/Admin Area download BIMA→upload/mapping/preview/konfirmasi di dompisprov→satu master WO→terlihat otomatis oleh TL/HD/Admin Branch pada scope branch yang ditugaskan→update progress/kendala/CLOSE→dashboard Area/Branch. Tidak membuat salinan WO per user. Antrean memiliki hitungan sesuai filter dan kartu mobile; tindakan Progress/Kendala/Close membuka form yang sesuai. Close cabang, menunggu BIMA, dan PS terkonfirmasi tetap berbeda; PS harian membutuhkan tgl_ps. Dashboard Area menampilkan handoff distribusi, dashboard Branch fokus tindak lanjut. Tidak ada migration/env baru pada tahap UI ini. Detail docs/AREA_BRANCH_OPERATIONS_UI.md.

## Potensi PS — 21 Oktober 2026
Dashboard Area/Branch memiliki kartu Potensi PS yang membuka antrean potential_ps. Delapan kombinasi status_order dari pemilik disimpan pada WorkOrder::POTENTIAL_PS_STATUSES dan satu scope untuk seluruh hitungan/list. Mengikuti scope/filter aktif, tanpa syarat progress tambahan. Aturan PS terkonfirmasi tetap. Detail status dan validasi: docs/POTENSI_PS.md.

## New Order — 21 Oktober 2026
Tujuh kombinasi Review Order TSQ yang ditetapkan pemilik memakai WorkOrder::NEW_ORDER_STATUSES dan scopeNewOrder. Kartu Area/Branch membuka list queue=new_order dengan filter/cakupan yang sama. Klasifikasi berdasarkan status_order terkini, berbeda dari order hari ini dan operational_status NEW; import tidak menimpa progress. Detail docs/NEW_ORDER.md.

## Owner Group dan penghapusan widget — 21 Oktober 2026
J Owner Group pada preset Work Orders v3→work_orders.owner_grup (migration 000014, nullable). Ikut hash sumber dan log; mapping yang tidak dipilih tidak menimpa nilai lama. Kartu/tab PS terkonfirmasi dihapus, indikator detail diganti Owner Group. PS hari ini/grafik dan aturan perhitungan harian tetap. Detail docs/OWNER_GRUP.md.

## Rekap mapping Owner Group — 21 Oktober 2026
Master mapping_ownergrup (PK id_mapping_ownergrup, owner_grup unik, kelompok_ownergrup, active) disediakan kosong melalui 000015. Kelompok dari contoh: DISTRICT, PMD, ED REGIONAL, SDI REGIONAL, FF SO 3, FF SO 4. Jangan menebak aturan dari substring; file Excel pemilik belum diterima. Rekap Area/Branch memakai lookup aktif langsung dan scope/filter yang sama; kosong/tidak dikenal/nonaktif masuk Belum dipetakan, tetap pada Grand Total. Klik angka membuka filter owner_group pada Work Order. Tidak mengubah sumber/assignment/progress atau membuat salinan WO. Detail docs/MAPPING_OWNERGRUP.md.

## Referensi Owner Group diterima — 22 Oktober 2026
mapping-ownergrup.xlsx, Sheet1 A1:B25: 24 aturan unik Owner Group→Mapping. Kolom kelompok_ownergrup pada master sudah menjalankan fungsi mapping_grup; jangan menambah kolom duplikat pada WO. File menambahkan DCS (1 aturan), selain DISTRICT 12, PMD 4, SDI REGIONAL 3, ED REGIONAL 2, FF SO 3 1 dan FF SO 4 1. Referensi dan hash tersimpan dalam database/data/owner_group_mapping_20261021.json. Terapkan dengan olo:apply-owner-group-mapping --actor=superadmin (--dry-run untuk preview), lock inbound, transaksi/audit, tanpa perubahan WO. Rule di luar file tidak dihapus; nama belum dikenal tetap Belum dipetakan. Detail dan 11 nama sumber yang masih membutuhkan aturan ada pada docs/MAPPING_OWNERGRUP.md.


## Status Progress dan definisi PS — arahan pemilik 23 Oktober 2026
Tabel Work Order memakai Status Progress (progress_category cabang saja, tanpa baris BIMA/timestamp pembaruan) dan Status Bima (status_order gabungan penuh, menggantikan kolom Kendala). Aksi berupa ikon kecil berlabel/tooltip Update Progress, Kendala, Close; tetap mengikuti otorisasi dan membuka form sebelum menyimpan. Close hanya selesai instalasi lapangan. PS wajib progress CLOSE DAN salah satu dari 14 kombinasi utuh pada App\Support\PsEligibility::STATUSES. Ini menggantikan aturan lama status_bima CLOSE/COMPLETE umum; mapping_order PS tidak cukup. Scope query, instance, list, analytics Area/Branch dan PS harian menggunakan definisi yang sama. Legacy COMPWORK dengan task kosong dan raw COMPWORK dikenali sebagai -COMPWORK saat membaca tanpa mengubah data sumber. Tanggal PS harian tetap tgl_ps; tidak ditebak dari waktu Close. Tidak menambah widget PS terkonfirmasi, migration, backfill, atau env. Detail file, hasil tes dan batas implementasi: docs/WORK_ORDER_STATUS_PROGRESS.md.


## CLOSE otomatis berdasarkan BIMA — arahan lanjutan pemilik 23 Oktober 2026
Status Bima yang cocok dengan 14 kombinasi PS pada PsEligibility kini secara eksplisit boleh menyesuaikan progress menjadi CLOSE, termasuk order historis Juli–Agustus. Ini adalah pengecualian baru terhadap larangan overwrite progress inbound; status lainnya tetap tidak boleh menimpa progress. Terapkan pada input baru/berubah/identik, lock/transaksi, revision, event sebelum-sesudah dan audit beralasan otomatis, tanpa auto-reopen saat status BIMA berubah lagi. Kendala tetap tersimpan dan tidak diselesaikan otomatis; tidak memberi verifikasi Area atau mengarang tanggal selesai/tgl_ps. Backfill via olo:reconcile-bima-close --actor=superadmin (--dry-run untuk preview), memakai lock inbound. Rekap Area/Branch berdasarkan bulan TGL ORDER dengan tautan order_month dan queue=complete; cohort bulan order berbeda dari bulan kejadian PS. PS harian tetap tgl_ps. Detail hasil nyata dan batas: docs/BIMA_AUTO_CLOSE.md. Tidak ada migration/env baru.


## Penyederhanaan menu dan istilah Branch — arahan pemilik 23 Oktober 2026
Menu dan tautan UI Branch & Mapping dihilangkan. Data referensi distribusi dan otorisasinya tetap dipertahankan untuk routing WO otomatis. Panel push MySQL→Google Sheets dihapus dari seluruh mode Sync Center; route/action push tidak tersedia, commit/retry arsip push ditolak dan worker menghentikan push lama tanpa menulis Google. Riwayat tetap read-only. Istilah cabang pada UI/petunjuk/validasi menjadi Branch, tanpa mengganti nama aktual/ID/kontrak file sumber. Ini menggantikan requirement publikasi monitoring aktif sebelumnya; inbound Excel, adapter pull legacy, distribusi otomatis dan progress tetap. Tidak ada migration/env baru. Detail file dan tes: docs/NAVIGATION_BRANCH_SYNC.md.


## Perbaikan upload Excel — 23 Oktober 2026
Batas aplikasi tetap XLSX 10 MiB. Artisan serve proyek harus memakai App\Console\LocalServeCommand yang menyisipkan upload_max_filesize=10M dan post_max_size=12M pada child PHP; php.ini global tidak diubah. UI memakai batas efektif PHP/aplikasi dan memeriksa ukuran sebelum submit. Error PHP upload serta HTTP 413 ditampilkan dalam bahasa Indonesia (ukuran, upload terputus, penyimpanan sementara), tanpa menyamakan semua kegagalan dengan ukuran. Apache mod_php/FPM mempunyai konfigurasi per direktori tersendiri. Tidak ada migration/env baru. Detail pengujian dan batas verifikasi unggah browser: docs/UPLOAD_LIMIT_FIX.md.


## Pemulihan mapping BIMA — 23 Oktober 2026
Export Work Orders terbaru memakai header D ORDER. Kenali sebagai alias ORDER ID/ORDER-ID hanya jika seluruh 13 header/posisi preset sesuai; jangan mengartikan ORDER generik sebagai identitas WO pada sembarang file. Pasangan utama tetap A/D/G/H/I/J/K/L/M/O/P/AC/AD ke tgl_order/no_tq_sc_id/sid/task_bima/jenis_pekerjaan/owner_grup/status_bima/layanan/alamat/nama_olo/sto/branch/regional. Deteksi ulang upload tertunda template=NULL pada GET, tanpa mengubah snapshot/progress/order. Tampilkan 13 pasangan utama dan nama SQL; tambahan opsional dalam accordion, derived tetap dihitung Laravel. Tombol Gunakan mapping BIMA hanya menampilkan default sebelum konfirmasi; pilihan tersimpan tetap dihormati tanpa reset eksplisit. Tidak ada migration/env baru. Detail docs/BIMA_MAPPING_RESTORE.md.


## Master Data — arahan pemilik 23 Oktober 2026
Menu baru Master Data mengelola Branch, Region dan Service Area (mapping STO): list/filter, tambah, edit dan konfirmasi hapus. Sesuai perluasan akses terbaru dari pemilik, role super_admin, area_coordinator, area_hd dan area_admin aktif dapat melihat menu serta memakai seluruh endpoint CRUD Master Data. Role Branch dan akun nonaktif tetap ditolak. Perluasan ini menggantikan batas awal HD/Admin Area saja. Menggunakan tabel/PK/SoftDeletes yang ada, tanpa migration/env baru. Validasi parent aktif/konsisten, kode unik termasuk arsip, audit, transaksi/row lock, versi edit dan lock inbound. Hapus ditolak bila masih ada referensi, tanpa cascade/memindahkan WO atau user; perubahan kode/mapping STO terpakai dilindungi. Sumber BIMA/progress WO tidak ditimpa. Referensi routing legacy tetap dipertahankan. UI Master Data memakai navigasi tiga master, filter ringkas, aksi ikon dengan label dan tooltip hover/fokus keyboard (Escape menutup), serta error per kolom. Detail hasil tes dan batas verifikasi visual: docs/MASTER_DATA.md.


## Master Data per submenu dan CRUD mapping — arahan lanjutan 23 Oktober 2026
Master Data adalah parent menu dengan submenu Region, Branch, Service Area, Mapping Order dan Mapping Owner Grup; ini menggantikan navigasi tiga tab. Tetap untuk super_admin/area_coordinator/area_hd/area_admin aktif, backend dan UI sama. Dua mapping memakai tabel yang sudah ada, kunci sumber case-sensitive/persis (hanya trim), kategori Order OPEN/STUCK/PS/CANCLWORK serta kelompok Owner Grup dari MappingOwnergrup::GROUPS. Simpan memakai lock inbound/transaksi/versi/audit. Perubahan Mapping Order merekonsiliasi hanya id_mapping_order/mapping_order pada WO BIMA yang terkait; tidak menimpa sumber, progress, perangkat, revision, timestamp, log BIMA atau definisi PS. Owner Grup langsung mengikuti lookup aktif saat rekap. Mapping terpakai tidak boleh dihapus/diubah kuncinya; gunakan Nonaktif atau tambah aturan baru. Mapping belum terpakai boleh dihapus dengan konfirmasi/audit (tabel mapping tidak memiliki SoftDeletes); tiga master lokasi tetap soft delete. Aksi tabel ikon kecil berlabel aksesibel dan tooltip Edit/Delete. Tidak ada migration/env baru. Hasil/file/batas verifikasi: docs/MASTER_DATA_SUBMENUS.md.


## Halaman aksi Work Order terpisah — arahan pemilik 23 Oktober 2026
Kolom Aksi tabel desktop/kartu mobile selalu menyediakan Detail Order (informasi order, referensi BIMA, perangkat, log/riwayat). Update Progress, Kendala dan Close membuka halaman mandiri /work-orders/{id}/actions/{mode}, menggantikan tiga mode form yang digabung pada detail. Progress: OGP BIMA, RESUBMIT AO (label Resubmit AO), SURVEY, JT, INSTALASI. Kendala: CANCEL (label Cancel Order), HOLD, BUTUH QE (label Butuh QE), PERIZINAN (label Perizinan), dengan detail wajib. Close hanya CLOSE, tetap instalasi lapangan selesai dan belum tentu PS. Validasi whitelist per endpoint, policy scope Branch, revision/transaksi/audit dan perlindungan terminal wajib. Kendala mode baru menyimpan issue/tindak lanjut, Hold/QE/Perizinan masuk kelompok Kendala pada analytics; Cancel tetap terpisah dan terminal. Penyelesaian issue wajib sebelum Close. Halaman lama ?action= diarahkan ke halaman baru; kategori historis dan endpoint legacy tetap untuk kompatibilitas/riwayat, tanpa backfill atau reset status sumber. Tidak ada migration/env baru. Detail/file/hasil tes: docs/WORK_ORDER_ACTION_PAGES.md.
