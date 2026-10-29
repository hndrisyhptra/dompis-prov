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


## Monitoring prioritas dan submenu — pemilik 26 Oktober 2026
Card Operasional Area ke Branch dihapus dari dashboard Area. Menu Order Prioritas menjadi parent dengan Monitoring Order Prioritas (/priority-orders/monitoring) dan List Order Prioritas (URL lama /priority-orders). Monitoring hanya bookmark visibleTo actor: pivot status_bima → task_bima × Owner Group, rekap Owner Group → task, dan rincian Branch → task → No WO berpagination. Kelompok mengikuti lookup aktif/persis mapping_ownergrup; kosong/tidak dikenal/nonaktif masuk Belum dipetakan. COMPLETE adalah status task BIMA, bukan definisi PS baru. Filter, tautan angka ke List dan download List memakai PriorityOrderQuery/Request bersama; tidak ada download pada rekap monitoring. Privasi Area/Branch, deduplikasi serta aturan bookmark saat pindah assignment tetap. Tampilan light/dark, collapsible dan fallback tanpa JS; tidak ada migration/env/dependency atau perubahan WO. Detail manifest/hasil: docs/PRIORITY_MONITORING.md.


## Header BIMA bergeser dan optimasi query — pemilik 26 Oktober 2026
Preset import BIMA kini dicocokkan berdasarkan 13 nama header unik lengkap, bukan posisi huruf Excel; ini menggantikan pemeriksaan posisi tetap sebelumnya. Export tanpa witel menempatkan Customer Name di N dan Workzone di O, dengan Booking ID tambahan; format lama O/P tetap dikenali. Alias ORDER/ORDER ID/ORDER-ID tetap hanya berlaku dalam kontrak BIMA lengkap, bukan header generik sembarang file. Header hilang/ambigu tidak diakui sebagai preset dan tetap membutuhkan pemeriksaan mapping manual; pilihan mapping tersimpan tidak ditimpa pada GET. Hash per WO berdasarkan atribut kanonik sehingga perubahan posisi saja tetap unchanged.
Agregasi antrean/dashboard/cohort memakai OrderMetricAggregate dari scope bisnis yang sama dalam query turunan yang kompatibel ONLY_FULL_GROUP_BY; scope akun/filter ada pada query dalam, tidak dibuang. List scoped menggunakan izin role saat render untuk menghindari query per baris; policy endpoint tetap memeriksa WO aktual. Upload staging batch <=100 baris/256KiB menjaga enkripsi; routing cache hanya dalam satu tahap yang dilindungi lock inbound dan dibuang sebelum commit berikutnya. Tidak ada migration/env/paket baru atau perubahan definisi PS, progress, source/hash, audit atau privasi bookmark. Hasil/manifest/batas: docs/BIMA_MAPPING_PERFORMANCE.md.


## Tanggal PS dari Date Modified — pemilik 26 Oktober 2026
Upload BIMA Work Orders kini memetakan Date Modified→tgl_ps (preset berbasis 14 header unik, v4-ps-date). Ini menggantikan larangan memakai Date Modified sebagai sumber PS pada addendum lama. Tanggal hanya diterapkan bila status_order memenuhi daftar PsEligibility yang sudah berlaku DAN lookup Mapping Order aktif menghasilkan PS. Auto CLOSE/definisi PS tidak diubah dan tidak semua COMPLETE dianggap PS. Non-PS, master nonaktif/tidak ditemukan/bukan PS, atau Date Modified kosong tidak menghapus tanggal PS lama; tanggal tidak valid/formula/error untuk baris PS ditolak. Nilai tanggal kalender file dipakai, bukan hari upload/waktu auto CLOSE. Date Modified mengikuti pergeseran header; pada preset BIMA tgl_ps hanya boleh Date Modified atau tidak diimpor. Format manual/Sheets legacy dengan kolom TGL PS eksplisit tetap kompatibel. Hash mencakup tanggal/sumber yang diterapkan; retry tidak menambah log, koreksi tercatat pada log BIMA dan otomatis dipakai KPI/seri harian Area/Branch. Rule tanggal dicatat pada preview, diperiksa ulang saat commit; cache rule hanya per tahap dengan lock inbound. Kontrak snapshot Excel v5 menolak preview lama agar upload/preview baru dilakukan. Tanpa migration/env/paket/backfill spekulatif atau perubahan file asli. Detail hasil/file/batas: docs/BIMA_PS_DATE.md.


## Prioritas selesai PS dan monitoring ringkas — pemilik 27 Oktober 2026
Order dengan progress CLOSE dan status BIMA yang memenuhi PsEligibility otomatis dilepas dari bookmark Area maupun lokal Branch saat commit import atau update progress. CLOSE lapangan yang belum PS tetap dipantau. Pelepasan memakai lock/transaksi dan audit per bookmark; tidak menghapus WO, progress, kendala atau log BIMA. PS tidak dapat dipilih ulang dari form lama. List, monitoring, total dan ekspor mengecualikan PS melalui query shared, termasuk bookmark lama; koreksi order tidak menghidupkan bookmark kembali otomatis. Rekonsiliasi CLI ber-actor Area dan dry-run tersedia. Dua tabel Status BIMA × Owner Group dan Rincian Task per Owner Group ditampilkan vertikal pada seluruh viewport, dengan heading/baris/scroll ringkas, collapse dan tautan angka tetap; menggantikan layout sejajar 26 Oktober. Tidak ada migration/env/dependency baru. Manifest, hasil tes dan data aktual: docs/PRIORITY_PS_RELEASE.md.


## Screenshot tabel prioritas dan tab Area — pemilik 27 Oktober 2026
Tabel monitoring prioritas tampil utuh, lebih padat, lebar mengikuti panel dan label membungkus, tanpa scroll internal atau batas tinggi; kelompok terbuka secara default agar siap screenshot. Tidak memangkas isi dan rincian Branch tetap berpagination dengan rentang yang jelas. Tinggi halaman mengikuti jumlah baris. Dashboard Area mempunyai tab Progress (default) dan Owner Group; Distribusi Owner Group hanya pada tab Owner Group. Tab GET tervalidasi mempertahankan filter/bulan, role dan modal/detail yang sama; KPI/filter tetap bersama. Ini menggantikan batas scroll tampilan prioritas sebelumnya. Tidak ada perubahan schema/env/dependency/data atau aturan PS/prioritas. Detail berkas/hasil/batas: docs/DASHBOARD_TABS_CAPTURE.md.


## Tiga tab monitoring prioritas — pemilik 27 Oktober 2026
Monitoring Order Prioritas dipisah menjadi Order Branch (default: Rincian Order per Branch), Owner Group (dua rekap status/task × kelompok), dan Order Kendala. Tabel Kendala memiliki Branch, No WO / TQ-SC-ID, status_order dan detail kendala terbaru dari issue OPEN/catatan tindak lanjutnya; satu order dihitung sekali, resolved dan catatan progress biasa tidak menggantikan kendala aktif. Tetap hanya bookmark prioritas visibleTo actor dan bukan PS, tanpa kebocoran pilihan Branch ke Area. Tab GET tervalidasi membawa filter/pagination, tabel ringkas tanpa scroll internal dan tautan No WO ke Detail. Relasi catatan terakhir dimuat per halaman, bukan query per baris. Tidak ada migration/env/dependency atau mutasi data. File, hasil dan batas: docs/PRIORITY_MONITORING_TABS.md.


## Update Progress/Kendala pada prioritas — koreksi pemilik 27 Oktober 2026
Kolom Kendala pada tab Order Kendala diganti menjadi Update Progress/Kendala. Nilainya adalah pembaruan terbaru antara catatan progress manual dan kendala/tindak lanjut aktif, dengan label kategori serta waktu WIB. Ini menggantikan larangan catatan progress umum menggantikan detail kendala pada addendum tab sebelumnya. Progress manual dibuktikan oleh audit progress.changed yang cocok dengan event order/revision/actor; log otomatis BIMA dan penugasan tidak menggantikan catatan manusia. Timestamp dan ID audit membedakan pembaruan pada detik yang sama. Membership tetap prioritas visibleTo dengan issue OPEN dan bukan PS; filter, privasi dan riwayat tetap. Lookup dibatasi ID halaman, tanpa query per baris. Tidak ada migration/env/dependency atau perubahan data. Manifest/hasil/batas: docs/PRIORITY_PROGRESS_ISSUE_UPDATES.md.

## Cakupan order dengan update progress/kendala — pemilik 27 Oktober 2026
Tab Order Kendala pada monitoring prioritas kini menampilkan order dengan issue OPEN ATAU progress manual yang terbukti melalui audit progress.changed dan event order/revision/actor yang cocok serta catatan tidak kosong. Ini menggantikan membership issue OPEN saja pada addendum sebelumnya. Order progress tanpa issue tampil; order dengan keduanya dihitung sekali. Log otomatis BIMA/penugasan dan order tanpa update tidak memenuhi bukti manual. Scope prioritas/akun/filter dan pengecualian PS tetap; state=issues eksplisit tetap membatasi ke kendala aktif. Setelah kendala selesai, order dengan progress manual tetap tampil memakai catatan manual terbaru; resolved-only tanpa progress tidak masuk. Isi kolom Update Progress/Kendala tetap kandidat terbaru dengan label/waktu WIB. Membership SQL diterapkan sebelum pagination dan lookup catatan dibatasi halaman, tanpa query per baris. Tidak ada migration/env/dependency atau mutasi data. File, hasil tes dan batas: docs/PRIORITY_UPDATED_ORDERS.md.

## Bookmark massal dan Order Kendala per Branch — pemilik 27 Oktober 2026
Order Kendala kini collapsible per Branch, default tertutup, Buka/Tutup semua scoped pada halaman; jumlah Branch diberi label halaman aktif dan pagination/Grand Total tetap. Bookmark massal tersedia dari List/Monitoring: tempel 1–50 No WO persis (batas sebelum deduplikasi), preview Siap/sudah dipilih/PS/duplikat/tidak ditemukan dalam akses/ambigu, kemudian konfirmasi Ya/Tidak. Scope tetap server: bersama Area atau lokal Branch; tidak memberi akses role baru atau membocorkan bookmark lokal ke Area. Token terenkripsi terikat actor/role/Branch dengan masa berlaku 15 menit. Commit memeriksa ulang akses, assignment dan PS di bawah lock actor/order serta transaksi; perubahan membuat seluruh batch meminta preview baru. Bookmark yang dipilih rekan dilewati; audit batch mencegah replay menambah kembali pilihan yang sudah dilepas. Insert/audit dibatch, tidak mengubah data/riwayat WO. Tidak ada migration/env/dependency/kredensial baru. Detail berkas/hasil/batas: docs/PRIORITY_BULK_BOOKMARK.md.

## Branch per Owner Group pada prioritas — pemilik 27 Oktober 2026
Tab Owner Group pada Monitoring Order Prioritas menambahkan tabel Branch per Owner Group: Branch alfabetis, kolom kelompok aktif dengan data, total baris/kolom dan Grand Total. Menggunakan agregasi OwnerGroupAnalyticsService atas builder PriorityOrderQuery filtered yang sama; seluruh hasil, bukan pagination rincian, deduplikasi bookmark, privasi Area/lokal Branch dan pengecualian PS tetap. Unknown/kosong/nonaktif masuk Belum dipetakan; tanpa assignment masuk Belum ditugaskan. Angka termasuk nol/total membuka List dengan filter persis. Filter unassigned boolean tervalidasi tersedia pada request/checkbox Area agar baris tanpa assignment tidak membuka semua order; tidak digabung Branch tertentu. Tabel ringkas/utuh untuk screenshot mengikuti white/dark, tanpa download rekap atau mapping spekulatif dari screenshot. Hanya tab Owner Group memuat agregasi baru. Tidak ada migration/env/dependency/kredensial atau mutasi data. Detail berkas, hasil dan batas: docs/PRIORITY_BRANCH_OWNER_GROUP.md.

## Mapping Order Region/Branch/STO — pemilik 27 Oktober 2026
Dashboard Area tab Progress menambah Mapping Order Region; Dashboard Branch menambah subtotal Branch dengan rincian STO collapsible. Kolom PS/CANCLWORK/OPEN/STUCK dan Grand Total memakai work_orders.mapping_order tersimpan, tanpa menebak status atau mengubah KPI PS. Unknown/NULL masuk Belum dipetakan, tanpa assignment/Region tetap pada Tanpa Region; STO kosong tetap dihitung dan kode 0 dipertahankan. Region dari master Branch, bukan teks sumber. Scope/filter sama dengan dashboard; default Area semua jenis, Branch NEW INSTALL, bulan/hari grafik tidak membatasi rekap akumulatif. Seluruh angka membuka modal/list/detail scoped. Filter mapping_order/sto/sto_missing tervalidasi dan tetap pada navigasi/ekspor Work Order; STO trim spasi tepi, persis case-sensitive, tidak dicocokkan ke master secara spekulatif. Agregasi query turunan kompatibel MariaDB ONLY_FULL_GROUP_BY, lookup batch tanpa query per baris; maksimal tambahan 3 query tetap. Tidak ada migration/env/dependency/kredensial, download dashboard, mutasi atau backfill data. Detail berkas/hasil/batas: docs/DASHBOARD_MAPPING_ORDER.md.

## Transaksi OPEN/STUCK dan Ach PS — pemilik 27 Oktober 2026
Dashboard Area tab Progress menambah rekap mapping OPEN/STUCK → Region → Branch menurut jenis_pekerjaan (jenis transaksi CRM BIMA), subtotal/Grand Total dan Region collapsible. New Install/Modify/Disconnect default; jenis tambahan/null tetap dihitung, filter jenis spesifik hanya menampilkan kolom tersebut. Angka membuka modal/list scoped; union Grand Total memakai mapping_order=open_stuck tervalidasi dan bertahan pada navigasi/ekspor. Menggunakan dimensi tambahan pada agregasi Mapping Order yang sama, tanpa query tambahan. Navigasi Area menjadi Progress/Owner Group/Ach PS; card Perolehan PS harian pindah ke Ach PS, input bulan hanya pada Ach PS dan bulan/filter tetap dibawa antar-tab. KPI PS/14 status/tanggal WIB dan aturan grafik tidak berubah, bulan grafik tidak membatasi transaksi akumulatif. Tidak ada migration/env/dependency/kredensial/mutasi data, perubahan dashboard Branch atau download dashboard. Detail berkas/hasil/batas: docs/OPEN_TRANSACTIONS_ACH_PS.md.

## Tabel PS harian Branch, ringkas/detail bulan — pemilik 27 Oktober 2026
Ach PS memakai tabel PS harian per Branch tersendiri, menggantikan accordion tanggal/jumlah lama. Default maksimal 15 hari kalender sampai hari ini pada bulan berjalan, 15 hari terakhir untuk bulan historis; Detail bulan (ps_view=month) menampilkan 28–31 tanggal dan Ringkas 15 hari kembali ke recent. Filter/bulan dipertahankan. Grand Total mengikuti tanggal yang tampil, total seluruh bulan ditulis terpisah; hari kosong nol, masa depan — dan tidak dihitung. Branch arsip/unassigned tetap sesuai scope, assignment terkini. Agregasi PS bulanan dikelompokkan Branch/tanggal dan dijumlahkan lintas Branch untuk grafik, tanpa query baru. Total periode memakai ps_from/ps_to tervalidasi, PS eligibility yang sama dan akhir maksimal hari ini WIB, tetap scoped serta terbawa ke navigasi/ekspor Work Order; sel tanggal memakai ps_date lama. Tidak ada migration/env/dependency/kredensial/mutasi data/download dashboard. Detail berkas/hasil/batas: docs/PS_DAILY_BRANCH_MATRIX.md.

## Jenis Pekerjaan ringkas dan Mapping Aging Order — pemilik 27 Oktober 2026
Judul rekap Area Progress menjadi OPEN & STUCK per Jenis Pekerjaan. Tabel tersebut dan Mapping Aging Order memakai tampilan padat tanpa scroll internal; Buka semua memperpanjang halaman sesuai jumlah Branch. Mapping Aging Order menghitung mapping_order OPEN menurut Region/Branch, kategori 0-7/7-14/14-28/>28 HARI dan Grand Total; tanggal kosong terpisah jika ada. Umur dihitung langsung dari TGL ORDER sampai hari ini WIB dengan batas >28/>14/>7, bukan kolom umur tersimpan; tanggal masa depan tetap mengikuti cabang else rumus lama, tanpa koreksi sumber. Angka membuka modal/list scoped memakai aging_bucket+aging_on tervalidasi berpasangan dan snapshot tanggal yang sama sampai export. Export tetap nilai kolom fisik database. Dimensi umur memakai agregasi Mapping Order yang sama tanpa query tambahan; tidak mengubah PS, sumber, progress atau dashboard Branch. Tidak ada migration/env/dependency/kredensial baru. Detail berkas/hasil/batas: docs/OPEN_JOB_TYPES_AGING.md.

## Rekap dihapus dan optimasi sebelum deploy — pemilik 27 Oktober 2026
Tabel Rekap jenis pekerjaan di dashboard Area dihapus beserta agregasi recap dan grouping jenis pekerjaan yang khusus untuknya. Filter jenis pekerjaan serta OPEN/STUCK dan Mapping Aging Order tetap; aging tetap pada Progress sesuai pembatalan pindah tab. AreaAnalytics menghitung laporan sesuai tab aktif saja: Progress matrix/mapping/cohort, Owner Group distribusi, Ach PS grafik/matrix harian. KPI bersama tetap sama, laporan tidak aktif null/seri kosong; bulan tidak membatasi rekap kumulatif. Master Branch/Region dipakai ulang per request Progress, agregat Owner Group tidak menghidrasi model WO. Modal daftar dashboard hanya mengambil atribut yang ditampilkan beserta status/tanggal, tidak memuat JSON sumber/bookmark/count kendala yang tidak digunakan, tetap scope/filter/pagination/policy lama; list/export lengkap tetap. Tidak ada cache hasil lintas akun, perubahan schema/index/migration/env atau mutasi data. Query service Progress 9→4 pada fixture 20/1.000 WO tanpa Region, modal 3 query tetap; hasil server belum diukur. Tests SQLite347/MariaDB64/JS28, build dan cache deployment terisolasi lulus. Deployment belum dilakukan; panduan berkas/perintah/hasil/batas: docs/PREDEPLOY_OPTIMIZATION.md.

## Pemulihan tabel BOQ terhapus — pemilik 27 Oktober 2026
qe_boqs/qe_boq_items/qe_boq_histories terhapus manual, sementara migration000017/000020 masih Ran. Pemilik mengirim dump dompis_qe (qe_lop_id/users.id_user), kemudian memilih cukup pulihkan tabel kosong untuk upload ulang karena tidak ada backup dompisprov yang sesuai. Jangan mengimpor/mengarang relasi data QE lama ke WO. Migration aditif000022 membuat kembali hanya ketiga tabel bila semuanya hilang, no-op bila lengkap, menolak schema parsial sebelum DDL; kontrak PK/FK/decimal/revision/hash/soft-delete/history dan breakdown000020 tetap. Down pemulihan tidak menghapus data. Sudah dieksekusi pada dompisprov dengan lock inbound, backup terenkripsi per chunk terverifikasi dan audit; 27 tabel lama digest identik termasuk 3482WO/1448harga/3user/1KML. Ketiga tabel kosong dan upload ulang siap; history lama tidak diklaim pulih. Tidak mengubah sumber SQL, master, env atau branding pengguna. Tes terkait SQLite29/MariaDB31 lulus; full348/349, satu ekspektasi branding login lama gagal terhadap UI Wibsy. Detail berkas/backup/hasil/batas: docs/BOQ_TABLE_RECOVERY.md.

## Nama BOQ dan Reset Bookmark — pemilik 28 Oktober 2026
Tabel qe_boqs/qe_boq_items/qe_boq_histories menjadi boq_jt/boq_items/boq_histories melalui migration000023, PK id_boq_jt/id_boq_items/id_boq_histories dan FK anak id_boq_jt. Nilai ID, uang, hash, revision, soft-delete, timestamp, file dan snapshot riwayat dipertahankan. Runtime memakai BoqJt/BoqItem/BoqHistory; migrasi historis mempertahankan kontrak lamanya. Index diganti create-before-drop untuk MariaDB lama, retry mengenali index identik dan FK dibentuk ulang. Sudah dieksekusi pada dompisprov dengan backup/lock/maintenance singkat/audit:30tabel digest identik termasuk3514WO/1448harga/1KML dan bookmark. Reset Bookmark pada List melepas semua pilihan scope actor di seluruh halaman, mengabaikan filter dengan penjelasan/modal jumlah Ya/Tidak; Area hanya pilihan bersama Area, Branch/TL hanya lokal tim pada assignment aktif, tidak menghapus arahan Area/Branch lain/bookmark lokal WO pindah. Gate/CSRF/throttle, token actor-role/digest15menit, lock/transaksi, audit batch dan replay idempotent; sumber/progress/BOQ/riwayat WO tidak diubah. Tidak melakukan reset aktual otomatis. FullSQLite354/355 (1 ekspektasi branding login lama), MariaDB53 dan JS29 lulus; build/lint/cache lulus. Tidak ada env/dependency baru. Berkas/perintah/backup/batas: docs/BOQ_RENAME_PRIORITY_RESET.md.


## Toolbar prioritas dan optimasi — pemilik 28 Oktober 2026
Reset Bookmark ditempatkan di sebelah Download Excel pada toolbar List Order Prioritas; petunjuk jumlah/cakupan, modal Ya/Tidak dan kondisi nonaktif tetap. Base query scoped dan total agregasi dipakai ulang untuk pagination seluruh state, termasuk closed; ringkasan tetap empat key. Snapshot/commit reset membaca enam atribut mentah tanpa hidrasi model, scope/lock/digest/transaksi/audit/replay tetap. List Prioritas8→7query pada5/30WO di SQLite/MariaDB, tanpa query per baris; filter Branch maksimal8. Tes terkait63SQLite/63MariaDB dan29JS lulus, build/lint lulus. Tidak ada reset aktual, migration/env/dependency atau perubahan branding. Manifest/hasil/batas: docs/PRIORITY_TOOLBAR_OPTIMIZATION.md.


## Akses mapping upload lintas driver — perbaikan 28 Oktober 2026
SyncJob.id_users di-cast integer agar perbandingan pemilik ketat tidak menolak akun yang sama ketika PDO mengembalikan FK sebagai teks. Bug GET mapping403 direproduksi dengan ATTR_STRINGIFY_FETCHES; upload/replay/preview/commit/retry dan Blade pemilik kini konsisten tanpa melonggarkan Gate/ownership. Awaiting_mapping tetap menunggu konfirmasi kolom, worker belum diantrekan. Detail riwayat hanya redirect ke mapping bagi pengunggah; operator sync lain membaca status/petunjuk tanpa mengambil alih, form langsung tetap403. Tes terkait46SQLite/46MariaDB dan29JS, build/lint lulus. Tidak ada migration/env/dependency atau mutasi data aktual; server pengguna belum diakses/diperbaiki langsung. Manifest, langkah rilis dan batas diagnosis: docs/SYNC_MAPPING_ACCESS_FIX.md.


## Pengukuran login/upload dan optimasi parser — 28 Oktober 2026
Keluhan login→dashboard dan upload0,9MB→mapping lambat ditangani dengan pengukuran terpisah, bukan asumsi worker/query saja. Parser XLSX menghindari traversal ulang subtree dan XPath value/formula berulang; kontrak validasi/ID/header/formula BOQ tetap. Median lokal5.000baris585→503ms, bukan SLA produksi. Sync Center hanya query inbound aktif dan proyeksi riwayat tanpa snapshot besar (Excel4query). MeasureWebPerformance opt-in WEB_PERFORMANCE_ENABLED defaultfalse/WEB_PERFORMANCE_SLOW_MS1000 menambah Server-Timing dan log route/method/status/app_ms/database_ms tanpa input/SQL/identitas; memakai delta durasi koneksi tanpa query log/listener. Upload→mapping tetap sinkron, worker baru preview/commit sesudah konfirmasi; tidak mengurangi hash/audit atau cache KPI lintas akun. FullSQLite366/367 (1 branding lama), MariaDB89/JS29/build/lint/cache terisolasi lulus. Tanpa migration/dependency/mutasi; env diagnostik opsional. Server belum diukur/deploy, masih memerlukan timing dan kapasitas aktual. Manifest/langkah/batas: docs/LOGIN_UPLOAD_PERFORMANCE.md.


## Polling sinkronisasi dan alasan konfirmasi — 29 Oktober 2026
Pemilik menegaskan preview dibuka memakai akun lain: konfirmasi tetap hanya pengunggah, bukan hak semua operator Area. Preview_ready menampilkan nama pengunggah/waktu berlaku dan alasan terpisah untuk akun, expiry, kontrak, mode atau hasil kosong; tidak menambah takeover/autoextend/commit otomatis. Polling relatif same-origin, HTML/JSON private no-store+Vary Accept/Cookie; fetch validasi JSON/state/job, timeout20detik, satu request, backoff3–30detik, Retry-After42930–120detik, retry manual dan pesan sesi/akses/server spesifik, tanpa berhenti diam-diam setelah10gangguan. Worker/state/data tidak dimutasi oleh polling. Tes59SQLite/35JS/build/lint lulus; tambahanMariaDB tidak dijalankan karena izin database ditolak. Tanpa migration/env/dependency; assetVite harus ikutdeploy. Server belum diakses, penyebab polling produksi belum terbukti tanpaHTTPstatus/log. Manifest/langkah/batas: docs/SYNC_POLLING_CONFIRMATION.md.
