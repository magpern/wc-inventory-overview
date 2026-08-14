# M23 Definitive Implementation Plan — Replenishment Defaults

## Context

M22 "Reorder → Draft Purchase Order Quick Action" closed the gap between M21's read-only `needs_reorder` classification and actually creating a PO, but it deliberately supplies no merchant-authored defaults: the New PO prefill it produces carries only product/variation identity, and its supplier suggestion is derived solely from committed purchase history (fixed at 2 queries, never scaling with historical-supplier count — a frozen, tested invariant, INV-M22-16). M22's own plan explicitly deferred two candidate extensions to a future milestone rather than guessing at their semantics mid-implementation: a persistent preferred supplier, and a persistent replenishment quantity. M23 resolves both, making the M22 workflow materially faster for repeat purchasing without turning the plugin into an automatic purchasing engine — every value M23 introduces is a suggestion the merchant can see, edit, or ignore before a PO is ever created. M22 and M23 are one feature train; M23 branches from M22's frozen tip (not `main`), and the two milestones release together once, later.

All facts below were verified directly against `/opt/biopentra/dev/wc-inventory-overview` on the frozen M22 branch tip — repository/train baseline audit, full read of the actual `Reorder_Prefill_Service`/`PO_Admin`/`Suppliers`/`Purchasing_Caps`/`PO_Quantities` code (not summaries), an exhaustive grep sweep confirming no preferred-supplier or default-quantity concept exists anywhere in the codebase today, an audit of admin-screen/schema/capability conventions, and a design-validation pass that corrected one of my own performance assumptions against actual WordPress/WooCommerce meta-cache internals and surfaced three concrete implementation gotchas (dropdown pagination truncation, a silent-clobber hazard on stale preferred suppliers, and WooCommerce's index-arrayed variation field-naming requirement) before they could become bugs.

## 1. Executive recommendation

Implement **both** candidate extensions — persistent preferred supplier (M23a) and persistent default replenishment quantity (M23b) — as two independent, orthogonal, optional per-item settings, stored as WordPress product/variation postmeta (no schema change, `DB_VERSION` stays 11), configured on the WooCommerce Product edit screen (Inventory tab) and Variation panel (new integration surface for this plugin — no existing precedent to extend), and consumed by one narrow modification to `Reorder_Prefill_Service`'s private supplier-resolution step plus one new key in its prefilled line array. Quantity is a **fixed order quantity**, never a target/par-stock-level derivation — that would require new position-relative arithmetic this plan explicitly rejects as forecasting-adjacent scope creep. No STOP condition is triggered.

## 2. Verified repository/train baseline

- `main`/`origin/main`: `7f300d556911960faa89d05d02fb8889c1a07992` (v1.38.0 released), untouched.
- `feature/m22-reorder-draft-po-quick-action`: local and origin HEAD both exactly `1716e3231d6caa229c5bf25ab6c98471c0f05cf7`; PR #29 confirmed `state: OPEN, isDraft: true, mergedAt: null`.
- `docs/milestones/m22-implementation-plan.md` and `docs/checklists/m22-release-readiness.md` both exist on that tip; plan doc ends "M22 PLANNING COMPLETE — READY FOR REVIEW", immutable since its own WP-M22-0 commit.
- Version at M22 tip: plugin header + `WC_INVENTORY_OVERVIEW_VERSION` = `1.39.0`. `DB_VERSION = '11'` (`class-wc-inventory-overview-install.php:15`), comment "Unchanged in M18/M19/M20/M21/M22".
- No M23 branch anywhere in `git branch -a`; no `docs/milestones/m23-*` file on `main` or the M22 branch; `git grep -il m23` on both returns only forward-looking narrative text (roadmap notes, "M23 will branch from here" statements), zero production code.

## 3. M22 extension-seam findings

`Reorder_Prefill_Service::resolve( int $product_id, int $variation_id = 0 ): array` is a **4-state** contract (not 5 — corrected from an earlier assumption): `status: 'malformed'|'invalid'|'stale'|'prefilled'`, `line: array{product_id,variation_id,name_snapshot,sku_snapshot}|null`, `supplier_id: int`, `notices: array`. On `'prefilled'`, the private `resolve_supplier( int $product_id, int $variation_id ): array{0:int,1:array}` runs exactly:

```php
$supplier_ids = Purchase_Order_Lines::distinct_supplier_history_for_item( $product_id, $variation_id ); // 1 query, committed statuses only
if ( empty( $supplier_ids ) ) return array( 0, array( notice_no_supplier() ) );
$supplier_rows = Suppliers::list_by_ids( $supplier_ids ); // 1 query, bulk
$eligible = array_filter(..., Suppliers::is_eligible_for_selection(...)); // active AND !merged_into_supplier_id
if ( empty( $eligible ) ) return array( 0, array( notice_no_supplier() ) );
if ( 1 === count( $eligible ) ) return array( $eligible[0], array() );
return array( 0, array( notice_multiple_suppliers() ) ); // never auto-picks among ambiguous multiple
```

Fixed at exactly 2 queries, independent of historical-supplier count (INV-M22-16). `render_lines_editor()` passes `$prefill_line` into `render_line_row( $prefill_line, 0, true )` only when the lines array is empty; `render_line_row()`'s defaults for an unset key are `qty_ordered ?? '1'` (editable) / `'0'` (read-only), `unit_cost ?? '0'`, `supplier_sku ?? ''` — inputs are `type="number" step="0.0001" min="0"`, matching the `decimal(19,4)` DB column. `PO_Quantities::validate_quantities()` requires `qty_ordered > 0` (zero/negative rejected, decimals to 4dp allowed, no upper bound) — this is PO-line validation, unmodified by M23, but the exact rule M23's own quantity-default validation must mirror. `Purchasing_Caps` has 15 action constants, all mapping to `manage_woocommerce` by default but individually filterable; no constant exists for "manage replenishment defaults," and none will be added (§16). `Suppliers` already exposes `get()`, `list_by_ids()`, `is_eligible_for_selection()`, `get_for_update()` — everything M23 needs to reuse, nothing to add there.

## 4. Existing product/supplier metadata findings

Confirmed via exhaustive grep across `includes/` and `tests/`: **no** `preferred_supplier`, `default_supplier`, per-product `_supplier_id` meta, `reorder_qty`, `restock_qty`, `target_stock`, `par_level`, `min_stock`, `moq`, `pack_size`, `case_qty`, or `safety_stock` concept exists anywhere. `supplier_sku` exists only as a per-PO-line free-text field, not a per-product default. The strongest existing precedent is `Settings::get_effective_low_stock_amount( WC_Product $product )`: reads WooCommerce core's own `_low_stock_amount` product field first, falls back to a plugin-wide option — a useful "explicit-override-else-global-default" *shape*, but it doesn't transfer directly since no WC-core field exists for "preferred supplier" or "default quantity." This plugin has **zero** existing precedent for adding fields to the WooCommerce Product/Variation edit screen (zero hits for `woocommerce_product_options_*`, `woocommerce_process_product_meta`, `woocommerce_save_product_variation`, `add_meta_box`) and **zero** precedent for a per-product-meta admin-form save pattern — both are genuinely new integration surfaces for M23, built on well-documented, standard WooCommerce extension hooks rather than anything plugin-specific. `Suppliers::list()` defaults to `per_page = 20` — any new supplier dropdown must explicitly pass `per_page => 200` (matching `PO_Admin::render_header_fields()`'s existing call) or it will silently truncate.

## 5. Preferred-supplier semantic decision

An explicit, merchant-authored, per-purchasable-item preference: "use this supplier by default when replenishing this item." Never inferred, never learned, never the most-recent/most-frequent/cheapest historical supplier — those remain M22's own unchanged history heuristic, used only as a fallback.

## 6. Preferred-supplier persistence decision

WordPress product/variation postmeta, key `_wc_io_preferred_supplier_id` (absint; `0`/absent = unset). No new table, no schema change — a single scalar per item needs no relational querying. `DB_VERSION` stays 11; `create_tables()`/`expected_schema_vN()` untouched.

## 7. Product/variation ownership decision

**No parent-to-variation inheritance.** Each purchasable item — a simple product's own post, or a variation's own post — stores its preference independently, using exactly M22's existing identity convention (`$item_post_id = $variation_id > 0 ? $variation_id : $product_id`). A variable parent is structurally non-purchasable (`PO_Product_Validator` rejects it, INV-M22-12) and never stores either value.

This was deliberately pressure-tested against the alternative (parent-level default with per-variation override) and rejected for three reasons: (1) storing purchasing configuration on an entity that can never appear on a PO line is an ownership smell; (2) inheritance would require its own provenance notice to avoid being exactly the "silent inheritance" this milestone is warned against; (3) directionality — adding inheritance later is additive/backward-compatible (an unset variation would start resolving to a parent value, a behavior change only for configurations that are currently no-ops), while removing it later would be a breaking change. The per-variation tedium this creates for merchants with many variations of one product is real but bounded, and its natural remedy is a bulk "apply to all variations" convenience *write* action — logged as a non-goal for a future milestone (§31), not solved by read-time fallback.

## 8. Supplier save-time validation

`WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( int $item_post_id, int $supplier_id ): true|WP_Error`. `$supplier_id === 0` clears the meta (explicit merchant-initiated clear). A non-zero id must resolve via `Suppliers::get()` to a currently eligible supplier (`is_eligible_for_selection()`), else `WP_Error`, no write — **except** one case: resubmitting the value already stored is accepted as a no-op even if that supplier has since become ineligible. This exception exists specifically to close a silent-clobber hazard: without it, a supplier dropdown built only from active suppliers wouldn't contain an already-stored-but-now-archived id, so it would render as unselected, and saving any unrelated product field would silently reset the preference to 0. The mitigation has two parts: the dropdown injects the stored value as an extra, clearly labeled "(unavailable)" option when it isn't otherwise eligible, and the save path treats an unchanged resubmission as a no-op rather than a new selection.

## 9. Supplier use-time validation

Every prefill re-validates via `Suppliers::get()` + `is_eligible_for_selection()` regardless of what passed at save time — use-time is authoritative, since a supplier valid when configured can go stale later (archived, merged) with no signal back to the stored preference. A stale preferred supplier is **never auto-cleared or rewritten** during a read/prefill — no hidden writes on GET, matching M22's own zero-mutation-on-GET invariant. It is simply skipped, with a notice, and the item falls through to M22's unchanged history algorithm.

## 10. Supplier precedence/fallback algorithm

Inside `resolve_supplier()`'s replacement, only on the `'prefilled'` branch:

```php
$item_post_id = $variation_id > 0 ? $variation_id : $product_id;
$preferred_supplier_id = Replenishment_Defaults::get_preferred_supplier_id( $item_post_id );

if ( $preferred_supplier_id > 0 ) {
    $supplier_row = Suppliers::get( $preferred_supplier_id );
    if ( $supplier_row && Suppliers::is_eligible_for_selection( $supplier_row ) ) {
        return array( $preferred_supplier_id, array() ); // history query never runs
    }
    list( $supplier_id, $history_notices ) = <unchanged M22 history algorithm>;
    return array( $supplier_id, array_merge( array( notice_preferred_supplier_stale() ), $history_notices ) );
}

return <unchanged M22 history algorithm>; // byte-for-byte M22 behavior when no preference configured
```

No notice is emitted on the happy path (valid preferred supplier used directly) — consistent with M22's existing pattern of only surfacing notices for imperfect/ambiguous states, not full successes.

## 11. Quantity semantic analysis

Compared against the plan brief's four options. Target/par-stock-level (Option B) was rejected: it requires deriving a suggested order quantity from `target − current position`, which is new arithmetic layered on top of M21's frozen Position/classification primitives, risks conflating a merchant-configured *target* with M21's *threshold*, and is exactly the kind of "introduce forecasting merely to make quantity dynamic" the brief warns against. No-quantity-in-M23 (Option C) was rejected because the domain support is clean and complete — `qty_ordered` already accepts positive decimals to 4dp with no upper bound, and `render_line_row()` already has an unused prefill slot (`?? '1'`) ready to receive a configured value with zero changes to that method.

## 12. Quantity final decision

**Fixed merchant-authored default replenishment quantity** (Option A). A single stored number, used verbatim as the prefilled `qty_ordered`, never combined with on-hand, incoming, or threshold. Fully editable; any submitted POST value wins.

## 13. Quantity validation contract

`WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( int $item_post_id, $raw ): true|WP_Error`. Blank/empty clears the meta. Otherwise: must be numeric and `> 0` — the exact rule `PO_Quantities::validate_quantities()` already applies to `qty_ordered`, reused rather than reinvented; zero, negative, and non-numeric all reject with `WP_Error`, no write. Stored normalized via `wc_format_decimal( $raw, 4 )` (WooCommerce's own decimal-separator-aware normalizer) so the value flowing into `render_line_row()`'s `value="…"` slot is exact and free of locale/float noise. No upper bound, matching the PO line's own rule.

## 14. Persistence/schema decision

Postmeta only (§6), for both values. Evaluated against a new table/column: neither value needs relational querying (no cross-item search, no reporting join), a single-item lookup is the only access pattern, and postmeta requires no `DB_VERSION` bump, no `create_tables()`/`expected_schema_vN()` change, and no migration — a strictly lower-risk, lower-effort choice for genuinely scalar per-item configuration. `DB_VERSION` stays 11.

## 15. Configuration UX

WooCommerce Product Data → **Inventory tab** for simple products (`woocommerce_product_options_stock_fields` render hook, `woocommerce_process_product_meta` save hook) and the standard **Variation panel** for variations (`woocommerce_product_after_variable_attributes` render hook, `woocommerce_save_product_variation` save hook, index-arrayed field names `_wc_io_preferred_supplier_id[<loop>]` / `_wc_io_default_replenishment_qty[<loop>]`, read via `$_POST[...][ $i ]` in the save callback — getting this wrong silently makes every variation write the last row's value). This is the natural home for per-product purchasing configuration (adjacent to where `_low_stock_amount` already lives) despite being a new integration surface for this plugin; it uses only standard, well-documented WooCommerce extension conventions, not invented ones. Variation field labels explicitly state "applies to this variation only" to reinforce §7's no-inheritance decision. Supplier field is a plain `<select>` populated via `Suppliers::list(['status' => 'active', 'per_page' => 200, 'orderby' => 'name', 'order' => 'ASC'])` (mirroring `PO_Admin::render_header_fields()` exactly) plus the injected "(unavailable)" stored-value option when applicable (§8) — not the existing `assets/supplier-picker.js` autocomplete, which is shaped for free-text supplier-name entry in Batch Intake/Restock, a different data model. Quantity field is a plain number input, `step="0.0001" min="0"`. A new orchestration class wires both hook pairs and calls into the persistence class; it renders no SQL and owns no validation logic itself.

## 16. Capability/security contract

Both render and save gate on WooCommerce **core's own product-edit capability**, `current_user_can( 'edit_product', $item_post_id )` — not `Purchasing_Caps`. This is a product-editing action, not a purchasing action: a user could hold product-edit rights with zero purchasing capability, or vice versa, so borrowing `Purchasing_Caps::EDIT_PO`/`MANAGE_SUPPLIERS` would be semantically wrong. It also avoids a genuine operational hazard: `Purchasing_Caps` is filterable per-site (`wc_io_purchasing_capability_map`); a site that narrows purchasing to a small group would otherwise let ordinary product editors see a field that silently fails to save. Gating render and save on the identical WC-core check eliminates that render/save asymmetry entirely. Concretely: for simple products, WooCommerce core already checks nonce + `current_user_can('edit_post', $post_id)` before `woocommerce_process_product_meta` fires, so the plan's own defensive `current_user_can('edit_product', $id)` inside the save handler is belt-and-suspenders; for variations, `WC_AJAX::save_variations()` only checks the *general* `edit_products` capability (not per-post), so the same defensive per-item check inside the variation save handler is a **real, non-redundant** added check and must not be skipped. No new nonce is introduced — WooCommerce's own product-save and variation-save nonces already cover both hooks. No new `Purchasing_Caps` constant is added.

## 17. Mutation ownership

`WC_Inventory_Overview_Replenishment_Defaults` is the sole owner of reads and writes for both meta keys — no other class calls `get_post_meta`/`update_post_meta`/`delete_post_meta` with `_wc_io_preferred_supplier_id` or `_wc_io_default_replenishment_qty`. `Reorder_Prefill_Service` remains strictly read-only (mechanically enforced today by M22's own `test_zero_mutation_shaped_tokens` guard, which forbids `update_post_meta(` in that file — this guard's coverage is inherited unchanged, not weakened). `PO_Service::create_draft()` remains the sole PO-creation mutation path, untouched.

## 18. Final M23-enhanced prefill algorithm

Only the `'prefilled'` branch of `resolve()` changes. Steps 1–4 (malformed/invalid/stale checks, `needs_reorder` recomputation) are byte-for-byte unchanged from M22. On reaching `'prefilled'`:

```php
$item_post_id = $variation_id > 0 ? $variation_id : $product_id;
list( $supplier_id, $supplier_notices ) = self::resolve_supplier_with_preference( $product_id, $variation_id ); // §10

$default_qty = Replenishment_Defaults::get_default_qty( $item_post_id );
if ( $default_qty > 0 ) {
    $line['qty_ordered'] = (string) $default_qty; // flows into render_line_row()'s existing ?? '1' slot
}

return self::result( 'prefilled', $line, $supplier_id, $supplier_notices );
```

## 19. Stale/concurrent behavior

Preferred supplier archived/merged after being configured → use-time check in §9/§10 catches it on every subsequent prefill, no auto-repointing to a merge target, no auto-clear. Quantity default is a static value with no eligibility concept, so it has no analogous staleness case — it is either present and positive, or absent, checked fresh on every prefill. Two merchants with the New PO screen open concurrently: the existing POST remains fully authoritative (§20); no locking/CAS is introduced, matching M22's own "no new concurrency surface" precedent. A configuration change made while another merchant's New PO tab is already open simply has no effect on that already-rendered page — expected, unremarkable browser-tab semantics, not a defect.

## 20. Editability/POST-authority contract

Every M23-sourced prefill value (`supplier_id`, `qty_ordered`) remains a fully editable form field; the submitted POST value is what `handle_save()` → `PO_Service::create_draft()` actually persists, exactly as today. Configuration values never bypass or pre-empt `PO_Validation`/`PO_Quantities`/`PO_Product_Validator`/`create_draft()`'s own lock-and-revalidate step.

## 21. Query/performance contract

Corrected against actual WordPress/WooCommerce internals (an earlier assumption that reading two new meta keys would add a query was wrong): `PO_Product_Validator::validate()` loads the item via `wc_get_product()`, and WooCommerce's own product/variation data stores (`WC_Product_Data_Store_CPT::read_product_data()`, `WC_Product_Variation_Data_Store_CPT`) already call the whole-post form of `get_post_meta( $id )` while loading the product object — which primes the entire post's meta cache in one query. By the time `Reorder_Prefill_Service` reads the two new M23 meta keys on that same post id, the cache is already primed, so **the meta reads are free** in the normal case (a persistent-object-cache configuration that serves the product object without a meta-cache-priming read would legitimately cost +1 — the contract is phrased as a bound, not exact equality, for this reason).

| Case | Supplier queries (prefilled branch) | vs. M22 |
|---|---|---|
| No preference configured | 2 (history + bulk fetch, unchanged) | **identical — INV-M22-16 preserved literally** |
| Valid preferred supplier | 1 (`Suppliers::get()`, history/bulk skipped) | −1, cheaper |
| Stale/invalid preferred supplier | 3 (`Suppliers::get()` + history + bulk) | +1 |

In every case the total is fixed and **invariant with respect to historical-supplier count** — tested at 0/1/10/50 historical suppliers on all three branches (unconfigured / valid preference / stale preference), asserting bounds and cross-scale invariance rather than a single hardcoded number. List-table (Inventory Overview) query count is **unaffected** — M23 touches no code that runs during list-table rendering; INV-M22-13's zero-delta invariant is a different code path entirely and is re-proven unmodified at WP-M23-6.

## 22. Exact production ownership / files

| File | Change |
|---|---|
| `includes/class-wc-inventory-overview-replenishment-defaults.php` | **New.** `WC_Inventory_Overview_Replenishment_Defaults`: meta-key constants, `get_preferred_supplier_id()`, `get_default_qty()`, `save_preferred_supplier()`, `save_default_qty()`. No SQL, no rendering, no hooks. |
| `includes/class-wc-inventory-overview-product-replenishment-admin.php` | **New.** Wires `woocommerce_product_options_stock_fields`/`woocommerce_process_product_meta` (simple) and `woocommerce_product_after_variable_attributes`/`woocommerce_save_product_variation` (variation); builds the supplier `<select>` + qty input; defensive `current_user_can('edit_product', $id)` in both save handlers (§16); surfaces `WP_Error` via WC admin notices. `::init()` registered behind `is_admin()`. |
| `includes/class-wc-inventory-overview-reorder-prefill-service.php` | `resolve_supplier()` restructured internally per §10/§18; public `resolve()` contract shape unchanged. Only production M22 file touched. |
| `wc-inventory-overview.php` | + two `require_once` entries for the new files, ordered after `Suppliers`/`PO_Quantities`, before `Reorder_Prefill_Service`'s own require. |
| `tests/docker/run-phpunit.sh` | At freeze only: extend `FILTER_ARGS` with the M23 test-class pattern. |

No other production file is touched. `PO_Service`, `PO_Validation`, `PO_Product_Validator`, `Purchase_Orders`, `Purchase_Order_Lines`, `Inventory_Position_Service`, `Reorder_Signal_Resolver`, `Settings`, `render_line_row()` are all read-only, unmodified dependencies.

## 23. BR-M23 matrix

1. A merchant may configure, per purchasable item, an explicit preferred supplier and/or a fixed default replenishment quantity. Both independent, both optional.
2. Precedence: on `'prefilled'`, a configured and currently eligible preferred supplier is used and the M22 history heuristic is not run at all.
3. No preferred supplier configured → supplier resolution is byte-for-byte M22 behavior, including all M22 notices and query count.
4. Configured but ineligible preferred supplier → falls back to the full unchanged M22 history algorithm, plus a distinct "preferred supplier no longer available" notice.
5. Eligibility is always `Suppliers::is_eligible_for_selection()`; M23 defines no second eligibility rule.
6. Save-time: a newly chosen preferred supplier id must resolve to an existing, currently eligible supplier, else `WP_Error`, no write.
7. Save-time exception: resubmitting the currently stored value unchanged is accepted as a no-op even if that supplier has since become ineligible (prevents silent clobber).
8. Use-time eligibility is re-validated on every prefill regardless of save-time validation; use-time is authoritative.
9. An archived, merged, or nonexistent preferred supplier is never preselected and never auto-cleared from storage.
10. Supplier id `0`/empty submission clears the preference — an explicit clear, distinct from BR-9's non-clearing of stale values.
11. Each variation carries its own defaults; no parent→variation inheritance, no variation→parent rollup; a variable parent stores neither value.
12. Item identity for storage is exactly M22's convention (variation → own post id; simple product → own post id).
13. Quantity semantics are a fixed order quantity, used verbatim — never combined with position, on-hand, incoming, or threshold.
14. Quantity validation: numeric, `> 0`, up to 4 decimals, no upper bound (reuses `PO_Quantities::validate_quantities()`'s rule); zero/negative/non-numeric → `WP_Error`, no write.
15. Blank/empty quantity submission clears the default.
16. A configured quantity only populates the prefilled line's `qty_ordered`; remains fully editable; a submitted POST value wins; absent a configured quantity, `render_line_row()`'s existing `?? '1'` default applies unchanged.
17. The New PO GET render remains non-mutating: reading defaults never writes; a stale preferred supplier never triggers a cleanup write.
18. The only new mutation surface is the product/variation configuration save; no new admin-post action, AJAX endpoint, or nonce.
19. Capability: fields render and save iff `current_user_can('edit_product', $item_post_id)`, checked identically at render and save; no `Purchasing_Caps` constant governs this surface.
20. M23 changes nothing about M21 classification, M22's four-state contract shape, PO creation/placement, stock, or cost; configuring defaults never creates or places a PO.

## 24. INV-M23 matrix

1. `Reorder_Signal_Resolver` remains sole owner of the `needs_reorder` comparison.
2. `Inventory_Position_Service` remains sole owner of Position; M23 performs no position arithmetic.
3. `Reorder_Prefill_Service` remains read-only (mechanically enforced by M22's existing `test_zero_mutation_shaped_tokens` guard, inherited unmodified).
4. `PO_Service::create_draft()` remains the sole PO-creation mutation path.
5. A preferred supplier never bypasses M17 dissolution semantics — archived/merged excluded at both save time and use time via the one shared `is_eligible_for_selection()` predicate.
6. A stale preferred supplier is never silently auto-cleared, rewritten, or repointed at a merge target.
7. With both defaults unset, `resolve()` produces output identical to M22 in status, line, supplier_id, notices, and query count.
8. `resolve()`'s external return shape (4 statuses, 4 keys) is unchanged; only a new notice value is added to the existing `notices` array.
9. Defaults never override a submitted POST value; submit-time validation is unaffected by what was prefilled.
10. A variable parent never stores replenishment defaults and never becomes purchasable.
11. Item identity convention matches M22's exactly; no third convention introduced.
12. `Replenishment_Defaults` is the sole owner of reads/writes for both meta keys.
13. No SQL in `Replenishment_Defaults` or the admin orchestration class; supplier lookups route through `Suppliers`.
14. No stock mutation and no cost mutation/calculation anywhere in M23 code.
15. No new public hook/filter introduced.
16. Inventory Overview list-table query count is unchanged at every fixture scale; M23 code never executes during list-table rendering.
17. Prefill supplier-resolution query count is bounded (≤3) and invariant with respect to historical-supplier count at 0/1/10/50 scale, on all three branches.
18. With no preference configured, prefill query count is exactly M22's unmodified 2.
19. No schema change; `DB_VERSION` stays 11; `create_tables()`/`expected_schema_vN()` untouched.
20. No new capability constant; render and save use the identical WC-core product-edit gate (no render/save asymmetry).

## 25. Characterization/test matrix

New feature-slug directory `replenishment-defaults`, following the confirmed `tests/{unit,integration}/<slug>/` convention (20 existing precedents, e.g. `tests/unit/reorder-prefill/`).

`tests/unit/replenishment-defaults/`
- `test-replenishment-defaults-architecture.php` — INV-3,12,13,14,15,19,20: sole meta-key ownership grep; no `$wpdb` in the two new files; no new `admin_post_`/`wp_ajax_` registration; no `apply_filters`/`do_action` introduced; `update_post_meta(` absent from the prefill service (extends the existing M22 guard's assertion, doesn't weaken it); capability checks are the WC product gate, not `Purchasing_Caps`; `DB_VERSION` string still `11`.
- `test-replenishment-defaults-validation.php` — BR-6,7,10,14,15: quantity accept/reject table (0, negative, non-numeric, 4dp, no-upper-bound); blank clears; supplier `0` clears; nonexistent/archived/merged rejected; stale-resubmit accepted as no-op.

`tests/integration/replenishment-defaults/`
- `test-m22-supplier-fallback-characterization.php` — written **first, before any production change** (WP-M23-1): pins all three of M22's existing history branches, exact notice strings, and exact query counts at 0/1/10/50 historical suppliers, and `render_line_row()`'s `qty_ordered ?? '1'` default — must stay green, unmodified, through the rest of the milestone.
- `test-replenishment-defaults-persistence.php` — BR-1,11,12: round-trip on a simple product and on a variation; parent meta untouched by variation writes and vice versa; two variations of one parent independent; unset returns `0`/`0.0`.
- `test-preferred-supplier-prefill.php` — BR-2,3,4,5,8,9; INV-5,6,7,8,17,18: valid preference wins, history not queried; unconfigured path identical to M22; stale preference falls back plus notice; storage unchanged after rendering a stale preference; a preference pointing at a merge source is never preselected.
- `test-default-quantity-prefill.php` — BR-13,16; INV-9: configured qty appears in the prefilled line; absent qty yields `'1'`; decimals render exactly; qty is not populated on `stale`/`invalid`/`malformed`; a submitted POST value overrides the prefilled value at actual submit.
- `test-product-replenishment-admin.php` — BR-18,19; INV-20: fields render on the simple-product Inventory tab and on the variation panel, not at variable-parent level; save round-trip through both WC hooks; index-arrayed variation field names verified with 3+ variations; `WP_Error` surfaces without persisting; saving an unrelated product field preserves an already-archived stored supplier (silent-clobber regression guard); a >20-supplier fixture proves the dropdown isn't truncated.
- `test-replenishment-defaults-capability.php` — BR-19; INV-20: a user without `edit_product` sees no fields and cannot save via either hook; render and save gates are identical; changing the `Purchasing_Caps` filter map has no effect on this surface.
- `test-replenishment-defaults-performance.php` — INV-16,17,18: the 2-axis query matrix (0/1/10/50 historical suppliers × unconfigured/valid/stale preference) asserted as bounds + cross-scale invariance; list-table zero-delta re-proof at 5/60 products.

Regression re-runs at WP-M23-5/7 (unmodified, must stay green): `tests/integration/reorder-prefill/*`, `tests/integration/reorder-signal/*`, `tests/unit/purchase-orders/*`, `tests/integration/supplier-merge/*` (M17), `tests/unit/reorder-prefill/test-reorder-prefill-architecture.php`.

## 26. WP-M23-0…7 implementation breakdown

Executed continuously, narrow/targeted validation per WP; one comprehensive pass at WP-M23-7 only.

**WP-M23-0 — Preflight + plan materialization.** Branch `feature/m23-replenishment-defaults` from verified `1716e3231d6caa229c5bf25ab6c98471c0f05cf7` (not `main`). Confirm clean tree, inherited M22 suites green as baseline, `Suppliers::list()`'s `per_page` default, M22's existing guard file lists. Write this plan to `docs/milestones/m23-implementation-plan.md`, commit alone. Stop if any inherited M22 test is red before a single line of M23 code exists.

**WP-M23-1 — Characterization tests (before any modification).** `test-m22-supplier-fallback-characterization.php` only, pinning current `resolve_supplier()`/`render_line_row()` behavior. Stop if any characterization assertion can't be written without first touching production code.

**WP-M23-2 — Domain/persistence class.** New `class-wc-inventory-overview-replenishment-defaults.php`; `require_once` wiring. Tests: `test-replenishment-defaults-architecture.php` (partial), `test-replenishment-defaults-validation.php`, `test-replenishment-defaults-persistence.php`. Stop if any getter requires a supplier query (it must not — eligibility is a use-time concern of the caller, not the storage class).

**WP-M23-3 — Configuration UI + save orchestration + security.** New `class-wc-inventory-overview-product-replenishment-admin.php`; hook wiring per §15/§16, including the "(unavailable)" stored-value dropdown injection (§8) and index-arrayed variation field names. Tests: `test-product-replenishment-admin.php`, `test-replenishment-defaults-capability.php`. Stop if the variation panel can't render/save per-variation without touching parent meta.

**WP-M23-4 — `Reorder_Prefill_Service` integration.** Restructure `resolve_supplier()` per §10/§18. Only production M22 file touched. WP-M23-1's characterization test must stay green unmodified. Tests: `test-preferred-supplier-prefill.php`, `test-default-quantity-prefill.php`. Stop if the no-preference path's query count moves at all from M22's 2.

**WP-M23-5 — Edge cases + M17/M21/M22 regression + concurrency.** Preferred supplier archived/merged/deleted between save and prefill; preference pointing at a merge source; variation whose parent has values (must be ignored, §7); non-stock-managed/deleted items; qty configured on a `stale`/`invalid` item (must not leak). Re-run `reorder-signal`, `reorder-prefill`, `supplier-merge`, `purchase-orders` suites unmodified. Stop if any M17 merge test needs modification.

**WP-M23-6 — Performance + architecture guards.** Complete `test-replenishment-defaults-architecture.php`; `test-replenishment-defaults-performance.php` (2-axis query matrix + list-table zero-delta re-proof). Stop if any cell scales with historical-supplier count.

**WP-M23-7 — Docs/version/freeze/CI (no further production files).** Version `1.39.0` → `1.40.0` (header + constant); `DB_VERSION` untouched at 11; `## [1.40.0] - Unreleased` CHANGELOG entry; `CLAUDE.md` Implementation Status row; `docs/checklists/m23-release-readiness.md`; brief admin-facing note on per-variation independence. Full validation per §27. Stop condition: any BR/INV unsatisfied — remediate within this WP's own scope; do not merge/tag/release/deploy under any circumstance.

## 27. Validation strategy

Per-WP: narrow/targeted PHPUnit filter runs only (`--filter '<Class>'`), fix failures immediately, continue — no full-suite runs between WPs. Once, at WP-M23-7: full unit suite; full M1–M23 focused suite (`FILTER_ARGS` extended with the M23 test-class pattern); full integration suite; M23-specific suite; M22+M23-combined-feature-train regression (the M22-specific suite re-run unmodified); `--list-tests` proof every new M23 class is discovered; PHPCS lint clean with delta check against the M22-frozen baseline; `composer validate --strict`; `docker compose config` (both compose files); `scripts/release-audit.sh --development` (never `--release`); push branch, open a **draft** PR against `main` (mirroring M22's own PR shape, so it clearly stacks on the M22 tip in review), obtain green GitHub Actions. AI-driven runtime acceptance (no human manual testing) via the same PHPUnit-integration-suite-is-sufficient reasoning M22 used, since M23's flows (product/variation save, prefill read) are already exercised end-to-end against real WordPress/WooCommerce core in the integration suite.

## 28. Documentation strategy

`CHANGELOG.md` — new `## [1.40.0] - Unreleased` entry above the `1.39.0` entry, matching the M22 entry's style. `CLAUDE.md` — new M23 row in the Implementation Status table (status `🧊 Frozen — Unreleased`, version `1.40.0`), update the "in-progress feature train" paragraph to mention M23 alongside M22. `docs/checklists/m23-release-readiness.md` — mirrors `m22-release-readiness.md`'s structure exactly. No combined M22+M23 release notes are written now — those belong to the later, separate release-preparation phase (§34).

## 29. Version/DB_VERSION recommendation

`DB_VERSION` stays `11` — postmeta only, no table/column change, no migration. Plugin development version bumps `1.39.0` → `1.40.0` at M23's own freeze, unreleased, un-tagged — matching the exact convention M22's own plan verified from `CLAUDE.md`'s release history ("Intermediate development version `1.35.0` (M18 alone) was never tagged," "`1.30.0`/`1.31.0` were never tagged"): each milestone in a train bumps the version header at its own freeze; only the train's *final* milestone's number becomes the eventual tag.

## 30. M22+M23 train release-version recommendation

`1.40.0` — the version M23 itself bumps to at freeze, per §29's convention, becomes the single combined release tag for the whole M22+M23 train once M23 also freezes and a combined release-readiness review (§34) authorizes it. `1.39.0` (M22's own unreleased freeze version) is never tagged, exactly as `1.30.0`/`1.31.0`/`1.35.0` were never tagged for prior trains.

## 31. Explicit non-goals

No parent→variation inheritance (§7) — logged as a candidate M24+ convenience *write* action ("apply to all variations of this product"), not a read-time fallback. No target/par-stock-level quantity semantic, no demand forecasting, no sales-velocity/lead-time-demand/safety-stock/EOQ calculation, no MOQ/pack-size/case-quantity concept. No automatic supplier or quantity "learning" from history beyond M22's existing static heuristic. No bulk "create drafts for all needs-reorder items" action. No new `Purchasing_Caps` constant. No new admin-post action, AJAX mutation endpoint, or nonce beyond WooCommerce's own product/variation save nonces. No schema/`DB_VERSION` change. No change to `render_line_row()`'s signature, `PO_Validation`, `PO_Product_Validator`, `PO_Service::create_draft()`, or any PO lifecycle-action handler. No change to M21 reorder classification or M22's four-state contract shape. No fix for the pre-existing `readme.txt` Stable-tag staleness (out of scope, carried from M22).

## 32. Risks / findings

- New WooCommerce product/variation edit-screen hook integration is a genuinely first-time surface for this plugin — standard, well-documented WC extension conventions, but real engineering unknowns around exact hook placement/markup exist until implemented; mitigated by WP-M23-3's dedicated, isolated scope.
- Silent-clobber hazard on a stale stored preferred supplier (§8) was caught during design review, not left implicit — the "(unavailable)" option injection + no-op-resubmit exception is a required part of WP-M23-3, not an optional hardening.
- Variation field naming must use WooCommerce's index-arrayed convention (§15) — a well-known but easy-to-get-wrong WC extension detail; called out explicitly as a stop-and-check point in WP-M23-3.
- The earlier assumption that meta reads would add a fixed +1 query to the prefill path was wrong (§21) — verified directly against WooCommerce's product/variation data-store internals; the corrected, more favorable contract (2 queries when unconfigured, matching M22 exactly) is what ships in the plan and must be what the performance tests assert.
- No STOP condition from the user's predefined list is triggered: preferred supplier represents cleanly as postmeta; quantity is fixed, not forecasted; identity is unambiguous and matches M22 exactly; M17 semantics are reinforced (single shared eligibility predicate, use-time authoritative, no auto-repointing) rather than conflicted; M22's fallback is preserved exactly, including its query count; no schema redesign; M21 classification untouched; no second PO creation path; scope (one domain class, one admin class, one modified private method, docs/version bump) is appropriately sized for one milestone.

## 33. Level A freeze criteria

All WPs (0–7) complete with individual commits. Full unit/focused/integration suites green, including the unmodified M22-specific suite (feature-train regression). `--list-tests` proof. PHPCS/`composer validate --strict`/`docker compose config` clean. `release-audit.sh --development` passes. GitHub Actions green on an unmerged draft PR. Level A review passed with written confirmation of: zero mutation on GET, ownership invariants (§17/§24), M17 eligibility parity, M22 fallback byte-for-byte preserved when unconfigured, capability parity with no render/save asymmetry, zero list-table query delta, bounded and scale-invariant prefill query count proven at 0/1/10/50-supplier × 3-preference-state scale, no schema change, no M24+ scope leakage (no parent-inheritance, no bulk-apply action, no forecasting). `docs/checklists/m23-release-readiness.md` created, explicitly marked unreleased. Working tree clean; plan file unmodified since WP-M23-0. **Not required**: tag, merged PR, GitHub Release, deploy.

## 34. Combined M22+M23 release-readiness requirements

Performed only after M23 also reaches Level A freeze, as its own separate phase — not part of M23's own implementation: re-verify both milestones' BR/INV matrices together; re-run the full regression suite once more on the combined tip; confirm no drift between the two milestones' documented behavior and actual code; produce the single combined release note (deferred per §28); then, and only then, authorize a real release PR, merge to `main`, tag `v1.40.0`, GitHub Release, and deployment — none of which are in scope for M23's own execution.

## 35. Exact next operation after plan approval

1. `git fetch origin` — re-verify `feature/m22-reorder-draft-po-quick-action` local/origin HEADs still match `1716e3231d6caa229c5bf25ab6c98471c0f05cf7`, clean tree.
2. `git checkout feature/m22-reorder-draft-po-quick-action` (or origin's copy) as the branch point — explicitly **not** `main`.
3. `git checkout -b feature/m23-replenishment-defaults`.
4. Write this plan's full content to `docs/milestones/m23-implementation-plan.md`.
5. Commit that file alone: `docs(m23): materialize approved implementation plan`.
6. Begin WP-M23-1 through WP-M23-7 continuously, stopping only at an explicit per-WP stop condition.

M23 PLANNING COMPLETE — READY FOR REVIEW
