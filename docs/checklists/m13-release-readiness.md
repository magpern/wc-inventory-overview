# M13 Release Readiness — Printable Purchase Order

**Status:** Level A freeze complete.
**Date:** 2026-08-10
**Branch tip (at freeze):** `feature/m13-printable-purchase-order`
**CI proof PR:** https://github.com/magpern/wc-inventory-overview/pull/14 (**DRAFT — DO NOT MERGE**)

## Freeze record

| Item | Value |
|------|--------|
| M13 implementation | Complete |
| Level A completion review | Complete |
| Independent (Level B) audit | **Not performed** — Level A classification, no schema/migration/mutation/public-API/ownership-boundary/destructive/security/storefront/concurrency trigger applies |
| Plugin development version | `1.30.0` |
| `DB_VERSION` | `10` (unchanged; no schema migration) |
| GitHub Actions | Green — CI run `31428516066`, Tests run `31428515992` |
| Schema change | None |
| Mutation change | None |
| New public API | None |
| New capability | None |
| New public hook/filter | None |
| New dependency | None (`composer.json`/`composer.lock` unchanged) |
| Immutable plan | `docs/milestones/m13-implementation-plan.md` @ `73b0880` — untouched after materialization (single commit touches this file) |
| Individually released | **No** — intentional |
| Feature train | **M13** opens a new, unreleased train after the M9–M12 train released as `v1.29.0` |
| Next authorized process step | **Plan M14, or close this new train — only with explicit approval.** Do **not** start M14 without one. |

## Level A completion review (focused)

Reviewed the full M13 diff (`git diff main`, 18 files, 1821 insertions / 42 deletions) against `docs/milestones/m13-implementation-plan.md`:

- **Scope matches exactly:** one new presentation-only class (`PO_Print_Renderer`), one new handler + admin_post registration + detail-screen entry point in `PO_Admin`, three new test files, CI filter update, documentation, version bump. No printable Goods Receipt, no PDF library, no email/attachment feature, no new capability, no new public hook — all confirmed absent by direct inspection and by the architecture-guard tests passing.
- **No scope creep** into spend analysis, order-history, supplier merge, grace-days Settings UI, Position drilldown, storefront confidence changes, Coverage/Forecast, warehouse locations, or the `Plugin` god-class refactor — none touched, none mentioned as done.
- **No schema / `DB_VERSION` change:** `includes/class-wc-inventory-overview-install.php` has zero diff against `main`; `DB_VERSION` constant unchanged at `'10'`.
- **No mutation path:** INV-M13-1 guard (zero write/mutation tokens in the renderer) passes; `handle_print()` contains no `->insert(`/`->update(`/`->delete(`/`set_stock_quantity`/`update_post_meta` call anywhere in its body.
- **Renderer stayed presentation-only (INV-M13-2):** guard tests confirm zero `$wpdb`, zero calls into `Purchase_Orders`/`Purchase_Order_Lines`/`Suppliers`/any repository class, zero `wc_get_product`/`wc_get_product_object`, zero `current_user_can`/`check_admin_referer`/`$_GET`/`$_POST` inside `PO_Print_Renderer`.
- **Print data sourced only through approved read owners (INV-M13-3):** `handle_print()` calls exactly `Purchase_Orders::get()`, `Purchase_Order_Lines::list_for_po()`, `Suppliers::get()`, and `PO_Statuses::label()` — no direct `$wpdb`, no duplicated aggregation SQL.
- **Capability + nonce precede any data read (INV-M13-4):** verified both by static source-position assertion (`self::guard(VIEW_PO)` precedes `check_admin_referer()` precedes the first `Purchase_Orders::get()` call, textually, in `handle_print()`'s own body) and behaviorally (missing nonce, invalid nonce, a nonce scoped to a different PO id, and an unauthorized user are all denied with nothing rendered).
- **Draft excluded; the other five statuses printable:** `printable_statuses()` returns exactly `placed`/`partially_received`/`received`/`cancelled`/`closed_short`; a dedicated test locks this set; a draft PO is denied server-side even with a technically-valid nonce, and the "Print" link itself is never rendered for a draft.
- **Historical snapshots used; no live product lookup:** product/variation identity is sourced from the PO line's own `name_snapshot`/`sku_snapshot` (matching the same convention `PO_Admin::render_line_row()` already uses for the existing detail screen); confirmed by a behavioral test that a line referencing a since-deleted product still prints correctly, and by the architecture guard's absence check for `wc_get_product`/`wc_get_product_object` in the renderer.
- **Supplier resilience:** supplier name always falls back to the PO header's own `supplier_name_snapshot`; contact/reference fields are populated only when `Suppliers::get()` resolves and are simply omitted otherwise — confirmed by a behavioral test against a hard-deleted supplier row.
- **No new dependency:** `composer.json`/`composer.lock` have zero diff against `main`.
- **Existing PO Admin behavior unaffected:** the pre-existing `Test_WC_IO_PO_Admin` suite (save/place/cancel/close-short/duplicate/receiving-history/timeline) passed unmodified as part of the full 583-test M1–M13-focused run.
- **CI fully green** on the draft PR (CI + Tests workflows, both `completed`/`success`).
- **Documentation accurate; no document claims M13 released:** every mention of M13 alongside release-status language ("frozen", "unreleased", "not yet merged, tagged, or released") is consistent across `CLAUDE.md`, `CHANGELOG.md`, `readme.txt`, `docs/architecture-audit.md`, `docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/rollback-plan.md`, and `docs/release-runbook.md`; `readme.txt`'s `Stable tag` remains `1.29.0`. The pre-existing stale "unreleased M9–M12... pending a bundled release" wording in `readme.txt` (predating the actual train closure) was corrected as a narrow documentation fix — not treated as new product scope.

No small documentation/factual error requiring narrow remediation was found beyond the stale-wording fix already included in the documentation commit. No genuine architecture discrepancy was found.

## Explicit non-actions at this freeze

- Do not merge PR #14 into `main`
- Do not tag `v1.30.0`
- Do not publish a GitHub Release
- Do not deploy
- Do not perform an independent (Level B) audit — not triggered for this milestone
- Do not start M14

## Local quality gates (pre-push)

| Gate | Result |
|------|--------|
| PHP Parallel Lint | Pass (168 files) |
| Composer validate | Pass (`./composer.json is valid`) |
| Docker Compose config | Pass |
| PHPCS (M13-touched files) | Pass — 0 errors, 0 warnings |
| Unit suite | OK — 303 tests, 1714 assertions, 0 risky |
| M1–M13 focused suite | OK — 583 tests, 2811 assertions, 0 risky |
| Integration suite | OK — 291 tests, 1140 assertions, 0 risky |
| M13 architecture + renderer + handler tests | OK — 33 tests, 104 assertions |
| `release-audit.sh --development` | Pass |
| GitHub Actions (draft PR #14) | Pass — CI `31428516066`, Tests `31428515992` |
