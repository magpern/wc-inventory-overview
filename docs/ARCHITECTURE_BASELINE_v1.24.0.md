# Architecture Baseline v1.24.0

**Status: FROZEN.** This document freezes the *architectural boundaries* of
WC Inventory Overview established by the completion of Milestones M0–M7 —
kept as the document's name and identity, since M8–M17 (below)
each closed gaps or added an Internal-only sole-owner (or narrow internal
value/policy) boundary, or presentation surface, within this same frozen shape
without changing any boundary recorded here (per §12 rule 7's update-in-place
instruction, no
`ARCHITECTURE_BASELINE_v1.25.0.md`/`v1.26.0.md`/`v1.27.0.md`/`v1.28.0.md`/`v1.29.0.md`/`v1.30.0.md`/`v1.31.0.md`/`v1.32.0.md`/`v1.33.0.md`/`v1.34.0.md`
was created — M17's schema bump (v10 → v11) adds a new sole-owner boundary
and two new tables/columns within the existing entity model; it does not
change any *architectural boundary* recorded in this document, so the same
update-in-place policy applies). It is the official architectural baseline
for every future milestone (M18 onward).

**Documentation-currency note (recorded during M15 discovery):** this
document was not updated in place for M14 at the time of M14's own freeze —
a process gap, not an architectural one. It is brought current for both M14
and M15 together in this pass (M15 implementation), per §12 rule 7. No fact
recorded for M13 or earlier changed as part of this correction.

| | |
|---|---|
| **Plugin version** | 1.34.0 (feature-branch-only, unreleased — M17 implemented and frozen on `feature/m17-supplier-merge`, `main` remains at `v1.33.0`; architectural boundaries themselves were frozen at 1.24.0/M7, M8–M17 each stayed inside that shape) |
| **Schema version (`DB_VERSION`)** | 11 |
| **Milestones complete** | M0 – M17 (M17 frozen but unreleased; schema-change/ownership-boundary-change milestone, releases standalone per this repo's own Release Triggers rule, not via a feature train) |
| **Baseline date** | 2026-08-08 (M7 freeze); updated in place through M17 2026-08-11 |
| **Supersedes** | Nothing — this document is additive. `CLAUDE.md` Part I (Architecture v1.0) remains the architectural authority for domain decisions (D1–D19) and invariants (INV-1–INV-8); this document is the **consolidated, post-M17 status snapshot** of that same architecture, plus the M1–M17 rules layered on top of it. |
| **Governs** | All milestones from M18 onward |

This is a **documentation-only** artifact. It changes no code, no schema, no
public API, and no `DB_VERSION`.

---

## 1. Purpose

Seven milestones (M0–M7) were delivered sequentially, each with its own
implementation plan under `docs/milestones/`. Each plan is a historical record
of *how* that milestone was built. None of them, individually, answers the
question a new contributor or a new milestone plan actually needs answered:
**what does the platform look like right now, as one coherent system?**

This document is that answer. It exists so that:

- **M8 and every later milestone plan** can cite this document instead of
  re-deriving or re-explaining M0–M7.
- **A new contributor** can read one document and understand the frozen
  shape of the system before touching code.
- **Architecture guard tests** (source-scanning `tests/unit/*/test-*-architecture.php`
  files) have one canonical prose description of the rules they enforce
  mechanically.
- **Future extension authors** (a REST API, a headless storefront, a second
  plugin) know exactly where the sanctioned entry points are and what they
  must never bypass.

Nothing in this document is a new decision. Every rule stated here already
exists, either in `CLAUDE.md` Part I (D1–D19, INV-1–INV-8), in an ADR, in a
milestone implementation plan, or in a guard-enforced architecture test. This
document's only job is to consolidate them into one place, current as of
v1.24.0.

---

## 2. Current Platform (one-page summary)

### What the platform provides today

WC Inventory Overview is a purchasing, receiving, and inventory-position
system for a single-warehouse WooCommerce shop, plus the first customer-facing
feature built on top of that foundation:

- **Suppliers** (M1) — first-class entities with contact info, currency, and
  a configured lead time, replacing free-text supplier fields.
- **Purchase Orders** (M2) — the purchasing commitment: supplier, currency,
  expected receipt date + confidence, a four-state lifecycle
  (`draft → placed → {cancelled | closed_short | partially_received | received}`),
  and a full append-only event log (PO Events) as the revision history.
- **Inventory Position** (M3) — a read-only, derived `{on_hand, incoming, position}`
  computed at read time from open PO lines; the single authoritative
  calculator for "what is our stock situation for this item?"
- **Goods Receipts** (M4) — the receiving event and the **only** operation
  that mutates WooCommerce stock and weighted-average cost. Supports direct
  receipt without a PO ("Quick Receive Without PO").
- **PO Receiving** (M5) — connects Purchase Orders to Goods Receipts:
  receiving against a PO line increments `qty_received`, auto-transitions PO
  status (`partially_received`/`received`), and is fully reconcilable via a
  WP-CLI command.
- **Migration & Retirement** (M6) — the legacy "Batch Intake" feature's
  historical records are additively migrated into the Goods Receipt schema as
  `source = 'migrated'` receipts; Batch Intake can no longer create new
  batches; legacy tables are frozen forever, never dropped.
- **Storefront Expected Delivery** (M7) — the first feature a customer sees:
  an out-of-stock product's storefront text reads "Expected back around 1
  September" / "Expected during week 36" / "Expected soon," derived from
  Inventory Position, behind a stable versioned API and a single merchant
  toggle.

Every one of these seven milestones is **additive** to the last. None
required rewriting a prior milestone's schema, service boundary, or public
contract.

### What is intentionally excluded

- No reservations table, no "Available" figure subtracting reserved stock.
- No inbound-shipment entity (tracking/carrier/revised dates live on the PO).
- No sales-ledger reconstruction (the movement ledger is purchase-side only).
- No REST API, Store API, GraphQL, or Blocks integration for any domain
  concept — the extension surface is service classes plus WordPress
  actions/filters until a concrete external consumer exists (D16).
- No structured data (`woocommerce_structured_data_product`) for expected
  delivery — cut deliberately in M7 (see §7.4).
- No persistent cross-request caching anywhere in the purchasing/position/
  storefront domain — request-scoped memoization only.
- No named coupling to any sibling plugin, in either direction.

### How future contributors should extend it

1. Read this document first, then the specific milestone plan(s) it
   summarizes if line-level detail is needed.
2. Identify which existing service already owns the concept you're touching
   (§6 Ownership model). If one exists, extend it — do not create a second
   calculator, mutator, or renderer for the same fact.
3. If no owner exists yet, a new milestone plan is the correct vehicle, not
   an ad-hoc addition to an unrelated milestone's code.
4. Any new architectural rule (a new sole-owner class, a new invariant, a new
   public API surface) gets an ADR (`docs/adr/`) and a source-scanning
   architecture guard test, exactly as M1–M7 all did. See §9.

---

## 3. Completed milestones

| # | Milestone | Release | Schema | Summary |
|---|---|---|---|---|
| M0 | Delivery Foundations | 1.17.3 | v5 (baseline, no change) | PHPUnit harness, PHPCS baseline, golden/characterization tests, DB-transaction helper, rehearsed release+rollback cycle. Zero functional change. |
| M1 | Suppliers | 1.18.0 | v6 | `wc_io_suppliers` table, Purchasing admin page, seed migration from legacy free-text supplier fields. |
| M2 | Purchase Orders | 1.19.0 | v7 | `wc_io_purchase_orders`/`wc_io_purchase_order_lines`/`wc_io_po_events`, four-state lifecycle, PO numbering (ADR-0002), expected dates/confidence, delayed detection. No receiving yet. |
| M3 | Inventory Position | 1.20.0 | v7 (unchanged) | Resolver + Service (D12 sole calculator), bulk open-line repository reads, Incoming/Position columns with per-supply drill-down. Read-only — no receiving yet. |
| M4 | Goods Receipts | 1.21.0 | v8 | `wc_io_goods_receipts`/`wc_io_receipt_lines`/`wc_io_receipt_costs`, Goods Receipt Service (sole D3/INV-2 stock mutator), Quick Receive Without PO, idempotency (token + compare-and-swap). No PO-linked receiving yet. |
| M5 | PO Receiving | 1.22.0 | v9 | `qty_received` column, `PO_Receiving_Sync` (sole `qty_received` owner), auto-transitioning PO status, reconciliation CLI. |
| M6 | Migration & Retirement | 1.23.0 | v10 | `migrated_receipt_id`/`migrated_at` tracking columns, `Batch_Migration_Service` (record materialization, never receiving-replay), migration CLI, Batch Intake retired (create/apply disabled, legacy tables frozen). |
| M7 | Storefront Expected Delivery | 1.24.0 | v10 (unchanged) | `Expected_Delivery_Result_Interface`/`Result`/`Resolver`/`Service`/`Renderer` (API v1, sole public API + sole-entry-point rule), built-in `woocommerce_get_availability` renderer, one merchant toggle, Invariants M7-1/M7-2/M7-3. |
| M8 | Hardening & GA | 1.25.0 | v10 (unchanged) | Physically removed the M6-deprecated Batch Intake create/apply surface (test fixture builder rewritten first, four deletion criteria verified); closed `PO_Delay`'s `partially_received` gap; added the repo-wide sibling-plugin-coupling conformance guard M7 deferred; repaired all remaining pre-existing golden-test bugs (integration suite promoted to a CI-blocking gate); CI hardening (PHP 8.4 aligned across all workflows); GA-scale (200-item) performance confirmation. Zero new domain concepts, zero public API change — the smallest milestone that lets M0–M8 be called production-finished. |
| M9 | Supplier Observed Lead-Time Statistics | 1.26.0 | v10 (unchanged) | `Supplier_Lead_Time_Service` (new sole-owner boundary, Internal not Public — no concrete external consumer exists yet, D16) computes read-only average/fastest/slowest/completed-order statistics per supplier from posted Goods Receipts linked to fully-`received` Purchase Orders; one bulk aggregate query, no persistence, no N+1 (proven at 10/40/200-supplier scale). Displayed read-only on the Supplier admin screen alongside the existing configured-lead-time fallback. Zero schema change, zero new public API, zero new domain concept — fills the `designed-for-later` slot D8 already reserved. |
| M10 | Purchase Order Expected-Date Suggestion | 1.27.0 | v10 (unchanged) | `Expected_Date_Suggestion_Service` (new sole-owner boundary, Internal not Public, D16) combines M9's observed statistics with the supplier's configured fallback into an advisory expected-date suggestion (observed → configured → none priority; calendar days only). Pre-fills the new-PO creation form's Expected Date/Confidence via the existing `wp_localize_script`/`po-admin.js` plumbing — no AJAX, no new endpoint. Always overridable, never authoritative (INV-M10-1); never runs on the edit-PO screen. `Supplier_Lead_Time_Service` gains one small additive predicate, `is_observed_value_usable()`, so M10 never duplicates M9's own "is this average good enough" threshold. Zero schema change, zero new public API, zero new domain concept. |
| M11 | Supplier On-Time Delivery Rate | 1.28.0 | v10 (unchanged) | `Expected_Deadline` (new, narrow, pure internal value/policy class — not a sole-owner *domain* boundary in the M9/M10 sense; owns only the "expected_date + grace_days → deadline" formula and known-date eligibility rule, INV-M11-2) is consumed by both `PO_Delay` (internally refactored, public contract unchanged) and the further-extended `Supplier_Lead_Time_Service`, which now also returns `on_time_count`/`rated_order_count` per supplier from the same single query M9 already runs — zero additional queries. Unknown-confidence completed orders are excluded from both numerator and denominator (INV-M11-1). Displayed read-only alongside Observed Lead Time on the Supplier admin screen. Zero schema change, zero new public API, zero new domain concept. |
| M12 | Supplier List Performance Surface | 1.29.0 | v10 (unchanged) | Presentation-only: Suppliers list table gains read-only Observed Lead Time and On-Time Rate columns, populated by one `Supplier_Lead_Time_Service::get_stats_bulk()` call per page (INV-M12-1/2). No new service, no schema change, no public API, no mutation. Completes the supplier performance comparison decision point on the list; released as part of the M9–M12 train, v1.29.0. |
| M13 | Printable Purchase Order | 1.30.0 | v10 (unchanged) | Presentation-only: new `PO_Print_Renderer` (Internal, INV-M13-2) renders a standalone, read-only, printable HTML view of a Purchase Order from an already-composed model, composed by `PO_Admin::handle_print()` (new `admin_post_wc_io_po_print` action) using only the three existing read owners (`Purchase_Orders`, `Purchase_Order_Lines`, `Suppliers`) plus `PO_Statuses::label()` — no new domain owner (§4/§6 unchanged). Requires `VIEW_PO` + a PO-and-action-scoped nonce before any data is read (INV-M13-4). Printable for every status except `draft`. No schema change, no mutation, no new public API, no new capability, no new hook. First milestone of the M13–M15 feature train. |
| M14 | Supplier Order History | 1.31.0 | v10 (unchanged) | Presentation + new Internal sole-owner boundary: new `Supplier_Order_History_Service` (Internal, INV-M14-3) composes a paginated, status-inclusive (INV-M14-4) list of a supplier's Purchase Orders exclusively through `Purchase_Orders::count()`/`list()`/`values_bulk()` (new additive method on the existing read owner) — no new domain owner. Rendered as an "Order History" section on the Supplier detail screen, reusing the existing `manage_woocommerce` gate; dedicated `wc_io_supplier_order_history_page` pagination parameter. Each row's Ordered/Received Value is PO-line cost only, in that PO's own currency, never summed across POs or currencies (INV-M14-2). Zero mutation (INV-M14-1), zero schema change, zero new public API, zero new capability, zero new hook. Second milestone of the M13–M15 feature train. |
| M15 | Supplier Spend Summary | 1.32.0 | v10 (unchanged) | Presentation + new Internal sole-owner boundary: new `Supplier_Spend_Service` (Internal, INV-M15-3) owns the "committed spend" status rule (BR-M15-1 — `placed`/`partially_received`/`received`/`closed_short` only, `draft`/`cancelled` always excluded, INV-M15-1) and composes a per-currency spend summary exclusively through the new, self-contained `Purchase_Orders::spend_summary_for_supplier()` (does not compose through `list()`) — no new domain owner. Rendered as a "Spend Summary" section on the Supplier detail screen, before Observed Lead Time / Order History, reusing the existing `manage_woocommerce` gate. Currencies never blended or converted (INV-M15-2); `po_count` is `COUNT(DISTINCT po.id)` scoped per currency row (BR-M15-5). A true database-level aggregate — exactly 1 query regardless of history size, unlike M14's page-scoped 3-query contract. Zero mutation, zero schema change, zero new public API, zero new capability, zero new hook. Completes the M13–M15 feature train; released as v1.32.0. |
| M16 | PO Expected-Date & Delay Transparency | 1.33.0 | v10 (unchanged) | Presentation-only, no new domain owner. Three additive surfaces: (1) `Expected_Date_Suggestion_Service`'s return shape gains `sample_count`/`average_days` (BR-M16-2), sourced from the same already-fetched `Supplier_Lead_Time_Service` stats — no additional query, no duplicated computation — so the New PO screen can display suggestion provenance (BR-M16-1). (2) A Settings-tab field exposes the pre-existing `PO_Delay::OPTION_GRACE_DAYS` option through an explicit validate-or-preserve contract in `Settings::save_from_post()` (BR-M16-4) — invalid/missing input always preserves the stored value, deliberately not `absint()`-style coercion; `PO_Delay` remains the sole authority on how `grace_days` affects delay computation, unchanged. (3) The Inventory Position drilldown (M3) gains Supplier/Status columns via two additional `SELECT` columns on the already-whitelisted `query_open_lines()` query — the M3 sole-caller architecture guard needed zero changes. Zero domain/operational mutation (one pre-existing settings-option write only), zero schema change, zero new public API, zero new capability, zero new hook. First milestone of a new, not-yet-named post-v1.32.0 train; frozen but unreleased. |
| M17 | Supplier Merge | 1.34.0 | v11 | New `Supplier_Merge_Service` (new sole-owner boundary, Internal not Public, D16) is the sole class permitted to write `wc_io_suppliers.merged_into_supplier_id` or bulk-reassign `supplier_id` across more than one Purchase Order/Goods Receipt row at once (INV-M17-2). Atomic, exception-safe (`try`/`catch(\Throwable)` guarantees rollback), fixed-lock-order row locking on both suppliers, server-enforced typed confirmation validated inside the transaction (BR-M17-16). New `Suppliers::get_for_update()`/`mark_merged()`/`get_names_bulk()` and `Purchase_Orders`/`Goods_Receipts::reassign_supplier_bulk()` primitives; new append-only `Supplier_Merges` repository (`wc_io_supplier_merges`, mirrors `PO_Events`). `Suppliers::reactivate()` hardened to permanently reject a merged supplier (INV-M17-11), enforced independently at the repository, admin-handler, and list-table layers. `PO_Service::create_draft()`/`Goods_Receipt_Service::create_draft_from_post()` extended to lock and re-validate the chosen supplier's row before inserting, closing a concurrent-create race against an in-flight merge (BR-M17-18, INV-M17-12). Schema change: `merged_into_supplier_id` column on `wc_io_suppliers`, new `wc_io_supplier_merges` table (`DB_VERSION` 10 → 11). Zero new public API, zero new WordPress hook (INV-M17-10) — the only new extension-adjacent surface is a private, test-bootstrap-gated failure-injection seam, structurally inert in production. Schema-change / ownership-boundary-change milestone; releases standalone. |

Full detail for each milestone: `docs/milestones/m{N}-implementation-plan.md`
and the corresponding section of `docs/architecture-audit.md`.

**M0–M12 released as v1.29.0; M13–M15 frozen, forming a new unreleased train.**
This baseline is updated in place for M9–M15, per the same §12 rule M8
established — no `ARCHITECTURE_BASELINE_v1.26.0.md` through
`v1.32.0.md` was created, since none of M9–M15 changed a frozen boundary or
introduced a new domain concept; M9 and M10 each added one new *Internal*
sole-owner boundary (see §4's `Supplier_Lead_Time_Service` and
`Expected_Date_Suggestion_Service` rows), M11 added one narrow internal
value/policy class (`Expected_Deadline`), M12 is presentation-only on an
existing list table, M13 is presentation-only via one new *Internal*
renderer with zero repository access of its own (§4), M14 added one new
*Internal* sole-owner boundary (`Supplier_Order_History_Service`) composing
only through existing/additive `Purchase_Orders` read methods, and M15 added
one further new *Internal* sole-owner boundary (`Supplier_Spend_Service`)
composing only through one new, self-contained `Purchase_Orders` aggregate
read method — all inside the domain this plugin already owns. Per
`docs/process/milestone-lifecycle.md` (the Standard Milestone Lifecycle, v2,
adopted after M9), M9–M12 were implemented, frozen, and released together as
one feature train (M9 via a full independent audit and remediation pass;
M10–M12 via a lightweight WP4 completion review; train closed and tagged
`v1.29.0` per `docs/checklists/feature-train-m9-m12-release-readiness.md`).
M13 opened a new unreleased feature train on the same lifecycle; M14 and M15
each joined it, each frozen with its own Level A completion review
(`docs/checklists/m13-release-readiness.md`,
`docs/checklists/m14-release-readiness.md`,
`docs/checklists/m15-release-readiness.md`); the next authorized process step
is to close and release the M13–M15 train, only with explicit approval — see
§10 for extension philosophy and §12 for the governance rules any later
milestone must follow.

---

## 4. Frozen architectural boundaries

These are the "sole owner" / "sole entry point" rules established across
M0–M8. Each is enforced by a source-scanning architecture guard test, not
just prose — a violation fails CI, not just code review.

| Boundary | Rule | Enforced by |
|---|---|---|
| **Stock/cost mutation (D3/INV-2)** | Only `Goods_Receipt_Service::post()`/`void()` mutates WooCommerce stock or weighted-average cost through this plugin's purchasing domain. As of M8, this is a literal single-caller guard for both `Restock_Service` mutation methods — `Batch_Intake_Service`'s pre-existing second caller (`apply_batch_from_post()`) was physically removed, closing the one deliberate exception this rule ever carried. | `tests/unit/goods-receipt/test-goods-receipt-architecture.php` |
| **Position calculation (D12)** | Only `Inventory_Position_Service` computes position/aggregation values (single or bulk). It is the only caller of `Purchase_Order_Lines::list_open_lines_for_*_ids()`. | `tests/unit/inventory-position/test-inventory-position-architecture.php` |
| **`qty_received` ownership chain (M5)** | `Goods_Receipt_Service` (orchestrator) → `PO_Receiving_Sync` (sole owner of the mutation decision) → `Purchase_Order_Lines::increment_qty_received()` (sole physical writer). One named CLI exception (`reconcile_line()`) on the same owner class. | `tests/unit/po-receiving/test-po-receiving-architecture.php` |
| **Migration is not receiving (M6)** | `Batch_Migration_Service` never calls `set_stock_quantity()`, never writes cost meta, never goes through `Goods_Receipt_Service`/`Restock_Service`/`PO_Receiving_Sync`. A migrated receipt is a historical fact written down, not a live mutation. | `tests/unit/batch-migration/test-batch-migration-architecture.php` |
| **One transaction per batch (M6-1)** | `Batch_Migration_Service::migrate_batch()` never spans a shared transaction across multiple batches. | Same guard file, instantiation-count assertion |
| **Expected-delivery public API (M7)** | Only `Expected_Delivery_Service` may call `Expected_Delivery_Resolver` (sole-entry-point rule). Only the Service and the Inventory Overview list table call `Inventory_Position_Service::` (D12 extended). | `tests/unit/expected-delivery/test-expected-delivery-architecture.php` |
| **Storefront rendering (M7)** | Only `Expected_Delivery_Renderer` touches `woocommerce_get_availability`. It goes through the Service only — never a repository, never `$wpdb`, never a PO-domain class directly. | Same guard file |
| **`PO_Delay` delayed-detection status gate (M2/M8/M11)** | A line may be flagged delayed only when its owning PO is `placed` or `partially_received` (M8 closed the gap where a partially-received PO's genuinely overdue remainder was silently never flagged); `received` is excluded by construction (INV-4 guarantees `outstanding = 0`). PHP and SQL predicates verified equivalent by a shared table-driven fixture. As of M11, `PO_Delay`'s deadline/eligibility sub-expressions are internally composed from `Expected_Deadline` (INV-M11-2) rather than inlined — its public contract, signatures, and behavior are unchanged (proven by re-running its complete pre-existing test suite unmodified). | `tests/unit/purchase-orders/test-po-delay.php` |
| **No sibling-plugin coupling (all milestones)** | No `class_exists()`/`function_exists()` check against a third-party symbol; no `remove_filter()`/`remove_action()` call; no hardcoded sibling-plugin identifier. Enforced per-milestone within each new feature's own files since M4; as of M8, also enforced **repo-wide across the entire `includes/` tree in one guard**, mechanically confirming ADR-0003's audit claim instead of leaving it as prose. | Per-milestone guards; `tests/unit/conformance/test-no-sibling-plugin-coupling.php` (repo-wide, M8) |
| **Observed lead-time and on-time statistics computation (M9/M11/M12)** | Only `Supplier_Lead_Time_Service` computes observed supplier lead-time and (M11) on-time delivery statistics (sole-owner rule, source-scanned for the `DATEDIFF(`/`observed_days` computation signature — not the returned array-key names, which the approved UI callers legitimately read without duplicating any calculation). Read-only by construction: never calls `set_stock_quantity`/`update_post_meta`/`->insert(`/`->update(`/`->delete(`, only ever `$wpdb->get_results()`. Internal, not Public (§7/§10.3) — no `API_VERSION`, no interface; a future milestone may promote it to a versioned public API without changing the computation it wraps, per D16. As of M10, its sole-caller allowlist also includes `Expected_Date_Suggestion_Service`. As of M11, its single grouped query is widened (not duplicated) to also compute `on_time_count`/`rated_order_count`, composing `Expected_Deadline`'s SQL micro-fragments rather than inlining the deadline formula itself (INV-M11-2) — still exactly one query, zero N+1. As of M12, the allowlist also includes `class-wc-inventory-overview-suppliers-list-table.php` as a presentation consumer; the list table must call `get_stats_bulk()` once per page and must not duplicate statistics SQL (INV-M12-1/2). | `tests/unit/supplier-lead-time/test-supplier-lead-time-architecture.php` |
| **Supplier list performance presentation (M12)** | Suppliers list table may display Observed Lead Time and On-Time Rate only via `Supplier_Lead_Time_Service` usability predicates and bulk stats — never via direct PO/GR aggregation or per-row `get_stats_for_supplier()`. | `tests/unit/suppliers/test-suppliers-list-performance.php`; `tests/integration/suppliers/test-suppliers-list-performance*.php` |
| **Expected-date suggestion policy (M10)** | Only `Expected_Date_Suggestion_Service` combines observed and configured lead-time signals into a suggestion (sole-owner rule, source-scanned for the `is_observed_value_usable(` call signature). Distinct responsibility from `Supplier_Lead_Time_Service` (owns *statistics*) — this service owns *recommendation policy* only, and never independently computes an average/min/max itself (guard-verified absence of M9's own `DATEDIFF(`/`observed_days` tokens). Never touches `$wpdb` directly at all — every read goes through `Supplier_Lead_Time_Service::get_stats_bulk()`. Internal, not Public, no `API_VERSION`, no interface. Sole caller: `WC_Inventory_Overview_PO_Admin`, and only on the new-PO creation screen. | `tests/unit/expected-date-suggestion/test-expected-date-suggestion-architecture.php` |
| **Expected-deadline policy primitive (M11)** | Only `Expected_Deadline` owns the "expected_date + grace_days → deadline" formula and the known-date eligibility rule (INV-M11-2), as pure PHP and as minimal SQL micro-fragments — deliberately closed to exactly four methods (`has_known_date()`, `deadline()`, `sql_deadline_expression()`, `sql_has_known_date_expression()`), guard-verified, so it can never grow into a general SQL-expression provider. Zero `$wpdb`, zero writes, zero WordPress option access (grace-days lookup stays `PO_Delay::grace_days_from_option()`'s responsibility). Sole callers: `PO_Delay` and `Supplier_Lead_Time_Service` — neither depends on the other. Smaller than a sole-owner *domain* boundary (it owns no concept from `docs/OWNERSHIP.md`); listed here for completeness since it is guard-enforced the same way. | `tests/unit/expected-deadline/test-expected-deadline-architecture.php` |
| **Printable Purchase Order presentation (M13)** | Only `PO_Print_Renderer` renders the printable PO document, and it is presentation-only (INV-M13-2): zero `$wpdb`, zero calls into `Purchase_Orders`/`Purchase_Order_Lines`/`Suppliers`/any repository class, zero product lookup (`wc_get_product`), zero authorization/lifecycle logic — it only formats an already-composed plain array. Sole caller: `WC_Inventory_Overview_PO_Admin::handle_print()`. That handler sources data only through the three approved read owners plus `PO_Statuses::label()` (INV-M13-3), and its own source requires the `VIEW_PO` capability check and a PO-and-action-scoped nonce (`wc_io_po_print_<id>`) to both textually precede the first repository read (INV-M13-4) — verified by source position, not just presence. Product/variation identity always comes from the PO line's own historical `name_snapshot`/`sku_snapshot`, never a live product lookup; supplier name always falls back to the PO header's own `supplier_name_snapshot` when the live `Suppliers` row does not resolve. Printable for `placed`/`partially_received`/`received`/`cancelled`/`closed_short`; never `draft` (INV-M13-1). | `tests/unit/po-print/test-po-print-architecture.php` |
| **Supplier Order History presentation (M14)** | Only `Supplier_Order_History_Service` composes the paginated, status-inclusive Order History projection (INV-M14-4), and it sources data exclusively through `Purchase_Orders::count()`/`list()`/`values_bulk()` (INV-M14-3) — zero `$wpdb`, zero calls into `Purchase_Order_Lines`/`Goods_Receipts`/`Receipt_Lines`/`Receipt_Costs`/`Suppliers` directly. Zero mutation (INV-M14-1), guard-verified absence of write tokens in both the service and `values_bulk()`'s own method body. Each row's Ordered/Received Value is PO-line cost only, in that PO's own currency, never summed across POs or currencies and never conflated with landed cost or inventory valuation (INV-M14-2). Sole caller: `class-wc-inventory-overview-purchasing-page.php` (guard-enforced allowlist). | `tests/unit/supplier-order-history/test-supplier-order-history-architecture.php` |
| **Supplier Spend Summary presentation (M15)** | Only `Supplier_Spend_Service` owns the "committed spend" status rule (BR-M15-1, INV-M15-1) and composes the per-currency spend summary exclusively through the new, self-contained `Purchase_Orders::spend_summary_for_supplier()` (INV-M15-3) — zero `$wpdb`, zero calls into `Purchase_Order_Lines`/`Goods_Receipts`/`Receipt_Lines`/`Receipt_Costs`/`Suppliers` directly, and (unlike M14) does not compose through `Purchase_Orders::list()` either — the aggregate is a bounded, standalone grouped query. Zero mutation, guard-verified absence of write tokens in both the service and `spend_summary_for_supplier()`'s own method body; guard-verified absence of FX/currency-blending tokens (INV-M15-2). `po_count` is `COUNT(DISTINCT po.id)` scoped per currency row (BR-M15-5) — a PO with lines in more than one currency may contribute to more than one row. Sole caller: `class-wc-inventory-overview-purchasing-page.php` (guard-enforced allowlist, INV-M15-4). | `tests/unit/supplier-spend/test-supplier-spend-architecture.php` |
| **Supplier merge mutation (M17)** | Only `Supplier_Merge_Service::merge()` may write `wc_io_suppliers.merged_into_supplier_id` or bulk-UPDATE `supplier_id` across more than one row of `wc_io_purchase_orders`/`wc_io_goods_receipts` at once (INV-M17-2). Guard-verified: only `Suppliers::mark_merged()` writes the column; only `Purchase_Orders`/`Goods_Receipts::reassign_supplier_bulk()` issue the bulk `UPDATE`; `Supplier_Merge_Service` is confirmed to be the sole caller of all four mutation primitives. `Purchasing_Page::handle_supplier_merge()` contains zero direct SQL (INV-M17-7) — it only calls the service. Zero new `do_action()`/`apply_filters()` anywhere in the new files (INV-M17-10). | `tests/unit/supplier-merge/test-supplier-merge-architecture.php` |
| **Merged-supplier reactivation prevention (M17)** | No supported code path — `Suppliers::reactivate()`, the `wc_io_supplier_reactivate` admin-post handler, or the Suppliers list-table row action — can return a supplier with `merged_into_supplier_id IS NOT NULL` to `status = 'active'` (INV-M17-11). Enforced independently at all three layers, each with its own test coverage. | `tests/unit/supplier-merge/test-supplier-merge-primitives.php`; `tests/integration/suppliers/test-suppliers-admin-prg.php`; `tests/integration/supplier-merge/test-supplier-merge-admin-render.php` |

**The pattern, stated once:** every domain fact in this system has exactly
one class that is allowed to compute it or write it, and every other class
in the codebase is structurally forbidden — by an automated test, not a
comment — from duplicating that logic. This is the single most important
architectural property of the whole platform, and it is why M1–M8 could each
ship as a pure addition without ever needing to refactor a prior milestone.

---

## 5. Domain invariants (INV-1 – INV-8, plus M6/M7 additions)

The eight formal invariants from `CLAUDE.md` §4 remain unchanged and in
force. Restated briefly, with their current enforcement points:

| Invariant | Statement | Still holds as of v1.24.0 because |
|---|---|---|
| **INV-1** | Independence of incoming supplies — any number of open PO lines may exist for the same item; never merged into one stored record. | Inventory Position aggregates at read time only (M3); no product-level "incoming" field exists anywhere in schema v10. |
| **INV-2** | Single stock mutator — only Goods Receipt post/void changes stock or cost. | §4 above (D3/INV-2 row). |
| **INV-3** | Derived aggregation — all incoming/position figures are computed at read time, never stored. | M3's Resolver/Service design; M7's Expected Delivery Service also derives, never stores (its only persistent footprint is one settings row). |
| **INV-4** | Computed quantities — `qty_outstanding` always calculated; `qty_received` is a maintained counter reconciled against posted receipts. | M5's `PO_Receiving_Sync` + reconciliation CLI (`wp wc-io reconcile-qty-received`). |
| **INV-5** | Computed delay — "Delayed" is a condition, never a stored state. | `PO_Delay::sql_line_delayed_predicate()`, reused unmodified by M3 and referenced (not re-derived) by M7's Resolver for Invariant M7-1. |
| **INV-6** | Auditability — posted/placed aggregates never hard-deleted; corrections are lifecycle transitions with full history. | PO Events (M2), Goods Receipt draft/posted/voided lifecycle (M4), migration events (M6). |
| **INV-7** | Presentation never destroys identity — aggregation/rollup is a presentation behavior; underlying line identity stays individually retrievable. | M3's per-supply drill-down; M7's variable-parent rollup (Invariant M7-2 is INV-7 + INV-8 applied to storefront presentation). |
| **INV-8** | Purchasing references the purchasable inventory item — for variable products, always the variation, never the parent. Parent products are presentation containers only. | Enforced from M2 onward; M7's Invariant M7-2 is this same rule applied to what the storefront may say about a variable parent. |

### Milestone-specific invariants added since M0

| Invariant | Milestone | Statement |
|---|---|---|
| **M6-1** | M6 | One `DB_Transaction` instantiation per `migrate_batch()` call — never a shared transaction spanning multiple batches. |
| **M6-2** | M6 | Order independence — migrating batch A's history is a pure function of batch A's own stored rows; it never reads current stock, current cost, or any other batch's rows. Batches can be migrated in any order with an identical result. |
| **M7-1** | M7 | A customer-safe expected-delivery line's date is never in the past, regardless of the upstream `is_delayed` flag (which can be stale for a `partially_received` PO or a non-zero delay grace period). |
| **M7-2** | M7 | An out-of-stock variable parent presents `STATE_EXPECTED_SOON`, never a specific date — regardless of how confident an individual variation's own date is. |
| **M7-3** | M7 | At most one product-scoped and one variation-scoped SQL query per rendered page, regardless of item count — measured by an equality assertion (20 vs. 40 mixed products issue the *same* query count), not asserted in prose. |
| **INV-M10-1** | M10 | Automatic expected-date suggestions may assist the operator but never become authoritative — a suggestion is always a pre-filled default, never a locked, forced, or silently-reasserted value; once the operator edits either the Expected Date or Confidence field, that entry wins permanently for the rest of that PO-creation page session. Enforced client-side (`assets/po-admin.js`) and reinforced server-side by construction (the suggestion service never writes anything; only the existing, unchanged PO form-submission path persists these fields). |
| **INV-M11-1** | M11 | On-time rate never judges an order without a known deadline — a completed order with `expected_confidence = 'unknown'` never contributes to either the numerator (`on_time_count`) or denominator (`rated_order_count`) of the on-time delivery rate; it is excluded entirely, never counted as late by default and never counted as on-time by default. A supplier's rate is unavailable ("not enough data") until `MINIMUM_SAMPLE_COUNT_FOR_DISPLAY` such eligible orders exist. |
| **INV-M11-2** | M11 | On-time and Delayed can never silently disagree — the deadline arithmetic (`expected_date + grace_days`, inclusive boundary) and the known-deadline eligibility rule are each defined in exactly one place (`Expected_Deadline`), consumed by both `PO_Delay` (live delay-flagging) and `Supplier_Lead_Time_Service` (historical on-time rate). No second, independently-defined notion of "deadline" or "eligible" is ever introduced, and neither service depends on the other's query shape to enforce this. |
| **INV-M12-1** | M12 | The Suppliers list must not compute observed lead time or on-time performance itself — no duplicated deadline/lead-time SQL or direct PO/GR statistics aggregation in the list-table class. |
| **INV-M12-2** | M12 | List preparation uses the bulk statistics path once for the page dataset — no per-row `get_stats_for_supplier()` (or equivalent) N+1. |
| **INV-M13-1** | M13 | The printable PO document is available for `placed`/`partially_received`/`received`/`cancelled`/`closed_short`; never for `draft` — printing a not-yet-placed commitment is a customer-facing-document risk. |
| **INV-M13-2** | M13 | `PO_Print_Renderer` is presentation-only — zero `$wpdb`, zero repository calls, only formats an already-composed plain array; it cannot itself diverge from what its caller supplied. |
| **INV-M13-3** | M13 | `PO_Admin::handle_print()` sources data only through the three approved read owners (`Purchase_Orders`, `Purchase_Order_Lines`, `Suppliers`) plus `PO_Statuses::label()` — no ad hoc query, no new read owner. |
| **INV-M13-4** | M13 | The `VIEW_PO` capability check and the PO-and-action-scoped nonce check must both textually precede the first repository read in `handle_print()` — verified by source position, not merely presence, so no code path can read PO data before authorization. |
| **INV-M14-1** | M14 | `Supplier_Order_History_Service` and `Purchase_Orders::values_bulk()` perform zero mutation — guard-verified absence of write tokens in both. |
| **INV-M14-2** | M14 | Ordered/Received Value is PO-line cost only, in that PO's own currency; never summed across POs, never summed or converted across currencies, and never conflated with landed cost (`Receipt_Costs`) or the weighted-average inventory-value figure. |
| **INV-M14-3** | M14 | `Supplier_Order_History_Service` sources data exclusively through `Purchase_Orders::count()`/`list()`/`values_bulk()` — never `$wpdb` directly, never `Purchase_Order_Lines`/`Goods_Receipts`/`Receipt_Lines`/`Receipt_Costs`/`Suppliers` directly. |
| **INV-M14-4** | M14 | Every PO status appears in Order History, including `draft` and `cancelled` — unlike M13's print feature, this is a full audit trail, not a customer-facing or commitment-only view; nothing is filtered by status. |
| **INV-M15-1** | M15 | Spend totals include only `placed`/`partially_received`/`received`/`closed_short` POs (BR-M15-1); `draft` (not yet a commitment) and `cancelled` (never fulfilled) are always excluded — a genuinely new business decision, not reused verbatim from M13 or M14. |
| **INV-M15-2** | M15 | Spend totals are grouped by the PO line's own currency and never summed or converted across currencies — one row per currency actually present in the supplier's committed lines; `po_count` is `COUNT(DISTINCT po.id)` scoped to that currency row only (BR-M15-5), never a supplier-wide count, never meant to be summed across rows. |
| **INV-M15-3** | M15 | `Supplier_Spend_Service` and `Purchase_Orders::spend_summary_for_supplier()` perform zero mutation and source data with no unapproved read access — guard-verified absence of write tokens and forbidden-read tokens in both. |
| **INV-M15-4** | M15 | Only `class-wc-inventory-overview-purchasing-page.php` may call `Supplier_Spend_Service::` — sole-consumer discipline, guard-enforced allowlist (same mechanism as INV-M14-3's sole-consumer test). |
| **INV-M17-1** | M17 | A merge is all-or-nothing — one atomic transaction, exception-safe (`try`/`catch(\Throwable)`), zero partial state on any exit path. |
| **INV-M17-2** | M17 | `Supplier_Merge_Service::merge()` is the sole class permitted to write `wc_io_suppliers.merged_into_supplier_id` or bulk-UPDATE `supplier_id` across more than one row of `wc_io_purchase_orders`/`wc_io_goods_receipts` at once. |
| **INV-M17-3** | M17 | Zero stock/cost mutation — a merge never calls `set_stock_quantity()`, never writes cost meta, never touches `wc_io_inventory_movements`. |
| **INV-M17-4** | M17 | `supplier_name_snapshot` on both `wc_io_purchase_orders` and `wc_io_goods_receipts` is byte-for-byte unchanged by a merge. |
| **INV-M17-5** | M17 | The mutation phase is a fixed, itemizable 4-statement set regardless of history size — no per-record loop. The complete `merge()` call's measured query count is likewise constant across fixture sizes (proven empirically at 500/2,000/5,000 related POs: 11 queries at every scale). |
| **INV-M17-6** | M17 | All derived-statistics services (Observed Lead Time, On-Time Rate, Order History, Spend Summary, Inventory Position drilldown) require zero code changes to correctly reflect a merge — every one filters by `supplier_id` at query time. |
| **INV-M17-7** | M17 | `Purchasing_Page::handle_supplier_merge()` contains no direct SQL for the merge — it only calls `Supplier_Merge_Service::merge()`. |
| **INV-M17-8** | M17 | No merge chain ever requires multi-hop resolution at operational-record read time — a direct-successor `merged_into_supplier_id` pointer is never walked recursively by any runtime code. |
| **INV-M17-9** | M17 | The `MERGE_SUPPLIER` capability gate is checked before any other work in the admin-post handler. |
| **INV-M17-10** | M17 | M17 introduces no new WordPress action, no new WordPress filter, and no new public API — the only new extension-adjacent surface is a private, test-bootstrap-gated failure-injection seam, structurally inert in production. |
| **INV-M17-11** | M17 | No supported code path — `Suppliers::reactivate()`, its admin-post handler, or the Suppliers list-table row action — can return a supplier with `merged_into_supplier_id IS NOT NULL` to `status = 'active'`. |
| **INV-M17-12** | M17 | After a merge commits, no Purchase Order or Goods Receipt draft that references the now-dissolved source as its `supplier_id` can be successfully created by any subsequent request — proven by dedicated concurrency tests, not merely asserted. |

---

## 6. Ownership model

### 6.1 The pipeline

```
Suppliers
   │  (business ownership: this plugin — replaces free-text fields)
   ▼
Purchase Orders
   │  (the purchasing commitment — never touches stock)
   ▼
Goods Receipts
   │  (the ONLY stock/cost mutation point — D3/INV-2)
   ▼
Inventory Position
   │  (derived read: on_hand + incoming, computed fresh every request)
   ▼
Expected Delivery
   │  (derived read: earliest customer-safe line from Position's incoming_lines)
   ▼
Storefront Rendering
      (presentation only: woocommerce_get_availability text)
```

Each stage **consumes** the stage above it and **exposes** a narrower,
purpose-built view to the stage below. No stage skips a layer: the
Renderer never queries Purchase Orders directly, and Purchase Orders never
write to WooCommerce stock directly. This is the concrete, code-level
expression of the "single mutation ownership" and "one authoritative
calculator" rules in §4.

### 6.2 Ownership by responsibility

For each stage, four distinct kinds of ownership are worth naming
separately, because conflating them is exactly how duplicate calculation
paths get introduced:

| Stage | Business ownership | Read ownership | Mutation ownership | Presentation ownership |
|---|---|---|---|---|
| **Suppliers** | This plugin (`wc-inventory-overview`) | `Suppliers` repository | `Suppliers` class (CRUD + normalization) | Purchasing → Suppliers admin page |
| **Purchase Orders** | This plugin | `Purchase_Orders`/`Purchase_Order_Lines` repositories | `PO_Service` (create/place/cancel/close-short/duplicate) | Purchasing → Purchase Orders admin page; standalone read-only print view (M13) via `PO_Print_Renderer`, composed by `PO_Admin` from the same repositories — no separate read/mutation ownership |
| **Goods Receipts** | This plugin | `Goods_Receipts`/`Receipt_Lines`/`Receipt_Costs` repositories | `Goods_Receipt_Service` (D3/INV-2 sole mutator) | Purchasing → Receive Stock admin page |
| **Inventory Position** | This plugin (derived, not stored) | `Inventory_Position_Service` (D12 sole calculator) | N/A — read-only, nothing to mutate | Inventory Overview admin page (Incoming/Position columns) |
| **Expected Delivery** | This plugin (derived, not stored — API v1) | `Expected_Delivery_Service` (sole public API) | N/A — read-only | `Expected_Delivery_Renderer` (storefront) |
| **Product catalog / stock quantity / prices** | WooCommerce | WooCommerce | WooCommerce | WooCommerce (this plugin never overrides `_stock` directly outside Goods Receipt posting) |

### 6.3 Cross-plugin ownership registry

The full cross-plugin registry (this plugin vs. WooCommerce vs. MP Commerce
Fulfillment, the outbound/fulfillment sibling) lives in `docs/OWNERSHIP.md`
and is authoritative for inbound/outbound domain boundaries — unchanged by
this baseline. This document's §6.1–6.2 is the **internal** pipeline within
this plugin's own inbound ownership, one level more detailed than the
cross-plugin registry needs to be.

The previously-referenced sibling "Biopentra Storefront" plugin, which
`CLAUDE.md`'s pre-M7 text named as the intended consumer of expected-delivery
data, was verified in M7 to be an empty directory. This plugin therefore owns
storefront presentation of expected delivery directly — see ADR-0003 and
§7.4 below. If Biopentra Storefront (or any other consumer) ships in the
future, it does not receive ownership of this concept; it becomes a second
generic consumer of `Expected_Delivery_Service`, exactly like the built-in
renderer.

---

## 7. Frozen public APIs

The platform has exactly two components with a formally versioned public
API. Everything else is internal implementation, reachable only through
WordPress admin UI, WP-CLI, or the sole-owner service classes named in §4 —
none of which carry an independent version number, because none of them
have an external consumer to version against yet (D16).

### 7.1 Inventory Position (M3)

**Public entry point:** `WC_Inventory_Overview_Inventory_Position_Service`

```php
Inventory_Position_Service::get_position( string $item_type, int $item_id, float $on_hand ): array
Inventory_Position_Service::get_positions_bulk( array $product_on_hand, array $variation_on_hand ): array
```

`$item_type` is `Inventory_Position_Service::TYPE_PRODUCT` or `TYPE_VARIATION`.
`$on_hand`/`$product_on_hand`/`$variation_on_hand` are always **caller-supplied**
— the Service never fetches On Hand itself; `get_positions_bulk()` takes two
separate ID-keyed maps (product IDs and variation IDs), not one combined list,
matching the Resolver's separate product-scoped and variation-scoped queries.
Returns `{on_hand, incoming, position, incoming_delayed, incoming_lines}`,
keyed by item ID for the bulk call. `get_position()` is defined as
`get_positions_bulk()` with a single-item map — single and bulk always share
one code path and can never disagree.

This API predates M7's formal `API_VERSION` convention and has no version
number of its own; its contract has been stable since v1.20.0 and is treated
as frozen by convention (any consumer, including M7's own
`Expected_Delivery_Service`, depends on this exact return shape).

**Not public:** `Inventory_Position_Resolver` (the pure calculation function)
is called only by the Service — an internal implementation detail, not a
second entry point.

### 7.2 Expected Delivery (M7)

**Public entry point:** `WC_Inventory_Overview_Expected_Delivery_Service`,
`API_VERSION = 1` (versioned independently of the plugin version).

```php
Expected_Delivery_Service::get_for_product( WC_Product|int $product ): Result_Interface
Expected_Delivery_Service::get_for_products_bulk( array $products ): array  // keyed by item ID
```

Both methods accept a `WC_Product`/`WC_Product_Variation` instance **or** a
bare product/variation ID — not the instance only.

**The public contract is the interface**,
`WC_Inventory_Overview_Expected_Delivery_Result_Interface` — four
`STATE_*` constants (`STATE_IN_STOCK` / `STATE_UNAVAILABLE` /
`STATE_EXPECTED_DATE` / `STATE_EXPECTED_SOON`) and five accessors
(`api_version()`, `available_now()`, `state()`, `expected_date()`,
`confidence()`), never the concrete `Result` class. `api_version()` is
**informational only** — consumers must never branch on it at runtime; a
backward-incompatible contract change is expressed as a new `API_VERSION`,
never as a runtime `if`.

**Extension filters** (the only two, by deliberate design — see §7.4 for
the ones that were considered and cut):

| Filter | Purpose | Fires |
|---|---|---|
| `wc_io_storefront_render_expected_delivery( bool $render, WC_Product $product )` | Generic per-render opt-out | Before any Position lookup — an opt-out never costs a query |
| `wc_io_expected_delivery_text( string $text, Result_Interface $result, WC_Product $product )` | Copy override | After the Renderer computes its default text |

Full consumer-facing reference: `docs/api-expected-delivery.md`.

### 7.3 Explicitly distinguished: public API vs. internal implementation

| Public API (stable, versioned or convention-frozen) | Internal implementation (no external contract) |
|---|---|
| `Inventory_Position_Service::get_position()` / `get_positions_bulk()` | `Inventory_Position_Resolver` |
| `Expected_Delivery_Service::get_for_product()` / `get_for_products_bulk()` | `Expected_Delivery_Resolver`, `Expected_Delivery_Result` (concrete class) |
| `Expected_Delivery_Result_Interface` | Every repository class (`Purchase_Order_Lines`, `Goods_Receipts`, `Receipt_Lines`, …) |
| `wc_io_storefront_render_expected_delivery`, `wc_io_expected_delivery_text` filters | `PO_Service`, `Goods_Receipt_Service`, `PO_Receiving_Sync`, `Batch_Migration_Service` (admin-UI-only entry points, no REST/headless contract) |
| | All `admin_post`/`wp_ajax_*` handlers (§ Admin surface, `docs/architecture-audit.md`) — these are WordPress admin plumbing, not a public API in the versioned sense |

### 7.4 Future extension points

Per D16 ("no speculative schema or APIs... until a concrete consumer
exists"), the following are **not built** and have no timeline, but each has
a documented, additive path that goes through the existing sole-entry-point
services rather than around them:

- **REST / Store API / GraphQL / Blocks integration for Expected Delivery**
  — must delegate to `Expected_Delivery_Service`. Never re-derive the answer
  from Inventory Position or PO data directly (ADR-0003, consequence 4).
- **Structured data** (`woocommerce_structured_data_product` / `offers`) —
  deliberately not implemented in M7. Cut because the `offers` key isn't
  guaranteed to exist when the filter fires, `@type` isn't reliably
  discriminated by product type, `availabilityStarts` is undocumented by
  Google for this context, and Rank Math already emits a competing Product
  entity on this install. Purely additive later, under its own plan, if a
  concrete consumer appears.
- **A second Result implementation** — the interface exists specifically so
  this doesn't require an `API_VERSION` bump.
- **Reservations / Available stock** — INV-11's designed-for-later extension
  to Inventory Position's `{On Hand, Incoming, Position}` model, adding
  `Reserved`/`Available`/`Coverage`/`Forecast` without changing the
  surrounding architecture (D11).
- **Inbound Shipment entity** — an additive insertion point already reserved
  by D10, not yet built.

---

## 8. Frozen schema

**`DB_VERSION = 11`. No pending migrations.** The schema was unchanged from
M6 (v1.23.0) through M16 (v1.33.0); M17 bumped it to v11, adding
`wc_io_suppliers.merged_into_supplier_id` and the new `wc_io_supplier_merges`
table.

### 8.1 Tables

| Table | Introduced | Owner (write) | Purpose |
|---|---|---|---|
| `wc_io_suppliers` | v6 (M1); `merged_into_supplier_id` column added v11 (M17) | `Suppliers` (all columns) / `Suppliers::mark_merged()` only (`merged_into_supplier_id`) | Supplier entity |
| `wc_io_supplier_merges` | v11 (M17) | `Supplier_Merges` (append-only), via `Supplier_Merge_Service` | Merge audit history — source/target IDs and name snapshots, reassigned counts, performer, timestamp |
| `wc_io_purchase_orders` | v7 (M2) | `Purchase_Orders`, via `PO_Service` | PO header |
| `wc_io_purchase_order_lines` | v7 (M2); `qty_received` column added v9 (M5) | `Purchase_Order_Lines`, via `PO_Service` (most columns) / `PO_Receiving_Sync` (`qty_received` only) | PO lines — the incoming supply record |
| `wc_io_po_events` | v7 (M2) | `PO_Events` (append-only) | PO audit history |
| `wc_io_goods_receipts` | v8 (M4) | `Goods_Receipts`, via `Goods_Receipt_Service` | Receipt header |
| `wc_io_receipt_lines` | v8 (M4) | `Receipt_Lines`, via `Goods_Receipt_Service` | Receipt lines, nullable `po_line_id` |
| `wc_io_receipt_costs` | v8 (M4) | `Receipt_Costs`, via `Goods_Receipt_Service` | Landed cost lines |
| `wc_io_inventory_movements` | v1; `reference_type`/`reference_id`/`supplier_id` columns added v8 (M4) | `Movements` | Stock/cost movement ledger |
| `wc_io_exchange_rates` | v2 | `Exchange_Rates` | FX rate history |
| `wc_io_purchase_batches` | v1; `migrated_receipt_id`/`migrated_at` columns added v10 (M6) | **Frozen** — historical legacy table, never written to by any active code path except `Batch_Migration_Service`'s tracking-column backfill | Legacy Batch Intake header (pre-M6) |
| `wc_io_purchase_batch_lines` | v1 | **Frozen** | Legacy Batch Intake lines |
| `wc_io_purchase_batch_costs` | v1 | **Frozen** | Legacy Batch Intake landed costs |

No table has ever been dropped. No table has ever been truncated by any
milestone's code path (guard-enforced for M6, the milestone where that risk
was highest).

### 8.2 Relationships

```
Supplier 1 ──── * Purchase Order 1 ──── * PO Line
   │                    │                   │  (nullable line-level link)
   │                    └──── * PO Event    ▼
   └────────── * Goods Receipt 1 ──── * Receipt Line ──── (purchasable item:
                     │  1                                   simple product or variation)
                     ├──── * Receipt Cost
                     └──── * Inventory Movement  (reference_type = 'receipt')

wc_io_purchase_batches (frozen, pre-M6)
   │  migrated_receipt_id → wc_io_goods_receipts.id  (nullable; set once, by migration only)
   └── wc_io_purchase_batch_lines / wc_io_purchase_batch_costs (frozen)
```

Referential integrity is by convention (WordPress norm — no MySQL foreign
keys): every write goes through a service method in an explicit DB
transaction; posted/placed aggregates are never hard-deleted; suppliers and
products are archived, never deleted; lines carry denormalized
`sku_snapshot`/`supplier_name` so history survives WP-side deletions.

### 8.3 Historical legacy tables

Per D14 (migration is additive) and M6's Retirement strategy, the three
`wc_io_purchase_batch*` tables are **frozen forever**:

- Never dropped, never truncated, by any code path in any milestone.
- Batch Intake can no longer *create* new rows in them (retired in M6) —
  they are read-only history from this point forward.
- `Batch_Migration_Service` reads from them (never writes business data to
  them — only backfills the two M6 tracking columns on the batch header) to
  materialize equivalent Goods Receipt records.
- They remain the permanent audit trail behind every migrated receipt —
  `wp wc-io migrate-batches --verify` reconciles against them indefinitely.

### 8.4 Goods Receipt vs. Purchase Order ownership (schema level)

- A `wc_io_goods_receipts` row **never** requires a `wc_io_purchase_orders`
  row — `source = 'direct'` receipts (M4) stand alone.
- A `wc_io_receipt_lines.po_line_id` is nullable — set only when that line
  fulfills a PO line (M5), never populated by any M4-era code path, never
  editable after creation.
- A `wc_io_purchase_order_lines.qty_received` is written **only** by
  `PO_Receiving_Sync`, in response to a Goods Receipt being posted or voided
  — the PO table is never the origin of a stock mutation; it only records
  the consequence of one.

### 8.5 Migration tracking

`wc_io_purchase_batches.migrated_receipt_id` (nullable FK-by-convention to
`wc_io_goods_receipts.id`) and `migrated_at` (nullable datetime) are the only
schema artifacts M6 added. Both are `NULL` for any batch never migrated, and
both are cleared back to `NULL` by `Batch_Migration_Service::rollback_batch()`
— rollback is schema-symmetric, not just data-symmetric.

---

## 9. Architectural invariants — see §5

(Consolidated above to avoid duplication — §5 is the authoritative invariant
list for this document.)

---

## 10. Extension philosophy

### 10.1 Where extensions belong

| If you need to... | Extend... | Never... |
|---|---|---|
| Read stock/incoming/position for a product | `Inventory_Position_Service` | Query `wc_io_purchase_order_lines` directly from new code |
| Read or render expected-delivery text | `Expected_Delivery_Service` (+ the two filters if you're a theme/consumer, not this plugin) | Call `Expected_Delivery_Resolver` directly, or re-derive from Position/PO data |
| Mutate stock or cost | `Goods_Receipt_Service::post()`/`void()` | Call `Restock_Service`'s mutation methods from anywhere except `Goods_Receipt_Service` and the (frozen, disabled) `Batch_Intake_Service` |
| Change `qty_received` | `PO_Receiving_Sync` (via `Goods_Receipt_Service`, or the reconciliation CLI's `--fix`) | Write `wc_io_purchase_order_lines.qty_received` from any other class |
| Add a new PO lifecycle transition | `PO_Lifecycle`'s transition table, with a new PO Event type | Introduce a second status-mutation path outside `PO_Service` |
| Materialize new historical data from legacy tables | A new, single-purpose service modeled on `Batch_Migration_Service` (one transaction per unit of work, pure field mapping, order-independent) | Route new historical writes through `Goods_Receipt_Service::post()` — that method is for *live* receiving, not replay |

### 10.2 Where filters belong

Every extension point in this plugin is a **generic** WordPress filter or
action — never a named dependency on a specific consumer plugin. The M7
pattern (`wc_io_storefront_render_expected_delivery` +
`wc_io_expected_delivery_text`) is the template for any future extension
point:

1. **Opt-out filters** run before any expensive work (query, computation) —
   an opt-out must never cost what it's opting out of.
2. **Copy/value-override filters** run after the default is computed, and
   receive the computed value plus enough context (the Result, the product)
   for a consumer to make an informed override — never raw internal state.
3. No filter or action may leak a forbidden field. M7's Result interface
   deliberately excludes `on_hand`, `incoming`, `supplier`, `po_number`,
   `po_id`, `outstanding`, `is_delayed` — a filter downstream of that Result
   inherits the same exclusion for free, because it never had access to the
   excluded data in the first place.

### 10.3 Where APIs belong

A class becomes a "public API" (interface-typed, `API_VERSION`-carrying,
guard-enforced sole-entry-point) only when a concrete external consumer
exists or is being built in the same milestone (D16). Until then, extension
happens through the filters described above, and through direct PHP class
usage within `includes/` — which is Internal, not Public, and may change
shape between minor versions without a version bump.

`Expected_Delivery_Service` is the platform's only formally versioned API as
of this baseline, because it is the only component with a real external
justification: the storefront output is customer-visible and the API was
built anticipating a possible second renderer (a sibling plugin, or a future
headless frontend).

### 10.4 What future milestones must never bypass

1. **The sole-owner rule (§4)** for any concept that already has one.
   Introducing a second class that computes Position, mutates stock, or
   renders expected-delivery text is not a new feature — it is an
   architecture violation, exactly the kind these guard tests exist to catch
   automatically.
2. **The `on_hand`/`incoming`/`position` privacy boundary at the storefront.**
   `Expected_Delivery_Result` never exposes raw inventory figures. Any future
   storefront-facing feature (low-stock badges, "only 2 left" messaging,
   etc.) needs its own deliberately-scoped Result, not a widened
   `Expected_Delivery_Result`.
3. **D16 (no speculative schema/APIs).** A new table, a new REST route, or a
   new public interface needs a concrete consumer in the same milestone,
   not "we'll probably need this eventually."
4. **INV-8 (variation-level purchasing).** Any new purchasing-adjacent
   feature (a future reservations table, a future coverage/forecast field)
   must reference the variation for variable products, never the parent.
5. **Zero schema change without a bump.** `DB_VERSION` changes only via a
   new `expected_schema_v{N}()` function chained into the dispatcher, with a
   schema-shape assertion test — never an ad-hoc `ALTER` outside
   `WC_Inventory_Overview_Install`.

---

## 11. Versioning policy

Three independent version numbers exist in this codebase. They change for
different reasons and must never be conflated:

| Version | Current value | Changes when | Consumed by |
|---|---|---|---|
| **Plugin version** (`Version:` header, `wc-inventory-overview.php`) | 1.24.0 | Every release, per SemVer-ish convention (`docs/release-runbook.md`) | WordPress plugin updater, GitHub Releases, `readme.txt` |
| **`DB_VERSION`** (`WC_Inventory_Overview_Install`) | 10 | Only when schema actually changes (new table, new column, new index) | The upgrade routine's `expected_schema_v{N}()` dispatcher; the schema-shape assertion tests |
| **`Expected_Delivery_Service::API_VERSION`** | 1 | Only on a backward-incompatible change to the `Result_Interface` contract — never on a plugin release that doesn't touch this contract | External consumers of `Expected_Delivery_Service` (informational only per `api_version()` — never a runtime branch point) |

A plugin release (e.g., 1.25.0) can ship with `DB_VERSION` and `API_VERSION`
both unchanged — M7 itself is the proof (1.24.0, `DB_VERSION` unchanged at
10). Conversely, a schema bump or an API version bump always accompanies a
plugin version bump, but never the other way around implicitly.

Any future public API (a second `API_VERSION`-carrying service, should one
be built) follows the same independent-numbering rule — its own version
number, bumped only for its own backward-incompatible changes.

---

## 12. Future-governance rules

These rules apply to M12 and every milestone after it (M8, M9, M10, and M11
all followed them — see the update to rule 6 below):

1. **This document is the starting point.** A new milestone plan opens by
   stating what it builds on top of this baseline — it does not re-explain
   or re-verify M0–M11's architecture from scratch. If a milestone plan finds
   this document inaccurate (a rule has drifted from the code), fixing this
   document is a prerequisite for that milestone, not a side effect of it.
2. **A new sole-owner boundary (or narrow internal value/policy class)
   requires a guard test in the same PR.** No "we'll add the architecture
   guard later" — every milestone from M3 onward has shipped its guard test
   alongside the feature (M9's `Supplier_Lead_Time_Service` guard, M10's
   `Expected_Date_Suggestion_Service` guard, and M11's `Expected_Deadline`
   guard all included), and that discipline is now the baseline, not
   optional.
3. **A new cross-cutting architectural decision requires an ADR.** Follow
   the Nygard format already established in `docs/adr/` (`0001`–`0003`).
   Number sequentially; update `docs/adr/README.md`'s index table.
4. **Reassigning ownership of an existing concept requires an Accepted ADR**
   in every affected repository before implementation — the same rule
   `docs/OWNERSHIP.md` already states for cross-plugin boundaries, extended
   here to apply to internal sole-owner boundaries too.
5. **Schema changes are additive by default.** Follow M1–M6's precedent:
   new tables/columns, never destructive `ALTER`s, never a dropped column,
   in the normal course of a milestone. A genuinely destructive schema
   change needs its own explicit sign-off and rollback plan, not just a
   version bump.
6. **Deprecation follows M6's four-category model:** *Removed* (dead hook
   registrations/UI entry points), *Disabled, not deleted* (methods kept
   callable — often by tests — but unreachable from production UI, marked
   `@deprecated`), *Extracted* (shared logic pulled into its own class before
   the original home is retired), *Frozen forever* (historical data tables,
   never dropped). **Fulfilled by M8:** the `@deprecated` Batch Intake
   create/apply surface M6 marked "disabled, not deleted, reserved for M8"
   was physically removed in M8, after rewriting its one remaining test
   dependency (`create_legacy_batch()`) to no longer need it and verifying
   four explicit deletion criteria. The legacy `wc_io_purchase_batches*`
   tables themselves remain frozen forever, per D14, untouched by that
   removal. The same discipline (reserve a physical-deletion date, verify
   deletion criteria, never delete speculatively) applies to any future
   `@deprecated` surface.
7. **No milestone plan ships without updating this document** if it changes
   any fact recorded here (a new table, a new invariant, a new sole-owner
   class, a version bump). Treat staleness here the same as a failing test.

---

## 13. Cross-references

| Topic | Canonical source |
|---|---|
| Domain architecture (D1–D19, INV-1–INV-8) | `CLAUDE.md` Part I |
| Milestone sequencing / roadmap | `CLAUDE.md` Part II, and the Implementation Status table at the end of `CLAUDE.md` |
| Per-milestone implementation detail | `docs/milestones/m{N}-implementation-plan.md` |
| Per-milestone code/schema audit | `docs/architecture-audit.md` |
| Cross-plugin domain ownership | `docs/OWNERSHIP.md` |
| Architecture decisions (ADRs) | `docs/adr/` |
| Expected Delivery consumer contract | `docs/api-expected-delivery.md` |
| Expected Delivery merchant behavior | `docs/admin-guide-storefront-availability.md` |
| Release process | `docs/release-runbook.md` |
| Rollback procedures | `docs/rollback-plan.md` |
| Test strategy | `docs/testing.md` |

---

**This baseline is frozen as of v1.24.0 / `DB_VERSION` 10 / M0–M7 complete;
updated in place through M11 (v1.28.0) per §12 rule 7.**
Any change to a fact recorded in this document should either update this
document directly (documentation-only change) or be accompanied by an update
to it (as part of a milestone that changes the underlying fact) — never left
to drift.
