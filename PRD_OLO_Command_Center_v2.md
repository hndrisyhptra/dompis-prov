# PRODUCT REQUIREMENTS DOCUMENT (PRD)
# OLO Command Center — Area Jawa–Bali

**Versi:** 2.0 • **Tanggal:** 17 Oktober 2026 • **Status:** Implementation-ready baseline; perlu validasi bisnis pada poin terbuka  
**Owner bisnis:** Koordinator Area OLO Jawa–Bali • **Pengguna:** HD Area, Koordinator Area, Admin Area, Admin Branch, Team Leader, Admin Material  
**Stack target:** Laravel, MySQL, Blade + Livewire + Alpine.js, Tailwind CSS, Google Sheets API, Telegram Bot API

## 1. Ringkasan eksekutif

Bangun web application untuk memantau Work Order (WO) OLO Telkom antarbranch di Area Jawa–Bali (11–25 branch, <100 WO baru/hari). HD Area mengunduh export Bima lalu **paste values** ke tab `BIMA_RAW` pada Google Spreadsheet. Tombol **Tarik dari Google Sheets** di web memvalidasi dan melakukan sinkronisasi upsert ke MySQL. Branch memperbarui progress/kendala di web; tombol **Kirim ke Google Sheets** mempublikasikan snapshot kondisi terbaru ke tab `MONITORING_OLO`. Pencatatan material dilakukan melalui Telegram Bot dengan struktur laporan terkontrol; webhook memvalidasi dan menyimpan transaksi di MySQL, lalu mempublikasikan ke tab `MATERIAL_LOG`. LHPP direkonstruksi dari gabungan WO + perangkat + teknisi + pemakaian material yang sudah tervalidasi, dan dapat diunduh menjadi `.xlsx` mengikuti template unggahan.

**Prinsip sumber kebenaran:** `BIMA_RAW` sumber data referensi order Bima; MySQL sumber status operasional, kendala, penugasan, material, dan persetujuan LHPP. `MONITORING_OLO` dan `MATERIAL_LOG` adalah **output/reporting read-only secara proses** dari database; perubahan manual di tab output tidak diimpor balik. Semua tombol sinkronisasi berjalan sebagai background queue job dengan status terlihat di web.

## 2. Temuan berbasis file yang diberikan

### 2.1 Export Bima
File `EXPORT-BIMA.xlsx` sheet **`OLO AREA-3`**, rentang A1:AF3343, memiliki 32 kolom, header baris pertama. Teridentifikasi **3.298 baris dengan identitas kolom H terisi, semuanya unik dalam sampel**; ini bukan jaminan kekekalan unik pada export berikutnya. Sheet pendukung: `Dashboard`, `REKAP ENOM`, `Boundry STO`. Export bersifat campuran formula dan data, khususnya kolom AC (`BRANCH / DISTRICT`), AE (`UMUR (HARI)`), AF (`KATEGORI UMUR`). Jangan menganggap nilai formula dan dashboard sebagai sumber kebenaran; hitung kembali derived fields dari MySQL.

**Mapping kolom persis (1-based):**

| Kolom | Header asli | Target MySQL | Kebijakan |
|---|---|---|---|
| A | NO | source_sequence | metadata, bukan identitas |
| B | TGL ORDER | order_date | date; parse serial Excel/teks |
| C | BULAN | bima_month_label | referensi |
| D | TAHUN | bima_year | referensi |
| E | REGIONAL | regional | referensi |
| F | BRANCH | source_branch_name | usulan mapping branch |
| G | STO | sto_code | referensi |
| H | NO TQ/SC/ID | external_order_id | **kunci utama external**; string persis |
| I | NAMA OLO | customer_name | data Bima |
| J | ALAMAT | installation_address | data Bima; sensitif |
| K | LAYANAN | service_name | master layanan |
| L | JENIS PEKERJAAN | job_type | tidak semua NEW INSTALL |
| M | BW | bandwidth | referensi |
| N | STATUS | bima_status | status mentah sumber |
| O | TASK BIMA | bima_task | referensi |
| P | TGL PS | bima_ps_date | parsed date |
| Q | SID | source_sid | opsional; jangan asumsikan sama H |
| R | KETERANGAN | bima_notes | sumber Bima, bukan progress branch |
| S | ODP | source_odp | sumber Bima |
| T | SN | source_sn | sumber Bima |
| U | OLT | olt | sumber Bima |
| V | PORT OLT | olt_port | sumber Bima |
| W | METRO | metro | sumber Bima |
| X | PORT METRO | metro_port | sumber Bima |
| Y | PANJANG DC | source_dc_length | angka/teks, jangan diperlakukan konsumsi aktual |
| Z | KATEGORY PROGRES | source_progress_category | klasifikasi Bima |
| AA | STATUS FINAL | bima_final_status | `OPEN/CLOSE/CANCEL` di sampel |
| AB | BULAN PS | bima_ps_month | referensi |
| AC | BRANCH / DISTRICT | district_from_sheet | derived via formula STO, optional |
| AD | PS HARI INI | source_ps_today | derived, jangan import sebagai KPI |
| AE | UMUR (HARI) | source_age_days | derived, hitung ulang |
| AF | KATEGORI UMUR | source_age_category | derived, hitung ulang |

**Kewajiban mapping:** `source_system = BIMA`; unik pada `(source_system, external_order_id)` jika aturan sumber dikonfirmasi. `NO TQ/SC/ID` jangan otomatis diganti label `No WO` tanpa mencatat alias. Berikan label UI **No WO / TQ-SC-ID**. Jika key kosong, baris ditolak. Kolom F dan AC bisa berbeda; prioritas branch ditetapkan oleh tabel mapping dan antrian review, bukan overwrite otomatis. Sample export menyertakan `NEW INSTALL`, `MODIFY`, `DISCONNECT`, `SUSPEND`, `RESUME`; dashboard PSB perlu filter jenis pekerjaan agar tidak menghitung semua jenis sebagai pasang baru.

### 2.2 Template LHPP
File `LHPP OLO.xlsx`, sheet `Sheet1`, header A1:AF1, **32 kolom persis**. Contoh memiliki 4 baris data dan SID duplikat di baris 4–5. Nilai lat/long contoh bercampur tanda koma, angka tanpa titik, tanda minus dan bentuk tidak standar: **wajib validasi**, jangan normalisasi koordinat dengan asumsi diam-diam. Tipe/merek perangkat dan ID teknisi perlu disimpan sebagai teks agar nol depan tidak hilang.

**Urutan header LHPP yang harus dipertahankan:**

`SID | Tipe | Nama_Pelanggan | Alamat_Instalasi | No_Voice | No_SID | SN_ONT | MEREK_ONT | SN_STB | MEREK_STB | SN_AP | Merek_AP | ODP | Qr_Code_ODP | Qr_Code_DC | PORT ODP | Valins ID | Nik Teknisi | Teknisi | Nik Teknisi (2) | Nama Teknisi (2) | CP Pelanggan | JENIS TRANSAKSI | Total DC | S-CLAMP | C-HOOK | OTP | PREKSO | TRAY | M-DC-SOC | LAT PELANGGAN | LONG PELANGGAN`.

Catatan: Jumlah kolom LHPP A–AF = 32; jangan tambahkan kolom audit ke workbook LHPP. Metadata dan temuan validasi ditampilkan di web atau file terpisah, bukan menambah kolom di template final.

## 3. Sasaran, outcome dan non-goals

**Tujuan:** (a) satu master WO lintas Area–Branch; (b) proses pull/push aman dan terukur; (c) update kendala/status tersentral; (d) material dilaporkan lewat Telegram dan terlacak; (e) LHPP siap rekonsiliasi dari data tervalidasi; (f) dashboard pencapaian harian berdasarkan definisi status yang jelas.

**KPI adopsi pilot (target awal untuk disetujui):** 100% WO yang berhasil di-import mempunyai identitas unik; 0 duplikasi dari retry sync; 100% push memiliki audit log; ≥95% WO aktif memiliki update sesuai SLA; 100% file LHPP final lolos pemeriksaan mandatory dan tipe; kesesuaian sampel rekonsiliasi LHPP ≥99%; target operasional bukan komitmen SLA sebelum disepakati.

**Non-goals v1:** tidak mengotomasi login/scraping Bima; tidak melakukan transaksi resmi di Bima; tidak melakukan pembelian/inventaris material penuh; tidak membiarkan bot Telegram mengubah status WO tanpa otorisasi; tidak membuat sistem keuangan atau rekonsiliasi otomatis ke pihak eksternal.

## 4. Persona dan RBAC

| Role | Scope | Fitur |
|---|---|---|
| Super Admin | Semua | User, role, config, integration secret, audit |
| Koordinator Area | Seluruh Area | Monitoring, pengawasan, approval koreksi, LHPP |
| HD/Admin Area | Seluruh Area | Pull, push, review conflict, mapping branch, export |
| Admin Branch | Branch yang diassign | WO, assignment, progres, kendala, data LHPP |
| Team Leader | Branch/tim | Progress, kendala, verifikasi data lapangan |
| Admin Material | Branch/scope terkait | Validasi material bot, perbaikan data, rekap |
| Bot Identity | Scoped service | Membuat material report dari webhook yang valid |

Implementasi permission via policy/scope backend, bukan hanya menyembunyikan menu. Ekspor atau API untuk branch harus difilter berdasarkan user_branch. Aksi sinkronisasi penuh area hanya HD/Admin Area dan role yang diotorisasi.

## 5. Skema Google Sheets yang direkomendasikan

**Satu Spreadsheet ID, enam tab dengan hak akses terbatas**. Pisahkan tab raw, output dan konfigurasi; jangan tulis status aplikasi di kolom tambahan `BIMA_RAW`.

| Tab | Direction | Kepemilikan & kebijakan | Kolom |
|---|---|---|---|
| `BIMA_RAW` | Google Sheets → MySQL | HD Area paste **values only** export `OLO AREA-3` | A:AF **identik dengan Bima**; row 1 header |
| `MONITORING_OLO` | MySQL → Sheets | Output snapshot, jangan diedit manual | `No WO/TQ-SC-ID`, `SID`, `Regional`, `Branch`, `STO`, `Layanan`, `Jenis Pekerjaan`, `Status Bima`, `Status Final Bima`, `Status Operasional`, `Status Verifikasi`, `Kategori Kendala Aktif`, `Detail Kendala`, `PIC`, `Target Selesai`, `Terakhir Update`, `Selesai Lapangan`, `Completed Tervalidasi`, `Aging`, `Last Push At` |
| `MATERIAL_LOG` | MySQL → Sheets | Jurnal material validated / flagged | `Report ID`, `Telegram Message ID`, `Event Time`, `No WO/TQ-SC-ID`, `Branch`, `Reporter`, `Teknisi`, `Material Code`, `Material Name`, `Qty`, `Unit`, `Action`, `Validation Status`, `Review By`, `Review At`, `Notes`, `Last Push At` |
| `MATERIAL_ITEMS` | Config/master | Admin master material | `Code`, `Name`, `Unit`, `LHPP Field`, `Active`, `Notes` |
| `SYNC_LOG` | MySQL → Sheets | Ringkasan audit, bukan data utama | `Job ID`, `Direction`, `Started`, `Finished`, `Requested By`, `Read`, `Inserted`, `Updated`, `Unchanged`, `Rejected`, `Conflicted`, `Status`, `Message` |
| `CONFIG` | Admin | Config non-secret saja | `Branch`, `Regional`, `STO Pattern`, `Enabled`, `Comment` |

**Saran kapasitas:** jika `MONITORING_OLO` dan `MATERIAL_LOG` besar, gunakan pagination per batch, batas permintaan API, dan snapshot terukur. Cache state di MySQL, bukan menggantungkan dashboard pada pembacaan Google Sheets langsung. Tab output diproteksi secara organisatoris; real permission Google berlaku pada file dan protected range, bukan role granular seperti database. Jangan taruh service-account JSON, bot token, atau kredensial apa pun di `CONFIG`.

## 6. Tombol Pull: `Tarik dari Google Sheets`

**Pemicu:** HD/Admin Area klik di web → modal preview job + konfirmasi → dispatch job ke queue.

**Urutan:** (1) verifikasi role dan lock eksklusif per koneksi; (2) baca nilai dari Spreadsheet ID + tab BIMA_RAW dengan Google Sheets API; (3) verifikasi header A:AF (dengan aturan toleransi whitespace yang didokumentasi, jangan silent remap); (4) parse key H dan tanggal Excel; (5) validasi baris; (6) hitung diff dari `source_snapshot_hash` per key; (7) preview hitungan NEW/CHANGED/UNCHANGED/REJECTED/CONFLICT; (8) konfirmasi operator; (9) upsert transaksi per chunk; (10) tulis detail import log dan job result; (11) tampilkan ringkasan hasil.

**Aturan merge:** field Bima dapat disinkronkan jika berubah; field internal (`operational_status`, assignment, kendala, material, teknisi, validasi LHPP) TIDAK BOLEH ditimpa dari Sheets. Branch hasil mapping berbeda dari branch yang sudah aktif → tandai conflict dan perlu approval. `external_order_id` immutable kecuali proses resmi koreksi data. WO tidak tampak pada snapshot berikutnya **tidak** dihapus/di-cancel otomatis. Retry job tidak boleh menambah WO atau log duplikat. Simpan source row hash dan source tab + timestamp.

**Hasil UI:** jumlah read, created, changed, unchanged, skipped, conflicted, failed; rincian error per baris dapat diunduh; latest sync dan actor. Status `queued/running/partial_success/success/failed/cancelled`. Jika header salah, berhenti sebelum menulis database. Jika sebagian row gagal, job hasil partial success dan detail jelas.

## 7. Tombol Push: `Kirim Progress ke Google Sheets`

**Pemicu:** HD/Admin Area klik; scope dapat `semua`, `branch`, atau `changed_since_last_push`; default `only changed` dengan opsi full rebuild terotorisasi.

**Urutan:** (1) catat batas snapshot, revision dan lock; (2) buat daftar output deterministik di MySQL; (3) map kolom MONITORING_OLO sesuai kontrak; (4) tulis batch ke tab output dengan Google Sheets API; (5) verifikasi hasil tulis / checksum bila perlu; (6) commit sinkronisasi sukses beserta watermark; (7) catat error tanpa memundurkan watermark jika belum terverifikasi.

**Kunci data:** `No WO/TQ-SC-ID` unik per baris dalam output monitoring; **jangan update berdasarkan nomor baris fisik** karena Sheets dapat disortir. Pilihan implementasi stabil v1 adalah **snapshot full replace of managed output range** pada tab `MONITORING_OLO` memakai staging+verification atau prosedur aman yang tidak meninggalkan data kosong saat gagal. Jangan menghapus tab `BIMA_RAW`. Untuk dataset besar, desain incremental upsert berbasis key dibangun setelah pilot.

**Aturan konflik:** Sheets output tidak menjadi sumber balik progress; jika HD mengedit output langsung, Push berikutnya mengganti hasil edit tersebut setelah peringatan. Push gagal: data MySQL tetap utuh dan status terakhir yang sukses tetap tercatat; aman untuk retry. Nilai PIC dan kendala direkap dari record aktif terbaru, dengan timestamp Asia/Jakarta dan UTC internal.

## 8. Material via Telegram Bot

**Desain rekomendasi:** Telegram Bot → webhook terautentikasi secret token dan allowlist chat/user/role → parser command terstruktur → validasi WO + branch + master material → `material_reports` + `material_report_items` dalam MySQL → approval/review jika perlu → append/publish jurnal `MATERIAL_LOG` dari MySQL. Dengan arsitektur ini laporan **otomatis tersimpan ke MySQL lalu otomatis dipublikasikan ke Google Sheets** secara near-real-time/asynchronous dan retryable; tidak menunggu operator menekan tombol push WO. Tombol `Sync Material ke Sheets` disediakan untuk backfill/manual retry. Pemrosesan webhook wajib idempotent berdasarkan `(chat_id,message_id)` / update_id. Hapus/update pesan tidak boleh membatalkan transaksi secara diam-diam; gunakan koreksi yang tercatat.

**Alur bot v1 (contoh, final syntax dapat disesuaikan):**

`/material` → bot minta No WO/TQ-SC-ID → tampilkan branch/lokasi minimal → pilih material dari master → qty+unit → opsi tambah material → preview → `KONFIRMASI` → simpan transaksi → balasan Report ID dan status. Alternatif command sekali kirim `/material WO123 DC=100 S-CLAMP=4 C-HOOK=1`; **jangan mengizinkan parsing bebas yang ambigu sebagai transaksi final**. Bot perlu menyediakan `/batal` dan `/status <ReportID>`. Jika teknisi belum terpetakan, masuk `needs_review` alih-alih hilang.

**Material transaction model:** satu report dapat memiliki banyak items. Material adalah transaksi `USE`, `RETURN`, atau `CORRECTION`, dengan signed quantity direkonsiliasi sesuai definisi bisnis. Perubahan report terkonfirmasi harus menambah correction event, bukan mengubah jejak transaksi diam-diam. Batasi qty, unit, material active, dan idempotency. `Total DC`, `S-CLAMP`, `C-HOOK`, `OTP`, `PREKSO`, `TRAY`, `M-DC-SOC` memiliki mapping ke header LHPP; `Total DC` dapat numerik atau status `EKSISTING` menurut template: policy khusus perlu disepakati.

**Keamanan bot:** token di secret manager/.env; validasi webhook secret, allowlist chat & user Telegram terikat user aplikasi, hindari informasi pelanggan sensitif di grup, batasi rate dan command, simpan sanitized payload dengan retention policy, jangan menganggap nama Telegram sebagai identitas formal teknisi.

## 9. Modul dan alur Area–Branch

1. **HD Area:** download Bima → paste VALUES di BIMA_RAW → klik Pull → lihat validasi/conflict → terima hasil.
2. **Admin Area:** mapping WO ke branch; branch baru bisa import dari Bima via jalur source yang sama sesuai otorisasi; bila branch punya export sendiri, upload ke staging tidak mengedit BIMA_RAW pusat tanpa prosedur.
3. **Admin Branch / Team Leader:** view WO scope branch → assignment teknisi → status `NEW/ASSIGNED/SURVEY/SCHEDULED/IN_PROGRESS/BLOCKED/FIELD_DONE/PENDING_VALIDATION/COMPLETED/CANCELLED` → catat kronologi update.
4. **Team Leader:** laporkan kendala dengan category, description, PIC, ETA dan evidence opsional. Kendala resolved kembali ke workflow semula; status source Bima tetap terpisah.
5. **Admin Material/teknisi:** laporan material via Telegram → validasi branch dan WO → jurnal material → review → publish MATERIAL_LOG Sheets otomatis.
6. **HD Area:** klik Push Monitoring untuk menulis snapshot status dan kendala dari MySQL ke MONITORING_OLO.
7. **Koordinator Area:** pantau dashboard, identify aging, reconciliation quality, branch performance, dan LHPP readiness; download LHPP untuk rekonsiliasi.

## 10. LHPP: persiapan dan export siap rekonsiliasi

**Halaman LHPP Center:** filter `tanggal`, `branch`, `layanan`, `jenis pekerjaan`, `status complete`, `readiness`, `No WO`. Tampilkan completeness per baris, error per field, status `DRAFT/NEEDS_DATA/READY/APPROVED/EXPORTED`. Peran berwenang dapat melengkapi data perangkat dan personel yang tidak tersedia dari Bima. Export hanya dari baris siap/approved sesuai policy dan mapping template.

**Mapping 32 kolom (A–AF), target sumber yang disarankan:**

| LHPP | Field | Sumber utama | Validasi |
|---|---|---|---|
| A | SID | `work_orders.source_sid` atau `external_order_id` hanya jika bisnis menyetujui | Wajib identity; jangan mengasumsikan H=SID |
| B | Tipe | job_type normalized | lookup tipe LHPP |
| C | Nama_Pelanggan | customer_name | nonempty |
| D | Alamat_Instalasi | installation_address | nonempty |
| E | No_Voice | `wo_lhpp_details.voice_number` | teks/default `-` jika diizinkan |
| F | No_SID | approved SID mapping | konsisten dengan A bila aturan mewajibkan |
| G | SN_ONT | `wo_devices` type ONT | string, validasi serial |
| H | MEREK_ONT | `wo_devices` | string |
| I | SN_STB | `wo_devices` type STB | optional |
| J | MEREK_STB | `wo_devices` | optional |
| K | SN_AP | `wo_devices` type AP | optional |
| L | Merek_AP | `wo_devices` | optional |
| M | ODP | work_orders.source_odp atau verified detail | string |
| N | Qr_Code_ODP | wo_lhpp_details | string |
| O | Qr_Code_DC | wo_lhpp_details | string |
| P | PORT ODP | wo_lhpp_details | string/number |
| Q | Valins ID | wo_lhpp_details | string |
| R | Nik Teknisi | assigned technician_1 ID | string preserve zero |
| S | Teknisi | assigned technician_1 name | wajib bila scoped |
| T | Nik Teknisi (2) | technician_2 ID | optional |
| U | Nama Teknisi (2) | technician_2 name | optional |
| V | CP Pelanggan | wo_lhpp_details | PII, access control |
| W | JENIS TRANSAKSI | wo_lhpp_details.transaction_type | controlled vocabulary |
| X | Total DC | material aggregation or approved override | qty/status 'EKSISTING' review |
| Y | S-CLAMP | material aggregation | qty |
| Z | C-HOOK | material aggregation | qty |
| AA | OTP | material aggregation | qty |
| AB | PREKSO | material aggregation | qty |
| AC | TRAY | material aggregation | qty |
| AD | M-DC-SOC | material aggregation | qty |
| AE | LAT PELANGGAN | wo_lhpp_details.latitude | WGS84 decimal degrees -90..90 |
| AF | LONG PELANGGAN | wo_lhpp_details.longitude | WGS84 decimal degrees -180..180 |

**Quality gate:** format dan urutan A–AF identik template; nama header persis; identitas duplikat dalam satu export ditolak/flagged (contoh LHPP input menunjukkan duplikat); data lokasi harus numeric geographic valid, **jangan menebak posisi desimal** dari angka `-7315385` / `1127284541` tanpa aturan resmi; material dari transaksi validated saja; tidak double count resubmitted bot report; SN/nik sebagai string; tidak mengekspor field teknisi/material yang belum diverifikasi sebagai angka nol secara diam-diam; dapat memilih report tanggal dan branch; catat siapa generate dan download.

Jika pengguna memilih export data yang belum lengkap, tampilkan daftar missing/invalid dan tawarkan `Draft Exception Report` **bukan** file final bersertifikat ready.

## 11. Struktur database v2

| Tabel | Kolom penting / relasi | Tujuan |
|---|---|---|
| `branches` | id, code, name, regional, active | master branch |
| `users` | id, name, email, role/status | identity |
| `user_branches` | user_id, branch_id | multi-scope RBAC |
| `branch_mappings` | id, bima_branch, sto_code, branch_id, priority, enabled | mapping source |
| `service_types` | code, label, active | master layanan |
| `work_orders` | id, source_system, external_order_id, branch_id, service_type_id, job_type, customer_name, order_date, source_bima_fields, bima_status, bima_final_status, operational_status, verified_at, source_hash, revision, last_progress_at, timestamps | unique `(source_system,external_order_id)` |
| `work_order_assignments` | wo_id, technician_id, assigned_by, assigned_at, revoked_at | penugasan |
| `wo_status_events` | wo_id, old, new, actor_id, event_at, notes | immutable event history |
| `wo_issues` | wo_id, category_id, status, PIC, opened_at, resolved_at, desc | kendala per kejadian |
| `wo_activity_logs` | wo_id, actor_id, event_type, payload, occurred_at | audit progress |
| `technicians` | id, branch_id, nik (varchar), name, active | master teknisi |
| `wo_devices` | wo_id, device_type, serial_number, brand, active | ONT/STB/AP |
| `wo_lhpp_details` | wo_id unique, voice_number, codes, port_odp, valins_id, contact, transaction_type, latitude, longitude, readiness | data pelengkap LHPP |
| `material_items` | id, code, name, unit, lhpp_field, active | master material |
| `material_reports` | id, wo_id, branch_id, telegram_chat_id, telegram_message_id, reporter_user_id, technician_id, event_at, status, notes, unique inbound key | header report |
| `material_report_items` | report_id, material_item_id, action, qty, unit, correction_of_item_id | journal line |
| `sheet_connections` | id, spreadsheet_id, tab_map_json, credential_ref, enabled | koneksi (no secret raw) |
| `sync_jobs` | id, direction, source, state, actor, start/end, stats_json, watermark, idempotency_key, error | all sync |
| `sync_job_rows` | sync_job_id, external_key, row_index, action, field_changes, error | detail sync |
| `sheet_publish_outbox` | id, payload_type, entity_id, version, status, attempts, next_attempt_at | retry material output |
| `lhpp_exports` | id, filters_json, row_count, checksum, generated_by, generated_at, file_path, status | export audit |
| `audit_logs` | actor_id, action, entity, old/new redacted, IP, timestamp | audit umum |

Semua foreign keys/index sesuai access patterns; soft delete untuk entitas administrasi; status changes, reports, corrections, dan export history bersifat append-only sesuai audit. Transaksi database wajib untuk operasi sinkronisasi per chunk dan penerimaan material.

## 12. Dashboard dan metrik

**Area Overview:** incoming by order date vs first imported date; `Field Done Today`; `Verified Complete Today`; `Bima Close Today` (berdasarkan tanggal PS jika valid); outstanding; blocked unique WO; aging SLA; last Bima Pull / last Monitoring Push / Material Publish lag. Status Bima dan status lapangan ditampilkan terpisah. Filter regional, branch, tanggal, layanan, jenis pekerjaan.

**Branch Performance:** incoming, complete, blocked, aged, update compliance, latest update, issue closure latency.

**WO Monitoring:** searchable list, detail timeline, Bima snapshots, material report linked, readiness LHPP.

**Material Center:** report count, pending review, qty per item/branch/period, suspected duplicates, report-to-WO unmatched, Sheets publish backlog, material reconciliation coverage.

**LHPP Center:** ready/not ready counts, missing fields, unvalidated materials, duplicated SID, invalid geo, export history.

**Metric rules:** `Incoming (Bima)` berdasarkan `order_date`; `Newly Imported` berdasarkan waktu pertama masuk MySQL; `Complete Today` berdasarkan tanggal verifikasi, bukan Telegram or raw status; `Blocked WO` distinct WO dengan issue OPEN; `Material Usage` agregasi transactional approved USE minus RETURN/correction; semua timestamp UTC disimpan, ditampilkan Asia/Jakarta (branch lintas zona dapat memiliki zona waktu sendiri nanti). Koordinator menetapkan official cut-off dan pemisahan status `CLOSE` Bima vs `COMPLETED` internal.

## 13. UX dan desain visual wajib

**Visual language:** clean white, modern internal operations tool, informasi ringkas dan terstruktur, **tanpa gradient, glassmorphism, glossy effects, oversized marketing cards, emoji dekoratif, atau desain AI slop**. Primary brand `#315B8C`. Semua button primary, focus ring, active state, chart accent bernuansa palet brand; status semantic tetap hijau/amber/merah untuk arti nyata. Radius default `rounded-md` (6px), bukan pill/rounded-2xl untuk sebagian besar komponen. Light default white, dark mode dirancang eksplisit menggunakan class `dark`/design tokens (bukan invert asal). Sistem responsive: mobile satu kolom, sidebar menjadi drawer; tabel wide horizontal-scroll dengan sticky identity column jika dibutuhkan; form nyaman di-touch. Semua actionable UI memiliki loading, disabled, toast dan error state; empty state memandu tindakan; state sync tidak boleh palsu.

**Design tokens:** primary `#315B8C`; primary-hover `#274B75`; primary-soft-light `#EBF1F7`; white `#FFFFFF`; body-light `#F7F9FC`; text-light `#17212E`; border-light `#DCE3EA`; dark-bg `#0F1722`; dark-card `#17212D`; dark-border `#2A3A4A`; dark-text `#E7EDF4`; text-muted `#62758A`. No gradient CSS at all. Buttons radius `6px`; cards `6px`; inputs `6px`; shadow subtle or none. Use Lucide icon consistently.

**Minimum screens:** Login, Area Dashboard, Work Orders table, WO detail/timeline, Sync Center (Pull + Push), Branch Dashboard, Issue Center, Material Center, Material Review detail, LHPP Center & validation detail, LHPP Export History, Users/Roles, Branch Mapping, Audit/Sync Logs, Settings Integrations.

## 14. API/route contracts (illustrative)

| Method | Endpoint | Function |
|---|---|---|
| POST | `/api/sync/bima/pull/preview` | read/validate remote header/rows, diff estimate |
| POST | `/api/sync/bima/pull/commit` | dispatch confirmed pull |
| POST | `/api/sync/monitoring/push` | dispatch monitoring snapshot |
| POST | `/api/sync/material/publish` | publish/retry material log |
| GET | `/api/sync/jobs/{id}` | progress/stats/errors |
| GET | `/api/work-orders` | scoped list/filter |
| GET | `/api/work-orders/{id}` | detail + timeline |
| PATCH | `/api/work-orders/{id}/progress` | status/updates |
| POST | `/api/work-orders/{id}/issues` | create issue |
| PATCH | `/api/issues/{id}` | resolve/escalate |
| POST | `/api/telegram/webhook` | authenticated bot update |
| GET | `/api/material/reports` | scoped material ledger |
| POST | `/api/material/reports/{id}/review` | review status |
| POST | `/api/lhpp/validate` | validate candidate rows |
| POST | `/api/lhpp/exports` | create export job |
| GET | `/api/lhpp/exports/{id}/download` | authorized file download |

Use Laravel web routes + CSRF for Blade/Livewire actions where applicable; webhook endpoint needs its own secret validation and rate controls. Do not ship insecure demo endpoints.

## 15. Non-functional requirements

- **Security:** least-privilege Google service-account OAuth scopes, sheet sharing, encrypted credentials outside repo, Telegram secret headers, per-branch authorization, CSRF protection, input validation, audit logs and export PII protection.
- **Availability:** graceful degradation during Google outage; Web update progress/material MySQL tetap berjalan; publisher retry with exponential backoff; manual resync.
- **Consistency:** idempotent input, deterministic key-based output, transactional MySQL commits, audit immutable, stale-workflow protection with `revision` for conflicting edits.
- **Performance:** first target import ~3.3k historical WO / <100 daily; chunk and queue processing; UI responsive with pagination; avoid calling external services on page load.
- **Maintainability:** service layer interfaces for Bima pull/Google publishing/Telegram parsing/LHPP; unit/integration/feature tests; configuration-driven sheet columns.
- **Observability:** queue health, last success, retries, dead-letter/error table, exception alerts, export checksum, correlation/job IDs.
- **Accessibility:** keyboard navigation, visible focus, adequate contrast in light/dark, form label/errors, touch targets, reduced motion.

## 16. Acceptance criteria per epic

**AC-PULL:** (1) 32 headers verified; (2) 3.298 sample unique IDs imported once (historical count depends valid rows); (3) rerun unchanged creates zero new WO; (4) branch progress cannot be overwritten; (5) collision or changed branch surfaced conflict; (6) missing key rejected with row index; (7) one failed row does not silently discard others; (8) job result audit persists.

**AC-PUSH:** (1) BIMA_RAW untouched; (2) changes appear under correct WO key even if Sheets sorted; (3) repeated push same revision safe; (4) inaccessible Google API yields failed/retryable state; (5) successful job recorded, not reported early; (6) PII fields restricted to approved outputs.

**AC-MATERIAL:** (1) bot whitelist only; (2) duplicate Telegram update records one material report; (3) unknown WO flagged not silently mapped; (4) material item/qty valid; (5) cancel/correction tracked; (6) confirmed material stored MySQL then eventually published Google Sheets with visible queue state; (7) Telegram/Sheets outage does not lose approved report.

**AC-LHPP:** (1) 32 headers A–AF exact; (2) duplicate SID in same export flagged; (3) coordinates checked as WGS84 and no guessing decimal; (4) NIK/SN remain text; (5) material totals from approved journal only; (6) missing mandatory fields flagged; (7) `READY` export equals input template structure; (8) export permission enforced; (9) file can be opened in Excel; (10) exported rows traceable back to WO+report.

**AC-UI:** (1) no gradient anywhere; (2) `#315B8C` primary; (3) default rounded-md; (4) light/dark persisted; (5) desktop/tablet/mobile navigation usable; (6) loading/error/empty states; (7) no placeholder fake KPIs in production.

## 17. UAT test matrix

| ID | Scenario | Expected |
|---|---|---|
| U01 | Pull valid initial Bima | inserted counts & trace |
| U02 | Pull same tab again | zero duplicate / unchanged |
| U03 | Pull blank H ID | row rejected, other rows continue |
| U04 | Pull branch F changed | conflict requiring review |
| U05 | WO missing from latest sheet | preserved, not deleted |
| U06 | Branch updated progress then Pull | progress unchanged |
| U07 | Push after changing progress | correct WO matching, BIMA_RAW unchanged |
| U08 | Rearrange output row then Push | correct snapshot by key |
| U09 | Google unavailable during Push | retryable failed status, MySQL safe |
| U10 | Telegram webhook replay | exactly one report |
| U11 | Unknown WO material report | flagged needs_review |
| U12 | Material correction | auditable net qty |
| U13 | Sheets output material unavailable | outbox retry visible |
| U14 | LHPP duplicate SID | prevented/flagged |
| U15 | LHPP coords malformed | export blocked until verified |
| U16 | LHPP serial/nik leading zeros | preserved as strings |
| U17 | Branch user fetch other branch WO | forbidden |
| U18 | Light/dark and mobile | pass UI checklist |
| U19 | Complete status reversed/reopened | auditable, daily snapshot correction policy |
| U20 | Simultaneous Pull/Push | locks & consistent job states |

## 18. Delivery plan / work breakdown

**Sprint 0 — Discovery/data contracts:** review exact 32-col Bima and LHPP; confirm definition H vs SID; confirm permitted source/paste process, region/branch mapping, materials master, completion KPI & sample bot message. Create seed reference data, deploy sandbox Google Spreadsheet tabs, test service account.

**Sprint 1 — Foundation:** Laravel auth/RBAC, branches/users, initial database schema/migrations, work_orders, audit, UI shell light/dark.

**Sprint 2 — Sheets Pull:** Google service integration, compare preview, queue workers, conflict resolution, mapping, sync log, tests.

**Sprint 3 — Branch operations & Push:** WO list/detail, status, issue, assignment, Area Dashboard and Monitoring Push; tests and pilot 2–3 branch.

**Sprint 4 — Material Bot:** bot onboarding/identity, structured commands, webhook, transaction ledger, review, automatic Google material publisher, failed-delivery backfill.

**Sprint 5 — LHPP:** devices/technician/LHPP details forms; validation/readiness, template-preserving XLSX export, audit, reconciliation pilot.

**Sprint 6 — Stabilization:** dashboards/material insight, Telegram daily summary, UAT, ops runbook, monitoring and backups, rollout.

Tasks are prioritized sequentially, not fixed time commitments; implement vertical slices and maintain green tests after each.

## 19. Business decisions outstanding (require owner sign-off)

1. Does `NO TQ/SC/ID` column H always identify one WO across Bima exports? Is column Q SID separate? Who approves mapping into LHPP `SID` and `No_SID`?
2. Does daily Complete use Bima `CLOSE`, `TGL PS`, internal verified completion, or dual metrics? Define report cutoff and correction rules.
3. Which materials map to `Total DC`, S-CLAMP, C-HOOK, OTP, PREKSO, TRAY, M-DC-SOC, their units, and allowed statuses such as `EKSISTING`?
4. Telegram material report exact grammar and who can report (technician, TL, admin), review/approval rules, and chat grouping.
5. Which LHPP columns are truly mandatory by job type, and can values be `-`? How should coordinate formatting be supplied from field teams?
6. Are `MODIFY`, `DISCONNECT`, `SUSPEND`, `RESUME` included in monitored backlog or only PSB installation filters?
7. Which file owner provides service-account access, and which users may edit BIMA_RAW / outputs?
8. Retention period and personal data access for customer contact, address, technician NIK, Telegram IDs.

## 20. Source artifacts and traceability

- User-provided `EXPORT-BIMA.xlsx` → sheet `OLO AREA-3`, A:AF; workbook supporting `Dashboard`, `REKAP ENOM`, `Boundry STO`.
- User-provided `LHPP OLO.xlsx` → sheet `Sheet1`, A:AF; template column order preserved.
- All descriptions above of file structure grounded in inspected workbooks; application URLs, spreadsheet ID, Telegram group, official SLA, and business approval rules are **not** supplied yet, so remain configurable.
