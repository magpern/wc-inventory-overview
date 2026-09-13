# WC Inventory Overview v1.44.0 — Personal Use Stock Adjustments (M27)

**Standalone release · Schema v12**

First post-roadmap milestone: audited outbound personal-use stock withdrawals with EUR weighted-average cost tracking, movement ledger integration, and a full admin workflow on **Inventory & Profit → Stock Adjustments**.

## Highlights

- **Stock Adjustments tab** — draft / posted / voided lifecycle for personal use documents (`SA-YYYY-NNNNN`).
- **Outbound WAC costing** — `Restock_Service::apply_outbound_line_change()` / `apply_outbound_line_reversal()`; sole caller `Stock_Adjustment_Service`.
- **Movement types** — `personal_use` and `personal_use_void` with `reference_type = stock_adjustment`.
- **Operator reconciliation** — documented §4.1 procedure for historical consumption without double-reducing stock.
- **Overview prefill** — “Record personal use” link on stock-managed rows (GET navigation only).

## Schema

- `DB_VERSION` **11 → 12**
- New tables: `wc_io_stock_adjustments`, `wc_io_stock_adjustment_lines`

## Not in this release

- REST / CLI for stock adjustments
- Generic shrinkage / found-stock adjustments (future milestone)
- Automatic backdating of consumption dates

## Upgrade notes

After upgrade, run plugin activation/migration (automatic on load). Posted adjustments survive code rollback only if voided first — see `docs/rollback-plan.md`.

## Documentation

- Plan: `docs/milestones/m27-implementation-plan.md`
- ADR: `docs/adr/0004-outbound-stock-adjustment-ownership.md`
- Operator guide: `docs/admin-guide-stock-adjustments.md`
