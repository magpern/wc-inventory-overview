# WC Inventory Overview 1.25.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.24.0** (M7 Storefront Expected Delivery, schema v10).

## What changed

**Milestone M8 — Hardening & GA.** Not a feature milestone: a hardening, cleanup, and conformance pass closing out every genuinely-justified, previously-deferred item from M0–M7. **Zero new domain concepts, zero schema change (`DB_VERSION` stays `10`), zero public API change.** With M8 complete, this platform (M0–M8) is considered **Version 1.0 / GA ready**.

- **Physically removed the M6-deprecated Batch Intake create/apply code** — per the governance rule reserving that deletion for M8. `WC_Inventory_Overview_Batch_Intake_Service`'s five `@deprecated` methods and their private-only helpers, `WC_Inventory_Overview_Batch_Intake_UI` (deleted entirely), and `WC_Inventory_Overview_Plugin::ajax_batch_preview()`/`handle_batch_apply_post()` are gone — all of it already unreachable from any admin/CLI path since M6. The one remaining test dependency (`create_legacy_batch()`, used 47 times across the M6 migration suite) was rewritten first, verified green, before any production code was touched. Legacy `wc_io_purchase_batches*` tables and history are completely untouched (D14, frozen forever) — this removes code, not history.
- **Fixed a real, admin-visible bug:** `PO_Delay`'s delayed-detection predicate gated on `status = 'placed'` only, so a partially-received Purchase Order's genuinely overdue remaining outstanding was never flagged "Delayed." Now also covers `partially_received`, mirroring the M5 precedent already applied to the Incoming query. This was the admin-side root cause the M7 storefront Resolver already defended against independently (Invariant M7-1) without fixing.
- **Repo-wide sibling-plugin-coupling conformance guard** — the guard M7 explicitly deferred to "M8's conformance audit." Confirms mechanically, not just by prose, that this plugin has zero named dependency on any sibling plugin: every `class_exists()`/`function_exists()` check across all of `includes/` is on a closed WordPress/WooCommerce/PHP-core allowlist, zero `remove_filter()`/`remove_action()` calls exist, zero hardcoded sibling-plugin identifiers appear anywhere.
- **The full integration test suite is clean and CI-blocking for the first time.** 11 pre-existing test-content bugs (stale method signatures, wrong class names, wrong column names, a stale return-shape assumption) across the FX, Movements, Costing, and Cost Adjustment characterization suites are fixed — every fix verified against current, unmodified production behavior; zero production code changed. `tests.yml`'s `continue-on-error: true` exception on the integration suite is removed.
- **CI pipeline hardening:** `ci.yml`/`release.yml` aligned to PHP 8.4 (the version `tests.yml` already exercised); the one live PHP 8.4 deprecation notice in the codebase fixed; a coverage gap in the CI-blocking test filter closed.
- **GA-scale (200-item) performance confirmation:** the existing Inventory Position (D12) and Expected Delivery (Invariant M7-3) query-scaling guards — proven at ~20–40 items — re-verified at a size closer to a real catalog. Confirmatory only, no caching or optimization change.

**Testing:** unit suite 216 tests / 1,456 assertions; M1–M8-focused (CI-blocking) suite 450 tests / 2,247 assertions; full integration suite 245 tests / 834 assertions, 0 errors, 0 failures, 0 skips (now itself CI-blocking); all seven architecture guard files (six pre-existing per-milestone guards plus this milestone's own repo-wide conformance guard) pass, 64 tests / 818 assertions. All suites 0 failures except 7 pre-existing, documented risky `Test_DB_Transaction` tests unrelated to M8.

**Explicitly not in this release:**

- No schema change of any kind — `DB_VERSION` stays `10`.
- No new domain concepts, no new public API surface, no new settings, no new hooks or filters.
- No general PHPCS cleanup — the pre-existing ~559 errors / 634 warnings are unchanged, deliberately excluded as disproportionate to a hardening pass.
- No split of the `class-wc-inventory-overview-plugin.php` "god class" into tab controllers — real, documented tech debt, deliberately evaluated and deferred past GA rather than attempted under release-time pressure. Not a silent drop — recorded in `docs/architecture-audit.md`'s Known risks section.
- No forecasting, purchasing recommendations, analytics, ERP/accounting integration, barcode support, warehouse automation, REST/Store API/GraphQL expansion, mobile apps, reporting dashboards, supplier intelligence, or new storefront capabilities — all explicitly out of scope for a hardening milestone.

## Install / upgrade

1. Download **`wc-inventory-overview-1.25.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. No schema step — `DB_VERSION` stays `10`, no `ALTER` runs, no upgrade routine fires.
4. No merchant-visible change except: (a) the Batch Intake tab remains absent (unchanged since M6 — nothing to notice), and (b) a partially-received Purchase Order with a genuinely overdue remaining quantity will now correctly show "Delayed" where it previously didn't.

## Before tagging

Per [docs/release-runbook.md](release-runbook.md#m8-hardening--ga): confirm `DB_VERSION` is still `10` and the schema assertion is `ok: true`; confirm the Batch Intake removal is operationally invisible (no PHP fatal/warning anywhere in the admin); verify the `PO_Delay` fix on a real partially-received, past-due PO; confirm the sibling-plugin conformance guard passes; confirm the full test suite (unit, M1–M8-focused, and now-blocking integration suite) passes with zero failures; confirm Quick Restock / Cost Adjustment / Goods Receipts / PO Receiving / batch migration CLI / Supplier admin / Inventory Position / Storefront Expected Delivery all remain fully functional.

## Rollback

**M8 is code/test/CI-only — as clean as M7's rollback story, nothing to reverse.**

- **Code rollback 1.25.0 → 1.24.0:** unconditionally safe. M8 wrote no data, changed no schema, and mutated nothing anywhere in its surface. The physically-removed Batch Intake code was already unreachable before M8 — rolling back simply restores that same, still-unreachable code, with no operational difference. The `PO_Delay` fix is a purely computed-value change (INV-5: delayed is never stored) — rolling back just reverts to the pre-M8 predicate, nothing to un-flag.
- Legacy `wc_io_purchase_batches*` data and tables are untouched either direction.

See [docs/rollback-plan.md](rollback-plan.md) for the full explanation.

Changelog: [CHANGELOG.md](../CHANGELOG.md)
