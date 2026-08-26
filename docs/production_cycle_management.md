# Production Cycle and Restocking Design (Poultry + Ruminant)

This document describes how to run **overlapping production cycles** (old stock finishing while new stock has already arrived) without losing annual totals.

## 1) Core principle: never delete historical production records

When a cycle ends, mark it as `closed` and keep all records tied to that cycle.

- Daily production rows remain in daily tables.
- Sales remain in `sales_records`.
- Expenses remain in `farm_expenses`.
- Inventory usage stays in `stock_transactions`.

This preserves end-of-year reports while allowing new cycles to start.

## 2) Introduce cycle master records

Use one cycle header per batch/herd cohort in `production_cycles`.

Suggested examples:
- Broiler batch: `BROILER-2026-04-A`
- Layer flock: `LAYER-2025-11-01`
- Ruminant fattening lot: `CATTLE-2026-Q2-01`

Key lifecycle:
1. **Planned** (`planned`) – expected intake date and target count.
2. **Active** (`active`) – stock received and production started.
3. **Closed** (`closed`) – sold off / culled / retired.
4. **Archived** (`archived`) – optional read-only status for old cycles.

## 3) Track new stock while old stock is still active

When new birds/animals are introduced before the old cycle ends:

1. Create a **new cycle** row in `production_cycles`.
2. Register the intake in `stock_batches` (quantity, unit cost, vendor, received date).
3. Keep both cycles active simultaneously.
4. For all daily entries, sales, and expenses, always select `cycle_id`.

This allows side-by-side monitoring:
- Cycle-level mortality and performance.
- Feed and medication costs by cycle.
- Margin by cycle.

## 4) Close cycle without losing year-end totals

At close time:

1. Post the final disposal sale (or transfer/cull adjustment).
2. Capture end-of-cycle KPI snapshot (`closing_headcount`, mortality %, FCR, egg average, etc.).
3. Update `production_cycles.status = 'closed'` and save `close_date`.

Reports should work in two modes:
- **Operational view**: only `active` cycles.
- **Financial/yearly view**: all statuses by date range (`sale_date`, `expense_date`, `record_date`).

Because records are date-based and cycle-linked, year-end totals remain accurate.

## 5) Recommended reporting structure

### A) Cycle P&L report
Group by `cycle_id`:
- Revenue from `sales_records`
- Direct costs from `farm_expenses`
- Feed usage from daily/stock tables
- Net margin per cycle

### B) Period P&L report (month/quarter/year)
Filter by date range and include all cycles (active + closed).

### C) Carry-over dashboard
Show:
- Active cycles count by species/type
- Cycles closing this month
- New cycles started this month

## 6) Data entry guardrails

- Daily forms must require `cycle_id` once multiple active cycles exist for same farm type.
- Prevent accidental closure if headcount is not reconciled.
- Disallow deleting a cycle that has linked transactions.
- Allow adjustments via explicit adjustment records, not hard deletes.

## 7) Migration summary

`migrations/002_production_cycles.sql` adds:
- `production_cycles` (cycle master)
- `stock_batches` (new stock intake batches)
- `cycle_id` links on daily records, sales, and expenses
- Indexes for cycle/date reporting

This supports poultry and ruminant systems where production cycles overlap.
