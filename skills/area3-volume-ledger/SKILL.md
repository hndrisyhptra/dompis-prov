---
name: area3-volume-ledger
description: Implement and reconcile its Area 3 BOQ component volume reservations, commitments, accepted actuals, authorized release and continuation allocation without double use.
---

# area3-volume-ledger

## Purpose
Represent legitimate reuse of unfinished BOQ volume through a single root ledger, separated by designator, component and physical unit.

## Invariant
For each root PP × BOQ item × Material/Jasa component:
`root quantity = available + reserved + committed + accepted`.
Every term is nonnegative. Never aggregate meters, units, links, points and batang into one physical total.

## State meanings
- Available: unallocated root component quantity, including authorized released remainder not subsequently reserved.
- Reserved: draft/sah BA KH allocation not yet backed by Sah SPK; also unassigned component allocation held by continuation PP.
- Committed: Sah SPK quantity less approved accepted actuals and authorized release.
- Accepted: actual quantity from fully approved three-level terms, immutable after approval.
- Release: Admin-authorized movement of selected unfinished committed quantity back to available, retaining original SPK, Final term and BA PP history.

## Constraints
- Root BOQ defines one stock of volume; continuation BOQs do not create a second stock.
- Material and Jasa may have different assigned and actual quantities; excluded components have zero capacity and display N/A.
- Draft negotiation reserves quantity. SPK Sah moves that reservation to committed exactly once.
- Pending/draft term actuals remain committed until all approvals complete. Only one active next-term chain is allowed per SPK.
- Release requires BA PP Sah, Final term approved by all three roles and Admin/Superadmin authorization. Release cannot exceed unaccepted and unreleased quantity.
- Continuation allocation must fit BOTH the source BA PP released-but-unallocated component amount and the global root available amount.
- A root or different mandor cannot consume quantity already reserved for a continuation. Do not count its unassigned allocation plus its draft BA KH twice.
- A continuation needs a unique manual No PP, Parent PP, Root PP and source BA PP. Its budget is a suballocation priced from the source BOQ snapshot.

## Implementation checklist
1. Reuse `ledger`, `capacity`, `remaining`, `accepted`, `released` and `continuationAvailable` from the domain service.
2. Validate every allocation at mutation time; do not rely on stale rendered capacity.
3. Keep release history and continuation lineage append-only. Never rewrite original quantities to represent remaining work.
4. Apply changes transactionally; production adds row locks, optimistic revisions, idempotency and immutable ledger events.
5. Report per component and unit; the dashboard may select one item or show percentages/counts for mixed-unit summaries.

## Acceptance
Test overlapping BA KH drafts, SPK issuance, multiple approvals, early Final, repeated release, accepted-volume reuse, two continuations competing for one source and a full continuation contract cycle. Reconcile every root ledger after each transition, including zero and fractional quantities.
