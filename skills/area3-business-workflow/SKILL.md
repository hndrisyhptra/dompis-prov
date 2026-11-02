---
name: area3-business-workflow
description: Implement its Area 3 Project Plan, BOQ, mandor contracting, three-level payment approval, APM gating, BA PP and continuation state transitions.
---

# area3-business-workflow

## Purpose
Preserve the actual process: PP → BOQ Final → SPH TA → Penawaran Mandor → Harga Kesepakatan → BA KH → SPK → Termin → PM → Manager Konstruksi → Finance → APM → BA PP Final → pelepasan Admin → PP lanjutan.

## Boundaries and constraints
- Prototype service mutations live in `area3-prototype/src/domain.ts`; UI calls the centralized store transaction. Production must enforce the same rules server-side, not trust the demo role selector or localStorage.
- Admin Konstruksi manages PP, BOQ, documents and volume release. Superadmin is explicitly privileged for those operational actions, but never substitutes for any payment approver.
- Level 1 only PM Konstruksi, level 2 only Manager Konstruksi after PM, level 3 only Finance after Manager.
- BA KH/SPK/BA PP attestation is a separate document workflow, never a three-level payment inbox task.
- Each SPK has at most three terms. Term 3 must be Final. Any earlier Final blocks all subsequent terms.
- The preceding Partial must have all three approvals plus a valid APM before the next term exists. Full payment is not a prerequisite.
- APM, payment, physical completion, document status and approval status are distinct fields.
- Return/reject requires a reason. Return permits correction and restarts the active approval chain; all prior decisions remain in append-only history. Rejection is terminal in this prototype.
- Submitted/approved quantities and issued price snapshots are immutable through draft forms.

## Implementation checklist
1. Validate permissions and existing status inside each service before mutation, then calculate on a cloned transaction; persist only on success.
2. PP requires manual unique No PP, Unit Kerja, Branch, Nama Kegiatan, ID Project, budget and date. Attestation also requires narrative, team, full schedule, risk, communication and quality.
3. BOQ Final and PP Sah precede mandor contracting. BA KH Sah precedes SPK. SPK Sah commits reserved volume and enables term 1.
4. Actual term volume is mandatory. Submission creates PM inbox work, locks fields, logs actor/time and updates counts from the shared state.
5. Finance records and validates a unique APM; corrections cannot invalidate a predecessor after its next term has been created without a future formal correction process.
6. Final approved creates Draft BA PP. Admin attests with evidence reference, releases selected valid remainder, then creates a manually numbered continuation.

## Acceptance
Attempt out-of-sequence approvals, wrong roles, missing reasons, fourth terms, next-term creation without APM, release before BA PP Sah, and duplicated PP numbers. Each must fail without partial mutation. Walk scenarios A–E from `src/seed.ts` and verify history and inbox changes.
