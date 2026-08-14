# M22 Definitive Implementation Plan — Reorder → Draft PO Quick Action

## Context

M21 "Position-Aware Reorder Signal" (v1.38.0, released) added a per-item `needs_reorder` classification (position ≤ effective low-stock threshold) but is deliberately read-only — no action button exists to act on it. M22 closes that gap: it lets a merchant go from "this item needs reorder" to "a draft PO for it, sitting in the existing Purchase Order workflow" — without inventing a second reorder algorithm, a demand-forecast quantity, or a new PO-mutation path. M22 and M23 form one feature train (per repo convention: M9–M12→v1.29.0, M13–M15→v1.32.0, M18–M19→v1.36.0); M22 must finish **Level A frozen, CI-green, unreleased** — no tag, no GitHub Release, no deploy, no release PR. M23 will branch from M22's frozen tip; the combined train releases once, later.

All facts below were verified directly against `/opt/biopentra/dev/wc-inventory-overview` source — preflight audit, full M21 call-chain trace, full PO/PO-line/supplier architecture trace, and two rounds of targeted re-verification (round 1: exact `PO_Admin` render signatures, absence of `row_actions()`/`Suppliers::list_by_ids()`, version-bump-at-freeze convention; round 2, after design review: exact table indexes on `wc_io_purchase_order_lines`/`wc_io_purchase_orders`, the full `Purchasing_Caps` capability map incl. the distinct `VIEW_PO`/`EDIT_PO` split, and the exact field-default markup in `render_line_row()`). Repo state: HEAD/main/origin all match, working tree clean, version 1.38.0, DB_VERSION 11, tag v1.38.0 published, no M22/M23 artifacts exist yet. (Pre-existing, unrelated: `readme.txt` Stable tag is stale at 1.36.0 — flag only, do not fix.)

This plan was reviewed once before finalization. The review confirmed the core architecture (Option B, redirect+prefill; feature-train topology) but required nine amendments, all incorporated below: stale reorder state now fails closed (§4/§8), supplier lookup is bulk not N+1 (§5), the new query's index support is verified explicitly (§5), the action link's capability check uses the authoritative `Purchasing_Caps::EDIT_PO`, not a borrowed `manage_woocommerce` check (§9), the malformed/invalid/stale URL contract is unified into one explicit status enum (§8), the quantity-field claim is reconciled with the actual `render_line_row()` default (§6), supplier history now counts only committed statuses (§5), query-count coverage is added at 0/1/10/50 historical suppliers (§18), and final acceptance is AI-driven runtime execution, not a human manual-smoke step (Verification).

## 1. Executive design decision

**Option B: redirect to the existing New PO screen with a server-validated prefill; create nothing until the merchant submits.** Immediate creation (Option A) is architecturally blocked: `WC_Inventory_Overview_PO_Service::create_draft()` (`includes/class-wc-inventory-overview-po-service.php:85-89`) requires `supplier_id`, and **no preferred/default-supplier concept exists anywhere in this codebase** (zero hits for `preferred_supplier`/`default_supplier`; confirmed by M21's own plan doc, which explicitly deferred this "genuine open design question" to M22). Guessing a supplier or forcing single-supplier-only eligibility would fragment the UX and require a new, riskier mutation path. Redirect-with-prefill has a direct, working precedent already in the codebase: `WC_Inventory_Overview_Goods_Receipt_Admin::render_new_from_po()` (`includes/class-wc-inventory-overview-goods-receipt-admin.php:172-207`) builds an in-memory, not-yet-persisted prefill array server-side from a GET param and feeds it into the existing render — no new persistence method, no new mutation entry point. M22 replicates this shape for `PO_Admin`.

## 2. Quick-action semantics

A new "Create Draft PO" link, rendered next to the existing "Needs reorder" badge, visible only to users holding the actual purchasing-edit capability (§9). Clicking it is a plain `GET` navigation to the existing New PO screen (`PO_Admin::detail_url(0)`) with two extra query params. Nothing is created/mutated by the navigation. The merchant reviews/edits the prefilled (or gracefully degraded) form and submits through the **completely unmodified** `handle_save()` → `PO_Service::create_draft()` pipeline — same nonce, same anti-double-submit request token, same validation, same transaction.

## 3. Exact action surface

Rendered exactly once, inside `WC_Inventory_Overview_List_Table::render_reorder_signal_badge()` (`includes/class-wc-inventory-overview-list-table.php:443-456`), immediately after the "Needs reorder" `<span>`, inside the `needs_reorder` branch only. This method's one call site, `render_direct_stock_badges_inner()` (line 424), is itself never invoked for a variable-parent row — `column_status()` (line 344) routes variable-parent rows through `compute_variable_aggregate()`'s rollup badges instead. **The variable-parent exclusion is therefore structural, requiring no new guard code.**

Verified: `render_reorder_signal_badge()` currently takes `int $item_id` (list-table.php:443) with its one call site at line 424 passing `$item->get_id()`. This signature changes to accept `WC_Product $item` so the new link renderer can call `is_type('variation')`/`get_parent_id()` on the already-loaded object — zero new queries — and its single call site is updated accordingly.

Variation-id resolution (matches `PO_Product_Validator`'s own convention, `includes/class-wc-inventory-overview-po-product-validator.php:21-24`):
- Simple product row: `wc_io_ro_product_id = $item->get_id()`, `wc_io_ro_variation_id` omitted (⇒ 0).
- Variation row: `wc_io_ro_product_id = $item->get_parent_id()` (parent id, already in memory), `wc_io_ro_variation_id = $item->get_id()`.

This link is the **only** M22 action surface. It does not appear on the Dashboard KPI/recent-low-stock table or the Overview summary cards — those remain read-only glance surfaces; scattering the same mutation-adjacent link across every M21 render site was explicitly rejected in favor of one primary operational surface (the Inventory Overview list table, where merchants already act on rows).

## 4. Reorder eligibility contract (server-side re-evaluation / TOCTOU)

The New PO render (`action=new`, no `po_id`) checks `$_GET['wc_io_ro_product_id']`. When present and non-zero after sanitization (§8), `WC_Inventory_Overview_Reorder_Prefill_Service::resolve( int $product_id, int $variation_id = 0 ): array` re-derives everything from scratch, never trusting the URL's mere presence:

1. `WC_Inventory_Overview_PO_Product_Validator::validate( $product_id, $variation_id )` — the **exact same validator** `PO_Service::create_draft()` uses at submit time (via `prepare_line_input()`). `WP_Error` (invalid product, variable/grouped/external parent, mismatched parent/variation, not stock-managed) → `resolve()` returns `status: 'invalid'` (§8); the New PO form renders blank with an informational notice naming the reason; never a fatal/`wp_die()`.
2. On success: `on_hand` from the resolved product's stock quantity (validator already guarantees `managing_stock()`).
3. `$threshold = WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $product )`.
4. `$position = WC_Inventory_Overview_Inventory_Position_Service::get_position( $item_type, $item_id, $on_hand )` — single-item variant, D12's one calculation path.
5. `$signal = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( $position['position'], $threshold )` — the sole classifier; never reimplemented.

**Amended contract (fail closed on staleness):** if `needs_reorder` is now `false` — stock/incoming/threshold changed between badge render and click — the reorder-specific prefill is **discarded entirely**: `resolve()` returns `status: 'stale'`, `line: null`, no supplier resolution is attempted, and the New PO screen renders its ordinary blank form plus a non-blocking informational notice ("This item no longer appears to need reordering — showing a blank purchase order. You can still create one manually."). A click on "Create Draft PO" is specifically an action that originates from a `needs_reorder` state; if that state no longer holds by the time the page resolves, re-showing the same prefill as if nothing changed would defeat the entire purpose of re-checking eligibility. This gives the TOCTOU re-evaluation real enforcement teeth while never blocking the merchant from using the (now-blank) form manually.

## 5. Supplier-selection contract

New repository method, using the **existing single-column indexes already on `wc_io_purchase_order_lines`** — no schema change required. Verified directly from the install schema (`includes/class-wc-inventory-overview-install.php:234-260`, `:206-230`): `wc_io_purchase_order_lines` has independent `KEY product_id`, `KEY variation_id`, `KEY po_id` (no composite `(product_id, variation_id)` index); `wc_io_purchase_orders` has `KEY status` and a PK on `id`. Rather than inventing a new combined-predicate query shape, this mirrors the **existing dual-path pattern** M3 already uses for the identical product-vs-variation distinction (`Purchase_Order_Lines::list_open_lines_for_product_ids()` vs `list_open_lines_for_variation_ids()`, `includes/class-wc-inventory-overview-purchase-order-lines.php:369-399`): if `$variation_id > 0`, filter primarily on the indexed `variation_id` column (maximally selective — a single variation's own line count); otherwise filter on the indexed `product_id` column with `variation_id = 0` (matches the existing convention that a simple product's lines always carry `variation_id = 0`). Both branches are bounded by an existing single-column index lookup, identical in shape and cost to code already proven at production scale by M3/M4. `DB_VERSION` stays 11.

```php
// includes/class-wc-inventory-overview-purchase-order-lines.php
/**
 * Distinct supplier IDs with committed (non-draft, non-cancelled) purchase
 * history for one purchasable item, most-recent order first (M22).
 *
 * @param int $product_id   Parent id for a variation, own id for a simple product.
 * @param int $variation_id Variation id, or 0 for a simple product.
 * @return int[] Supplier IDs, ordered by most-recent po.order_date DESC. No eligibility filtering here.
 */
public static function distinct_supplier_history_for_item( int $product_id, int $variation_id = 0 ): array
```

**Amended status filter (committed history only):** `WHERE po.status IN ('placed', 'partially_received', 'received', 'closed_short')` — using the indexed `status` column — rather than the previously-proposed "everything except cancelled." A `draft` PO is not a committed purchasing relationship (it may never be placed, may be abandoned, or may itself have been created from a mis-click); using it as the sole basis for automatically preselecting a supplier on a *new* draft would let an abandoned draft silently propagate. This matches the "committed purchasing" status set the repository already establishes elsewhere for comparable judgments (`WC_Inventory_Overview_Supplier_Spend_Service`'s BR-M15-1: `placed`/`partially_received`/`received`/`closed_short` only, `draft` and `cancelled` always excluded) — M22 reuses that exact, already-precedented status set rather than asserting a new one.

**Amended supplier lookup (bulk, not N+1):** new bulk repository method, mirroring the existing `Purchase_Orders::list_by_ids( array $ids ): array` bulk-fetch precedent (`includes/class-wc-inventory-overview-purchase-orders.php:113-128`):

```php
// includes/class-wc-inventory-overview-suppliers.php
/**
 * Bulk-fetch supplier rows by id, one query regardless of count (M22).
 *
 * @param int[] $ids Supplier ids.
 * @return array<int,array<string,mixed>> Rows keyed by id; missing ids simply absent.
 */
public static function list_by_ids( array $ids ): array

/**
 * Whether a supplier row (already fetched) is selectable for a new PO
 * (M22). Same predicate already inlined at 4 existing PO_Service /
 * Goods_Receipt_Service call sites (status=active, not merged) — not
 * retrofitted onto those 4 sites in this milestone (out of scope).
 */
public static function is_eligible_for_selection( array $supplier ): bool {
    return self::STATUS_ACTIVE === ( $supplier['status'] ?? '' ) && empty( $supplier['merged_into_supplier_id'] );
}
```

Resolution: call `distinct_supplier_history_for_item()` once (1 query), then `list_by_ids()` once with the full set of distinct ids (1 query, `WHERE id IN (...)`, single round-trip regardless of N) and filter the result by `is_eligible_for_selection()` in PHP. **Total: exactly 2 queries, independent of the number of distinct historical suppliers** — no per-supplier `get()` loop. Query-count coverage at 0/1/10/50 historical suppliers is a required test (§18).

- **0 eligible** → supplier unselected; notice "No eligible supplier found in this item's purchase history — choose one below"; normal full active-supplier dropdown.
- **1 eligible** → preselected, still editable.
- **Many eligible** → unselected (never auto-picked); notice that history spans more than one supplier.
- **Archived-but-unmerged** → excluded (status check); falls through to 0/1/many among the remainder.
- **Merged/dissolved** → excluded defensively. Note: M17's `reassign_supplier_bulk()` unconditionally rewrites all historical PO rows to the surviving target on merge, so this case is reachable only via direct DB manipulation in tests, not the normal merge flow.

## 6. Quantity contract

**Merchant-supplied only — M22 never derives or computes a quantity.** No authoritative reorder-quantity source exists anywhere in the repo (no par level, MOQ, pack size, velocity) — inventing one is out of scope. The prefilled line array carries only `product_id`/`variation_id`/`name_snapshot`/`sku_snapshot`; `qty_ordered`, `unit_cost`, and `supplier_sku` are never set by M22's code.

**Frozen against actual source** (`po-admin.php:579,589,572`): when a line array omits `qty_ordered`, `render_line_row()`'s own pre-existing markup already applies `value="1"`; when it omits `unit_cost`, it applies `value="0"`; when it omits `supplier_sku`, it applies `value=""`. These are **today's unmodified blank-New-PO-line defaults**, identical whether the row originates from a plain "Add Purchase Order" click or a reorder-prefilled one. M22 introduces no new default and no new field-population logic for these three fields — the correct, unambiguous statement of this rule is: *M22 does not supply a quantity; the merchant sees exactly the same starting value (`1`) they would see on any ordinary new PO line, and can change or accept it before submitting.* `PO_Validation::validate_line()` is untouched. M10's existing supplier-driven Expected Date auto-suggestion continues to work unchanged once a supplier is selected.

## 7. Variable-product contract

Structural exclusion (§3): the link never renders on a variable-parent row (no code path reaches it there), and even a hand-crafted URL pointing at a variable parent is rejected by `PO_Product_Validator::validate()`'s existing `wc_io_po_product_type` error, reused unmodified — degrading gracefully to `status: 'invalid'` (§8), never a smuggled-in parent-level line.

## 8. Prefill/data contract

```php
// includes/class-wc-inventory-overview-po-admin.php
public static function reorder_prefill_url( int $product_id, int $variation_id = 0 ): string {
    $args = array( 'wc_io_ro_product_id' => $product_id );
    if ( $variation_id > 0 ) {
        $args['wc_io_ro_variation_id'] = $variation_id;
    }
    return add_query_arg( $args, self::detail_url( 0 ) );
}
```

**Amended, unified status contract.** `Reorder_Prefill_Service::resolve()` returns exactly one of five states, so "absent," "malformed," "invalid identity," and "stale" are no longer conflated (the prior draft's §8/§9 disagreed on this — resolved here):

```php
/**
 * @return array{
 *   status: 'prefilled'|'stale'|'invalid'|'malformed',
 *   line: array{product_id:int, variation_id:int, name_snapshot:string, sku_snapshot:string}|null,
 *   supplier_id: int,
 *   notices: array<int, array{type:'info'|'warning', message:string}>,
 * }
 */
public static function resolve( int $product_id, int $variation_id = 0 ): array;
```

Rendering logic in `PO_Admin::render_detail()`, evaluated in this exact order:

1. **Param key absent from `$_GET` entirely** → `resolve()` is not called at all. Ordinary blank New PO form, **zero notices**. (This is the common case: every non-reorder-originated visit to "Add Purchase Order.")
2. **Key present, but `absint( wp_unslash( $_GET['wc_io_ro_product_id'] ) )` collapses to `0`** (non-numeric, negative, or literally `0`) → `resolve()` is not called (there is no valid id to resolve). Ordinary blank form **+ one notice**: "The reorder link was invalid — showing a blank purchase order." This is the `'malformed'` case — distinguished from case 1 precisely so a tampered/corrupted link is distinguishable, in tests and in the merchant's own eyes, from simply not having come from a reorder link.
3. **Key present, numeric id > 0, but `PO_Product_Validator::validate()` rejects it** (deleted product, variable/grouped/external parent, mismatched parent/variation pair, not stock-managed) → `resolve()` returns `status: 'invalid'`. Blank form **+ one notice** naming the general reason ("This item could not be prefilled — showing a blank purchase order.").
4. **Valid identity, but `needs_reorder` is now false** → `resolve()` returns `status: 'stale'` per §4. Blank form **+ one notice** (§4's exact wording).
5. **Valid identity, `needs_reorder` true** → `resolve()` returns `status: 'prefilled'`, `line` populated, `supplier_id`/supplier notices per §5.

Parsing: `absint( wp_unslash( $_GET['wc_io_ro_product_id'] ) )` / same for `variation_id`, default `0` — happens in `PO_Admin`, only when rendering the New-PO action (never edit/view, §9). No nonce required (§9). Cases 1–2 above are handled entirely in `PO_Admin` before `Reorder_Prefill_Service` is ever invoked, keeping the service itself simple (it only ever receives an `absint`-sanitized positive product id).

## 9. Security contract

**Amended: capability check is `Purchasing_Caps::EDIT_PO`, resolved through the authoritative capability map — not a borrowed `manage_woocommerce` check.** Verified: `WC_Inventory_Overview_Purchasing_Caps` (`includes/class-wc-inventory-overview-purchasing-caps.php`) defines a distinct `VIEW_PO` ('view_po') and `EDIT_PO` ('edit_po') constant; `PO_Admin::render_panel()` (`po-admin.php:218`) gates reaching *any* PO screen — list, new, edit, view — on `VIEW_PO` only, while `handle_save()` (`po-admin.php:752`) — the method that actually creates the draft — requires `EDIT_PO`. Both currently resolve to `manage_woocommerce` via the same filterable map (`purchasing-caps.php:49-50`), but they are semantically distinct and independently filterable (`wc_io_purchasing_capability_map`). Because M22's action link exists specifically to *lead to creating* a PO, its gating capability must track `EDIT_PO` (what `handle_save()` actually requires to complete the action), not `VIEW_PO` (what merely getting to look at the screen requires) and not a raw `current_user_can('manage_woocommerce')` inherited incidentally from M21's own internal gating of `position_map`. Concretely:

- **List-table link visibility**: rendered only if `WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO )` is true, in addition to already being inside the `manage_woocommerce`-gated `position_map` code path (unchanged, M21's own gate). A viewer with `VIEW_PO` but not `EDIT_PO` (a future, filtered-apart role — e.g. a warehouse read-only role) would never see the link, since they could not complete the action it leads to.
- **Prefill-service activation**: `render_detail()` calls `Reorder_Prefill_Service::resolve()` only if `Purchasing_Caps::current_user_can(EDIT_PO)` is also true — independent of `render_panel()`'s own pre-existing `VIEW_PO` gate for reaching the New PO screen at all. If `EDIT_PO` is absent, the GET params are treated exactly like case 1 in §8 (silently ignored, ordinary blank form, no notice) — the viewer can still see and use the plain form (their existing `VIEW_PO` entitlement, unchanged), they just never see reorder-derived product/supplier prefill or purchase-history-derived supplier information they may not be entitled to.
- **Nonce**: none needed for the GET navigation — nothing mutates on GET, identical reasoning to the existing `Goods_Receipt_Admin::render_new_from_po()` precedent (also unvalidated `$_GET['po_id']`, no nonce). The actual mutation (`handle_save()`) keeps its existing nonce + one-shot request-token, unchanged, still gated by `EDIT_PO`.
- **Why URL params can't bypass anything**: they are read exactly once, only to compute `<option selected>` markup for an ordinary editable form. `handle_save()` never reads `wc_io_ro_*` — it reads `$_POST['po']`/`$_POST['lines']` exactly as today and routes to the unmodified `PO_Service::create_draft()`, which re-runs its own full `PO_Product_Validator` + `Suppliers::get_for_update()` + `PO_Validation` on whatever was actually submitted. A tampered/omitted param can, at worst, cause a "wrong" or absent pre-selection — never bypass a check, never grant a capability the submitting user doesn't independently hold.

## 10. Staleness/concurrency contract

Covered by §4 (now fail-closed) and §8 (unified status contract). No locking/CAS/idempotency token needed at the render step (pure read). The only real mutation, `create_draft()`, keeps its own unchanged concurrency control. M22 adds no new concurrency surface.

## 11. Duplicate behavior

None needed. Two separate visits + two full submits produce two independent drafts by design — matches today's baseline "Add Purchase Order" behavior. No dedup/lookup query added.

## 12. Mutation ownership

`PO_Service::create_draft()` remains the sole PO-creation mutation entry point, byte-for-byte unmodified. No new code calls `Purchase_Orders::create_draft()`/`Purchase_Order_Lines::create()` directly; no new `admin_post_*` handler.

## 13. Query/performance contract

| Surface | Additional queries |
|---|---|
| List-table row link | **Zero** — derived from the already-loaded `WC_Product` object and the already-computed `needs_reorder` boolean. |
| New-PO-with-prefill render, `status: 'stale'`/`'invalid'`/`'malformed'` path | **Zero or minimal** — `'malformed'` issues none (rejected before `resolve()` is called); `'invalid'`/`'stale'` issue only the identity/position lookups (product load + `get_position()`'s ≤2 queries), no supplier query at all (§4/§5: supplier resolution is skipped entirely once `status` is anything but `'prefilled'`). |
| New-PO-with-prefill render, `status: 'prefilled'` path | **Fixed at 2 additional queries beyond identity/position resolution, independent of historical supplier count**: 1 `distinct_supplier_history_for_item()` + 1 `Suppliers::list_by_ids()` bulk call (§5). Combined with 1 product load (object-cache-backed) + `get_position()`'s ≤2 queries, the full bound is small and fixed, never scaling with catalog size or with how many suppliers have ever sold this one item. |

## 14. Schema decision

None. No new table/column/index/option. Existing single-column indexes on `product_id`/`variation_id`/`status` are sufficient (§5). `DB_VERSION` stays `11`.

## 15. Production ownership / exact files

| File | Change |
|---|---|
| `includes/class-wc-inventory-overview-reorder-prefill-service.php` | **New.** `WC_Inventory_Overview_Reorder_Prefill_Service::resolve( int $product_id, int $variation_id = 0 ): array` — five-state contract per §8. |
| `includes/class-wc-inventory-overview-purchase-order-lines.php` | + `distinct_supplier_history_for_item()` (§5, committed-status filter, index-aware dual-path query). |
| `includes/class-wc-inventory-overview-suppliers.php` | + `list_by_ids( array $ids ): array` (bulk, §5) + `is_eligible_for_selection( array $supplier ): bool` (§5). |
| `includes/class-wc-inventory-overview-po-admin.php` | + `reorder_prefill_url()`. Extend `render_detail( string $action )` (line 251) to implement §8's ordered status handling, gated by `Purchasing_Caps::EDIT_PO` (§9). Extend `render_header_fields( $po, array $field_errs, bool $editable )` (line 376) with trailing `int $prefill_supplier_id = 0`. Extend `render_lines_editor( array $lines, $po, array $field_errs, bool $editable )` (line 492) with trailing `array $prefill_line = array()`, threaded into its existing `empty($lines) && $editable` branch (line 519) as `render_line_row( ! empty($prefill_line) ? $prefill_line : null, 0, true )`. `render_line_row( $line, $index, bool $editable )` (line 546) is **unchanged** — verified it already accepts a partial line array (only `product_id`/`variation_id`/`name_snapshot`/`sku_snapshot` set) and defaults `qty_ordered` to `'1'`, `unit_cost` to `'0'`, `supplier_sku` to `''` exactly as required by §6. |
| `includes/class-wc-inventory-overview-list-table.php` | `render_reorder_signal_badge( int $item_id, ... )` → `render_reorder_signal_badge( WC_Product $item, float $threshold, array $position_map )` (its one call site at line 424 updated). + `render_reorder_action_link( WC_Product $item ): string`, called only from the `needs_reorder` branch, gated by `Purchasing_Caps::EDIT_PO` (§9). |
| `wc-inventory-overview.php` | + `require_once` for the new service file, placed after its dependencies (`Suppliers`, `PO_Product_Validator`, `Settings`, `Inventory_Position_Service`, `Reorder_Signal_Resolver`, `Purchase_Order_Lines`), before `po-admin.php`'s require. |
| `tests/docker/run-phpunit.sh` | At freeze only: add `Test_WC_IO_Reorder_Prefill_` to `FILTER_ARGS`. |

No other production file is touched. `PO_Service`, `PO_Validation`, `PO_Product_Validator`, `Purchase_Orders`, `Inventory_Position_Service`, `Reorder_Signal_Resolver`, `Settings` are read-only, unmodified dependencies.

Also verified: `list-table.php` has **no** `WP_List_Table::row_actions()` usage anywhere — this table is entirely custom-rendered, so the new link is added as plain inline `<a class="button button-small">` markup, matching the style already used elsewhere (e.g. PO_Admin's "Receive"/"Print" buttons).

## 16. BR-M22 business rule matrix

1. **BR-M22-1**: Link renders iff `needs_reorder === true` for that row — never for `covered_by_incoming`, never on a variable-parent rollup row.
2. **BR-M22-2**: Every M22 surface (link, prefill activation, supplier preselection) requires `Purchasing_Caps::current_user_can( EDIT_PO )` — the authoritative purchasing-edit capability, resolved through the filterable capability map, not a hard-coded `manage_woocommerce` check (§9). Reaching the New PO screen at all continues to require only `VIEW_PO`, unchanged.
3. **BR-M22-3**: Variation row → `wc_io_ro_product_id` = parent id, `wc_io_ro_variation_id` = variation id. Simple product row → `wc_io_ro_product_id` = own id, `wc_io_ro_variation_id` = 0.
4. **BR-M22-4**: GET params are pure UX hints; no nonce required (nothing mutates on GET).
5. **BR-M22-5**: Absent param key → ordinary blank form, no notice. Malformed/zero param → ordinary blank form + "invalid link" notice, `Reorder_Prefill_Service` not invoked. Numeric id failing `PO_Product_Validator::validate()` → `status: 'invalid'`, blank form + reason notice. All three are distinguishable in code and in tests (§8).
6. **BR-M22-6**: Prefill activation always re-derives `needs_reorder` fresh via the M21 primitives; never trusts the originating badge/URL (TOCTOU).
7. **BR-M22-7 (amended)**: Valid identity but no-longer-`needs_reorder` → `status: 'stale'`; the reorder-specific prefill (product, supplier) is **discarded entirely**; ordinary blank form + non-blocking informational notice; merchant never blocked from creating a PO manually.
8. **BR-M22-8**: Supplier resolution per §5 — committed-status history only (`placed`/`partially_received`/`received`/`closed_short`; `draft` and `cancelled` both excluded); bulk-fetched (`list_by_ids()`, 1 query regardless of count); 0/1/many eligible; archived/merged always excluded.
9. **BR-M22-9**: Quantity/cost/SKU are never derived or set by M22's code; the field values a merchant sees are `render_line_row()`'s own pre-existing blank-line defaults (`qty_ordered='1'`, `unit_cost='0'`, `supplier_sku=''`), identical to any ordinary new PO line (§6).
10. **BR-M22-10**: Variable parents rejected by identity resolution (`status: 'invalid'`) exactly as a manual entry would be; link never appears there.
11. **BR-M22-11**: GET params are consulted only when rendering the New-PO action (no `po_id`); the Edit-PO screen never reads or is affected by them.
12. **BR-M22-12**: GET navigation never creates/mutates/persists anything; a PO is created only via explicit submission through unmodified `handle_save()` → `PO_Service::create_draft()`.
13. **BR-M22-13**: No dedup/duplicate-prevention mechanism; repeated use may produce separate drafts by design.
14. **BR-M22-14**: No stock/cost/product mutation anywhere in new M22 code.
15. **BR-M22-15**: List-table per-row/per-page query count unchanged from v1.38.0.
16. **BR-M22-16**: New-PO-with-prefill render issues a small, fixed, item-and-supplier-count-independent number of queries (§13) — never scans the catalog or the full PO-lines table, and never issues one query per historical supplier.

## 17. INV-M22 invariant matrix

1. **INV-M22-1**: `Reorder_Signal_Resolver::resolve()` remains sole owner of the `needs_reorder` comparison; never reimplemented.
2. **INV-M22-2**: Position is obtained exclusively via `Inventory_Position_Service`; never summed independently.
3. **INV-M22-3**: `PO_Service::create_draft()` remains the sole PO-creation mutation entry point; no parallel path.
4. **INV-M22-4**: A supplier is preselected iff it passes `Suppliers::is_eligible_for_selection()`; `create_draft()`'s independent lock+re-validate remains authoritative regardless of what was prefilled.
5. **INV-M22-5**: An archived or merged supplier is never preselected.
6. **INV-M22-6**: No automatic PO placement — every M22-originated PO reaches only `draft` status via explicit merchant submission.
7. **INV-M22-7**: No stock mutation anywhere in new/touched code.
8. **INV-M22-8**: No cost mutation or cost calculation.
9. **INV-M22-9**: No new public hook/filter introduced.
10. **INV-M22-10**: No SQL in `PO_Admin`/`List_Table`; all reads route through repository/service classes.
11. **INV-M22-11**: No schema change; `DB_VERSION` stays 11.
12. **INV-M22-12**: A variable parent never becomes a PO line, whether via manual entry or a crafted reorder-prefill URL.
13. **INV-M22-13**: Inventory Overview list-table query count is identical pre/post M22 at every fixture scale.
14. **INV-M22-14 (amended)**: Every new M22 surface is reachable if and only if `Purchasing_Caps::current_user_can(EDIT_PO)` is true, resolved through the authoritative capability map — not a hard-coded string comparison to `manage_woocommerce`. No new capability constant is introduced; `EDIT_PO` already exists and already governs `handle_save()`.
15. **INV-M22-15**: Tampering with, omitting, or fabricating `wc_io_ro_*` params can never change what `PO_Validation`/`PO_Product_Validator`/`PO_Service::create_draft()` accept or reject at submit time.
16. **INV-M22-16 (new)**: Supplier resolution for the New-PO prefill issues exactly 2 queries (1 history + 1 bulk fetch) regardless of the number of distinct historical suppliers for the item — never N+1.

## 18. Test matrix

New directory, feature-slug `reorder-prefill` (spans `List_Table`/`PO_Admin`/`Purchase_Order_Lines`/`Suppliers`, mirroring how `reorder-signal` got its own directory in M21):

| Test file | Covers | Asserts |
|---|---|---|
| `tests/unit/reorder-prefill/test-reorder-prefill-architecture.php` | INV-1,3,7,9,10 | Grep guards: no reimplementation of `position <= threshold` outside the resolver; zero mutation-shaped tokens in new/touched files; no new `admin_post_`/`wp_ajax_`/hook registrations; no `$wpdb` in `PO_Admin`/`List_Table`; no new `current_user_can()` call in new M22 code checking anything other than via `Purchasing_Caps`. |
| `tests/integration/reorder-prefill/test-purchase-order-lines-supplier-history.php` | BR-8 (history) | Zero/one/many suppliers; a `cancelled` PO's supplier excluded; a `draft`-only PO's supplier excluded (BR-8 amendment — draft alone must never surface a supplier); `placed`/`partially_received`/`received`/`closed_short` all count; most-recent-first ordering; simple vs. variation id convention; unrelated product/variation never included; **query-count assertion at 0/1/10/50 distinct historical suppliers — exactly 1 query in every case** (this method alone). |
| `tests/integration/reorder-prefill/test-suppliers-eligibility-and-bulk-fetch.php` | BR-8 (eligibility + bulk fetch), INV-5, INV-16 | `is_eligible_for_selection()`: active+unmerged→true; archived→false; merged→false regardless of status; malformed input→false, no notice/exception. `list_by_ids()`: returns rows keyed by id; missing ids simply absent; **query-count assertion at 0/1/10/50 requested ids — exactly 1 query in every case (or 0 for an empty id list)**. |
| `tests/integration/reorder-prefill/test-reorder-prefill-service.php` | BR-1,5,6,7,8,9,10; INV-2,4,12,16 | `resolve()` across all five §8 status branches; the 0/1/many-supplier branches within `'prefilled'`; valid variation (product_id=parent, variation_id=child); deleted product id; variable-parent id; mismatched parent/variation pair; non-stock-managed product; `'stale'` case explicitly asserts `line === null` and no supplier query is issued (§13); returned `line` never contains qty/cost/sku keys; **end-to-end query-count assertion for the `'prefilled'` path at 0/1/10/50 historical suppliers — fixed total, never growing with supplier count (INV-16)**. |
| `tests/integration/reorder-prefill/test-po-admin-reorder-prefill-rendering.php` | BR-2,3,4,5,7,8,9,11,12 | All five §8 cases render the documented form state + notice (or lack thereof); each case distinguishable from the others (absent vs. malformed vs. invalid vs. stale vs. prefilled — no two collapse to the same observable output); `EDIT_PO`-gated activation (a `VIEW_PO`-only viewer sees the ordinary blank form regardless of GET params, no notice); Edit-PO screen with the same GET params present is unaffected; nothing persisted by the GET render. |
| `tests/integration/reorder-prefill/test-list-table-reorder-action-link.php` | BR-1,2,3,15; INV-13,14 | Link present/correct-href for simple + variation `needs_reorder` rows, only for an `EDIT_PO`-capable viewer; absent for a `VIEW_PO`-only viewer, absent for a viewer lacking `manage_woocommerce` entirely, absent on `covered_by_incoming` rows, absent on variable-parent rollup rows; badge markup unchanged; query count identical with/without the link at 5- and 60-product fixture scales. |
| `tests/integration/reorder-prefill/test-reorder-prefill-security-toctou.php` | BR-4,6,7; INV-15 | Tampered/mismatched params → `'invalid'`, graceful fallback, no fatal; omitted params → byte-identical baseline (case 1, no notice); stock changed between render and click → `'stale'`, reorder-specific prefill discarded, no supplier query issued; identical `WP_Error` code from `PO_Product_Validator` whether invoked at prefill time or submit time (no rule drift). |
| `tests/integration/reorder-prefill/test-capability-matrix.php` | BR-2; INV-14 | Consolidated pass across every M22 surface for `EDIT_PO` / `VIEW_PO`-only / neither; static check confirming every new `current_user_can()`-equivalent check in M22's diff routes through `Purchasing_Caps`, not a raw capability string. |

Regression re-runs at freeze (unmodified, must stay green): `tests/integration/reorder-signal/test-list-table-reorder-badges.php`, `tests/unit/purchase-orders/test-po-architecture.php`, `test-po-service.php`, `tests/unit/reorder-signal/test-reorder-signal-architecture.php` (extend its sole-classifier allowlist to include the new service).

## 19. Work packages

Executed continuously without stopping for approval between packages. Narrow/targeted validation per WP; one comprehensive pass at WP-M22-7 only.

**WP-M22-0 — Plan materialization.** Create `feature/m22-reorder-draft-po-quick-action` from verified `main`. Write this plan to `docs/milestones/m22-implementation-plan.md`. Commit alone, no validation (doc-only).

**WP-M22-1 — Repository layer.** Add `distinct_supplier_history_for_item()` (`purchase-order-lines.php`, committed-status filter, index-aware dual-path query per §5) and `list_by_ids()` + `is_eligible_for_selection()` (`suppliers.php`). Tests: the two new repository test files (§18), including the required 0/1/10/50-scale query-count assertions. Validate: those two files only. Stop condition: if achieving the query shape requires anything beyond the existing single-column indexes (i.e. a composite index would be needed for acceptable performance), stop and reassess — do not add a schema migration inside M22 without re-approval.

**WP-M22-2 — Reorder_Prefill_Service.** New file + `require_once` wiring. Implements `resolve()`'s five-state contract (§8), composing WP-1's bulk primitives with the existing M21/M3/Settings primitives. Test: `test-reorder-prefill-service.php`, including the fixed-query-count assertion for the `'prefilled'` path at 0/1/10/50 historical suppliers. Stop conditions: if `get_position()`'s single-item path issues more than 2 queries, stop (§13's bound is invalidated); if the `'prefilled'` path's total query count is found to vary with historical-supplier count, stop — WP-1's bulk fetch was not wired correctly, do not paper over it with a "typically small" justification.

**WP-M22-3 — PO_Admin prefill wiring.** Add `reorder_prefill_url()`; extend `render_detail()` to implement §8's five-case ordering gated by `Purchasing_Caps::EDIT_PO`; extend `render_header_fields()`, `render_lines_editor()` exactly per §15. `render_line_row()` untouched. Test: `test-po-admin-reorder-prefill-rendering.php`; regression-check `test-po-service.php`. Stop conditions: if wiring requires changing `render_line_row()`'s own signature, stop (§15's "no change needed" assumption needs re-verification); if any two of the five §8 status cases are found to produce indistinguishable rendered output, stop — that violates the explicit intent of unifying but *distinguishing* these cases.

**WP-M22-4 — List-table action link.** Change `render_reorder_signal_badge()` to accept `WC_Product $item`; add `render_reorder_action_link()`, gated by `Purchasing_Caps::EDIT_PO`. Test: `test-list-table-reorder-action-link.php`; regression-check `test-list-table-reorder-badges.php` (badge markup must stay byte-identical). Stop condition (hard gate): any new SQL query introduced — fix before proceeding, INV-M22-13 has zero tolerance here.

**WP-M22-5 — End-to-end + TOCTOU/security.** Test-only. `test-reorder-prefill-security-toctou.php`; extend the rendering test with a real `handle_save()` POST simulated after a prefilled GET render, asserting the created PO's product/variation match the prefill and no field carries an un-submitted value. Stop condition: any GET param found to influence `handle_save()`'s validation/authorization outcome — stop immediately (INV-M22-15 violation, requires re-design not a targeted fix).

**WP-M22-6 — Capability-matrix + query-count regression pass.** Test-only. `test-capability-matrix.php`; extend the architecture test with the "no new non-`Purchasing_Caps` capability check" static guard; consolidate and re-run every query-count assertion from WP-1/WP-2/WP-4 in one pass to guard against later drift. Validate: full `tests/*/reorder-prefill/` directory. Stop conditions (hard gates): any surface reachable without `EDIT_PO`; any query-count assertion regressing above its fixed bound.

**WP-M22-7 — Full validation, documentation, freeze (Level A, no release).** No production files. Docs: `CHANGELOG.md` entry, `CLAUDE.md` Implementation Status row, `docs/checklists/m22-release-readiness.md` (marked "Frozen, CI-green, joining a future combined M22+M23 release train — not tagged, not released, not deployed"). Version: bump plugin `Version:` header + version constant to **1.39.0**, matching confirmed repo convention (§21) — a documentation/version-string change only, not a release. Validation: see §20. Stop condition: any BR/INV unsatisfied — remediate within this WP's own scope only; do not merge/tag/release/deploy under any circumstance.

## 20. Final validation strategy (run once, at WP-M22-7 only)

1. Full unit suite — 0 failures.
2. Full M1–M22 focused suite (`FILTER_ARGS` extended with `Test_WC_IO_Reorder_Prefill_`) — 0 failures.
3. Full integration suite — 0 failures.
4. `--list-tests` proof every new M22 test class is picked up by the focused filter.
5. PHPCS lint clean on the full diff; delta check against the M21-frozen baseline.
6. `composer validate` clean.
7. `docker compose config` valid.
8. `scripts/release-audit.sh --development` (never `--release`).
9. Push branch, open a **draft** PR, obtain green GitHub Actions.
10. Level A completion review: BR/INV matrix verified, zero mutation confirmed, capability parity confirmed (`EDIT_PO`, not a borrowed check), zero new list-table queries confirmed, fixed supplier-resolution query count confirmed at scale, no scope leakage into M23 territory.

## 21. Documentation/version strategy

Confirmed directly against `CLAUDE.md`'s own release history: *"Intermediate development version `1.35.0` (M18 alone) was never tagged"* and *"Intermediate development versions `1.30.0`/`1.31.0` were never tagged"* — proving the established pattern is **bump the version header at each milestone's own freeze, defer only the git tag/GitHub Release to the train's final milestone**. WP-M22-7 therefore bumps to `1.39.0` and adds a `## [1.39.0] - Unreleased` CHANGELOG entry, matching this pattern exactly — this does not conflict with "no release for M22" (§25 is about tags/GitHub Releases/deploys, not the in-repo version string). `DB_VERSION` stays 11 regardless. The pre-existing `readme.txt` Stable-tag staleness (1.36.0 vs. actual 1.38.0) is confirmed pre-existing and unrelated — not fixed here.

## 22. Non-goals

No immediate/one-click creation without review. No demand-forecast/par-level/MOQ/pack-size/restock-quantity formula (candidate for M23, semantics not frozen — §23). No persistent preferred/default-supplier setting (candidate for M23). No new mutation entry point, admin-post action, AJAX endpoint, nonce, or token beyond what exists. No bulk "create drafts for all needs-reorder items" action (deferred, kept narrow — see §23). No dedup engine. No retrofit of the 4 existing duplicated supplier-eligibility predicates. No schema change, no new capability, no new public hook. No fix for the `readme.txt` staleness. No change to `render_line_row()`, `PO_Validation`, `PO_Product_Validator`'s rules, or any lifecycle-action handler (`place`/`cancel`/`close_short`/`duplicate`).

## 23. M22/M23 boundary — recommended M23 scope

**M23 — Replenishment Defaults.** Two candidate extensions, both building directly on M22's `Reorder_Prefill_Service` without requiring it to be re-architected, both schema-additive-only (new product meta, no PO/PO-line schema change), both naturally sequenced after M22 (nothing to consult until M22's resolution steps exist):

- **M23(a) — persistent per-product/variation preferred supplier.** `Reorder_Prefill_Service::resolve()`'s supplier-resolution step gains a first check: if a preferred supplier is configured *and* passes `is_eligible_for_selection()`, use it directly, skipping the history heuristic; otherwise fall back to exactly today's M22 logic. Straightforward — a boolean/id, not a rate or formula.
- **M23(b) — persistent per-product replenishment quantity.** **Deliberately left semantically open here, not frozen.** "Restock quantity" could mean a fixed reorder quantity, a target/par stock level (from which an order quantity would need to be derived relative to current position), or something else — these have materially different UX and validation implications and were not investigated during M22's planning pass. M23 must independently plan and decide this semantic before implementation; M22 provides only the extension point (an optional `qty_ordered` value `Reorder_Prefill_Service` could pass through to the prefilled line if configured), not the decision itself.

**Recommend keeping M23 to exactly these two candidate extensions**, with (b)'s exact semantics resolved during M23's own planning pass. A bulk "create drafts for all needs-reorder items, grouped by supplier" action is a materially larger, riskier capability (multi-item transaction semantics, partial-failure handling, a genuinely new UI surface) that risks becoming its own oversized milestone; log it as a candidate for M24+, not M23.

## 24. M22 freeze criteria

All WPs (0–7) complete with individual commits. Full unit/focused/integration suites green. `--list-tests` proof. PHPCS/`composer validate`/`docker compose config` clean. `release-audit.sh --development` passes. GitHub Actions green on an unmerged draft PR. Level A review passed with written confirmation of: zero mutation, ownership invariants, `EDIT_PO` capability parity (not a borrowed check), zero new list-table queries, fixed (not N+1) supplier-resolution query count proven at 0/1/10/50-supplier scale, stale-state fail-closed behavior proven, and no M23 scope leakage. `docs/checklists/m22-release-readiness.md` created and explicitly marked unreleased. Working tree clean; plan file unmodified since WP-M22-0. **Not required**: tag, merged PR, GitHub Release, deploy.

## 25. Release-train strategy

No tag/Release/deploy/merge for M22 standalone — draft PR stays open. M23 branches from M22's frozen feature-branch tip (not `main`), mirroring the exact precedent of M9→M10→M11→M12 and M18→M19. One combined release workflow runs once, after M23 also freezes, covering both. None of the repo's release triggers (schema change, migration, public API change, ownership-boundary change, storefront behavior change, security fix, breaking change) apply to M22.

## 26. Risks / deviations

- `readme.txt` Stable-tag staleness: pre-existing, unrelated, flagged only.
- Version-bump-at-freeze (§21) deviates from a plain reading of "M22 must not be released" but is confirmed correct against actual repo convention (verified via `CLAUDE.md`'s own release-history text) — a version-string bump is not a release/tag/deploy.
- M23(b)'s exact quantity semantics (fixed reorder quantity vs. par/target level) is explicitly left open for M23's own planning (§23) — not a gap in M22, a deliberate scope boundary.
- All other open questions from the original research passes (`render_detail()`/`render_header_fields()`/`render_lines_editor()`/`render_line_row()` exact signatures and defaults; absence of `WP_List_Table::row_actions()`; absence of a pre-existing `Suppliers::list_by_ids()`; exact table indexes; the `VIEW_PO`/`EDIT_PO` capability split) were fully resolved by direct source verification across two review passes — no unresolved TBDs remain.

## 27. Exact next operation after plan approval

1. `git checkout main && git pull` — re-verify HEAD/origin/tag match, clean tree.
2. `git checkout -b feature/m22-reorder-draft-po-quick-action`.
3. Write this plan's full content to `docs/milestones/m22-implementation-plan.md`.
4. Commit that file alone: `docs(m22): materialize approved implementation plan`.
5. Begin WP-M22-1 through WP-M22-7 continuously, stopping only at an explicit per-WP stop condition.

## Verification

- Unit/integration tests per §18/§19 run at each WP via the repo's existing PHPUnit/Docker test runner (`tests/docker/run-phpunit.sh`), following the established `tests/unit/<slug>/`, `tests/integration/<slug>/` convention with no `@group` annotations.
- Query-count regressions (INV-M22-13, INV-M22-16, WP-M22-4/6's hard gates) verified via the same query-counting pattern M21 already established for its own list-table tests, extended with the new 0/1/10/50-historical-supplier scale points for the prefill path.
- Full freeze validation per §20, run once at WP-M22-7.
- **Final acceptance is AI-driven runtime execution, not a human manual-smoke step** — mirroring the repo's own established post-release practice (per `CLAUDE.md`: M20 and M21 both used "AI-driven dev acceptance... real WordPress admin/runtime execution against dev.biopentra.eu, browser automation unavailable"). After WP-M22-7 freezes (still pre-release, on the feature branch — this is acceptance testing, not a release action), run the equivalent `wp eval-file`-driven exercise of the new surfaces against the dev WordPress runtime: confirm the link renders only for `needs_reorder` rows and only for `EDIT_PO`-capable users, confirm each of §8's five status cases produces its documented form state when the New PO screen is loaded with the corresponding GET params, and confirm a simulated submission after a `'prefilled'` load produces a draft PO whose line matches. No browser automation or human click-through is required or expected.

M22 PLANNING COMPLETE — READY FOR REVIEW
