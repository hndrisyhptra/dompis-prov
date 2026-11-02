---
name: area3-financial-calculation
description: Implement and verify official KHS component prices, agreed BA KH/SPK snapshots, actual term amounts, outstanding payment and consolidated budgets for its Area 3.
---

# area3-financial-calculation

## Purpose
Keep BOQ Owner, planning budget, mandor contracts, actual invoices and payments mathematically consistent without conflating their meaning.

## Constraints
- Master KHS is authoritative. Imported Excel prices are comparison only. Each mismatch requires explicit resolution before finalization.
- Material and Jasa are independent price components. An agreed Material price cannot exceed Material KHS even if Jasa is discounted; the reverse also holds.
- A missing component is N/A, not a zero-priced included component. An official price of zero is valid and distinct from null.
- Persist BOQ final price/version snapshots and BA KH/SPK agreed prices. Updating Master KHS invalidates affected draft BOQ review but never silently reprices final historical documents.
- Never fabricate the known official sample totals from unrelated synthetic rows. Prototype `DEMO-*` rows are explicitly synthetic (6 rows, BOQ Rp134.780.000 at seed version 1), not the supplied 14-item Rp140.056.789 reference.

## Calculations
- BOQ Owner = sum(volume × official Material price + volume × official Jasa price).
- BA KH/SPK = sum(assigned Material volume × agreed Material price + assigned Jasa volume × agreed Jasa price), included components only.
- Current term = sum(current Material actual × agreed Material price + current Jasa actual × agreed Jasa price).
- Previously approved actuals are never included in the current term again.
- Cumulative approved = sum(approved terms only). Projected remaining contract = SPK − previous approved amounts − current draft amount.
- Outstanding payment = approved term amounts − recorded payments. Draft/pending/rejected amounts are not approved outstanding.
- BA PP completed amount uses cumulative accepted quantities across all approved terms, not just the last Final.
- Consolidated project budget sums root PPs once. Continuation budgets are source BOQ-price suballocations and never add to consolidated budget.

## Implementation checklist
1. Use shared functions `boqTotal`, `dealTotal`, `termTotal`, `accepted`; derive screens, previews, CSV and KPI values from them.
2. Preserve precision and consistent rounding at item boundaries. Prototype rounds currency amounts to 2 decimals; display units/quantities independently. Production must use decimal/rational arithmetic and documented rupiah/cents rounding, never binary floats as financial authority.
3. Block NaN, infinity, negative quantities/prices, overbilling and payments above approved outstanding.
4. APM validation and payment recording are Finance operations and do not silently change approval decisions or quantities.
5. Export real CSV, escaping quotes and neutralizing formula-leading text; never advertise XLSX/DOCX generation when only HTML/CSV exists.

## Acceptance
Check excluded components, independent price ceilings, partial payment, multiple actual terms, cumulative BA PP, immutable historical prices after KHS update and root-budget deduplication. All summaries/previews must equal the underlying transaction rows.
