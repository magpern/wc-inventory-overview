# M17 — Exhaustive Discovery + Haiku-Ready Definitive Implementation Plan (Hardened)

**Repository:** `/opt/biopentra/dev/wc-inventory-overview`
**Planning model:** Claude Sonnet 5 (this pass, read-only)
**Implementer:** Claude Haiku 4.5 (materializes this plan as `docs/milestones/m17-implementation-plan.md`, then implements)

**Revision note:** this is the hardened revision of the M17 Supplier Merge plan. Discovery, candidate selection, and overall scope (Parts A–E, G, H) are unchanged and were **not** re-litigated. This revision resolves thirteen concrete safety/precision gaps identified in a second review pass: non-reactivatable merged suppliers, server-enforced confirmation, exception-safe transactions, SQL-failure-vs-zero-rows contracts, removal of speculative public hooks in favor of an internal test seam, closure of the concurrent-create race via supplier-row locking at creation time (new WP-M17-4), corrected query accounting, corrected merge-chain wording, corrected target-metadata wording, and elimination of every remaining "or equivalent"/"Haiku's call" choice.

## Context

Every milestone plan since M9 evaluated a Supplier Merge tool and rejected it *for that milestone* while flagging it as the correct next dedicated milestone once its own design questions were resolved. `docs/admin-guide-suppliers.md` has carried an explicit "Not Yet Available: Supplier merge tool" backlog line since before M9. M16's own plan (Part G) named it directly as the reasonable next milestone. This document is the implementation-ready, Haiku-executable specification, intended for verbatim materialization as `docs/milestones/m17-implementation-plan.md`.

**Baseline (verified live during planning):** `v1.33.0` (M16) is released — tag `v1.33.0` → commit `7fd59ad`, GitHub Release published, `origin/main` @ `2c9e280` contains it (`git merge-base --is-ancestor 86bd193 origin/main` → `YES`; PR #19 merged). **Canonical M17 implementation base is `origin/main` @ `2c9e280`.** No release blocker.

---

# PART A — Verified Post-M16 Repository Baseline

| Item | Value |
|---|---|
| Repository path | `/opt/biopentra/dev/wc-inventory-overview` |
| `origin/main` SHA | `2c9e280` (`docs(release): record v1.33.0 publication`) |
| Latest tag | `v1.33.0` → commit `7fd59ad` |
| Latest GitHub Release | `WC Inventory Overview 1.33.0`, published, Latest |
| Plugin header version at tag | `1.33.0` |
| `readme.txt` Stable tag | `1.33.0` |
| `DB_VERSION` | `'10'` — unchanged since M6 |
| M16 release/freeze state | Released standalone; `docs/checklists/m16-release-readiness.md` records Level A freeze; PR #19 merged |
| M13–M16 plan immutability | `docs/milestones/m13..m16-implementation-plan.md` present, untouched |
| GitHub Actions baseline | `ci.yml`, `tests.yml` (lint, unit, focused, integration — all blocking), `release.yml` (tag-triggered) |
| M17 branch/plan/code exists? | No |

No material repository contradiction blocks reliable M17 design.

---

# PART B — Repository Discovery Summary

- **Suppliers** (`class-wc-inventory-overview-suppliers.php`): table `wc_io_suppliers` (`id, name, normalized_name UNIQUE, default_currency, default_lead_time_days, email, phone, supplier_reference, note, status, created_at, updated_at`). `create()/update()/archive()/reactivate()` (all idempotent, `archive()`/`reactivate()` return plain `bool`, driven by `false !== $wpdb->update(...)`). **No merge/reassign method exists.** **No `get_for_update()` exists — must be added.** **`reactivate()` currently has no awareness of any merged concept and its caller (`Purchasing_Page::handle_supplier_reactivate()`) currently discards its return value entirely** (always shows a "Supplier reactivated." success notice regardless of outcome) — both are direct M17 concerns (Part F/J).
- **Purchasing/Receiving**: `wc_io_purchase_orders.supplier_id` (NOT NULL DEFAULT 0, indexed) and `wc_io_goods_receipts.supplier_id` (NULL, indexed) are the two live, independently-stored, bulk-reassignable FK-like columns. Both are only mutable today through `update_fields()` while editable (PO: draft/placed; receipt: draft). No existing path reassigns a terminal PO or posted receipt — merge needs a new, deliberate, out-of-band bulk path.
- **`wc_io_inventory_movements.supplier_id`/`supplier_name`**: written once at receipt-post time, append-only, never updated after insert.
- **`WC_Inventory_Overview_DB_Transaction`**: `begin(): bool / commit(): bool / rollback(): bool / is_active(): bool`; nested via SAVEPOINTs; no built-in locking — callers issue raw `SELECT ... FOR UPDATE` after `begin()`.
- **Derived statistics** (`Supplier_Lead_Time_Service`, `Supplier_Order_History_Service`, `Supplier_Spend_Service`): 100% query-time, zero caching, all filter by `purchase_orders.supplier_id` — self-correct automatically once `supplier_id` moves.
- **Capability model**: all `Purchasing_Caps` constants map to `manage_woocommerce`, filterable. `MANAGE_SUPPLIERS` is dead code (Suppliers admin hardcodes `current_user_can('manage_woocommerce')`) — M17 does not retroactively fix this (out of scope) but wires its own new `MERGE_SUPPLIER` constant correctly from day one.
- **`WC_Inventory_Overview_PO_Request_Token`**: verified generic (`issue( string $context = 'po' )` / `consume( string $token, string $context = 'po' )`, user-scoped transient, free-string context) — directly reusable with `context = 'supplier_merge'`.
- **Existing `$wpdb` failure convention** (confirmed in `Purchase_Orders::update_fields()`, line ~391): `$result = $wpdb->update(...); if ( false === $result ) { return new WP_Error( 'wc_io_po_update_failed', 'Failed to update purchase order', array( 'db_error' => $wpdb->last_error ) ); } return true;` — **this exact shape (`false === $result` → `WP_Error` carrying `$wpdb->last_error`) is the established, repository-wide convention for distinguishing genuine SQL failure from a legitimate zero/low-affected-row outcome.** M17's new write methods reuse this convention precisely (Part H).
- **PO placement already gates on supplier status** (`PO_Validation::validate_for_place()`, line ~132): `if ( isset( $supplier['status'] ) && STATUS_ACTIVE !== $supplier['status'] ) { return new WP_Error( 'wc_io_po_supplier', 'Supplier must be active to place a purchase order' ); }`. **Draft creation does not check status at all** (`PO_Service::create_draft()` only checks the supplier resolves). **`Goods_Receipt_Service::post()` has no supplier-status check of any kind** — but this is irrelevant to the merge race (see Part I §Concurrency): any receipt that already exists at merge time is swept up by the merge's own bulk UPDATE regardless of its status, so the only real gap is at *creation* time, not posting time.
- **No existing failure-injection/test-seam pattern exists anywhere in this codebase** (confirmed by targeted search — no fakes, mocks, or test-only injection points found). M17 introduces exactly one, precisely specified in Part I.
- **Query-count-contract testing convention, confirmed exact mechanism** (`tests/integration/supplier-spend/test-supplier-spend-performance.php:queries_for_get_summary()`): `$before = $wpdb->num_queries; <call under test>; return $wpdb->num_queries - $before;` — this delta-on-`$wpdb->num_queries` technique is the established, repo-wide pattern for every milestone's query-count-bounded contract (M9/M11/M12/M14/M15 all use it per that file's own docblock). M17 reuses it exactly (Part K/L).
- **Schema-upgrade test naming convention** (`tests/integration/install/test-schema-v10-upgrade.php`, class `Test_WC_IO_Batch_Migration_Schema_V10_Upgrade extends WP_UnitTestCase`): file name is `test-schema-v{N}-upgrade.php` in `tests/integration/install/`; that particular v10 class name reflects its M6/batch-migration origin specifically — not a generic template. M17's v11 file follows the same file-naming pattern but with a generic class name, since v11 has no batch-migration relationship (Part L).
- **Admin request pattern** (`PO_Admin::handle_place()`, `Purchasing_Page::handle_supplier_archive()`): capability guard → `check_admin_referer()` → (consequential actions) request-token consume → service call → PRG redirect with a transient-backed notice on error. M17's merge handler follows this shape exactly.
- **Milestone-lifecycle process**: WP0 → WP1 Implementation → **WP2 Independent Audit (fresh Claude instance)** → WP3 Remediation → WP4 Freeze → WP5/WP6. Release Triggers (schema change, ownership-boundary change, etc.) mean M17 releases standalone after freeze, not via a train.

---

# PART C — Complete Supplier Reference Map

*(Unchanged from prior pass — reproduced for completeness; this table was not challenged in the hardening review.)*

| Table.Column | Type/Index | Reference or Snapshot | Mutable today? | Merge action |
|---|---|---|---|---|
| `wc_io_suppliers.id` | PK | Identity | n/a | Source row survives, archived + `merged_into_supplier_id` set |
| `wc_io_suppliers.normalized_name` | varchar(191) UNIQUE | Dedupe key | on create/update | Untouched |
| `wc_io_purchase_orders.supplier_id` | bigint NOT NULL DEFAULT 0, KEY | **Live FK** | only draft/placed via `update_fields()` | **MUST reassign, all statuses** |
| `wc_io_purchase_orders.supplier_name_snapshot` | varchar(190) NOT NULL DEFAULT '' | **Snapshot** — frozen once placed | only draft | **MUST NOT rewrite** |
| `wc_io_purchase_order_lines.supplier_sku` | varchar(100) NULL | Not a supplier FK | n/a | No action |
| `wc_io_goods_receipts.supplier_id` | bigint NULL, KEY | **Live FK**, independently stored on every receipt | only draft via `update_fields()` | **MUST reassign, all statuses** |
| `wc_io_goods_receipts.supplier_name_snapshot` | varchar(190) NULL | **Snapshot** | only draft | **MUST NOT rewrite** |
| `wc_io_inventory_movements.supplier_id`/`supplier_name` | bigint NULL KEY / text NULL | Point-in-time copy, append-only | never | **MUST NOT rewrite** |
| `wc_io_purchase_batches.supplier_name` | text NULL | Legacy, pre-dates Suppliers entity | frozen | Out of scope |

**Merge blast radius: exactly two live bulk-UPDATE targets** — `wc_io_purchase_orders.supplier_id` and `wc_io_goods_receipts.supplier_id`. Everything else is explicitly frozen.

---

# PART D/E — Candidate Comparison and Selection

*(Unchanged — Supplier Merge selected. See prior-pass rationale: four consecutive milestone plans independently reached this verdict; M16 Part G explicitly suggested it; every other candidate is blocked by missing prerequisite data, D16, or is out of this plugin's ownership scope per `docs/OWNERSHIP.md`. This repo's own Release Triggers rule — schema change + ownership-boundary change — confirms M17 releases standalone, not via a train.)*

---

# PART F — Exhaustive Business Semantics (Hardened)

## Selected operation

`merge( source_supplier_id, target_supplier_id, performed_by, confirmation )`:
1. All of source's `wc_io_purchase_orders` and `wc_io_goods_receipts` rows (every status) become `target`'s.
2. `supplier_name_snapshot` on those rows is **not** rewritten.
3. `wc_io_inventory_movements` is **not** touched.
4. Target's own metadata is **never copied from source and never mutated** by the merge — it may only be *read*, for eligibility validation, the audit snapshot, and success/error presentation (corrected wording, Section 9 of the review — the original "never read from or written to" phrasing was inaccurate: target status and name are necessarily read).
5. Source is archived and stamped `merged_into_supplier_id = target_id`, and is thereby **permanently and irreversibly excluded from all future operational use** — it can never be reactivated, never selected in any supplier picker, and never participate in another merge as either source or target (new requirement, Section 1 of the review).
6. The typed confirmation the admin enters is **re-validated server-side, inside the transaction, against the freshly row-locked source row's current name** — client-side JS disable/enable is UX only and is never trusted as the authorization mechanism (new requirement, Section 2).
7. One audit row is written recording source, target, operator, timestamp, and aggregate reassigned-counts. No per-record audit rows.
8. The whole operation is one atomic transaction, made **exception-safe**: both `WP_Error` return paths and any thrown `Throwable` after `begin()` guarantee `rollback()` and leave zero partial state (new requirement, Section 3).
9. The operation is irreversible at the product level (no "undo merge" in M17).
10. No WordPress hook (action or filter) is introduced for this feature — public API impact remains genuinely NONE (corrected, Section 5 — the prior draft's `do_action('wc_io_supplier_merged', ...)` and `apply_filters('wc_io_supplier_merge_fail_after_step', ...)` are both removed).

## Business Rules

- **BR-M17-1 (Distinctness):** `source_supplier_id !== target_supplier_id` → `wc_io_supplier_merge_same_supplier`.
- **BR-M17-2 (Existence):** both suppliers must exist → `wc_io_supplier_merge_source_not_found` / `wc_io_supplier_merge_target_not_found`.
- **BR-M17-3 (Source eligibility):** source may be active or archived, but `source.merged_into_supplier_id` must be `NULL` → `wc_io_supplier_merge_source_already_merged` (message includes the existing target's name, resolved via `Suppliers::get()` on the stored `merged_into_supplier_id`).
- **BR-M17-4 (Target eligibility):** target must have `status = 'active'` AND `target.merged_into_supplier_id` must be `NULL` → `wc_io_supplier_merge_target_not_active` / `wc_io_supplier_merge_target_already_merged`.
  - *Why BR-M17-3/4 together fully solve merge chains with zero chain-walking logic:* any supplier that has ever been a *source* is permanently frozen from further participation as either role (BR-M17-15 below generalizes this). A supplier that has only ever been a *target* remains fully live and can itself become a source later — `A→B` then later `B→C` is two ordinary single-hop merges, never a multi-hop chain resolution (corrected framing — see BR-M17-17 for the precise historical-metadata semantics this produces).
- **BR-M17-5 (Metadata policy):** target's `name`, `normalized_name`, `default_currency`, `default_lead_time_days`, `email`, `phone`, `supplier_reference`, `note` are never copied from source and never mutated by the merge. They may be **read** (not written) for eligibility validation and audit/UI presentation. No field-by-field conflict resolution exists or is needed. *(Corrected wording — see Section 9 of the review.)*
- **BR-M17-6 (Reference reassignment):** `UPDATE wc_io_purchase_orders SET supplier_id = :target WHERE supplier_id = :source` and the equivalent for `wc_io_goods_receipts`, each a single bulk statement, applied to every status, deliberately bypassing `PO_Lifecycle::assert_editable()` and receipt draft-editability gates.
- **BR-M17-7 (Snapshot preservation):** `supplier_name_snapshot` on both tables is never written by the merge.
- **BR-M17-8 (Movement immutability):** `wc_io_inventory_movements` rows are never written by the merge.
- **BR-M17-9 (Transaction atomicity, exception-safe):** the entire operation runs inside one `WC_Inventory_Overview_DB_Transaction`, wrapped in a `try/catch (\Throwable $e)`. Every `WP_Error` return path after `begin()` calls `rollback()` before returning. Any `Throwable` reaching the `catch` block calls `rollback()` (guarded by `$txn->is_active()`) and returns a single generic error, `wc_io_supplier_merge_failed` (message: *"Supplier merge failed unexpectedly and was rolled back."*, with the caught exception's message attached only as internal `WP_Error` data, never rendered to the admin UI). The transaction is never left active on any exit path.
- **BR-M17-10 (Post-merge source state):** on success, source is set `status = 'archived'` and `merged_into_supplier_id = target_id` via `Suppliers::mark_merged()`.
- **BR-M17-11 (Auditability):** exactly one row is inserted into `wc_io_supplier_merges` per successful merge (`source_supplier_id`, `source_supplier_name_snapshot`, `target_supplier_id`, `target_supplier_name_snapshot`, `purchase_orders_reassigned`, `goods_receipts_reassigned`, `performed_by`, `created_at`). No per-record audit rows.
- **BR-M17-12 (Idempotency/double-submit):** a second attempt to merge an already-merged source deterministically fails with `wc_io_supplier_merge_source_already_merged` — never silently no-ops, never partially re-applies. Combined with PRG and a request token (context `supplier_merge`), this covers stale pages, refresh, and double-submit identically.
- **BR-M17-13 (Authorization):** new capability `WC_Inventory_Overview_Purchasing_Caps::MERGE_SUPPLIER`, default-mapped to `manage_woocommerce`, filterable exactly like every existing purchasing capability.
- **BR-M17-14 (Failure behavior):** any `WP_Error` or caught `Throwable` means **zero** rows changed anywhere — guaranteed by BR-M17-9 — the caller may safely retry.
- **BR-M17-15 (Permanent dissolution):** a supplier with `merged_into_supplier_id IS NOT NULL` is permanently and irreversibly excluded from all future operational selection: it can never be reactivated (`Suppliers::reactivate()` must reject it deterministically), never appears in supplier autocomplete or New PO/Goods Receipt supplier selection (already true structurally, since it is always also archived), and can never be used as a merge source or target again (already covered by BR-M17-3/4, restated here as the general product contract).
- **BR-M17-16 (Server-enforced confirmation):** `merge()` accepts an explicit `string $confirmation` argument. It is compared, inside the transaction, against the *freshly row-locked* source supplier's `name` field using exact string equality (`===`) — no case-folding, no whitespace trimming beyond what the admin handler's standard `sanitize_text_field( wp_unslash( ... ) )` already performs on every POST field in this codebase, and never compared against `normalized_name`. A mismatch returns `wc_io_supplier_merge_confirmation_mismatch` and rolls back before any mutation. Client-side JS enable/disable is UX convenience only; it has no authority.
- **BR-M17-17 (Merge-chain historical metadata, corrected):** `merged_into_supplier_id` records the **direct** target of that specific historical merge — it is not a canonical-identity resolver and M17 implements no recursive chain-resolution logic. If `A` is merged into `B`, and later `B` is merged into `C`, then: `A.merged_into_supplier_id = B` (unchanged by the second merge — A's own row is never touched again once dissolved, consistent with BR-M17-15), and `B.merged_into_supplier_id = C`. All of `A`'s operational records were already moved to `B` by the first merge, and are moved again to `C` by the second merge's own bulk UPDATE (since those PO/receipt rows now carry `supplier_id = B`, which the `B→C` merge's `WHERE supplier_id = B` clause correctly sweeps up) — so no runtime read ever needs to traverse the `A→B→C` chain; it is automatically flattened at the data level by the second merge. The UI notice on `A`'s (now long-dissolved) detail page continues to say "merged into B," which is historically accurate and is not corrected to say "C" — this is intentional (BR-M17-7's snapshot-fidelity philosophy applied to merge history itself, not just PO/receipt snapshots).
- **BR-M17-18 (Concurrent-create closure, new):** creating a *new* Purchase Order draft or a *new* Goods Receipt draft requires locking and re-validating the chosen supplier's row (`FOR UPDATE`, active status, `merged_into_supplier_id IS NULL`) inside that creation's own transaction, exactly mirroring merge's own row lock — this serializes PO/receipt creation against a concurrently-running merge on the same supplier row, closing the race (Part I §Concurrency, WP-M17-4).

## Invariants

- **INV-M17-1:** the merge is all-or-nothing.
- **INV-M17-2:** `WC_Inventory_Overview_Supplier_Merge_Service::merge()` is the sole class permitted to write `wc_io_suppliers.merged_into_supplier_id` or to bulk-update `supplier_id` across more than one row of `wc_io_purchase_orders`/`wc_io_goods_receipts` at once. Enforced by an architecture guard test.
- **INV-M17-3:** zero stock/cost mutation.
- **INV-M17-4:** `supplier_name_snapshot` (both tables) byte-for-byte unchanged.
- **INV-M17-5:** the *mutation phase* (Part K) is a fixed, itemizable, constant-count set of statements regardless of the number of reassigned POs/receipts — no per-record loop, no per-record UPDATE, no per-record audit write. The *complete `merge()` call's* measured query count (Part K) is likewise constant across fixture sizes, though its absolute number includes precondition reads and transaction-control statements not part of the mutation phase itself.
- **INV-M17-6:** all derived-statistics services require zero code changes to correctly reflect a merge — verified empirically (WP-M17-5), not by inspection alone.
- **INV-M17-7:** the admin page (`Purchasing_Page`) contains no direct SQL for the merge.
- **INV-M17-8:** no merge chain ever requires multi-hop resolution **at operational-record read time** — BR-M17-3/4/18 make this structurally impossible for PO/receipt data. Historical `merged_into_supplier_id` values on dissolved supplier rows are direct-successor pointers only (BR-M17-17), not something any code resolves recursively.
- **INV-M17-9:** capability gate (`MERGE_SUPPLIER`) is checked before any other work in the admin-post handler.
- **INV-M17-10:** M17 introduces **no new WordPress action, no new WordPress filter, and no new public API of any kind.** The only new extension-adjacent surface is a private, test-bootstrap-gated static method (Part I) that is structurally inert in production and is not documented as, or usable as, an extension point.
- **INV-M17-11 (new):** no supported code path — `Suppliers::reactivate()`, the `wc_io_supplier_reactivate` admin-post handler, the Suppliers list-table row action, or any other — can return a supplier with `merged_into_supplier_id IS NOT NULL` to `status = 'active'`.
- **INV-M17-12 (new):** after a merge commits, no Purchase Order or Goods Receipt draft that references the now-dissolved source as its `supplier_id` can be successfully created by any subsequent request — proven by WP-M17-4's concurrency tests, not merely asserted.

---

# PART G — Architecture and Ownership

| Role | Owner |
|---|---|
| Business rules (BR-M17-*) | `WC_Inventory_Overview_Supplier_Merge_Service` (new) |
| Transaction boundary | Same service, using `WC_Inventory_Overview_DB_Transaction` (existing, unmodified) |
| Supplier read/lock (`get`, `get_for_update`) | `WC_Inventory_Overview_Suppliers` (new methods) |
| Supplier mutation (`mark_merged`, hardened `reactivate`) | `WC_Inventory_Overview_Suppliers` |
| PO reference bulk-mutation | `WC_Inventory_Overview_Purchase_Orders::reassign_supplier_bulk()` (new) |
| Receipt reference bulk-mutation | `WC_Inventory_Overview_Goods_Receipts::reassign_supplier_bulk()` (new) |
| Audit-history read/write | `WC_Inventory_Overview_Supplier_Merges` (new, append-only, mirrors `PO_Events`) |
| Concurrent-create closure | `WC_Inventory_Overview_PO_Service::create_draft()`, `WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post()` (both extended, WP-M17-4) |
| Presentation (admin-post handler, form, notices) | `WC_Inventory_Overview_Purchasing_Page` (existing, extended) |
| Capability | `WC_Inventory_Overview_Purchasing_Caps::MERGE_SUPPLIER` (new) |

No cross-domain SQL in the admin page — matches the `PO_Admin`/`PO_Service` precedent exactly.

---

# PART H — Schema/Migration Design

**`DB_VERSION` moves `10 → 11`.**

## 1. `wc_io_suppliers` — new column

```sql
ALTER TABLE {prefix}wc_io_suppliers
  ADD COLUMN merged_into_supplier_id bigint(20) unsigned NULL DEFAULT NULL AFTER status,
  ADD KEY merged_into_supplier_id (merged_into_supplier_id);
```
No FK constraint (repo convention: none exist anywhere).

## 2. New table `wc_io_supplier_merges` (append-only, mirrors `wc_io_po_events`)

```sql
CREATE TABLE {prefix}wc_io_supplier_merges (
  id                              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_supplier_id              bigint(20) unsigned NOT NULL,
  source_supplier_name_snapshot   varchar(190) NOT NULL DEFAULT '',
  target_supplier_id              bigint(20) unsigned NOT NULL,
  target_supplier_name_snapshot   varchar(190) NOT NULL DEFAULT '',
  purchase_orders_reassigned      int(10) unsigned NOT NULL DEFAULT 0,
  goods_receipts_reassigned       int(10) unsigned NOT NULL DEFAULT 0,
  performed_by                    bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at                      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY source_supplier_id (source_supplier_id),
  KEY target_supplier_id (target_supplier_id)
) {charset_collate};
```
Public mutation surface: `add( array $data ): int|WP_Error` only.

## Upgrade path

- `expected_schema_v11()`: `$base = self::expected_schema_v10(); $base['tables'][] = 'wc_io_supplier_merges'; $base['columns']['wc_io_suppliers'][] = 'merged_into_supplier_id'; return $base;`.
- **Dispatcher trap (explicit, mandatory check):** `expected_schema()`'s `version_compare()` cascade must add a new top branch `>= 11 → expected_schema_v11()`. WP-M17-1 must explicitly prove (via test, not eyeballing) that `expected_schema('11')` resolves to `expected_schema_v11()`.
- `assert_schema_shape()` must pass post-upgrade; no backfill needed (`merged_into_supplier_id` starts `NULL` for all existing rows).
- Downgrade: schema additions are left in place harmlessly on a code rollback (never dropped); already-completed merges are **not** reversed by a code rollback (Part N).

---

# PART I — Transaction, Concurrency, and Failure Design (Hardened)

## Repository primitive contracts (resolves Section 4 of the review)

Every new low-level write method distinguishes genuine SQL failure from a legitimate zero/positive result, reusing the exact `false === $result` → `WP_Error` convention already established by `Purchase_Orders::update_fields()`:

```php
// WC_Inventory_Overview_Purchase_Orders
public static function reassign_supplier_bulk( int $from_id, int $to_id ) { // int|WP_Error
    global $wpdb;
    $table  = self::table_name();
    $result = $wpdb->query(
        $wpdb->prepare( "UPDATE {$table} SET supplier_id = %d WHERE supplier_id = %d", $to_id, $from_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input, interpolated the same way every other repository method in this codebase interpolates its own table_name().
    );
    if ( false === $result ) {
        return new WP_Error( 'wc_io_po_reassign_supplier_failed', 'Failed to reassign purchase orders to new supplier', array( 'db_error' => $wpdb->last_error ) );
    }
    return (int) $result; // 0 is a valid, successful "nothing to reassign" result -- never coerced to a boolean.
}
```
`WC_Inventory_Overview_Goods_Receipts::reassign_supplier_bulk()` is identical in shape, targeting `wc_io_goods_receipts`, error code `wc_io_gr_reassign_supplier_failed`.

```php
// WC_Inventory_Overview_Suppliers
public static function mark_merged( int $source_id, int $target_id ) { // true|WP_Error
    global $wpdb;
    $result = $wpdb->update(
        self::table_name(),
        array(
            'status'                  => self::STATUS_ARCHIVED,
            'merged_into_supplier_id' => $target_id,
            'updated_at'              => current_time( 'mysql', true ),
        ),
        array( 'id' => $source_id ),
        array( '%s', '%d', '%s' ),
        array( '%d' )
    );
    if ( false === $result ) {
        return new WP_Error( 'wc_io_supplier_mark_merged_failed', 'Failed to mark supplier as merged', array( 'db_error' => $wpdb->last_error ) );
    }
    if ( 0 === $result ) {
        // The source row was already locked (FOR UPDATE) and validated to exist moments
        // earlier in this same transaction (BR-M17-3) -- zero rows affected here is a
        // structurally-unexpected anomaly, not a normal outcome. Treat as a hard failure.
        return new WP_Error( 'wc_io_supplier_mark_merged_unexpected_zero_rows', 'Source supplier row was not updated as expected during merge' );
    }
    return true;
}
```

`WC_Inventory_Overview_Suppliers::get_for_update( int $id )` mirrors `Purchase_Orders::get_for_update()` exactly: `SELECT * FROM {suppliers} WHERE id = %d FOR UPDATE`, `WP_Error` if not found, and it is only ever valid to call inside an already-active `DB_Transaction`.

`WC_Inventory_Overview_Suppliers::reactivate( int $id ): bool` — hardened (resolves Section 1 of the review): after the existing `$existing = self::get( $id ); if ( is_wp_error( $existing ) ) return false;` and the existing already-active short-circuit, add:
```php
if ( ! empty( $existing['merged_into_supplier_id'] ) ) {
    return false; // BR-M17-15: a merged supplier is permanently dissolved and may never be reactivated.
}
```
This is a non-breaking, in-place extension of the method's existing bool contract (`false` already means "did not activate," e.g. for not-found).

`WC_Inventory_Overview_Supplier_Merges::add( array $data ): int|WP_Error` — validates required keys, inserts, returns new ID or `WP_Error` on `false === $wpdb->insert(...)`.

## Exact `merge()` sequence (exception-safe, resolves Sections 2/3/5 of the review)

```php
public static function merge( int $source_supplier_id, int $target_supplier_id, int $performed_by, string $confirmation ) {
    global $wpdb;

    // Cheap, pre-transaction, retryable checks (BR-M17-1/2) -- no lock needed.
    if ( $source_supplier_id === $target_supplier_id ) {
        return new WP_Error( 'wc_io_supplier_merge_same_supplier', __( 'A supplier cannot be merged into itself.', 'wc-inventory-overview' ) );
    }
    if ( is_wp_error( WC_Inventory_Overview_Suppliers::get( $source_supplier_id ) ) ) {
        return new WP_Error( 'wc_io_supplier_merge_source_not_found', __( 'Source supplier not found.', 'wc-inventory-overview' ) );
    }
    if ( is_wp_error( WC_Inventory_Overview_Suppliers::get( $target_supplier_id ) ) ) {
        return new WP_Error( 'wc_io_supplier_merge_target_not_found', __( 'Target supplier not found.', 'wc-inventory-overview' ) );
    }

    $txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
    if ( ! $txn->begin() ) {
        return new WP_Error( 'wc_io_supplier_merge_txn_failed', __( 'Failed to begin merge transaction.', 'wc-inventory-overview' ) );
    }

    try {
        // Fixed lock order (lower supplier id first) prevents deadlock against a
        // concurrent reverse-direction merge attempt on the same two suppliers.
        $first_id  = min( $source_supplier_id, $target_supplier_id );
        $second_id = max( $source_supplier_id, $target_supplier_id );

        $first = WC_Inventory_Overview_Suppliers::get_for_update( $first_id );
        if ( is_wp_error( $first ) ) {
            $txn->rollback();
            return $first;
        }
        $second = WC_Inventory_Overview_Suppliers::get_for_update( $second_id );
        if ( is_wp_error( $second ) ) {
            $txn->rollback();
            return $second;
        }
        $source = ( (int) $first['id'] === $source_supplier_id ) ? $first : $second;
        $target = ( (int) $first['id'] === $target_supplier_id ) ? $first : $second;

        // BR-M17-16: server-side confirmation, checked against the freshly locked row.
        if ( $confirmation !== $source['name'] ) {
            $txn->rollback();
            return new WP_Error( 'wc_io_supplier_merge_confirmation_mismatch', __( 'Confirmation text did not match the source supplier name exactly.', 'wc-inventory-overview' ) );
        }

        // BR-M17-3/4, re-validated against the locked rows (closes the TOCTOU gap
        // between the pre-transaction existence check above and this point).
        if ( ! empty( $source['merged_into_supplier_id'] ) ) {
            $txn->rollback();
            $existing_target = WC_Inventory_Overview_Suppliers::get( (int) $source['merged_into_supplier_id'] );
            $target_name     = is_wp_error( $existing_target ) ? '' : $existing_target['name'];
            return new WP_Error( 'wc_io_supplier_merge_source_already_merged', sprintf( __( 'This supplier was already merged into %s.', 'wc-inventory-overview' ), $target_name ) );
        }
        if ( WC_Inventory_Overview_Suppliers::STATUS_ACTIVE !== $target['status'] ) {
            $txn->rollback();
            return new WP_Error( 'wc_io_supplier_merge_target_not_active', __( 'Target supplier must be active.', 'wc-inventory-overview' ) );
        }
        if ( ! empty( $target['merged_into_supplier_id'] ) ) {
            $txn->rollback();
            return new WP_Error( 'wc_io_supplier_merge_target_already_merged', __( 'Target supplier has itself already been merged into another supplier.', 'wc-inventory-overview' ) );
        }

        $po_count = WC_Inventory_Overview_Purchase_Orders::reassign_supplier_bulk( $source_supplier_id, $target_supplier_id );
        if ( is_wp_error( $po_count ) ) {
            $txn->rollback();
            return $po_count;
        }
        self::maybe_inject_test_failure( 'po_reassign' );

        $gr_count = WC_Inventory_Overview_Goods_Receipts::reassign_supplier_bulk( $source_supplier_id, $target_supplier_id );
        if ( is_wp_error( $gr_count ) ) {
            $txn->rollback();
            return $gr_count;
        }
        self::maybe_inject_test_failure( 'gr_reassign' );

        $audit_id = WC_Inventory_Overview_Supplier_Merges::add(
            array(
                'source_supplier_id'            => $source_supplier_id,
                'source_supplier_name_snapshot' => $source['name'],
                'target_supplier_id'            => $target_supplier_id,
                'target_supplier_name_snapshot' => $target['name'],
                'purchase_orders_reassigned'    => $po_count,
                'goods_receipts_reassigned'     => $gr_count,
                'performed_by'                  => $performed_by,
            )
        );
        if ( is_wp_error( $audit_id ) ) {
            $txn->rollback();
            return $audit_id;
        }
        self::maybe_inject_test_failure( 'audit_insert' );

        $marked = WC_Inventory_Overview_Suppliers::mark_merged( $source_supplier_id, $target_supplier_id );
        if ( is_wp_error( $marked ) ) {
            $txn->rollback();
            return $marked;
        }

        if ( ! $txn->commit() ) {
            $txn->rollback();
            return new WP_Error( 'wc_io_supplier_merge_txn_failed', __( 'Failed to commit merge transaction.', 'wc-inventory-overview' ) );
        }
    } catch ( \Throwable $e ) {
        if ( $txn->is_active() ) {
            $txn->rollback();
        }
        return new WP_Error(
            'wc_io_supplier_merge_failed',
            __( 'Supplier merge failed unexpectedly and was rolled back.', 'wc-inventory-overview' ),
            array( 'internal' => $e->getMessage() )
        );
    }

    return array(
        'source_supplier_id'         => $source_supplier_id,
        'target_supplier_id'         => $target_supplier_id,
        'purchase_orders_reassigned' => $po_count,
        'goods_receipts_reassigned'  => $gr_count,
    );
}
```

No `do_action()` is fired anywhere in this method — resolves Section 5's requirement that public API impact remains genuinely NONE. The caller (the admin handler) is responsible for translating the success array or `WP_Error` code into a redirect/notice.

## Internal test-only failure-injection seam (resolves Section 5, exact mechanism)

No WordPress hook of any kind. A private static property on the service class, gated by a constant that exists **only** inside `tests/bootstrap.php` (added there, not anywhere in `includes/`), so the setter is structurally a no-op in every production request:

```php
// tests/bootstrap.php -- added once, alongside the existing WP_TESTS_DIR/WP_TESTS_MULTISITE definitions.
if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
    define( 'WC_IO_PHPUNIT_RUNNING', true );
}
```
```php
// includes/class-wc-inventory-overview-supplier-merge-service.php
class WC_Inventory_Overview_Supplier_Merge_Service {

    /**
     * Test-only failure-injection seam. The setter is a hard no-op unless
     * WC_IO_PHPUNIT_RUNNING is defined (tests/bootstrap.php only) -- this is
     * not a WordPress hook and is not part of this plugin's extension surface.
     *
     * @var string|null
     */
    private static $test_fail_after_step = null;

    /**
     * Test-only. Arms failure injection after the named step
     * ('po_reassign'|'gr_reassign'|'audit_insert'), or disarms it (null).
     * Callers MUST reset to null in tearDown().
     */
    public static function set_test_fail_after_step( ?string $step ): void {
        if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
            return;
        }
        self::$test_fail_after_step = $step;
    }

    private static function maybe_inject_test_failure( string $step ): void {
        if ( defined( 'WC_IO_PHPUNIT_RUNNING' ) && self::$test_fail_after_step === $step ) {
            throw new RuntimeException( 'wc_io_test_injected_failure:' . $step );
        }
    }
}
```
Three injection points, matching the three post-lock mutation steps: `po_reassign`, `gr_reassign`, `audit_insert`. Each thrown `RuntimeException` is caught by the `try/catch (\Throwable $e)` wrapper above, proving BR-M17-9's exception-safety at each of the three points, not just at the explicit `WP_Error` paths.

## Concurrency scenarios, resolved

| Scenario | Resolution |
|---|---|
| A **new** PO/receipt draft is created choosing source as its supplier, concurrently with an in-flight merge | **Closed by WP-M17-4** (BR-M17-18): draft creation now locks (`FOR UPDATE`) and re-validates the chosen supplier's row before inserting, inside its own transaction — this serializes against merge's own lock on the same row. See "Resolving the concurrent-create race" below for why relying on InnoDB's implicit index locking alone would **not** have been sufficient. |
| Receiving posts against a source PO mid-merge | No conflict — receiving never touches `supplier_id` |
| A second merge targets source, or uses source as target, concurrently | Blocks on the row lock until the first transaction commits, then the re-validation inside the lock deterministically fails it (BR-M17-3/4) |
| Source or target edited (name/currency/etc.) concurrently | Non-issue by construction — merge's own writes touch only `status`/`merged_into_supplier_id`/`updated_at` on the source row and nothing on the target row (BR-M17-5) |

### Resolving the concurrent-create race (Section 6 of the review)

**Investigated:** this environment's database is MariaDB `11.8.8` (`tests/docker/docker-compose.phpunit.yml`), with no isolation-level override anywhere in the compose file or scripts — confirmed via search of `tests/docker/`. **InnoDB's/MariaDB's default transaction isolation is `REPEATABLE READ`**, not `READ COMMITTED`.

Under `REPEATABLE READ`, an `UPDATE ... WHERE supplier_id = %d` against the indexed `supplier_id` column *does* take next-key (gap) locks that can block a concurrent `INSERT` attempting to add a new row with the same indexed value into that locked range — so a naive reading might conclude the bulk UPDATE alone already serializes against a concurrent create. **This is not sufficient, and Option A (rely on implicit InnoDB locking alone) was rejected on closer analysis**: even if the gap lock delays the competing `INSERT` statement until the merge transaction commits, it does **not** change what value that `INSERT` writes — the application layer (`PO_Service::create_draft()`) had already decided, in PHP, to write `supplier_id = source_id` before that SQL statement was even issued. Delaying the statement's execution does not re-run that PHP-level decision. The `INSERT` would still proceed, once unblocked, to create a new PO row pointing at the now-dissolved source. Gap-locking prevents phantom *reads* for repeatable-read consistency; it does not retroactively revise an already-formed `INSERT`'s values.

**Therefore Option B — extend the authoritative creation paths to lock and re-validate the supplier row before persisting a supplier reference — is the correct and only complete fix**, and it is small and proportionate: it reuses the exact same `Suppliers::get_for_update()` primitive merge itself needs, applied at exactly the two places a *brand-new* supplier_id value is accepted from operator input:

- `WC_Inventory_Overview_PO_Service::create_draft()`
- `WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post()`

Both currently call `WC_Inventory_Overview_Suppliers::get( $supplier_id )` (an unlocked read) before opening their own transaction. The fix moves that resolution *inside* the transaction and replaces it with `get_for_update()`, additionally validating `status === STATUS_ACTIVE` and `merged_into_supplier_id === null` (the exact same eligibility test as BR-M17-4's target check) before proceeding to insert. This makes creation and merge serialize on the *same supplier row lock*, which is airtight: whichever transaction acquires the lock first completes fully (including its own commit) before the other proceeds, and the second transaction then re-reads the current, post-commit state and correctly rejects an ineligible supplier.

**No other creation path needs this change.** `PO_Service::duplicate()` copies an existing, already-once-validated PO's `supplier_id` forward rather than accepting a freshly operator-chosen value — a duplicated PO referencing a since-archived supplier is a pre-existing, non-merge-specific edge case (already true today for any manually archived supplier) and is out of scope. `Goods_Receipt_Service::post()` needs no change: any receipt that exists at the moment a merge's bulk UPDATE runs is swept up by that UPDATE regardless of its status (draft or otherwise), so by the time `post()` is ever called, a receipt cannot still be pointing at a dissolved source unless it was newly created in the exact window WP-M17-4 now closes.

**This materially but narrowly enlarges M17's scope** by two small, precisely-bounded changes to existing creation methods — it does not enlarge it into a parallel initiative. It is captured as its own dedicated work package (**WP-M17-4**, Part M) exactly as required, not folded into WP-M17-3.

---

# PART J — Admin UX and Security Design (Hardened)

## Entry point

New "Merge into another supplier" section on the Supplier detail screen (`Purchasing_Page::render_supplier_detail()`):
- **Eligible source** (active or archived, `merged_into_supplier_id IS NULL`): shows the merge form.
- **Already-merged source** (`merged_into_supplier_id IS NOT NULL`): shows a static notice, *"This supplier was merged into {target name} on {date}"*, linking to the target — no form, and (Section 1) **no enabled Reactivate control anywhere on this screen or in the list table for this row.**

## Reactivation prevention (resolves Section 1)

- `Suppliers::reactivate()` hardened as specified in Part I.
- `Purchasing_Page::handle_supplier_reactivate()` **must be changed to check the return value** (currently discarded entirely — a pre-existing gap this milestone must close, since leaving it unchecked would mean the admin-post handler shows "Supplier reactivated." even when the repository correctly refused): on `false`, redirect with an error notice (*"This supplier could not be reactivated."*); on `true`, the existing success-notice path is unchanged.
- Suppliers list-table row actions (`class-wc-inventory-overview-suppliers-list-table.php`): the existing `else` branch (rendered whenever `status !== 'active'`) currently always shows Reactivate. It must be split: if `empty( $item['merged_into_supplier_id'] )`, render Reactivate exactly as today; otherwise, render a plain, non-actionable text label, *"Merged into {target name}"* (no link), instead of a row action. Resolving `{target name}` for potentially several merged rows on one page must use a single bulk lookup (`Suppliers::get_names_bulk( array $ids ): array`, new small helper returning an `id => name` map in one query) rather than a per-row `get()` call — consistent with this list table's own M12 "supplier list performance" precedent of avoiding N+1 queries.
- Supplier autocomplete / New PO or Goods Receipt supplier selection: **no code change needed** — `ajax_search_suppliers()` already filters `status => 'active'`, and a merged supplier is always also archived (BR-M17-10 sets both together), so it is already structurally excluded. Verified, not assumed.

## Target selection

New dedicated AJAX endpoint `wp_ajax_wc_io_search_merge_targets` (registered in `Purchasing_Page::init()`, alongside every other `add_action('wp_ajax_...')` registration in that method): filters `status = 'active'` AND `merged_into_supplier_id IS NULL` AND excludes the source supplier's own ID (passed as a request parameter). Client: `assets/supplier-merge.js` (exact filename — see Part L §Frozen conventions), a dedicated Select2 instance mirroring `assets/supplier-picker.js`'s existing registration (same dependency array, same in-footer flag, same version constant) with a new handle `wc-io-supplier-merge`, registered in `class-wc-inventory-overview-plugin.php` immediately alongside the existing `wc-io-supplier-picker` registration (~line 1600–1610).

## Server-enforced confirmation (resolves Section 2)

POST field `supplier_merge_confirmation`. The admin handler reads it with this codebase's standard convention, `sanitize_text_field( wp_unslash( $_POST['supplier_merge_confirmation'] ?? '' ) )`, and passes it as `merge()`'s fourth argument — **the JS typed-name gate that disables the submit button until the input matches is UX only; BR-M17-16's server-side check inside the locked transaction is authoritative and is proven by a direct crafted-POST test that bypasses the JS entirely (Part L).**

## Copy and notices

Warning text states explicitly: (1) this cannot be undone, (2) all purchase orders and goods receipts move to the target, (3) the source will be archived and can never be reactivated, (4) historical documents keep showing the original supplier name where they already did. Success notice: *"{Source} was merged into {Target}. {N} purchase orders and {M} goods receipts were reassigned."* — redirects to the target's detail page.

### Error code → notice copy mapping (resolves Section 10J, frozen)

| `WP_Error` code | Notice copy |
|---|---|
| `wc_io_supplier_merge_same_supplier` | "A supplier cannot be merged into itself." |
| `wc_io_supplier_merge_source_not_found` | "Source supplier not found." |
| `wc_io_supplier_merge_target_not_found` | "Target supplier not found." |
| `wc_io_supplier_merge_source_already_merged` | "This supplier was already merged into {existing target}." |
| `wc_io_supplier_merge_target_not_active` | "Target supplier must be active." |
| `wc_io_supplier_merge_target_already_merged` | "Target supplier has itself already been merged into another supplier." |
| `wc_io_supplier_merge_confirmation_mismatch` | "Confirmation text did not match the source supplier name exactly." |
| `wc_io_supplier_merge_txn_failed` | "Could not complete the merge (transaction error). No changes were made." |
| `wc_io_supplier_merge_failed` (generic, exception path) | "Supplier merge failed unexpectedly and was rolled back. No changes were made." |
| (token consume failure, before service is even called) | "This merge request has already been used or has expired. Please try again." |

## Security contract

- Capability: `MERGE_SUPPLIER` (new), checked first via `Purchasing_Caps::current_user_can()`.
- Nonce: action `wc_io_supplier_merge_{$source_id}`, field `wc_io_supplier_merge_nonce` — matches `wc_io_supplier_archive_{$id}`'s convention.
- Token: `WC_Inventory_Overview_PO_Request_Token::issue( 'supplier_merge' )` / `::consume( $token, 'supplier_merge' )`.
- Route: `admin-post.php?action=wc_io_supplier_merge`, POST only, registered in `Purchasing_Page::init()` alongside the existing `admin_post_wc_io_supplier_reactivate`/`admin_post_wc_io_supplier_archive` registrations.
- Order: capability → nonce → token consume → sanitize inputs (`absint()` on both IDs, `sanitize_text_field(wp_unslash())` on confirmation) → service call → PRG redirect.

---

# PART K — Query/Performance Design (Corrected)

Two distinct, separately-defined metrics (resolves Section 7 — the prior draft's flat "6 queries" claim is withdrawn):

**A. Mutation phase** — the fixed, itemizable set of domain-mutating statements once both supplier rows are locked and revalidated. Exactly **4 statements**, constant regardless of scale: (1) bulk `UPDATE wc_io_purchase_orders`, (2) bulk `UPDATE wc_io_goods_receipts`, (3) `INSERT` into `wc_io_supplier_merges`, (4) `UPDATE wc_io_suppliers` (`mark_merged`). Both bulk updates use the existing `KEY supplier_id` index on their respective tables — no new index required.

**B. Complete `merge()` call** — measured empirically, not assumed, using this codebase's own established technique (`tests/integration/supplier-spend/test-supplier-spend-performance.php:queries_for_get_summary()`): `$before = $wpdb->num_queries; ... merge() ...; $count = $wpdb->num_queries - $before;`. This total additionally includes the two pre-transaction existence reads, the transaction's `BEGIN`/`COMMIT` statements, and the two `SELECT ... FOR UPDATE` locks — none of which scale with the number of related POs/receipts. **The invariant (INV-M17-5) is that this measured total is constant across fixture sizes, not that it equals any specific pre-asserted number.** WP-M17-8 (Part M) must report the actual measured count at 500/2,000/5,000 related POs and confirm all three are equal — it must not simply restate a guessed figure.

---

# PART L — Test Strategy (Hardened, all remaining choices frozen)

| File | Type | Class | Covers |
|---|---|---|---|
| `tests/integration/install/test-schema-v11-upgrade.php` | Integration | `Test_WC_IO_Schema_V11_Upgrade` | Fresh install has column+table; upgrade from simulated v10 adds them losslessly; idempotent re-run; `assert_schema_shape()` green; dispatcher resolves `'11'` to `expected_schema_v11()` specifically (not a silent v10 fallthrough) |
| `tests/unit/supplier-merge/test-supplier-merge-primitives.php` | Unit | `Test_WC_IO_Supplier_Merge_Primitives` | `Suppliers::get_for_update()`, hardened `reactivate()` (merged-supplier rejection), `mark_merged()` (success, `false` DB failure, unexpected-zero-rows), `Purchase_Orders::reassign_supplier_bulk()` / `Goods_Receipts::reassign_supplier_bulk()` (count semantics + DB-failure `WP_Error`), `Supplier_Merges::add()`, `Suppliers::get_names_bulk()` |
| `tests/unit/supplier-merge/test-supplier-merge-service.php` | Unit | `Test_WC_IO_Supplier_Merge_Service` | Every BR-M17-1..18; full threat matrix below; exception-path rollback via the test-only seam; server-side confirmation mismatch (including a case proving a request with **no** JS involved, i.e. a directly crafted `merge()` call with a wrong `$confirmation`, is rejected identically) |
| `tests/integration/supplier-merge/test-supplier-merge-concurrency.php` | Integration | `Test_WC_IO_Supplier_Merge_Concurrency` | WP-M17-4: creating a PO/GR draft against a supplier that a merge has already dissolved is rejected; the row-lock ordering does not deadlock two opposite-direction concurrent merge attempts (tested via nested-transaction/SAVEPOINT simulation consistent with what PHPUnit's single-connection test harness can actually exercise — see WP-M17-4 item 19 for the explicit boundary of what is provable under this harness vs. reasoned-about only) |
| `tests/integration/supplier-merge/test-supplier-merge-derived-stats.php` | Integration | `Test_WC_IO_Supplier_Merge_Derived_Stats` | INV-M17-6: Lead Time, Order History, Spend Summary, Inventory Position drilldown all reflect the merge with zero code changes |
| `tests/integration/supplier-merge/test-supplier-merge-admin.php` | Integration | `Test_WC_IO_Supplier_Merge_Admin` | HTTP-level: capability-denied, bad-nonce, double-submit-token, happy-path redirect + notice, every error-code → notice mapping, **and a direct crafted-POST test with a correct nonce/token but a wrong `supplier_merge_confirmation` value, proving server-side rejection independent of any JS** |
| `tests/integration/supplier-merge/test-supplier-merge-admin-render.php` | Integration | `Test_WC_IO_Supplier_Merge_Admin_Render` | Automated UI-rendering assertions (resolves Section 11) — see list below |
| `tests/unit/supplier-merge/test-supplier-merge-architecture.php` | Unit (guard) | `Test_WC_IO_Supplier_Merge_Architecture` | INV-M17-2 (sole mutator), INV-M17-7 (no SQL in admin page), INV-M17-10 (no new `do_action`/`apply_filters` introduced by this feature — grep-based guard over the new files) |
| `tests/unit/supplier-merge/test-supplier-merge-performance.php` | Unit | `Test_WC_IO_Supplier_Merge_Performance` | INV-M17-5 (measured-constant query count at 500/2000/5000 POs, via `$wpdb->num_queries` delta) + failure-injection rollback proof at all three seam points |

**Automated UI-rendering assertions (`Test_WC_IO_Supplier_Merge_Admin_Render`), exact list (resolves Section 11):** output-buffered assertions on `Purchasing_Page::render_supplier_detail()`'s HTML (`ob_start()`/`ob_get_clean()` around the render call, matching this codebase's general admin-page-rendering test approach) proving: (1) an active eligible source renders the merge section; (2) an archived eligible source renders the merge section; (3) an already-merged source renders the static "merged into X" notice and **no** form; (4) an already-merged source's row **has no enabled Reactivate control** (neither on the detail screen nor — via a companion assertion against `Suppliers_List_Table::column_name()`'s row actions — in the list table); (5) the target picker's rendered markup references the new `wc_io_search_merge_targets` AJAX action and a `data-exclude-supplier-id` (or equivalent rendered attribute) matching the source's own ID; (6) the typed-confirmation field is present; (7) the nonce field is present with the correct action name; (8) the request-token hidden field is present; (9) the warning copy contains the word "cannot be undone" (or the plan's exact irreversibility phrase); (10) the submit control is present and (per the JS behavior) marked to start disabled.

## Threat/UI matrix

| Case | Expected result |
|---|---|
| Active source → active target, correct confirmation | Success |
| `source == target` | `wc_io_supplier_merge_same_supplier` |
| Source missing | `wc_io_supplier_merge_source_not_found` |
| Target missing | `wc_io_supplier_merge_target_not_found` |
| Archived source → active target | Success (primary real-world case) |
| Any source → archived target | `wc_io_supplier_merge_target_not_active` |
| Source already merged | `wc_io_supplier_merge_source_already_merged` |
| Target already merged | `wc_io_supplier_merge_target_already_merged` |
| Confirmation text wrong/blank/case-mismatched | `wc_io_supplier_merge_confirmation_mismatch` (case-mismatch is a rejection, not a match — BR-M17-16 forbids case-folding) |
| Source with zero POs/receipts | Success, counts = 0 |
| Source with many POs (mixed statuses incl. terminal) | Success, all reassigned, snapshots untouched |
| Source/target different currencies | Success — currency is target-metadata-only, never blocked or blended |
| Insufficient capability | 403 |
| Bad nonce | Rejected before service is called |
| Double submit (same token twice) | Second attempt fails token consume |
| Repeat merge after success (stale page) | `wc_io_supplier_merge_source_already_merged` |
| Injected failure after PO reassign / GR reassign / audit insert (3 cases) | Full rollback — zero rows changed anywhere, including the source's `status`/`merged_into_supplier_id` |
| Concurrent new PO/GR draft creation against source, racing a merge | Creation blocked/rejected once merge commits (WP-M17-4) |
| Attempt to reactivate a merged supplier (repository call, admin handler, and list-table row action all tested independently) | Rejected at all three layers |

## Regression matrix

Unchanged from the prior pass: Suppliers list/detail, autocomplete, New PO supplier selection, Purchase Orders, Printable PO, Goods Receipts, Inventory Position, Expected Delivery, Observed Lead Time, On-Time Rate, Expected-date suggestion/provenance, Supplier Order History, Supplier Spend Summary, M16 grace-days setting — all require only their existing suites re-run unmodified, **plus** the newly-touched `PO_Service::create_draft()` and `Goods_Receipt_Service::create_draft_from_post()` paths (WP-M17-4) now require their own existing unit/integration suites re-run to confirm the added supplier-lock step introduces no regression for the ordinary (non-merge, non-racing) happy path.

## CI filter (frozen, resolves Section 10H)

Append `|Test_WC_IO_Supplier_Merge_` to the single existing regex string at `tests/docker/run-phpunit.sh:154` (appended at the end, matching the observed per-milestone append pattern). Verify via `--list-tests` that every class in the table above is discovered.

## Manual/browser acceptance fixture policy (resolves Section 12)

Not "create disposable fixtures and delete them afterward" — an exact, safe procedure, reusing this repo's own existing pre-deploy convention (`docs/deployment-checklist.md`: *"Database backup: `./wp db export`"*):

1. Before starting the acceptance pass, take a database export exactly as the deployment checklist already does for releases (`docker compose ... run --rm wpcli wp db export <path>`, per the host CLAUDE.md's documented WP-CLI invocation for this stack).
2. Create explicitly-named, easily-identifiable disposable fixtures (e.g. supplier names prefixed `M17 Acceptance —`), and record every created ID (suppliers, POs, receipts) as the pass proceeds.
3. Execute the merge acceptance steps from the threat/UI matrix above against these fixtures only — never against real operational suppliers.
4. Verify the visible admin UI and, where practical, the underlying database rows directly.
5. Clean up by deleting, by recorded ID, only the rows this acceptance pass itself created — across `wc_io_suppliers`, `wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_goods_receipts`, and `wc_io_supplier_merges`. This is a QA-fixture cleanup of rows the operator just created for testing, not an "undo" of a real merge, and does not conflict with the production-facing append-only guarantee for `wc_io_supplier_merges` (BR-M17-11), which concerns real merges, not test fixtures deleted immediately after verification.
6. If step 5 cannot be completed with full confidence (e.g. an unexpected cascading effect was observed), fall back to restoring the step-1 database export rather than attempting further manual cleanup.

---

# PART M — Haiku Implementation Work Packages (Renumbered, one new WP)

Checkpoint rule unchanged: after each WP, run its stated validation, inspect the diff, confirm no scope leaked past this WP's stated concerns, commit, then proceed. If validation exposes a semantic gap this plan's BR/INV rules don't resolve — **stop and report, do not improvise.**

## WP-M17-0 — Preconditions, branch, plan materialization

1. **Objective:** verify baseline, create the branch, land the immutable plan as the first commit.
2–9. As before (no changes).
10. **Algorithm:** `git fetch origin`; confirm `origin/main` is still `2c9e280` (STOP on drift); `git checkout -b feature/m17-supplier-merge origin/main`; write the plan file; commit.
11–19. Unchanged from the prior pass.

## WP-M17-1 — Schema/migration foundation

1. **Objective:** add `merged_into_supplier_id` to `wc_io_suppliers`, create `wc_io_supplier_merges`, bump `DB_VERSION` to `11`, add `MERGE_SUPPLIER` capability, add the `WC_IO_PHPUNIT_RUNNING` test-bootstrap constant.
2. **Existing files to modify:** `includes/class-wc-inventory-overview-install.php` (DDL edits, `DB_VERSION`, `expected_schema_v11()`, dispatcher branch); `includes/class-wc-inventory-overview-purchasing-caps.php` (`const MERGE_SUPPLIER = 'merge_supplier';`); `tests/bootstrap.php` (add the `WC_IO_PHPUNIT_RUNNING` constant, near the existing `WP_TESTS_*` definitions).
3. **New files:** none.
4–9. As specified in Part H.
15. **Test files:** `tests/integration/install/test-schema-v11-upgrade.php` → `Test_WC_IO_Schema_V11_Upgrade extends WP_UnitTestCase` (exact file/class name, frozen — see Part B/L for naming rationale).
16. **Test cases:** fresh install has column+table; simulated upgrade from v10 adds them losslessly; idempotent re-run; `assert_schema_shape()` green; **explicit proof `expected_schema('11')` resolves to `expected_schema_v11()`**, not a v10 fallthrough (e.g. assert the returned array actually contains `wc_io_supplier_merges` in `['tables']`).
17. **Validation:** `--filter='Test_WC_IO_Schema_V11_Upgrade'` green.
18. **Completion checkpoint:** schema assertion green on fresh install and upgrade; commit.
19. **Stop conditions:** `dbDelta()` cannot express the ALTER as planned; dispatcher structure differs from Part H's assumption.

## WP-M17-2 — Repository primitives

1. **Objective:** add the low-level read/write methods, with explicit SQL-failure-vs-zero-rows contracts (resolves Section 4).
2. **Existing files to modify:** `includes/class-wc-inventory-overview-suppliers.php` (`get_for_update()`, `mark_merged()`, hardened `reactivate()`, `get_names_bulk( array $ids ): array`), `includes/class-wc-inventory-overview-purchase-orders.php` (`reassign_supplier_bulk(): int|WP_Error`), `includes/class-wc-inventory-overview-goods-receipts.php` (`reassign_supplier_bulk(): int|WP_Error`).
3. **New files:** `includes/class-wc-inventory-overview-supplier-merges.php` (`table_name()`, `add( array $data ): int|WP_Error`, `get_for_source( int $source_id ): ?array`).
4. **Classes involved:** `Suppliers`, `Purchase_Orders`, `Goods_Receipts`, `Supplier_Merges` (new).
5–9. Exact signatures/algorithm as given in Part I.
10. **Business rules applied:** none directly — pure mechanism; BR-M17-* enforcement belongs to WP-M17-3.
11. **Prohibited alternatives:** no per-row loop inside either `reassign_supplier_bulk()`; no touching `supplier_name_snapshot`; no casting a `false` `$wpdb` result to `0` or to `true`.
12. **Error behavior:** exactly as Part I's code (SQL failure → `WP_Error` carrying `$wpdb->last_error`; zero-affected-rows is a valid *success* for the bulk-reassign methods, but a *hard failure* for `mark_merged()` given its precondition of an already-locked, already-validated row).
13. **Transaction behavior:** none of these methods open their own transaction — callers must already have one active (documented in each method's docblock).
14. **Query behavior:** each method is exactly one SQL statement (`get_names_bulk()` is one `IN (...)` query for however many IDs are passed, not one query per ID).
15. **Test files:** `tests/unit/supplier-merge/test-supplier-merge-primitives.php` → `Test_WC_IO_Supplier_Merge_Primitives`.
16. **Test cases:** happy path + not-found for `get_for_update()`; `reactivate()` returns `false` for a merged supplier without changing its row; `mark_merged()` success, simulated `$wpdb->update()` failure (mockable via a `wpdb`-failure test pattern consistent with how this codebase already asserts `false === $result` paths elsewhere, or by targeting an impossible column/value that forces a real driver error), and the zero-rows anomaly path; `reassign_supplier_bulk()` correct counts for 0/1/N matching rows, `supplier_name_snapshot` byte-identical after; `Supplier_Merges::add()` round-trips all fields; `get_names_bulk()` returns correct id⇒name map for N ids in one query (assert via `$wpdb->num_queries` delta = 1).
17. **Validation:** `--filter='Test_WC_IO_Supplier_Merge_Primitives'` green.
18. **Completion checkpoint:** commit.
19. **Stop conditions:** none anticipated — mechanical given WP-M17-1's schema.

## WP-M17-3 — Merge domain/transaction service

1. **Objective:** implement the full orchestration, exception-safe, with server-enforced confirmation (resolves Sections 2/3/5/8/9).
2. **Existing files to modify:** none.
3. **New files:** `includes/class-wc-inventory-overview-supplier-merge-service.php`.
4. **Classes involved:** `WC_Inventory_Overview_Supplier_Merge_Service` (new).
5–6. **Method signature:** `public static function merge( int $source_supplier_id, int $target_supplier_id, int $performed_by, string $confirmation ): array|WP_Error`, plus the test-only `set_test_fail_after_step()`.
7–8. **Inputs/outputs:** as Part I.
9. **Algorithm:** the exact code given in Part I, verbatim, including the `try/catch (\Throwable $e)` wrapper.
10. **Business rules applied:** BR-M17-1 through BR-M17-18.
11. **Prohibited alternatives:** no `PO_Lifecycle`/receipt-editability routing; no per-record audit rows; no `DB_Transaction::run()` lambda form (manual `begin()`/`rollback()`/`commit()`, matching `PO_Service`'s style, for consistency and precise failure-injection placement); **no `do_action()` or `apply_filters()` anywhere in this file** (INV-M17-10); no case-folding or `normalized_name` comparison for the confirmation check.
12. **Error behavior:** every failure path returns a specific `WP_Error` code (Part J's mapping table); every `Throwable` is caught and converted to the single generic `wc_io_supplier_merge_failed` code.
13. **Transaction behavior:** exactly Part I's sequence — one `DB_Transaction`, fixed low-ID-first lock order, all writes inside it, `try/catch` guarantees rollback on both `WP_Error` and `Throwable` paths, transaction never left active.
14. **Query behavior:** Part K's two-metric contract.
15. **Test files:** `tests/unit/supplier-merge/test-supplier-merge-service.php`.
16. **Test cases:** full threat matrix (Part L, 20 cases) including the three failure-injection points via `set_test_fail_after_step()` and the direct-`merge()`-call confirmation-mismatch case (bypassing any HTTP/JS layer entirely).
17. **Validation:** `--filter='Test_WC_IO_Supplier_Merge_Service'` green; spot-check via a debug query that `supplier_name_snapshot` and `wc_io_inventory_movements` are byte-identical pre/post merge on a fixture with both POs and receipts.
18. **Completion checkpoint:** all threat-matrix cases pass; commit.
19. **Stop conditions:** a threat-matrix scenario exposes a semantic gap not covered by BR-M17-1..18 — stop and report, do not invent a new rule.

## WP-M17-4 — Concurrency hardening: supplier-row locking at creation time (NEW, resolves Section 6)

1. **Objective:** close the concurrent-create race identified in Part I — after a merge commits, no new PO or Goods Receipt draft can be successfully created referencing the now-dissolved source.
2. **Existing files to modify:** `includes/class-wc-inventory-overview-po-service.php` (`create_draft()` — replace the unlocked `WC_Inventory_Overview_Suppliers::get( $supplier_id )` call with a locked, transaction-scoped read+validation), `includes/class-wc-inventory-overview-goods-receipt-service.php` (`create_draft_from_post()` — same change).
3. **New files:** none.
4. **Classes involved:** `WC_Inventory_Overview_PO_Service`, `WC_Inventory_Overview_Goods_Receipt_Service`, consuming `Suppliers::get_for_update()` (WP-M17-2).
5–6. **Methods (behavioral change only, signatures unchanged):** `create_draft( array $header, array $lines = [] )` and `create_draft_from_post( array $src )`. Both must move their supplier resolution to occur *after* `$txn->begin()` (moving the transaction-open point earlier than it currently is in `PO_Service::create_draft()`, since today it opens *after* the unlocked supplier read — this ordering must be swapped), calling `Suppliers::get_for_update( $supplier_id )` instead of `Suppliers::get( $supplier_id )`, then validating `status === STATUS_ACTIVE` (this is a **new** creation-time check — today only *placement*, not *draft creation*, checks status) and `merged_into_supplier_id === null`, returning a `WP_Error` (`wc_io_po_supplier_inactive` / `wc_io_gr_supplier_inactive`) and rolling back if either fails.
7. **Inputs:** unchanged (`$header`/`$lines` and `$src` respectively) — this is an internal implementation change, not an API change.
8. **Outputs:** unchanged on the happy path; a new, narrowly-scoped rejection on the archived/merged-supplier path that previously would have silently succeeded (this is a **deliberate, in-scope tightening**, not a regression — a supplier that's inactive at creation time should not be assignable to a brand-new draft any more than it should be assignable to a placed PO).
9. **Algorithm:** lock supplier row first thing inside the transaction → validate active + not-merged → proceed with the rest of the existing creation logic unchanged.
10. **Business rules applied:** BR-M17-18.
11. **Prohibited alternatives:** do not add this check to `PO_Service::duplicate()` or `Goods_Receipt_Service::post()` (Part I explains precisely why neither needs it); do not rely on InnoDB gap-locking alone (Part I explains precisely why that would be incorrect).
12. **Error behavior:** `wc_io_po_supplier_inactive` / `wc_io_gr_supplier_inactive`, each a `WP_Error` returned before any row is inserted.
13. **Transaction behavior:** the supplier lock is the *first* statement inside the (now earlier-opened) transaction, before any PO/receipt row is inserted.
14. **Query behavior:** one additional `SELECT ... FOR UPDATE` per creation call versus today's unlocked `SELECT` — same query count, different locking semantics, no scaling concern.
15. **Test files:** `tests/integration/supplier-merge/test-supplier-merge-concurrency.php` → `Test_WC_IO_Supplier_Merge_Concurrency`; plus regression assertions added to the *existing* PO/GR creation test suites confirming the ordinary (active-supplier) happy path is unaffected.
16. **Test cases:** (a) draft PO creation against an already-merged supplier is rejected; (b) draft receipt creation against an already-merged supplier is rejected; (c) draft PO/receipt creation against an ordinary active supplier is unaffected (regression); (d) draft creation against an ordinary archived-but-not-merged supplier — decide and assert the correct pre-existing expectation (today's behavior for a plain archived supplier at draft-creation time must be preserved exactly as-is outside the new merged-specific check, i.e. this WP must not accidentally start blocking plain-archived-supplier draft creation if that was previously allowed — verify current behavior first via a regression test, then assert it is unchanged).
17. **Validation:** `--filter='Test_WC_IO_Supplier_Merge_Concurrency'` green, plus the existing PO-creation and Goods-Receipt-creation suites re-run green with zero new failures.
18. **Completion checkpoint:** race closed and proven, zero regression in ordinary creation flows; commit.
19. **Stop conditions:** closing this race turns out to require locking behavior `DB_Transaction`/MariaDB cannot actually provide as reasoned in Part I (e.g. if empirical testing shows the lock does not serialize as expected) — stop and report exactly what was and wasn't provable under the PHPUnit/MariaDB test harness, do not claim untested coverage; if item 16(d)'s regression check reveals plain-archived-supplier draft creation was *never* actually blocked before (which Part B's discovery already indicates is the case), do not silently start blocking it now beyond what BR-M17-18 requires — the new check is `merged_into_supplier_id`-aware specifically, not a blanket "reject any non-active supplier at draft time" change, unless this WP's own regression testing proves that distinction is unsafe to preserve.

## WP-M17-5 — Derived-statistics reconciliation proof

*(Renumbered from WP-M17-4; content unchanged from the prior pass.)*

1. **Objective:** empirically prove INV-M17-6.
2–14. n/a (test-only).
15. **New files:** `tests/integration/supplier-merge/test-supplier-merge-derived-stats.php` → `Test_WC_IO_Supplier_Merge_Derived_Stats`.
16. **Test cases:** merge a source with received POs/receipts (contributing lead-time/on-time/spend/history data) into a target with its own separate history; assert target's stats now include source's former data, source's stats are now empty, and the Inventory Position drilldown's Supplier column shows the target's name for any still-open reassigned line.
17. **Validation:** `--filter='Test_WC_IO_Supplier_Merge_Derived_Stats'` green.
18. **Completion checkpoint:** commit.
19. **Stop conditions:** any service shows stale data — stop and report; do not add ad hoc cache-busting without re-confirming the "zero caching" architecture claim first.

## WP-M17-6 — Admin handler and security

*(Renumbered from WP-M17-5; content hardened per Sections 2/10.)*

1. **Objective:** wire the HTTP-level entry point, including server-side confirmation.
2. **Existing files to modify:** `includes/class-wc-inventory-overview-purchasing-page.php` (register `admin_post_wc_io_supplier_merge` → `handle_supplier_merge()` and `wp_ajax_wc_io_search_merge_targets` → `ajax_search_merge_targets()`, both in `init()` alongside the existing registrations; also fix `handle_supplier_reactivate()` to check `reactivate()`'s return value, per Part J).
3. **New files:** none.
4. **Classes involved:** `WC_Inventory_Overview_Purchasing_Page`.
5–6. **Methods:** `handle_supplier_merge(): void` — capability guard (`MERGE_SUPPLIER`) → `check_admin_referer()` → token consume → sanitize `source_supplier_id`/`target_supplier_id` (`absint()`) and `supplier_merge_confirmation` (`sanitize_text_field(wp_unslash())`) → `Supplier_Merge_Service::merge( $source_id, $target_id, get_current_user_id(), $confirmation )` → success: redirect to target's detail URL with a success flag; `WP_Error`: transient notice (Part J's mapping table) + redirect to source's detail page. `ajax_search_merge_targets(): void` — capability + nonce → `Suppliers::list([...])` filtered per Part J.
7–14. As Part J.
15. **Test files:** `tests/integration/supplier-merge/test-supplier-merge-admin.php`.
16. **Test cases:** capability-denied → 403; bad nonce → rejected pre-service; happy path → correct redirect/notice; every error code → correct notice text; double-submit → second attempt rejected at token-consume; **crafted POST with valid nonce/token but wrong confirmation text → `wc_io_supplier_merge_confirmation_mismatch`, proving server-side enforcement independent of JS**; AJAX target search excludes source/archived/merged suppliers; reactivate handler now shows an error notice (not a false success) for a merged supplier.
17. **Validation:** `--filter='Test_WC_IO_Supplier_Merge_Admin'` green.
18. **Completion checkpoint:** commit.
19. **Stop conditions:** `PO_Request_Token`'s actual runtime behavior at this WP doesn't match the generic-context assumption confirmed in Part B — stop and report rather than forking a parallel mechanism.

## WP-M17-7 — Admin UI, reactivation-prevention UI, automated render tests

*(Renumbered from WP-M17-6; scope expanded per Sections 1/11.)*

1. **Objective:** build the merge form, already-merged notice, hardened list-table Reactivate suppression, target picker, server-backed typed confirmation UX, and automated rendering coverage.
2. **Existing files to modify:** `includes/class-wc-inventory-overview-purchasing-page.php` (`render_supplier_detail()` — merge section + already-merged notice), `includes/class-wc-inventory-overview-suppliers-list-table.php` (row-actions split per Part J), `includes/class-wc-inventory-overview-plugin.php` (register `assets/supplier-merge.js`, exact filename, alongside `supplier-picker.js`'s existing registration).
3. **New files:** `assets/supplier-merge.js` (exact name, frozen — no alternative).
4. **Classes involved:** `WC_Inventory_Overview_Purchasing_Page`, `WC_Inventory_Overview_Suppliers_List_Table` (render only).
5–14. Presentation-only.
15. **Test files:** `tests/integration/supplier-merge/test-supplier-merge-admin-render.php` → `Test_WC_IO_Supplier_Merge_Admin_Render` (exact list of assertions in Part L).
16. **Test cases:** the 10-item list in Part L, plus manual/browser acceptance per Part L's exact fixture policy.
17. **Validation:** `--filter='Test_WC_IO_Supplier_Merge_Admin_Render'` green; manual acceptance pass completed per Part L's procedure (backup → disposable fixtures → verify → ID-scoped cleanup or restore).
18. **Completion checkpoint:** automated render assertions and manual acceptance both pass; commit.
19. **Stop conditions:** none anticipated beyond ordinary UI implementation judgment within the copy/behavior already specified in Part J.

## WP-M17-8 — Performance and internal failure-injection coverage

*(Renumbered from WP-M17-7; content corrected per Section 7.)*

1. **Objective:** empirically prove INV-M17-5 (measured-constant query count) and BR-M17-9's exception-safety at all three injection points.
2. **Existing files to modify:** none.
3. **New files:** `tests/unit/supplier-merge/test-supplier-merge-performance.php` → `Test_WC_IO_Supplier_Merge_Performance`.
4–14. n/a (test-only).
15. **Test cases:** fixture generation at 500/2,000/5,000 POs for one source supplier (reuse `tests/integration/supplier-spend/test-supplier-spend-performance.php`'s direct-`$wpdb`-insert seeding technique — the established, fast, at-scale fixture pattern in this codebase, not the full PO service lifecycle); measure `merge()`'s total query count via the exact `$wpdb->num_queries`-delta technique from that same file (Part K); assert the three measured counts are equal to each other (the actual invariant — not asserted equal to any pre-guessed number); report the actual measured value; three failure-injection cases via `set_test_fail_after_step()`, each proving full rollback (row counts/content on `wc_io_suppliers`, `wc_io_purchase_orders`, `wc_io_goods_receipts`, `wc_io_supplier_merges` all unchanged from pre-merge state).
17. **Validation:** `--filter='Test_WC_IO_Supplier_Merge_Performance'` green; the report must state the actual measured query count at each scale, not restate a plan-asserted figure.
18. **Completion checkpoint:** measured and reported; rollback proven at all three points; commit.
19. **Stop conditions:** the measured count is not constant across fixture sizes (indicates an accidental per-row loop somewhere in WP-M17-2/3) — stop and report, do not just note the discrepancy and move on.

## WP-M17-9 — Documentation and version

*(Renumbered from WP-M17-8; content otherwise unchanged.)*

1. **Objective:** update every doc this milestone touches.
2. **Existing files to modify:** `wc-inventory-overview.php` (`Version: 1.34.0`), `readme.txt`, `CHANGELOG.md`, `docs/admin-guide-suppliers.md` (remove the "Not Yet Available" line; add operator guidance including the permanent-dissolution and irreversibility facts), `docs/ARCHITECTURE_BASELINE_v1.24.0.md` (new sole-owner row, new INV-M17-* entries, schema table update), `docs/OWNERSHIP.md` (new row), `docs/testing.md` (CI filter table), `docs/rollback-plan.md` (new `## ⚠ M17` section, parallel to M4/M5: code rollback does not reverse completed merges), `docs/release-runbook.md` (new `### M17` appendix: pre-tag v11 schema check, feature walkthrough, explicit note this trips Release Triggers and releases standalone, explicit pre-first-production-merge backup recommendation).
3. **New files:** none (operator guidance folds into `docs/admin-guide-suppliers.md`, its existing canonical home — deliberate, not a gap).
4–14. n/a.
15–17. **Validation:** grep the diff for any accidental "released"/"tagged"/"deployed" language describing M17 itself — must be none.
18. **Completion checkpoint:** commit.
19. **Stop conditions:** none anticipated.

## WP-M17-10 — Validation and WP1→WP2 handoff

*(Renumbered from WP-M17-9; content otherwise unchanged.)*

1. **Objective:** finish Haiku's WP1 scope and hand off cleanly for the separate, fresh-instance WP2 independent audit — not to perform that audit, not to create the WP4 freeze artifact.
15. **Full regression matrix:** entire existing suite unmodified + all new WP-M17-0..9 suites, zero failures, zero new PHPCS findings beyond baseline, `--list-tests` confirms discovery of every new class listed in Part L, `release-audit.sh --development` passes, GitHub Actions green on the final pushed commit.
17. **Validation commands:** unit / integration / default (M1–M17-focused) PHPUnit runs; `--list-tests`; PHPCS vs. `.phpcs-baseline.xml`; `release-audit.sh --development`.
18. **Completion checkpoint:** clean tree, all commits pushed, all gates green — report per Part R, then stop. Do not merge, tag, release, deploy, freeze, or start M18.
19. **Stop conditions:** any red gate not fixable within this WP's own scope.

---

# PART N — Documentation, Version, and Rollback Summary

Unchanged from the prior pass except: `docs/admin-guide-suppliers.md`'s new operator section must now also state the permanent-dissolution fact (a merged supplier can never be reactivated) alongside the irreversibility-of-data-movement fact. Version `1.34.0`, `DB_VERSION` `10 → 11`. Code rollback is not data undo — stated explicitly in the new `docs/rollback-plan.md` M17 section, parallel to M4/M5. Recommend a database backup before the first production merges post-release, called out in the release runbook's M17 appendix.

---

# PART O — Lifecycle Classification and Release Recommendation

Unchanged: **Level B.** Full lifecycle applies (WP1 Haiku implementation → WP2 fresh-instance audit → WP3 remediation → WP4 freeze → WP5/WP6). This repo's own Release Triggers rule (schema change + ownership-boundary change) means **M17 releases standalone immediately after WP4 freeze**, as `v1.34.0`, following the same runbook shape M16 just used for `v1.33.0`.

---

# PART P — Definition of Done

Unchanged in structure, updated for the new WP: **Implementation (Haiku's scope, WP-M17-0..10) is complete when** all 11 work packages' checkpoints are satisfied, full regression suite is green, `--list-tests`/PHPCS/`release-audit.sh --development`/GitHub Actions all green, working tree clean, all commits pushed to `feature/m17-supplier-merge`. **Explicitly not part of this Definition of Done:** WP2 audit, WP3 remediation, WP4 freeze artifact, merge to `main`, tag, GitHub Release, deployment.

---

# PART Q — Haiku Implementation Stop Conditions

STOP and report (do not redesign, do not improvise) if:
- `origin/main` has moved from `2c9e280` by the time WP-M17-0 runs.
- `dbDelta()`/`assert_schema_shape()` cannot express the v11 schema as planned, or the dispatcher cascade differs from Part H's assumption.
- `WC_Inventory_Overview_DB_Transaction` cannot provide the atomicity/locking guarantees Part I assumes.
- Any historical snapshot column, or anything in `wc_io_inventory_movements`, would need to be written to satisfy a test.
- A new supplier-referencing table/column is discovered that Part C's reference map omitted.
- The measured query count in WP-M17-8 is not constant across fixture sizes.
- `WC_Inventory_Overview_PO_Request_Token`'s actual runtime behavior doesn't match the generic-context assumption confirmed in Part B.
- Any test exposes a merge semantics question not covered by BR-M17-1 through BR-M17-18.
- CI can only be made green by weakening an existing gate.
- The WP-M17-4 concurrency-closure design cannot actually be proven under the PHPUnit/MariaDB test harness available — report exactly what was and wasn't provable, don't claim untested coverage.
- Closing the concurrent-create race is found to require behavior changes beyond `PO_Service::create_draft()` and `Goods_Receipt_Service::create_draft_from_post()` (e.g. if `duplicate()` or `post()` turn out to have a real exposure Part I's reasoning missed) — stop and report the specific gap rather than silently expanding WP-M17-4's touched-file list.
- Fixing `handle_supplier_reactivate()` to check `reactivate()`'s return value is found to have any other caller/behavior dependency this plan didn't anticipate.

---

# PART R — Final Implementation Report Contract

Unchanged in shape from the prior pass, with these items now mandatory in addition to the original 30: confirmation that no `do_action`/`apply_filters` was introduced anywhere in the new code (INV-M17-10); confirmation `Suppliers::reactivate()` and its admin handler and list-table row action all reject a merged supplier (INV-M17-11), with test evidence for each of the three layers independently; the actual measured `merge()` query count at each fixture scale (Part K/WP-M17-8), not a restated estimate; confirmation the WP-M17-4 concurrency test suite passed and an explicit statement of what it did and did not manage to prove under the PHPUnit/MariaDB harness; confirmation the server-side confirmation check was proven via a crafted-POST test independent of any JS.

---

**M17 FINAL HAIKU-READY PLAN READY FOR IMPLEMENTATION**
