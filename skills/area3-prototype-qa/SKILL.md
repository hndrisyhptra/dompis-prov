---
name: area3-prototype-qa
description: Test the its Area 3 interactive prototype through the linked business scenarios, permissions, persistence, financial/volume invariants, documents and responsive layouts.
---

# area3-prototype-qa

## Purpose
Validate observable business behavior, not screenshots alone. Source: `area3-prototype/src`; automated invariants: `tests/workflow.test.ts`; manual steps: `QA.md`.

## Checklist
- Run `npm test` and `npm run build` inside the prototype directory. Do not run production migrations or change Dompis Prov database to test the isolated frontend.
- Exercise every sidebar route and required create/detail/edit route, breadcrumb, search, status/Branch/date filters, pagination and contextual links.
- Create a unique manual PP, edit all six sections, save/reload, validate and attest. Test missing required data and duplicate number rejection.
- A: Simulasi Import Demo → price mismatch → review with note → Final. Verify finalization is blocked beforehand and no arbitrary file parsing is claimed.
- B/C: assign Material/Jasa separately, compare SPH/offer/agreement, exceed each KHS component ceiling, issue BA KH with evidence, create and attest matching SPK.
- D: actual volume → preview BA RP → submit → PM → Manager → Finance. Test wrong role, required return/reject reason, locked actuals, history and inbox counts.
- Record APM and partial payment independently. Next term must remain blocked until three approvals + valid preceding Partial APM, but must open without full payment.
- Test Term 3 Final and early Final blocks later terms. Historical quantities remain read-only.
- E: Final approved → BA PP evidence/attestation → selected component release → manually numbered continuation → verify Parent/Root/source, reserved volume and unchanged consolidated budget.
- Check BOQ, BA KH, SPK, current-term and cumulative BA PP totals against shared data. Preview all nine document types and print HTML. Do not invent signatures.
- Change KHS: affected draft BOQ needs review; issued historical snapshots remain unchanged. Superadmin user CRUD is separate from formal approvals.
- CSV exports reflect filters and contain actual generated data. JSON backup and reset confirmation work.
- Save form drafts, workflow events, APM, release and continuation; reload and use browser back/forward without losing state.
- Inspect 375, 768 and 1440px, light/dark, keyboard focus, modal trap/Escape, mobile drawer, table scroll, error/loading/empty/disabled reasons. Reset temporary viewport overrides.

## Acceptance and reporting
All primary actions must execute a real mock transaction or navigation, not a toast pretending to perform work. Report actual automated count, browser scenarios verified, untested paths and backend limitations separately. The demo role picker is not authentication. localStorage is not production financial storage. Never claim credentials or external integrations were tested.
