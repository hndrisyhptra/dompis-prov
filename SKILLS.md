# SKILLS.md — OLO Command Center Codex Operating Skill

## Identity and objective
You are the senior full-stack Laravel engineer implementing **OLO Command Center v2** for 11–25 OLO branches, <100 new WO/day, source export Bima pasted to Google Sheets. Read `AGENTS.md` and `PRD_OLO_Command_Center_v2.md` before touching code. Implement real working modules, not static mockups or arbitrary fake figures.

## Mandatory preflight
1. Inspect repository first: framework version, directory structure, existing migrations/models/roles/routes/views, package constraints, tests, `composer.json`, `package.json`, `.env.example`. Do not blindly overwrite existing business functions.
2. Summarize existing app architecture and migration risks in a short implementation note.
3. Implement one end-to-end vertical slice at a time: migrations→models→service→controller/action→UI→tests→docs.
4. Never put credentials, PII, real Google service keys or bot tokens in code, fixtures, logs or git; use `.env.example` placeholders.
5. Never claim external Google/Telegram integration is operational before real end-to-end tests; build local fakes and feature tests for unavailable credentials.

## Source-of-truth requirements
- Google `BIMA_RAW` is inbound Bima reference only, exact 32 columns A:AF. Key at H `NO TQ/SC/ID` (not automatically Q `SID`).
- MySQL owns operational status, issues, assignments, material, LHPP details and approval.
- `MONITORING_OLO` & `MATERIAL_LOG` are outbound projections. Never read back their manual changes as authoritative.
- `/pull` must be idempotent upsert by `(source_system, external_order_id)` and NEVER overwrite branch operational progress.
- `/push` must not touch BIMA_RAW, must be key-safe if output row sorting occurred, and show queue job state.
- Telegram inbound report: authenticated/whitelisted/idempotent; material report saved MySQL first, async publish Sheets with retry/outbox.
- LHPP output exactly 32 columns A–AF and headers from `LHPP OLO.xlsx`; preserve text IDs and validate coords, duplicates and required fields.

## UI design skill (enforce strictly)
**Design intent:** clean white, modern, pragmatic operational dashboard, information hierarchy like serious enterprise SaaS; NO AI slop.
- Accent primary `#315B8C`; hover `#274B75`, soft `#EBF1F7`.
- Light: white #FFFFFF, canvas #F7F9FC, text #17212E, border #DCE3EA.
- Dark: canvas #0F1722, surface #17212D, border #2A3A4A, text #E7EDF4; contrast accessible.
- **NO GRADIENT**: prohibit `bg-gradient-*`, `linear-gradient`, conic/radial gradients, glossy treatment.
- **Rounded-md** is preferred component radius (`6px`). No oversized radii, unnecessary pills, large blurs, glass cards or neon effects. Compact buttons, purposeful whitespace, fine borders.
- Primary buttons solid #315B8C, white text; secondary neutral outline; danger for destructive action. All buttons loading/disabled/error/confirmation where relevant.
- Tailwind `dark` variants on every styled surface, typography, border, hover/focus/empty/skeleton state. Theme toggle persists preference; honor system default.
- Responsive: sidebar desktop, collapsible drawer mobile, cards stack, tables horizontally scroll (not squeezed), stable actions; test 375/768/1280 px.
- Typography balanced (base 14–15px), page title clear, small uppercase only for tiny metadata; avoid huge hero banners, unnecessary illustration, emoji; Lucide icons used sparingly.
- Dashboard: prioritize date, Area/branch filter, last-sync freshness, real KPIs, exceptions/action queue, recent activity, compact dense table.
- Never show random demo KPIs in production. Use explicit empty states/loading state.

## Architecture skill
- Laravel services: `BimaSheetPullService`, `MonitoringSheetPushService`, `MaterialTelegramIngestService`, `MaterialSheetPublisher`, `LhppExportService`.
- External adapters behind interfaces + test fakes; `GoogleSheetGateway`, `TelegramGateway`.
- DB transactions, unique constraints and idempotency keys; queue jobs for Sheets/Telegram material publishing and LHPP generation.
- Use Laravel policies and branch scopes on EVERY API/query/export.
- Revision/optimistic lock for simultaneous status updates; immutable event logs for material corrections.
- Keep source-specific enum/code mapping separate from internal normalized workflow.
- Google API failure/retry has bounded exponential backoff; heartbeat/sync log shows failure reason safely.
- Derived aging, daily completed and LHPP quantities are calculated server-side; do not trust spreadsheet formula outputs.

## Build order
1. Auth/RBAC, branch, users, layouts and design tokens + tests.
2. Work orders schema, Bima mapping 32 cols, read+validate+preview+pull queued job, sync logs.
3. Branch progress, issue module and event timeline, Area dashboard, monitoring outbound push.
4. Telegram identity/permissions, structured material workflow, ledger, review, automatic Sheets publisher and retries.
5. LHPP data completeness/validation, template-preserving export, export log, reconciliation UAT.
6. Pilot QA, deployment/security, documentation and ops.

## Every feature's Definition of Done
- Migrations work and reversible where practical; fixtures use safe anonymized data.
- Unit/feature/integration tests including unauthorized branch, duplicate ID and retry.
- UI supports light/dark, mobile and keyboard.
- No gradient or giant radius; styling adheres to palette.
- Real loading/error/empty states; actionable controls perform real actions.
- Form validation/authorization/event audit implemented.
- Run lint/tests/build. If external credentials unavailable, report stub/fake pass separately.
- Update docs with changed files, run commands, environment keys, migrations and known limits.

## Priority hazards
- Export Bima sample has formulas AC/AE/AF; these are NOT authoritative.
- Sample LHPP has duplicated SID and malformed lat/long; do not guess corrections.
- Data type H, Q, NIK, SN must be string-preserving.
- Multiple job types in Bima; default PSB dashboard should explicitly filter `NEW INSTALL` unless owner chooses different policy.
- No auto-cancellation for WO absent from newest BIMA_RAW.

## What to output per Codex iteration
Give: short design decisions, changed file list, exact commands run, tests passed/failed, screenshots or UI verification if available, migration notes, pending owner decisions. Continue implementing scope if possible; do not stop after planning alone.


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


## Order Prioritas — arahan pemilik 24 Oktober 2026
Menu Order Prioritas memakai bookmark dari No WO di Work Order. Pilihan Area adalah daftar bersama super_admin/area_admin/area_hd dan terlihat oleh TL/HD/Admin Branch sesuai assignment WO. Pilihan lokal Branch adalah daftar bersama tim Branch terkait, tidak boleh muncul dalam daftar, count, eager load, detail atau indikator Area; tidak bersifat personal per user. Koordinator Area/Admin Material/Bot tidak mendapat izin baru implisit. Scope ditentukan server; tidak mempercayai scope/id branch dari client. Satu WO bisa punya pilihan Area dan Branch, tetapi monitoring Branch deduplikasi satu baris. Model WorkOrderPriority/tabel work_order_priorities (PK id_work_order_priorities, FK bernama unik) terpisah dari sumber/progress; unique id_work_orders+scope_key, UUID token, transaksi/row lock, audit dan idempotency. Hapus hanya bookmark scope actor, token stale ditolak. Prioritas Area mengikuti Branch WO saat ini; prioritas lokal lama tidak ikut dipindah/ditampilkan ke Branch baru jika assignment berubah. Semua jenis pekerjaan ditampilkan default; Close tidak menghapus bookmark otomatis. Status/kendala/umur/actor/waktu real dibaca ulang saat buka/Perbarui; tidak mengklaim realtime push. Migration aditif 000016, tanpa env baru. Detail file, tes dan hasil migration: docs/PRIORITY_ORDERS.md.


## Progress otomatis saat import BIMA — arahan pemilik 24 Oktober 2026
Selain 14 aturan CLOSE/PS yang sudah ada, pemilik mengizinkan progress otomatis SURVEY untuk 22 kombinasi status_order dan INSTALASI untuk 37 kombinasi persis pada App\Support\BimaProgressMapping. Terapkan saat commit untuk sumber baru/berubah/identik (Excel dan adapter pull legacy), bukan saat upload/preview. Ini memperluas pengecualian overwrite progress inbound hanya untuk daftar eksplisit; status di luar daftar tidak menimpa progress. CLOSE/PS tetap memakai aturan lama dan lebih dahulu; SURVEY/INSTALASI tidak membuka kembali kategori terminal CLOSE/CANCEL/CLOSE ON DESK atau status COMPLETED/CANCELLED. Setiap perubahan menambah revision, waktu, event progress sebelum-sesudah dan audit dengan job/actor/status sumber; tidak menghapus/mengganti log Branch, log task BIMA, kendala/tindak lanjut, perangkat, tanggal selesai/PS atau verifikasi. Jika kategori sudah sama, tidak membuat event/revision duplikat. Hash sumber tidak memuat progress; hitungan Tetap adalah sumber identik, bukan jaminan progress tak berubah. Tidak ada backfill massal, migration atau env baru. Detail: docs/BIMA_AUTO_PROGRESS.md.


## STO/Branch mengikuti import terbaru — arahan pemilik 24 Oktober 2026
Pemilik mengizinkan perubahan STO/BRANCH dan penugasan WO berdasarkan upload BIMA terbaru yang dikonfirmasi. Ini menggantikan penolakan "Branch sumber/mapping berubah". Satu WO tetap diidentifikasi oleh source_system+no_tq_sc_id; perbarui sumber dan id_branches, jangan membuat salinan. Branch sumber yang dikenali melalui alias legacy atau nama/kode master aktif didahulukan atas perbedaan kepemilikan STO; STO menjadi fallback jika Branch tidak dikenali. Larangan master STO/Region tidak aktif/arsip atau relasi master tidak konsisten tetap, tanpa mengubah master/user otomatis. Perubahan penugasan meskipun hash sumber identik harus diklasifikasikan berubah; perubahan sumber Branch/STO dicatat pada log BIMA dan audit, pindah id_branches menambah revision dan event penugasan tanpa reset progress/kendala/perangkat/tanggal/verifikasi. Aturan auto progress dari daftar pemilik tetap terpisah. Branch lama kehilangan akses, Branch tujuan memperoleh akses sesuai assignment user yang sudah ada; bookmark Area mengikuti order, bookmark lokal Branch lama tetap tersimpan dan tidak berpindah. Konflik mapping/sumber/assignment yang berubah setelah preview tetap meminta preview baru. Tidak menjalankan ulang job lama/production import otomatis. Tidak ada migration/env baru. Detail docs/BIMA_LATEST_ROUTING.md.


## Riwayat dan dashboard ringkas — arahan pemilik 25 Oktober 2026
Sync Center menampilkan 5 job terakhir, urutan id_sync_jobs terbaru, dengan tombol Detail riwayat ke /sync/history (semua job, pagination 20, otorisasi sync tetap). Matrix Area menjadi On Going (Survey, OGP BIMA termasuk RESUBMIT AO, Instalasi, Kendala, JT, Lainnya) dan Complete (Cancel, CLOSE). CNDC kini masuk Lainnya; filter URL legacy cndc tetap tersedia. New Order Hari ini sebelum PS Hari ini/Total Order berarti TGL ORDER hari ini WIB; widget New Order berdasarkan 7 status TSQ tidak diubah. PS tetap aturan 14 kombinasi+progress CLOSE dan TGL PS untuk harian. Matrix dan Distribusi Owner Group dikelompokkan Region, default collapsed, menampilkan agregat Region dan Grand Total; buka Region untuk Branch. Angka tabel Area/Branch (termasuk subtotal, total, nol, jenis pekerjaan, cohort dan PS harian) membuka dialog daftar WO berpagination dengan aksi Detail langsung ke order; query memakai validasi/scope/policy Work Order yang sama, tanpa mutasi/salinan sumber. Sediakan loading/error/retry/empty, abort request lama, fokus keyboard dan fallback tautan penuh. Tanpa Region dan belum ditugaskan memakai filter terpisah agar tidak kehilangan cakupan saat digabung tanggal/owner/progress. Tidak ada migration/env baru. Detail docs/DASHBOARD_DRILLDOWNS.md.


## RAB/BOQ opsional untuk JT — arahan pemilik 25 Oktober 2026
Progress JT menyediakan Simpan JT & Upload RAB/BOQ atau Simpan JT · Skip dulu. Progress tersimpan terlebih dahulu; BOQ opsional dan dapat diunggah belakangan jika order sedang/pernah JT (bukti event lama/baru JT), termasuk setelah progress berubah. Detail WO menyediakan Upload/Revisi dan Preview BOQ dalam scope yang sama. Master designator_types dari SQL pemilik dipakai; designator_categories/kolom category tidak digunakan sesuai koreksi terbaru. designators, packages, designator_package_prices memakai kolom/ID sumber (kecuali kategori); FK users menyesuaikan id_users lokal, actor legacy tidak ditebak. qe_boqs/qe_boq_items/qe_boq_histories disesuaikan ke id_work_orders dan PK/FK bernama spesifik; tidak mengimpor BOQ proyek QE lama karena qe_lop_id tidak memiliki padanan. Import awal XLSX/CSV menggunakan DESIGNATOR, QTY dan TYPE opsional (mengikuti master tipe aktif); harga dari paket pilihan, snapshot desimal presisi, pembulatan half-up 2 digit per item. Invalid/duplikat/formula/price kosong ditolak seluruh file. Satu header BOQ aktif per WO, revision+lock+audit, soft-delete item versi lama dan history append-only menunjuk item immutable, file privat+SHA/content hash dan retry idempotent. BOQ aktif menyimpan grand_total sebagai dasar menu Revenue berikutnya, bukan pengakuan Revenue/PS/approval. Upload BOQ tidak menimpa progress/perangkat/kendala/sumber BIMA; import BIMA tidak mengubah BOQ. Migration aditif 000017, tanpa env/kredensial baru. Detail docs/JT_BOQ_IMPORT.md.


## Template DOMPIS dan KML — arahan pemilik 25 Oktober 2026
Import BOQ memakai template asli Paket 5/10: sheet BoQ, header 6–7, designator B, volume G, item 9–732; deteksi paket dari E6, kosong/nol dilewati, footer diabaikan, input volume formula/error/negatif ditolak. Harga tetap master dengan snapshot presisi; 418 harga Paket 5 yang belum ada dilengkapi dari template tanpa overwrite. Tiga harga Paket 10 berbeda (template Rp1/master Rp0) tetap mengikuti master sampai keputusan pemilik. KML opsional per WO, upload scope progress dan preview/download scope view; file privat, versi append-only, SHA/revision/lock/audit, validasi XML NONET tanpa DTD/entity/NetworkLink, geometri Point/LineString/Polygon/MultiGeometry, max5MB/200 objek/10.000 koordinat. Leaflet lokal via Vite, OSM tile internet dengan atribusi, loading/empty/error/retry serta white/dark. Tidak menimpa progress/BOQ/BIMA, tidak menebak koordinat, tidak mengubah sumber asli. Migration000018/000019; env provider peta opsional tanpa API key. Detail docs/BOQ_TEMPLATES_KML.md.


## Revenue Overview dan volume formula — arahan pemilik 25 Oktober 2026
Contoh BOQ terbaru menggunakan rumus G (ROUND, referensi G9:G732, aritmetika/persen). Ini menggantikan larangan seluruh formula volume pada template DOMPIS: hitung subset aman di server dengan rational/decimal, jangan percaya cache/subtotal Excel atau menjalankan eval/macro/link. Angka/rumus di luar subset harus menghasilkan validasi baris, bukan fallback cache. CSV/XLSX standar tetap angka langsung. Hasil contoh 11 item: material1010302, jasa770401, total1780703. MATERIAL masuk material_total; JASA/INSTALASI/PENGUKURAN/DISMANTLE labor_total; volume per satuan dan kelompok terpisah, snapshot harga/histori/hash/revisi tidak ditimpa. Migration000020 menambah/backfill dua total dari snapshot item aktif tanpa reprice. Revenue Overview /revenue untuk semua role manusia aktif memakai visibleTo/user_branches yang sama (Area penuh, Branch/TL/Admin Material hanya penugasan aktif; Bot ditolak). Hanya BOQ aktif dihitung sekali, rincian PS/belum PS/Cancel dengan definisi PS yang berlaku; Total termasuk Cancel, belum invoice/pembayaran/pengakuan akuntansi. Filter TGL ORDER/Region/Branch/paket/kelompok/No WO-SID, rekap Branch/bulanan dan daftar scoped dengan Detail/Preview BOQ. Tidak ada env/credential/dependency baru. Detail docs/REVENUE_OVERVIEW.md.


## Dompis Prov, login dan grafik cohort — arahan pemilik
Nama UI/sidebar/mobile/title/login menjadi Dompis Prov WIBS Area Jawa Bali. Login white/dark responsif tanpa tombol tema, toggle tampilkan/sembunyikan kata sandi juga tersedia pada form User Management. Kata sandi create/update cukup minimal8 karakter, tanpa wajib huruf besar/kecil/angka; confirmed/max255/otorisasi/editkosong tetap. Tidak reset user existing. Penyelesaian berdasarkan bulan order memakai grafik garis 4 seri Total WO/Progress CLOSE/PS/Outstanding, bulan terlama→terbaru, titik membuka modal scoped seperti tabel, data numerik tetap dalam accordion. Definisi metrik/query/scope tidak berubah. Tidak ada migration/env/dependency baru. Detail docs/DOMPIS_BRANDING_LOGIN_CHART.md.


## Material Telegram langsung ke database — arahan pemilik 25 Oktober 2026
Pelaporan material Branch kini Telegram → MySQL Dompis Prov langsung, menggantikan alur Sheets perantara sebelumnya. Template 45 kolom mengikuti MaterialReportFields; NO TQ/SC/ID wajib untuk resolve WO BIMA dalam akses akun/chat, 44 lainnya boleh kosong/- (NULL), nol tetap nol. Nomor/SN/QR/NIK teks; kuantitas decimal15,3 nonnegatif; koordinat dot WGS84 range valid tanpa inferensi. Migration000021 menambah material_reports dan empat tabel identity/chat/inbox/outbox, PK/FK spesifik; tidak mengubah tabel internal, WO/progress/BOQ/stock/Revenue/LHPP. Whitelist identitas dan chat, scope Branch asal laporan, secret webhook, encrypted inbox/outbox, unique update/chat+message, transaksi/audit/row+queue lock dan retry. Koreksi /koreksi membuat snapshot baru, versi lama tetap; edited/forward/anonymous diabaikan. Menu Material list/detail/template untuk manusia aktif mengikuti Area/Branch scope. Pemilik menyatakan bot belum dibuat: siapkan alur saja, TELEGRAM_MATERIAL_ENABLED defaultfalse; tidak mendaftarkan webhook/mengirim Telegram nyata atau mengklaim terhubung. Onboarding/command/manifest/hasil: docs/TELEGRAM_MATERIAL_REPORTS.md.


## Tabel dashboard dan download Excel — arahan pemilik 26 Oktober 2026
Tabel dashboard Area/Branch dirapikan dengan header/identity sticky dalam scroll panel, angka rata kanan, zebra/hover ringan, Grand Total dan toolbar light/dark/mobile. Region tetap collapsed; Buka semua/Tutup semua scoped per tabel. Setiap tabel (progress, Owner Group, cohort bulan, jenis pekerjaan, harian, tindak lanjut Branch dan daftar modal) mempunyai download XLSX asli yang mengikuti filter tervalidasi dan scope akun yang sama, bukan hanya pagination/Region terbuka. Bulan Area hanya mengatur grafik PS, hari Branch hanya seri harian; rekap lainnya tetap akumulatif. Modal mengekspor semua hasil pilihan, antrean Branch semua outstanding (UI6 teratas), default Area semua jenis/Branch NEW INSTALL. File memuat waktu WIB/filter/definisi periode, native count/date, text ID dengan nol depan, AutoFilter/freeze/header/total. Teks sumber tidak dieksekusi sebagai formula. Gate aktif/manusia, Area hanya role Area, scope assignment Branch, throttle/audit aman, file sementara privat dihapus sesudah kirim. Tidak ada migration/env/dependency/kredensial baru dan tidak mengubah data operasional/template asli/integrasi. Detail manifest/hasil/batas: docs/DASHBOARD_TABLE_EXPORTS.md.


## Koreksi download Excel — pemilik 26 Oktober 2026
Download Excel hanya pada header tabel Work Order dan Order Prioritas; seluruh tabel dashboard Area/Branch dan modal tidak menyediakan tombol download. Endpoint ekspor dashboard lama dihapus; perapian tabel/collapsible/klik angka tetap. Work Order /work-orders/export memakai filter/query/urutan daftar yang sama, default NEW INSTALL; prioritas /priority-orders/export memakai shared PriorityOrderListRequest/PriorityOrderQuery untuk pencarian, Branch, source, state, sort, seluruh jenis pekerjaan. Unduhan seluruh hasil tanpa batas halaman, scope server, prioritas lokal Branch tidak bocor ke Area atau ikut pindah Branch, deduplikasi WO. Gate prioritas tidak diperluas. Writer native dipakai ulang sebagai XlsxTableWriter, audit orders.exported, file privat/no-store/cleanup dan loading/error/retry. Tidak ada migration/env/dependency baru. Ini menggantikan penempatan download dashboard pada addendum sebelumnya. Manifest/hasil/batas: docs/ORDER_LIST_EXPORTS.md.


## Ekspor seluruh kolom Work Order — arahan pemilik 26 Oktober 2026
Download pada Work Order dan Order Prioritas sekarang memuat seluruh kolom fisik work_orders dengan nama/urutan schema database, termasuk PK/FK, sumber BIMA JSON/hash, override perangkat, progress dan timestamp. Ini menggantikan pilihan kolom ringkas/metadata prioritas pada ekspor sebelumnya. Order Prioritas hanya membatasi baris ke pilihan yang visibleTo actor, bukan mengekspor tabel bookmark/token. Nilai getRawOriginal dipakai agar branch, umur/kategori tersimpan, null, JSON dan progress sesuai database; tidak mengganti dengan accessor/label/relasi. Timestamp ekspor UTC database hingga detik, konteks waktu unduhan WIB; identifier teks menjaga nol/presisi. Tetap filter/sort/seluruh halaman dan scope Branch. Teks terlalu panjang untuk sel Excel menghasilkan validasi nyata, bukan silent truncation. Tidak ada migration/env/dependency baru atau perubahan data. Detail/files/tests: docs/ORDER_LIST_EXPORTS.md.
