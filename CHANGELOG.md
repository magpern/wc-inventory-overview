# Changelog — WC Inventory Overview

## [1.23.0] - 2026-08-07

**Milestone M6 — Migration & Retirement** — the headline guarantee: migrating legacy Batch Intake history into Goods Receipts leaves current WooCommerce stock and cost **byte-for-byte unchanged** for every affected product, because migration is historical record materialization, not receiving — it never mutates current stock/cost, only writes a historical record in today's schema (verified by a dedicated golden/characterization test, a release blocker in its own right). Replaces the batch↔movement regex linkage with the typed `reference_type`/`reference_id` columns M4 already added, exactly as Architecture v1.0 §1 (D14) promised. Retires Batch Intake's ability to create new batches — the one thing it still did that Goods Receipts (M4/M5) hadn't already superseded — while leaving the legacy tables frozen, readable, and permanently the audit trail behind every migrated receipt. **Schema v10** — two migration-tracking columns on `wc_io_purchase_batches` (`migrated_receipt_id`, `migrated_at`); no new business-domain schema. **Prerequisite:** v1.22.0 (M5 PO Receiving).

### Added

- **`wp wc-io migrate-batches [--apply] [--verify] [--batch=<id>] [--rollback=<id>] [--limit=<n>]`** — operator-initiated, dry-run by default (modeled on `reconcile-qty-received`'s shape). `--apply` migrates one batch at a time through `WC_Inventory_Overview_Batch_Migration_Service::migrate_batch()`, each call its own transaction (Invariant M6-1 — never a shared transaction across batches, which is what makes an interrupted run safely resumable: earlier-migrated batches stay committed, the failed batch fully rolls back, and a rerun picks up exactly where it stopped). `--verify` is the permanent, read-only reconciliation tool for this data going forward. `--rollback=<id>` undoes one batch's migration (deletes its migrated receipt/lines/costs, clears its movement reference, clears its tracking columns) after an operator confirmation prompt — never a Goods Receipt void, and never touches current stock/cost either.
- **`WC_Inventory_Overview_Batch_Migration_Service`** — the migration engine. Every batch's migration is a pure function of that batch's own already-stored rows (Invariant M6-2 — order independence: batches may be migrated in any order with an identical result, since migration never reads current stock, current cost, or any other batch's data). Migrated receipts always carry `source = 'migrated'`, `supplier_id = NULL` (never fuzzy-matched from the batch's free-text supplier name), and `po_line_id = NULL` on every line (never a fabricated PO linkage, per D7 and M5's own binding note). Receipt numbers are allocated in the batch's *original* year, not the migration year; timestamps (`posted_at`/`created_at`/`updated_at`) are the batch's original `created_at`, not migration time (`wc_io_purchase_batches.migrated_at` records that separately).
- **`Goods_Receipt_Service::void()` migrated-source guard** — rejects voiding a `source = 'migrated'` receipt with a clear error. Voiding reverses stock/cost relative to *current* state, which is wrong for a historical replay row for the same reason forward-migration never calls `post()`.
- **`WC_Inventory_Overview_Landed_Cost_Types`** — the landed-cost-type vocabulary (7 slugs/labels, unchanged), extracted out of `Batch_Intake_Service` into a small, neutral class both `Goods_Receipt_Costing` and (while retained) `Batch_Intake_Service` depend on — closing the hidden-coupling remediation trigger M4's own plan flagged for this exact moment.
- **`WC_Inventory_Overview_Movements::backfill_reference()`** — the sole, purpose-built writer that updates one existing movement row's `reference_type`/`reference_id` in place (never inserts a new row, never touches quantity/cost/note/timestamp columns), used exclusively by migration.
- **Tests:** `tests/unit/batch-migration/` and `tests/integration/batch-migration/` (81 new tests) covering architecture guards (no live-mutation calls anywhere in the migration path), pure field-mapping, the historical-integrity golden test, movement backfill, idempotency/resumability, a deterministic forced-failure transactional-rollback test, order independence, rollback symmetry, retirement regression, the landed-cost-type extraction characterization, and per-batch query-cost performance — plus new coverage in `tests/integration/goods-receipt/` (migrated-void-guard) and `tests/integration/install/` (schema v9→v10 upgrade).

### Removed

- **Batch Intake's create/apply entry points** (`admin_post_wc_io_batch_apply`, `wp_ajax_wc_io_batch_preview`) — no new batch can be created after this release. The "Batch Intake" tab is gone from the Restock / Cost Adjustment admin nav (default subview becomes Quick Restock); a stale `restock_view=batch` bookmark now falls back to Quick Restock instead of erroring.

### Verified unchanged

- Every affected product's stock, average unit cost, and inventory value are byte-for-byte identical before and after a full migration run — the headline claim of this milestone, verified by a dedicated golden test across simple/EUR, USD-with-landed-cost, blended-existing-average, and multi-batch/multi-currency scenarios.
- Legacy `wc_io_purchase_batches`/`wc_io_purchase_batch_lines`/`wc_io_purchase_batch_costs` tables and rows are never dropped, truncated, or otherwise destroyed by any code path in this release (D14 — frozen, not destroyed).
- Quick Restock, Cost Adjustment, Goods Receipts (M4), PO Receiving (M5), Supplier admin, and Inventory Position are all unaffected by Batch Intake's retirement.
- `Batch_Intake_Service`'s create/apply/preview methods and `Batch_Intake_UI::render_panel()` are retained (marked `@deprecated`, disabled-not-deleted, slated for physical removal in M8) — not broken, just unreachable via the removed hooks.

### Important

Unlike M4/M5, this release does **not** introduce a new "code rollback is unsafe" risk class — see the new M6 section at the top of `docs/rollback-plan.md`: migrated Goods Receipts are purely additive rows a pre-M6 codebase never reads, so a plugin-code rollback to v1.22.0 is safe by construction even after batches have been migrated. See `docs/migration-guide-batch-intake.md` for the full operator runbook (backup, dry-run, apply, verify, rollback, and recovering from an interruption).

## [1.22.0] - 2026-08-06

**Milestone M5 — Purchase Order Receiving** — connects Purchase Orders (M2) to the Goods Receipt engine (M4): `qty_received` becomes a real, maintained column (full INV-4 formula), and receipt lines can now link to a PO line. `Goods_Receipt_Service` remains the sole stock/cost mutator and gains a second responsibility — sole business orchestrator for `qty_received` changes, delegated to a new sole-owner class. No second mutation path was introduced anywhere. **Schema v9** — one column addition, zero new tables (M4 had already prepared `receipt_line.po_line_id` and `goods_receipt.source` for this moment). **Prerequisite:** v1.21.0 (M4 Receipt Engine).

### Added

- **`qty_received` on `wc_io_purchase_order_lines`** (schema v9): the full INV-4 formula, `qty_outstanding = GREATEST(0, qty_ordered - qty_received - qty_cancelled)`. The forbidden-column guard from M2/M4 is lifted — the one `forbidden_columns` entry M5 is permitted to change. `Purchase_Order_Lines::increment_qty_received()` is the sole physical writer anywhere in the codebase (architecture-guard enforced).
- **`WC_Inventory_Overview_PO_Receiving_Sync`** — the sole owner of every `qty_received` mutation and its PO-status/PO-event side effects. `apply_line_delta()` (the normal receiving path) is called only by `Goods_Receipt_Service`, from inside its existing transaction, immediately after that line's stock mutation and movement insert succeed. `reconcile_line()` (the reconciliation path) is called only by the new CLI command. Neither method opens its own transaction. Three-tier ownership chain — orchestrator (`Goods_Receipt_Service`) → owner (`PO_Receiving_Sync`) → physical writer (`increment_qty_received()`) — enforced by dedicated architecture guards.
- **Two new PO statuses**, `partially_received` / `received`, auto-transitioned only via a pure, direction-agnostic recompute function (`PO_Statuses::recompute_for_receiving()` — the same current-state-relative design principle M4 used for void correctness, applied here to status). Never reachable through the operator-gated transition table; `cancel`/`close_short` remain available from `partially_received`, not from `received`; neither status is editable.
- **Receiving against a PO**: a "Receive" button on the PO detail page (gated by a new `RECEIVE_PO` capability, default `manage_woocommerce`) pre-fills a new Goods Receipt draft from the PO's outstanding lines — reusing the same `create_draft_from_post()` M4 already built, no new persistence method. The line editor's product picker gained an optional `po_line_id` per line; product-mismatch between a submitted line and its referenced PO line is rejected before any draft is even saved.
- **Mixed and multi-PO receipts**: one receipt may contain PO-linked lines (from one or more POs) alongside direct lines; `source` (`direct`/`po`/`mixed`) is derived from line composition, never operator-chosen.
- **Over-receipt, per D5**: never blocked. A line's quantity may exceed its PO line's current outstanding; the post-confirm screen warns explicitly, and the resulting PO event carries `over_receipt`/`qty_over` markers.
- **Five new PO event types**: `po_line_received`, `po_line_receipt_voided`, `po_partially_received`, `po_received`, `po_qty_received_reconciled` — closing the audit-trail gap M4's own Audit-trail decision explicitly reserved for this milestone (INV-6's "PO event log" clause, structurally inapplicable to M4's PO-less receipts, is now literally satisfiable).
- **Reconciliation tooling**: `wp wc-io reconcile-qty-received [--fix] [--po=<id>]` — read-only drift report by default; `--fix` repairs through `PO_Receiving_Sync::reconcile_line()` only, never bypassing the sole-writer chain. Every repair is individually logged and recorded as its own PO event; summary output reports verified/repaired counts.
- **Receiving history**: a bulk (not per-line) query on the PO detail page lists every receipt line fulfilling any of that PO's lines; Goods Receipt detail pages show a "Fulfils: PO-XXXX line N" back-link per PO-linked line. PO line rows gain a "Received" column.
- **Tests:** `tests/unit/po-receiving/` and `tests/integration/po-receiving/` (12 new files) covering the full formula, the status-recompute function's direction-agnostic behavior, both mandatory rollback regression scenarios (post-A/post-B/void-A, and post-A/post-B/void-B/void-A — order-independence), the forced-failure test proving stock/`qty_received`/PO-status roll back together, over-receipt, mixed/multi-PO receipts, pre-transaction validation, and the M3 Incoming regression M3's own plan deferred to this milestone.

### Fixed

- **M3's Inventory Position "Incoming" figure** now correctly reflects receiving: the raw SQL `GREATEST()` literal in `Purchase_Order_Lines::query_open_lines()` gained the `qty_received` term, and its `WHERE` clause now includes `partially_received`/`received` POs (previously `placed` only) so a partially-received PO's remaining outstanding still surfaces as Incoming.
- **Outstanding-quantity display** — the receipt line editor now shows a live "Outstanding: X.XXXX" figure next to the qty field for every PO-linked line, read fresh at render time (found missing during a pre-tag independent audit against the M5 plan's own Definition of Done).
- **Mandatory over-receipt warning** — the post-confirmation screen now shows a non-suppressible warning naming every over-receiving line and its over-received quantity whenever any line's quantity exceeds its current outstanding, reusing the same server-side over-receipt assessment already computed at post time (found missing during the same audit).
- **N+1 query pattern in pre-transaction PO-line validation** — `Goods_Receipt_Service::validate_and_assess_po_linked_lines()` now bulk-fetches every referenced PO line and owning PO (two new repository methods, `Purchase_Order_Lines::list_by_ids()` / `Purchase_Orders::list_by_ids()`) instead of one `get()` call per line; the performance test suite now verifies constant query cost and exercises the plan's own named ~100-line scale, previously untested past 4 lines.
- **`PO_Service::close_short()` and `qty_received`** — closing a PO short now cancels exactly a line's unreceived remainder (`qty_ordered - qty_received`) instead of the full ordered quantity, restoring the invariant `qty_received + qty_cancelled == qty_ordered` for lines closed short after a partial receipt (previously the displayed "Cancelled" figure could exceed what was actually never received).

### Verified unchanged

- `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()`'s caller set gained zero new entries — `PO_Receiving_Sync` never calls either method; all stock/cost mutation still flows exclusively through `Goods_Receipt_Service`.
- No header-level `po_id` column exists on `wc_io_goods_receipts` (D6: line-level linkage only, unchanged from M4).
- No new value is ever written to the per-line `wc_io_purchase_order_lines.status` bookkeeping column — "line completion" is derived from `qty_outstanding == 0`, never stored as a line-level enum.
- Batch Intake, Quick Restock, Cost Adjustment, and Supplier admin behavior are all unmodified.
- Every M4 architecture guard's disposition (kept unchanged / revised with a named replacement / retired with a named replacement) was individually verified, not assumed — none silently broken, none silently deleted.

### Important

Like M4, M5 mutates state that a code-only rollback cannot reverse: a plugin-code rollback to a pre-M5 version does **not** reverse the `qty_received`/PO-status effects of receipts already posted under M5 — see the extended note in `docs/rollback-plan.md`.

## [1.21.0] - 2026-08-06

**Milestone M4 — Receipt Engine (Goods Receipt)** — the first milestone that mutates WooCommerce stock and weighted-average cost through this plugin (D3/INV-2). Implements "Quick Receive Without PO" (D7): direct receipts, no PO linkage. **Schema v8** — three new tables plus an `inventory_movements` ALTER. **Prerequisite:** v1.20.0 (M3 Inventory Position).

### Added

- **Goods Receipt entity** (`wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs`): header/lines/landed-costs, three-state lifecycle (`draft → posted → voided`, no reopen), `GR-{YYYY}-{NNNN}` numbering (never-reuse, mirrors PO numbering). `receipt_line.po_line_id` exists (nullable, indexed) for M5 but is never populated by any M4 code path.
- **`WC_Inventory_Overview_Goods_Receipt_Service`** — the sole entry point for every M4 inventory mutation (structurally enforced by an architecture-guard test). `post()`/`void()` each run inside exactly one `WC_Inventory_Overview_DB_Transaction::run()` closure, with every fallible call routed through a `throw_if_error()` WP_Error→Exception bridge (`DB_Transaction::run()` only catches `Exception`). Forced-failure tests prove full SQL rollback: zero partial stock/cost/movement changes, receipt status unchanged.
- **`WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal()`** — voiding's current-state-relative reversal: subtracts only the voided receipt line's own stored delta from *current* stock/average, not a snapshot restore, so it composes correctly no matter how many other receipts posted against the same product in between. Rejects (does not partially apply) when the resulting stock would go negative.
- **Landed-cost allocation** (`WC_Inventory_Overview_Goods_Receipt_Costing`): proportional-by-line-value formula ported from `Batch_Intake_Service`, remainder to the last line.
- **Movement provenance**: `TYPE_GOODS_RECEIPT` / `TYPE_GOODS_RECEIPT_VOID` movement types; `wc_io_inventory_movements` gains `reference_type` / `reference_id` / `supplier_id` (nullable — existing `purchase`/`purchase_batch`/`cost_adjustment` inserts unaffected).
- **Idempotency**: one-shot request tokens (`gr_post`/`gr_void` contexts, reusing `PO_Request_Token`) consumed as the very first statement of `post()`/`void()`, plus a compare-and-swap status `UPDATE ... WHERE status = %s` as the transaction's first write — the complete M4 concurrency model (no row locking, no `SELECT ... FOR UPDATE`, deliberately).
- **"Receive Stock" admin tab**: draft create/edit/delete, product/variation picker (excludes variable parents, grouped, external, non-stock-managed products), landed-cost rows, computed preview, explicit post-confirmation screen, void with mandatory reason, read-only posted/voided view. Alongside — not replacing — Batch Intake, Quick Restock, and Cost Adjustment.
- **Capabilities**: `VIEW_RECEIPT` / `EDIT_RECEIPT` / `POST_RECEIPT` / `VOID_RECEIPT` / `DELETE_RECEIPT`, defaulting to `manage_woocommerce` through the existing filterable map (no new WordPress capability); enforced both in the admin controller and independently inside every service mutation method.
- **Tests:** `tests/unit/goods-receipt/` (numbering, lifecycle, 16-test architecture guard) and `tests/integration/goods-receipt/` (repositories, costing/allocation, Restock reversal in isolation, transactional post/void including the intervening-receipt void regression, idempotency, capability) — 230 tests / 1,039 assertions. `tests/docker/run-phpunit.sh`'s blocking filter now includes the `Test_WC_IO_Goods_Receipt_` family alongside the existing M1/M2/M3 prefixes.

### Fixed

- **Object-cache/rollback divergence** (found during M4 implementation, not merely anticipated): a rolled-back `post()`/`void()` correctly reverted the underlying SQL row, but WordPress's own `update_post_meta()` writes through to the object-cache `post_meta` group synchronously — a write a SQL `ROLLBACK` cannot reach on a persistent cache backend (Redis, on this deployment). Fixed by calling `clean_post_cache()` for every touched product on both the commit and the rollback path (pure invalidation, safe regardless of transaction outcome).

### Verified unchanged

- Schema is additive; `qty_received` still absent from `wc_io_purchase_order_lines` (forbidden-column guard unchanged from v7).
- No PO table (`wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_po_events`) is ever written by M4 — no PO linkage, no `qty_received`, no PO events, no PO status/quantity change (verified by the architecture guard).
- Batch Intake, Quick Restock, Cost Adjustment, Purchase Order admin, Supplier admin, and Inventory Position behavior are all unmodified.
- M0 golden suite and existing characterization fixtures unchanged; the cumulative integration suite's 13 pre-existing failures (4 errors + 7 failures + 2 skips, documented in `docs/testing.md`) are unchanged in count and identity — M4 introduced zero new failures.

### Important

Unlike M1–M3, M4 mutates WooCommerce stock and cost. A plugin-code rollback to a pre-M4 version does **not** reverse the stock/cost/value effects of Goods Receipts already posted under M4 — see the new prominent note in `docs/rollback-plan.md`.

## [1.20.0] - 2026-08-05

**Milestone M3 — Inventory Position** — a first-class, read-only Inventory Position ({On Hand, Incoming, Position}, D11) for every simple product and variation, surfaced on Inventory Overview. **No schema change, no migration** — `DB_VERSION` remains `7`. **No receiving** — no Goods Receipts, no stock/cost mutation, no `qty_received`; M4/M5 will extend the Incoming formula once receiving exists. **Prerequisite:** v1.19.1 (M2 test-infrastructure hotfix).

### Added

- **Inventory Position Resolver** (`WC_Inventory_Overview_Inventory_Position_Resolver`): stateless, read-only calculator — `Position = On Hand + Incoming` — independent of `$wpdb`, WooCommerce product loading, and PO repositories.
- **Inventory Position Service** (`WC_Inventory_Overview_Inventory_Position_Service`): the sole authoritative calculator (D12), single (`get_position()`) and bulk (`get_positions_bulk()`); aggregates independent contributing PO lines in PHP, retains them individually (`incoming_lines`) for drill-down, and never refetches WooCommerce products or writes data.
- **Bulk open-line repository reads** on `WC_Inventory_Overview_Purchase_Order_Lines`: `list_open_lines_for_product_ids()` and `list_open_lines_for_variation_ids()` — two separate, safely-prepared queries (never one OR-based query), qualifying on PO header `status = placed` only, reusing `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()` for the delayed flag.
- **Incoming and Position columns** on Inventory Overview, next to Stock, gated to `manage_woocommerce` at the same sensitivity tier as average cost / inventory value (no new capability).
- **Per-supply drill-down**: reuses the existing details-toggle/expandable-details pattern (including a new expandable detail row per variation, completing that pattern for variation rows). Each contributing PO line renders independently — PO number/link, outstanding quantity, expected date, confidence, delayed indication — never merged, even when two lines share a date or supplier (INV-1/INV-7).
- **Variable-parent presentation rollup**: parent Incoming/Position are a presentation-only sum of child-variation figures; no incoming record is ever created against a variable parent (INV-8). Child variations retain individual figures and drill-downs.
- **Composable states**: low/out-of-stock badges, Incoming, Position, and delayed indication all display simultaneously — never mutually exclusive.
- **Bulk-fetch sequencing**: `get_positions_bulk()` is called exactly once, after the complete product/variation groups structure (including variations discovered by the later per-parent query) is built — no per-row Position queries, verified by a query-scaling regression test over 20+ mixed simple/variation items.
- **Tests:** `tests/unit/inventory-position/` (resolver, D12 architecture guards) and `tests/integration/inventory-position/` (repository, service, list table) — 44 tests / 137 assertions. `tests/docker/run-phpunit.sh`'s blocking filter now includes the `Test_WC_IO_Inventory_Position_` prefix alongside the existing M1/M2 prefixes.

### Verified unchanged

- No schema change; `DB_VERSION` remains `'7'`; `qty_received` still absent from `wc_io_purchase_order_lines`.
- Supplier behavior, PO lifecycle/mutation behavior, Purchase Order admin screens, and `WC_Inventory_Overview_PO_Delay` / `PO_Quantities` / `PO_Expected` behavior are unmodified.
- M0 golden suite and existing characterization fixtures unchanged; the cumulative integration suite's pre-existing failures (documented in `docs/testing.md`) are unchanged by this milestone.

## [1.19.1] - 2026-08-05

**Test Infrastructure Hotfix** — test/CI infrastructure repair only. No database schema, migration, business-behavior, or UI changes. `DB_VERSION` remains `7`.

### Fixed

- `tests/docker/run-phpunit.sh` never ran `composer install` for the plugin's own `composer.json` (which already declares `phpunit/phpunit`, PHPCS, etc. as dev dependencies), so `vendor/bin/phpunit` never existed and every run failed immediately with `Could not open input file: vendor/bin/phpunit`. Added the missing install step.
- `tests/bootstrap.php` loads WooCommerce via a direct `require` rather than WordPress's normal `activate_plugin()` flow, so WooCommerce's own activation routine (which grants the `manage_woocommerce` capability to the administrator role) never ran. Every Purchase Orders admin-handler test failed with "Insufficient permissions." as a result. Added an explicit `WC_Install::create_roles()` call in the test bootstrap (standard practice in third-party WooCommerce extension test suites).
- `phpunit.xml.dist` declared `failOnDeprecated="true"`, an attribute that does not exist in any PHPUnit 9.x schema (confirmed against the installed `vendor/phpunit/phpunit/schema/*.xsd`); PHPUnit silently ignored it. Removed as a no-op cleanup.
- `tests/docker/docker-compose.phpunit.yml` had no explicit Compose project `name:`, defaulting to the generic directory name `docker` and risking collisions with other ephemeral stacks on a shared host. Added `name: wc-io-phpunit`. Also removed a stale, already-overridden `WP_TESTS_PHPUNIT_POLYFILLS_PATH` environment value that pointed at a path the provisioning script never actually uses.

### Added

- `.github/workflows/tests.yml` gained a `phpunit` job that runs the unit suite and the M2-focused suite (both blocking) plus the cumulative integration suite (visible in the Actions log, `continue-on-error: true` pending the known pre-existing test-content issues below — never silently reported as green).

### Documentation

- `tests/README.md` rewritten — it previously documented an old, non-functional `docker-compose.test.yml`/`seed.sh` workflow instead of the actual `docker-compose.phpunit.yml`/`run-phpunit.sh` harness.
- `docs/testing.md` updated: corrected CI/CD table, added PHPCS status section, added an itemized "Known test-content issues" section for pre-existing failures surfaced now that the suite can finally execute to completion (all in M0-era golden characterization tests -- costing, FX, movements, cost-adjustment, batch-intake -- none in M2/Purchase Orders code).

## [1.19.0] - 2026-08-05

**Milestone M2 — Purchase Orders** — PO aggregate, four-state lifecycle, events audit log, expected-receipt dates with confidence, delayed detection, Purchasing admin UI. Schema v7. **Prerequisite:** v1.18.1 (M1 Purchasing PRG hotfix). **No receiving** — stock and `qty_received` remain out of scope until M5.

### Added

- **Purchase Order aggregate** (schema v7): `wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_po_events` tables.
- **Four-state lifecycle:** `draft` → `placed` → `cancelled` | `closed_short`; terminal statuses (`cancelled`, `closed_short`) are absorbing.
- **PO numbering:** `PO-{YYYY}-{NNNN}` format, never reused; unique index on `po_number`. Concurrency model documented in ADR-0002.
- **PO Service** (`WC_Inventory_Overview_PO_Service`): transactional create, update, place, cancel, close short, duplicate, line CRUD; all mutations via explicit DB transactions.
- **PO events:** append-only audit log with optional reason codes (`supplier_change`, `price_change`, `quantity_change`, `schedule_change`, `manual`, `other`).
- **Expected receipt:** header and optional line-level `expected_date` + confidence (`exact` / `estimated` / `unknown`).
- **Delayed detection:** computed condition for placed POs past effective expected date + grace days (`WC_Inventory_Overview_PO_Delay`).
- **Purchasing → Purchase Orders admin tab:** list (status views, delayed filter, search), create/edit detail, event timeline, PRG `admin-post` handlers (save, place, cancel, close short, delete draft, duplicate) with nonces and request tokens.
- **Purchasing capabilities map** (`WC_Inventory_Overview_Purchasing_Caps`): filterable action→capability defaults (`manage_woocommerce`).
- **Assets:** `assets/purchasing.css`, `assets/po-admin.js` (product line editor on PO detail).

### Modified

- `DB_VERSION` '6' → '7'.
- `includes/class-wc-inventory-overview-install.php`: v7 DDL, `expected_schema_v7()`, canonical `wc_io_schema_assertion` option, forbidden-column guard (rejects `qty_received` on PO lines until M5).
- `includes/class-wc-inventory-overview-purchasing-page.php`: Purchase Orders tab (default), delegates PO panel to `PO_Admin`.
- `wc-inventory-overview.php`: require M2 PO classes (statuses, lifecycle, service, admin, list table, etc.).

### Technical

- New files: `includes/class-wc-inventory-overview-po-*.php`, `includes/class-wc-inventory-overview-purchase-orders.php`, `includes/class-wc-inventory-overview-purchase-order-lines.php`, `includes/class-wc-inventory-overview-purchase-orders-list-table.php`, `includes/class-wc-inventory-overview-purchasing-caps.php`, `assets/po-admin.js`, `assets/purchasing.css`, `docs/adr/0002-po-number-allocation-concurrency.md`, `docs/milestones/m2-implementation-plan.md`.
- Test files: `tests/unit/purchase-orders/test-po-*.php` (lifecycle, numbering, service, validation, delay, admin, architecture); extended `tests/integration/install/test-schema-shape-assertion.php`.
- Test harness: `tests/docker/docker-compose.phpunit.yml`, `tests/docker/run-phpunit.sh`.

### Notes

- **No stock or costing changes:** PO lifecycle actions do not mutate WooCommerce stock or weighted-average cost meta.
- **No receiving:** `qty_received`, Goods Receipt, and Receive-Against-PO arrive in M5; schema assertion actively forbids premature receiving columns.
- **No print hooks:** printable PO is a reserved future capability; no `wc_io_po_print_actions` or similar public hook added.
- **M0 golden suite:** passes unmodified; zero fixture changes to costing/FX/allocation/movement characterization tests.
- **Numbering concurrency:** duplicate-key failures under rare concurrent draft creates are a documented limitation (ADR-0002), not a correctness defect.

## [1.18.1] - 2026-08-05

**M1 hotfix** — supplier Purchasing admin PRG and list-table fixes. No schema change (remains v6).

### Fixed

- Supplier save / archive / reactivate admin-post handlers now call `wp_safe_redirect()` + `exit` (were incorrectly calling `wp_safe_remote_post()`, leaving a blank `admin-post.php` page).
- List-table Archive / Reactivate row actions use nonce-checked `admin-post` URLs and are handled by the same handlers (previously unnonced GET links that never routed).
- Active / Archived / All views from `get_views()` are rendered on the suppliers list.
- Create/update success redirect lands on the edit screen with a `saved` notice (removed dead identical ternary).
- Supplier `default_currency` validation accepts form values after `sanitize_key()` (uppercase EUR/USD/SEK).

### Technical

- Touched: `includes/class-wc-inventory-overview-purchasing-page.php`, `includes/class-wc-inventory-overview-suppliers-list-table.php`, `includes/class-wc-inventory-overview-suppliers.php`.
- Tests: `tests/integration/suppliers/test-suppliers-admin-prg.php`; `tests/includes/test-case.php` `flush_cache()` visibility for modern WP PHPUnit.

### Notes

- Patch release on the M1 baseline. Distinct from M2 / v1.19.0 Purchase Orders work.

## [1.18.0] - 2026-08-04

**Milestone M1 — Suppliers** — first-class supplier entity, Purchasing admin page (Suppliers section), seed migration from historical supplier strings, schema v6.

### Added

- **Supplier entity**: new `wc_io_suppliers` table with name, normalized-name (dedupe key), default currency, configured lead time, contact fields, status (active/archived).
- **Supplier service** (`WC_Inventory_Overview_Suppliers`): full CRUD, `get()`, `get_by_normalized_name()`, `list()`, `count()`, `create()`, `update()`, `archive()`, `reactivate()`. No hard-delete.
- **Supplier normalization**: whitespace collapse + trim + casefold only (no punctuation stripping, no suffix removal, no accent folding).
- **Schema-shape assertion** (`assert_schema_shape()`): generic mechanism checking table existence, column presence, unique index presence. Extends by milestone (e.g. M2 adds v7 assertion). Gating mechanism for new features.
- **Purchasing admin page** (new submenu under WooCommerce, uniform `manage_woocommerce` capability):
  - **Suppliers tab**: list with pagination, search, Active/Archived views; detail/create/edit with all §11.1 fields (name, currency, lead time, email, phone, supplier reference, note); archive/reactivate actions.
  - **Tab structure**: extensible for M2+ (Purchase Orders, Receive Stock tabs).
- **Idempotent seed migration** (`WC_Inventory_Overview_Suppliers_Migration`): distinct historical `supplier_name` strings from batches + movements → normalized → deduplicated supplier rows. Deterministic 3-step tie-break (most-frequent original string, earliest created_at, alphabetical strcmp). Persists report to `wc_io_supplier_seed_migration_report` option.
- **Supplier autocomplete**: dedicated JS + own nonce on Batch Intake's `wc-io-batch-supplier` and Quick Restock's `wc-io-supplier` inputs; "+ create supplier" inline quick-create affordance; AJAX handlers `wc_io_search_suppliers`, `wc_io_quick_create_supplier`.
- **Action hook**: `wc_io_supplier_created` (post-commit, full-row payload).

### Modified

- `DB_VERSION` '5' → '6' (first schema-bumping milestone).
- `includes/class-wc-inventory-overview-install.php`: `create_tables()` adds `wc_io_suppliers` DDL; `activate()`/`maybe_upgrade()` call `assert_schema_shape()` + conditional `Migration::run()`.
- `wc-inventory-overview.php`: require the four new classes (service, migration, list table, page controller).
- `includes/class-wc-inventory-overview-plugin.php`: `init()` instantiates Purchasing_Page; `enqueue_restock_assets()` enqueues supplier-picker.js + localized data.
- `includes/class-wc-inventory-overview-batch-intake-ui.php`: additive picker markup + quick-create modal.
- `tests/includes/test-case.php`: additive `create_supplier()` helper.
- `docs/architecture-audit.md`, `docs/release-runbook.md`, `docs/checklists/deployment-checklist.md`: M1-specific updates per §R6.

### Technical

- New files: `includes/class-wc-inventory-overview-suppliers.php`, `includes/class-wc-inventory-overview-suppliers-migration.php`, `includes/class-wc-inventory-overview-suppliers-list-table.php`, `includes/class-wc-inventory-overview-purchasing-page.php`, `assets/supplier-picker.js`, `docs/admin-guide-suppliers.md`.
- Test files: `tests/unit/suppliers/test-normalization.php`, `tests/integration/suppliers/test-suppliers-crud.php`, `tests/integration/suppliers/test-suppliers-migration.php`, `tests/integration/suppliers/test-suppliers-autocomplete-ajax.php`, `tests/integration/suppliers/test-suppliers-capabilities.php`, `tests/integration/install/test-schema-shape-assertion.php`, `tests/fixtures/suppliers/fixture-migration-*.php`.
- Test infrastructure: new test helpers in `tests/includes/test-case.php`.

### Notes

- **M0 golden suite regression**: Full M0 golden test suite (weighted-average, FX, allocation, movements, batch preview/apply) passes unmodified. Zero behavioral changes to existing costing/FX/allocation logic.
- **Backward compatibility**: Legacy free-text supplier fields in Batch Intake/Quick Restock remain unchanged; no `$_POST` handling modification. Supplier autocomplete is purely additive (zero-named select element).
- **No data loss**: Seed migration is idempotent (run twice = identical result); `wc_io_purchase_batches` and `wc_io_inventory_movements` tables untouched.
- **Purchasing menu** only appears when schema assertion passes and user has `manage_woocommerce` capability.

## [1.17.3] - 2026-08-03

**Milestone M0 — Delivery Foundations** — automated test suite infrastructure and characterization tests (zero functional changes).

### Added

- **Test infrastructure**: PHPUnit, PHPCS, GitHub Actions CI/CD workflow.
- **Docker-based test environment**: ephemeral WordPress+WooCommerce stack for isolated testing (`tests/docker/docker-compose.test.yml`).
- **Golden fixtures and characterization tests**: Frozen behavior specifications for weighted-average costing, FX resolution, landed-cost allocation, batch preview/apply parity, movement records, and cost adjustments.
- **DB-transaction helper**: Reusable database transaction wrapper with SAVEPOINT support (built in M0, integrated into M4+).
- **Release rehearsal templates**: Release runbook, deployment, rollback, and validation checklists (reused by every future release).
- **Test documentation**: Philosophy, fixture governance rule, running and extending tests.

### Technical

- `composer.json`, `composer.lock` — development dependencies (PHPUnit, PHPCS, WordPress coding standards).
- `phpunit.xml.dist`, `phpcs.xml.dist`, `.phpcs-baseline.xml` — test configurations.
- `.github/workflows/tests.yml` — CI workflow (PHPUnit + PHPCS + PHP Lint).
- `includes/class-wc-inventory-overview-db-transaction.php` — transaction helper (inert until M4).
- `docs/testing.md`, `docs/release-runbook.md`, `docs/checklists/` — documentation and reusable release templates.

### Notes

- **No plugin behavior changes** — version 1.17.3 functions identically to 1.17.2. Test infrastructure is a pure-tooling addition excluded from the release ZIP.
- The test suite, while comprehensive, is **not** part of the distributed plugin; it ships only in the GitHub repository.
- Golden fixtures lock current behavior as the regression baseline for all future milestones.

## [1.17.2] - 2026-05-19

**Standalone repository releases** — canonical GitHub home is [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview) with `v*` tags.

### Added

- `includes/class-github-updater.php` — queries this repo's GitHub Releases (`/releases/latest`); installs `wc-inventory-overview-X.Y.Z.zip` only.
- `.github/workflows/ci.yml` and `.github/workflows/release.yml`.
- Disable on dev: `WC_INVENTORY_OVERVIEW_DISABLE_GITHUB_UPDATER` or filter `wc_inventory_overview_github_updater_enabled`.

### Notes

- No intentional plugin behavior changes vs **1.17.1**.

## [1.17.1] - 2026-05-19

**Packaging-only release** — production ZIP and GitHub Release automation for the `biopentra-custom-plugins` monorepo. **No intentional plugin behavior changes** vs 1.17.0.

### Added

- Monorepo release tooling: `scripts/build-one-plugin-zip.sh`, `scripts/release-audit-plugin.sh`, `scripts/lib/verify-release-zip.py`.
- GitHub Actions workflow `.github/workflows/release-wc-inventory-overview.yml` (tag `wc-inventory-overview-v*`).
- Distribution files: `readme.txt`, `LICENSE`, this changelog.

### Changed

- Production ZIP excludes `cli/` and other dev-only paths; ships runtime code and `assets/` only.

### Notes

- WP-CLI maintenance scripts remain in the Git repository under `cli/` — not included in the distributed ZIP.
- Tag format: `wc-inventory-overview-v{version}`.

## [1.17.0]

Prior feature releases tracked in the WooCommerce project; see monorepo git history for `plugins/wc-inventory-overview/`.
