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
