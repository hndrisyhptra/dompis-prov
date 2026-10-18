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
