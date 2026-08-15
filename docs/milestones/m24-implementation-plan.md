# M24 Definitive Implementation Plan: Replenishment Planning Screen

*Revision 2 — amended after user review. Architecture confirmed sound; this revision fixed five MAJOR gaps (redirect URL safety, scoped-discovery specification, the true end-to-end query contract, index verification for the new bulk SQL, and variable-parent checkbox handling), four MEDIUM gaps (deterministic ordering, bulk-action visibility gating, supplier-resolution notice parity, stale-preference UI surfacing), and one PROCESS change (M24+M25 release as one train). Every fix was verified against the live repository, not assumed.*

*Revision 3 — second review pass. Three further technical corrections: (1) the Revision-2 claim that the scoped `include` discovery path costs "exactly one SQL query" is downgraded to an observed, N-independent baseline — `wc_get_products()` is not guaranteed to be one statement; (2) whether WooCommerce's `include` param actually returns `product_variation` posts under this repository's query defaults is now an explicit pre-refactor characterization requirement, not an assumption; (3) `MAX_LINES = 500` is moved earlier in the pipeline so it bounds the expensive supplier/defaults/history resolution work itself, not just the final display list — the catalog-wide inherited ~8,000-candidate ceiling no longer reaches the resolution step at all. One MEDIUM fix: the variable-parent POST filter (§10.2) is changed from a per-ID `wc_get_product()` loop to the same bounded `include` lookup used for scoped discovery. One evidence-strength fix: the `EXPLAIN` acceptance criterion for the new bulk SQL is strengthened beyond "not a full table scan."*

## Context

The M21→M22→M23 chain (Position-aware reorder classification → single-item "Create Draft PO" quick action → merchant-configured preferred supplier/default quantity) leaves one gap: nothing shows a merchant *all* of their current needs-reorder items, who to buy each from, and how much, in one place. Today a merchant must open the Inventory Overview list, spot a needs-reorder row, click "Create Draft PO," and repeat — one row at a time. M24 closes that gap with a read-only Replenishment Planning screen. Bulk *creation* of draft POs is deliberately **not** part of M24 — it requires new multi-record mutation, transaction, and partial-failure design too large to bundle safely with the read-side work, so it is reserved for M25.

---

## 1. Verified v1.40.0 baseline

- `main` == `origin/main`, HEAD `6965262bb035697c66427b6f907480042a03e5e6`, working tree clean.
- Tag `v1.40.0` → `a5efaad0d87868a2b185733de5d147b580be1549` (PR #31, M22+M23 bundled train).
- Plugin version `1.40.0`; `DB_VERSION = 11` (`includes/class-wc-inventory-overview-install.php:15`), unchanged since M18.
- No M24 branch/plan/implementation exists. Historical "M24+" mentions in other milestones' docs are non-binding brainstorming, not committed scope.
- Process: `docs/process/milestone-lifecycle.md` (WP0→WP6). No "Level A/B" terminology exists in the repo — treated here as a risk-tiering label for WP2 audit depth.

## 2. Current replenishment workflow assessment

- **M21** — `Reorder_Signal_Resolver::resolve(float $position, float $threshold): array{needs_reorder, covered_by_incoming}`. Pure, stateless, single-item.
- **M22** — `Reorder_Prefill_Service::resolve(int $product_id, int $variation_id = 0): array`. Single-item, read-only, TOCTOU-safe GET-prefill. Supplier resolution: committed history only, fixed 2-query bound.
- **M23** — `Replenishment_Defaults` (postmeta, per-item, no bulk getters, no parent→variation inheritance). `resolve_supplier()` checks preferred-if-eligible first, else falls back to M22's unchanged history algorithm.
- **The gap**: every read primitive is single-item by design except `Inventory_Position_Service::get_positions_bulk()` (M3) — the template M24 generalizes from. No merchant-facing "everything I should reorder" surface exists today.

## 3. Candidate M24 options, evaluated

- **A — Bulk create draft POs directly.** Rejected: couples new bulk read-resolution with new bulk write-orchestration in one milestone.
- **B — Bulk "Apply Defaults to Variations."** Orthogonal configuration convenience; queued later (M26).
- **C — Replenishment Review/Planning screen (read-only). Selected.** Generalizes three already-proven patterns (bulk Position, GET-untrusted/re-validate, bounded catalog scan) from 1 item to N. Zero schema, zero mutation.
- **D — Draft-PO reuse/deduplication.** No evidence of a real gap anywhere in the codebase. Rejected for M24; kept as an evidence-gated backlog note (M27).
- **E — other candidate.** None found more compelling.

## 4. Proposed M24–M27 roadmap ladder

| # | Title | Primary capability | Dependency | Size | Risk | Schema | Mutation | Lifecycle | Why here |
|---|---|---|---|---|---|---|---|---|---|
| M24 | Replenishment Planning Screen | Bulk read: needs-reorder worklist with resolved supplier + quantity, grouped by supplier | M21, M22, M23, M3's bulk-query template | L | Medium | None | None | Level A (two WPs held to Level-B-caliber audit, §26) | Closes the read-side bulk gap; independently valuable; zero commit risk |
| M25 | Bulk Draft PO Creation | Mutation: consume M24's plan, group by supplier, call `create_draft()` once per group | M24 | L | High | None | Multi-record (N POs) | Level B | Only buildable once M24's contract exists; isolates all new mutation risk |
| M26 | Apply Defaults to Variations | Bulk-write `Replenishment_Defaults` across a variable product's variations | M23, M24 UI patterns | S | Low | None | Single-table bulk postmeta write | Level A | Configuration convenience, not workflow-critical |
| M27 | Duplicate-Draft Detection (evidence-gated) | Advisory warning on duplicate open-draft lines | M25 | S–M | Low | None | None (advisory) | Level A | Only scheduled if real incidents are observed post-M25 |

## 5. Selected M24 title/scope

**M24 = "Replenishment Planning Screen"**: a read-only, catalog-wide (or selection-scoped) worklist that bulk-resolves Position, supplier, and suggested quantity for every current needs-reorder item and groups results by supplier. Zero mutation.

## 6. Why this option wins

Bounded entirely by existing, proven architecture; zero mutation surface; independently valuable without M25; A/B/D/E all lose on evidence or scope grounds (§3).

## 7. Explicit non-goals

No forecasting. No mutation of any kind. No schema change. No currency conversion. No new capability/role/public hook. No selection-persistence beyond one request/redirect. No duplicate-draft detection (M27). No "apply defaults to variations" (M26). No changes to `PO_Service`, `Purchase_Orders`, `Purchase_Order_Lines::create()`.

## 8. Current architecture ownership

| Concern | Owner | File |
|---|---|---|
| needs_reorder classification | `Reorder_Signal_Resolver::resolve()` | `includes/class-wc-inventory-overview-reorder-signal-resolver.php` |
| Position, single & bulk | `Inventory_Position_Service::get_position()`/`get_positions_bulk()` | `includes/class-wc-inventory-overview-inventory-position-service.php` |
| Single-item prefill orchestration | `Reorder_Prefill_Service::resolve()` | `includes/class-wc-inventory-overview-reorder-prefill-service.php` |
| Preferred-supplier/default-qty persistence | `Replenishment_Defaults` | `includes/class-wc-inventory-overview-replenishment-defaults.php` |
| Single-item committed-history supplier lookup | `Purchase_Order_Lines::distinct_supplier_history_for_item()` (line 424) | `includes/class-wc-inventory-overview-purchase-order-lines.php` |
| Supplier bulk fetch / eligibility predicate | `Suppliers::list_by_ids()` (361) / `is_eligible_for_selection()` (386) | `includes/class-wc-inventory-overview-suppliers.php` |
| Product/variation identity validation | `PO_Product_Validator::validate()` | `includes/class-wc-inventory-overview-po-product-validator.php` |
| Paginated product query (wraps `wc_get_products()`) | `Repository::query_products()` (line 47) | `includes/class-wc-inventory-overview-repository.php` |
| Full-catalog low-stock/needs-reorder scan (counts only, protected) | `Summary::scan_low_stock_and_needs_reorder()` (77) / `classify_needs_reorder_bulk()` (156) | `includes/class-wc-inventory-overview-summary.php` |
| Purchase Order creation (sole mutation entry point) | `PO_Service::create_draft()` (82) | `includes/class-wc-inventory-overview-po-service.php` |
| Purchasing capability map | `Purchasing_Caps` | `includes/class-wc-inventory-overview-purchasing-caps.php` |
| Purchasing admin page/tabs | `Purchasing_Page` (`TAB_SUPPLIERS`/`TAB_ORDERS`/`TAB_RECEIPTS`, 16-18) | `includes/class-wc-inventory-overview-purchasing-page.php` |
| Inventory Overview bulk-action plumbing, row checkboxes | `List_Table::get_bulk_actions()` (124), `column_cb()` (137), `Overview_Controller::maybe_handle_bulk()`/`detect_bulk_action()` (199/261) | `includes/class-wc-inventory-overview-list-table.php`, `includes/class-wc-inventory-overview-overview-controller.php` |

## 9. Proposed architecture (corrected)

### 9.1 The two extraction targets remain, but the Summary extraction is now more substantial

`Summary::scan_low_stock_and_needs_reorder()`/`classify_needs_reorder_bulk()` are `protected static`, and the former discards item-level detail after tallying — confirmed directly in the file (lines 19-200). The fix below now also resolves the scoped-discovery gap (§9.2) and the redundant-validation gap (§9.3) that review turned up.

### 9.2 Scoped discovery (MAJOR fix, revised in Rev. 3): `Repository::query_products()` gains an `include` passthrough

Verified: `Repository::query_products()` (`class-wc-inventory-overview-repository.php:47`) ultimately calls WooCommerce core's `wc_get_products( $args )`, which natively supports `'include' => array<int>` (maps to `WP_Query`'s `post__in`) — no plugin-level query-building work needed, just passthrough. `query_products()` currently has no `include`/`post__in` handling (verified: no match for either string in the file). Additive fix:

```php
// includes/class-wc-inventory-overview-repository.php, query_products(), additive:
if ( ! empty( $params['include'] ) && is_array( $params['include'] ) ) {
    $args['include']   = array_map( 'absint', $params['include'] );
    $args['limit']     = count( $args['include'] );
    $args['paginate']  = false; // single bounded result set, no page math needed
    unset( $args['page'] );
}
```

**Correction (Rev. 3):** Revision 2 claimed this makes the scoped fetch "exactly one query." That overstates what one `wc_get_products()` call guarantees — depending on WordPress/WooCommerce object-cache state, a single call can still trigger separate post, postmeta, term, and product-lookup-table queries. **The contract this plan actually needs is: one bounded bulk product-fetch operation whose SQL count is independent of N, never a per-ID query — not literally one SQL statement.** WP-M24-1/WP-M24-3 (§27) now measure the real cold-cache SQL count at N=10/50/100 and freeze that *observed* number as the baseline, rather than pre-declaring 1. See §20 for the corrected query-contract table and §35 for the corrected stop condition.

### 9.3 Redundant per-item validation removed (MAJOR fix)

The original draft had `build_plan()` re-run `PO_Product_Validator::validate()` per surviving item "for defense in depth." Verified `PO_Product_Validator::validate()` (`class-wc-inventory-overview-po-product-validator.php:30`) calls `wc_get_product()` internally per id and checks: existence, not variable/grouped/external, is variation-or-simple, `managing_stock()`. **Every one of these checks is already performed, on a freshly-loaded `WC_Product` object, by the scan itself** (`gather_low_stock_candidates()`, below) on every render — whether catalog-wide (paged) or ID-scoped (single `include` query). An item can only reach the classification step by having just been loaded from the DB and passed `managing_stock()`/`is_in_stock()`/type bucketing in that same call. Re-validating via `PO_Product_Validator::validate()` would silently reintroduce up to N additional `wc_get_product()` calls — exactly the risk flagged in review — for zero additional safety. **Fix: `build_plan()` does not call `PO_Product_Validator::validate()` at all.** The live scan is the TOCTOU protection; `parent_id`/`variation_id`/`name`/`sku` are captured directly from the already-loaded `$p` object during the single gather pass, never re-fetched.

### 9.4 Variation-ID support in scoped discovery must be proven, not assumed (MAJOR fix, new in Rev. 3)

Revision 2 sent both simple-product and variation ids to `Repository::query_products(['include' => [...]])` without confirming WooCommerce actually returns `product_variation` posts under this repository's existing query defaults. This is genuinely uncertain in advance: `query_products()`'s non-`children` branch already sets `$args['type']` to include `ProductType::VARIATION` when `sellable_stock_lines_only` is set (verified at `class-wc-inventory-overview-repository.php:92-96`), which is promising, but whether `wc_get_products()`'s `include`/`post__in` handling correctly intersects with a mixed `type` array spanning both the `product` and `product_variation` post types has not been observed.

**Fix: a characterization test is written at WP-M24-1, before any Summary refactor**, calling `Repository::query_products()` directly (not through any new M24 code) with a mixed fixture:
- one simple product id,
- one variable parent id,
- two concrete variation ids (of a third, separate variable product),

merged with the same `sellable_stock_lines_only => true` params the eligibility scan already uses, and `include` set to all four ids. The test records exactly what is returned — types, ids, counts — against this required semantic outcome:
- the simple product id → returned, as a simple `WC_Product`;
- each variation id → returned, as that exact `WC_Product_Variation`;
- the variable parent id → **excluded** from what a purchasable-candidate consumer would treat as eligible (whether by never being returned, or by being returned but then failing the existing `managing_stock()` eligibility filter downstream — either is acceptable, but which one actually happens must be recorded).

**If this fixture does not produce that outcome** (e.g. variations are silently dropped when mixed with a non-variation `include` list), the bounded fallback is: two separate `include`-scoped calls — one with `type => SIMPLE`/`include => $product_ids`, one with `type => VARIATION`/`include => $variation_ids` — merged in PHP. This is still bounded (no per-ID loop, still N-independent SQL count per §9.2's corrected framing) and is not a degradation, just a second bounded call instead of one. **No per-ID fallback (a `wc_get_product()` loop) is acceptable under any outcome.** Whichever path the characterization test selects is what WP-M24-3 implements and documents in its commit.

### 9.5 `MAX_LINES` must bound resolution work, not just display output (MAJOR fix, redesigned in Rev. 3)

Revision 2's `MAX_LINES = 500` truncation happened only after supplier/quantity resolution had already run over the *entire* classified needs-reorder result — and the catalog-wide inherited scan ceiling allows up to ~8,000 low-stock candidates (40 pages × 200/page, pre-existing, unmodified). If most of those turned out to be `needs_reorder`, the downstream `IN (...)` supplier-history query, the `Replenishment_Defaults::get_bulk()` meta-cache prime, and the `Suppliers::list_by_ids()` union could all receive thousands of ids — "flat query count" does not make a multi-thousand-row `IN (...)` list cheap, and the plan must not hide that behind a query-count metric alone (this is the same class of concern §14.1 already raised for the history SQL, now applied to the *size* of the input rather than the shape of the query).

**Fix: truncation happens between classification and resolution, not after resolution.** The existing gather-then-classify-once pattern is unchanged (`gather_low_stock_candidates()` collects up to 8,000 low-stock candidates across ≤40 pages exactly as today; `classify_needs_reorder_bulk()` still runs exactly once against that full gathered set, one bulk Position call, flat query count regardless of candidate count — this part was never the actual resource risk, since it does no per-item resolution, only a bulk Position fetch plus an in-memory comparison loop). What changes is what happens to the *classified* needs-reorder result before it is handed to the resolution stage:

```php
// includes/class-wc-inventory-overview-summary.php
/**
 * Itemized sibling of scan_low_stock_and_needs_reorder(). Same gather +
 * classify pipeline (catalog-wide: existing 40-page loop then one
 * classify_needs_reorder_bulk() call; scoped: one bounded include-based
 * fetch per §9.2/§9.4 then classify). $limit, when > 0, truncates the
 * CLASSIFIED needs-reorder result to its first $limit members in the
 * SAME order the candidates were gathered (Repository::query_products()'s
 * own ordering, e.g. modified DESC) -- never re-sorted before truncation,
 * since sorting the full up-to-8000-item set first would defeat the bound.
 * $limit = 0 means unbounded (only ever used by the item_ids-scoped path,
 * which is already bounded to <=100 externally by the bulk-action cap).
 * Captures name/sku/parent_id from the already-loaded WC_Product during
 * the gather pass (no re-fetch, §9.3).
 *
 * @return array{
 *   items: array<array{product_id:int, variation_id:int, name:string, sku:string, on_hand:float, threshold:float, position:float}>,
 *   truncated: bool,
 * }
 */
public static function get_needs_reorder_items( array $base_params, array $item_ids = array(), int $limit = 0 ): array;
```

`Replenishment_Planning_Service::build_plan()` calls this with `$limit = self::MAX_LINES + 1` (501: 500 for resolution/display plus one sentinel proving more exist) on the catalog-wide path. Only the resulting ≤500-item `items` array — never the full up-to-8,000-candidate gathered set — is passed into supplier/quantity resolution (`Replenishment_Defaults::get_bulk()`, `distinct_supplier_history_for_items_bulk()`, `Suppliers::list_by_ids()`). The §10.3 deterministic **display** ordering (supplier name, then product name) is then applied to this already-capped ≤500-item set — cheap, since sorting 500 items is trivial; it is a separate, later ordering step from the gather-order truncation described here, and must not be confused with it. On the scoped (`item_ids` non-empty) path, `$limit = 0` (unbounded) is passed since the input is already bounded to ≤100 by the bulk-action cap (§10.1) and never needs truncation.

`scan_low_stock_and_needs_reorder()` (the existing protected, counts-only method) is unaffected by any of this — it still calls the shared `gather_low_stock_candidates()` helper with no limit and no item_ids, unchanged, byte-identical output.

BR-M24-1 and BR-M24-11 are corrected accordingly in §22 — M24 no longer claims to resolve "every" needs-reorder item catalog-wide; it discovers and resolves the first ≤500 in deterministic gather order and reports truncation.

### 9.6 Unchanged from Revision 2

`Summary` remains the sole full-catalog/scoped low-stock/needs-reorder scanner — no second scan-loop implementation. `Supplier_Preference_Resolver::decide()` (new, pure, shared by `Reorder_Prefill_Service` and the new planning service), the two bulk repository methods (`Purchase_Order_Lines::distinct_supplier_history_for_items_bulk()`, `Replenishment_Defaults::get_bulk()`), the `Replenishment_Planning_Service::build_plan()` orchestrator, and the `Purchasing_Page::TAB_PLANNING` admin surface — signatures in §21.

## 10. Exact UI workflow (corrected)

Two entry points, one destination:

1. **Primary — Purchasing → Planning tab** (`page=wc-io-purchasing&tab=planning`). No `item_ids` → `build_plan(array(), array())` → catalog-wide discovery via `Summary::get_needs_reorder_items($base_params)` (unscoped path, existing 40-page ceiling, unchanged). Capped at `MAX_LINES = 500` (`truncated:true` + notice if exceeded).
2. **Secondary — Inventory Overview bulk action.** New `wc_io_plan_replenishment` entry in `List_Table::get_bulk_actions()`, **added to the returned array only when `Purchasing_Caps::current_user_can(VIEW_PO)` is true** (MEDIUM fix: a viewer who can never reach the destination never sees the action offered — server-side re-gating in the POST handler, §17, remains mandatory regardless). Merchant checkbox-selects rows (existing `post[]` mechanism — confirmed variation rows render their own checkbox via `column_cb()` when expanded, `class-wc-inventory-overview-list-table.php:137`), picks "Plan replenishment," submits.

   `Overview_Controller::maybe_handle_bulk()` gains a new branch: `check_admin_referer('bulk-wc-inventory-items')` (unchanged nonce) → **reject selections over 100 ids** (reduced from the original draft's 300 — see §10.1) with an explanatory notice pointing to entry point 1 → **filter out any selected id whose product type is `variable`** (MAJOR-5 fix, §10.2) → `wp_safe_redirect()` (PRG, GET) to `page=wc-io-purchasing&tab=planning&item_ids=<comma-separated ints>[&wc_io_plan_skipped=<n>]`.

Both paths converge on the same tab/`build_plan()` call/table renderer: grouped-by-supplier sections, then "Unresolved," in the deterministic order fixed in §10.3. No "select all and create POs" button exists in M24 — that's M25.

### 10.1 Bulk-action selection cap: 300 → 100 (MAJOR fix)

The original draft's own math was wrong: 300 ten-digit IDs alone require ≈3,299 characters (300×10 digits + 299 commas), already exceeding its own proposed 2,048-char conservative floor before the rest of the URL/encoding is even added. **Fix: reduce the cap to 100.** Worst case at 100 ten-digit IDs: `100×10 + 99 commas = 1,099` chars for the `item_ids` value alone, plus `item_ids=` (9) plus the rest of the URL (`/wp-admin/admin.php?page=wc-io-purchasing&tab=planning&` ≈ 55-75 chars depending on host) ≈ **1,200-1,300 chars total**, comfortably under the 2,048 floor with margin. No transient/session persistence is added merely to support a larger number — the stateless GET design is kept, the cap is what moves. `MAX_LINES` (the catalog-wide plan's own truncation ceiling, §13/§21) stays 500 — it is a separate, larger bound that only applies to the unscoped/catalog-wide discovery path, which has no URL-length constraint since it carries no `item_ids` at all.

BR-M24-13 updated: reject selections over **100** ids (was 300). Test: `test-planning-bulk-action-nonce-and-cap.php` gains an explicit maximum-URL-length assertion at exactly 100 synthetic ten-digit-ID rows.

### 10.2 Variable-parent selections (MAJOR fix, mechanism revised in Rev. 3)

Verified: `column_cb()` renders a checkbox for *whatever* `$item` is passed to it, and a variable-parent's own row (with its "N variations" expand toggle) is itself a distinct row with its own checkbox — so a merchant can select a variable parent's own (non-purchasable) product ID, not just its child variations. Per INV-8, a variable parent can never itself be a PO line.

**Chosen behavior: reject with an explicit notice, do not expand.** Expansion (auto-selecting all currently-qualifying child variations) was rejected: it would silently multiply one click into an unbounded number of plan lines, complicate whether the 100-id cap applies before or after expansion, and surprise a merchant who clicked one checkbox expecting one decision.

**Mechanism (corrected in Rev. 3 — MEDIUM fix, was a per-ID N+1 in the original amendment):** the original design looped the ≤100 selected ids and called `wc_get_product()` once per id — a fresh HTTP request with no warm object cache to rely on, so this really could cost up to 100 product loads, precisely the N+1 pattern M24 exists to eliminate elsewhere. **Fixed to reuse the exact same bounded `include`-based lookup validated by §9.4's characterization test**: `maybe_handle_bulk()`'s new branch calls `Repository::query_products(['include' => $selected_ids, 'limit' => count($selected_ids), 'paginate' => false])` once (or twice, if §9.4's characterization test selects the two-call fallback), gets back the corresponding `WC_Product` objects in one bounded operation, and filters locally in PHP for `is_type('variable')` — zero per-ID queries, same N-independent SQL-count baseline established at WP-M24-3 (§9.2). Surviving (non-variable) ids are counted for the `wc_io_plan_skipped` notice and passed to the redirect. If any were dropped, append `&wc_io_plan_skipped=<n>` to the redirect; the Planning tab renders "N selected item(s) were skipped because they are variable parent products — expand them in Inventory Overview and select specific variations instead." If the filtered set is empty, redirect back to Inventory Overview with a failure notice instead of proceeding to an empty Planning tab.

### 10.3 Deterministic ordering — two distinct stages (MEDIUM fix, clarified in Rev. 3)

There are now **two separate orderings**, applied at two separate pipeline stages, and they must not be conflated:

1. **Gather-order truncation (§9.5, catalog-wide path only).** `Summary::get_needs_reorder_items()`'s `$limit`-based truncation to the first 500 needs-reorder items (+1 sentinel) happens in whatever order `Repository::query_products()`'s pages were gathered in (its own default `orderby=modified, order=DESC`, or whatever `$base_params['orderby']` specifies) — **not** re-sorted by name first. This is what makes the bound in §9.5 actually cheap: re-sorting the full up-to-8,000-item candidate set before truncating would require holding/sorting the full set, defeating the point.
2. **Display ordering (this section, applied after truncation/resolution, to the ≤500-item resolved set — or the ≤100-item scoped set, which is never truncated):**
   - Supplier groups ordered by supplier name (case-insensitive), then `supplier_id` ascending as tiebreak.
   - The "Unresolved" section always renders **after** all resolved supplier groups.
   - Lines within a group/section ordered by product name (case-insensitive), then SKU, then item id (`variation_id` if set, else `product_id`) as tiebreak.

Because truncation (stage 1) already happened before resolution, stage 2's sort operates on at most 500 (or 100) items regardless of catalog size — cheap, and never re-triggers the bound from §9.5.

### 10.4 Stale preferred-supplier surfacing (MEDIUM fix)

A line with `preferred_supplier_stale = true` (configured preferred supplier was ineligible, fell back to history) renders with a small, explicit per-line badge/status text (e.g. "Preferred supplier unavailable — using purchase history") in its group. It must not look identical to an ordinary resolved line. No mutation or repair action exists in M24 — purely a visual/status difference.

## 11. Item eligibility contract

Unchanged rule set, now reachable through both `get_needs_reorder_items()` paths (§9.4):
- Must be a `WC_Product` (simple or variation only). Variable parents never independently pass `managing_stock()` in this plugin's model — excluded implicitly by the same filter the existing scan already applies; no separate `PO_Product_Validator::validate()` call is used (§9.3), and the bulk-action's own variable-parent filter (§10.2) is a POST-time UX safeguard, not the eligibility mechanism.
- `managing_stock()` true, `is_in_stock()` true, stock quantity set, effective low-stock amount set, On Hand ≤ threshold.
- Of those, only items whose bulk-resolved Position yields `needs_reorder === true` are kept — `covered_by_incoming` items never appear, not even as "unresolved."
- **Never trusts a stale badge or stale `item_ids` selection**: every id, from either entry point, is re-resolved from a freshly-loaded `WC_Product` object on every render via the scoped or catalog-wide gather path. A scoped id that no longer qualifies, or no longer exists, is silently dropped (the `include`-scoped `wc_get_products()` call simply returns no row for it) — not shown as an error, consistent with BR-M24-3.

## 12. Supplier resolution contract (corrected)

Batched sibling of `Reorder_Prefill_Service::resolve_supplier()`, via `Supplier_Preference_Resolver::decide()`:

1. `Replenishment_Defaults::get_bulk( $item_ids )` — 1 query.
2. `Purchase_Order_Lines::distinct_supplier_history_for_items_bulk( $product_ids, $variation_ids )` — 2 queries (§14.1 for the exact SQL/index shape).
3. Union every touched supplier id into one `Suppliers::list_by_ids()` call — 1 query.
4. Per item: `Supplier_Preference_Resolver::decide(...)`.

**MEDIUM fix — parity must include notices, not just the resolved supplier id.** `Supplier_Preference_Resolver::decide()` is a pure decider; it does not own notice text — callers do, exactly as `Reorder_Prefill_Service` already owns its own notice strings today. BR-M24-4 (below) is corrected to require the cross-check test to freeze **both** the resolved `supplier_id` **and** the semantic notice/outcome (none / ambiguous / stale-preference) for every fixture, not supplier id alone — otherwise `Reorder_Prefill_Service` and `Replenishment_Planning_Service` could gradually diverge in how they *explain* an identical resolution while still agreeing on the chosen supplier.

## 13. Quantity resolution contract

Unchanged: `Replenishment_Defaults::get_bulk()` supplies `default_qty` from the same cache-primed postmeta read as the preferred-supplier id. `default_qty > 0` → `qty_suggested` is that value. Unset → stays `0.0`, never guessed/forecast/defaulted to `1`.

## 14. Currency/grouping contract

Unchanged: one group per resolved `supplier_id`; group `currency` = that supplier's `default_currency`, read once from the §12 `Suppliers::list_by_ids()` fetch; never chosen/converted/blended. Matches `PO_Service::create_draft()`'s existing rule exactly.

### 14.1 Bulk supplier-history SQL shape and index verification (MAJOR fix)

Verified the existing single-item `distinct_supplier_history_for_item()` (`class-wc-inventory-overview-purchase-order-lines.php:424`):
```sql
SELECT po.supplier_id AS supplier_id, MAX(po.order_date) AS latest_order_date
FROM wc_io_purchase_order_lines pol
INNER JOIN wc_io_purchase_orders po ON po.id = pol.po_id
WHERE po.status IN ('placed','partially_received','received','closed_short')
  AND pol.variation_id = %d               -- or: pol.variation_id = 0 AND pol.product_id = %d
GROUP BY po.supplier_id
ORDER BY latest_order_date DESC
```
Verified table indexes on `wc_io_purchase_order_lines` (`class-wc-inventory-overview-install.php:254-259`): `KEY po_id`, `KEY product_id`, `KEY variation_id`, `KEY status`, `KEY expected_date`. The bulk sibling changes only the equality predicates to `IN (...)`, preserving the dual-path shape (a product-scoped branch and a variation-scoped branch, mirroring the existing `list_open_lines_for_product_ids()`/`list_open_lines_for_variation_ids()` pattern already used elsewhere in this file):
```sql
-- Variation-scoped branch:
... WHERE po.status IN (...) AND pol.variation_id IN (%d, %d, ...)
GROUP BY pol.variation_id, po.supplier_id
-- Product-scoped branch (simple products, variation_id = 0):
... WHERE po.status IN (...) AND pol.variation_id = 0 AND pol.product_id IN (%d, %d, ...)
GROUP BY pol.product_id, po.supplier_id
```
Both branches use the existing single-column `product_id`/`variation_id` indexes for an index range scan across the `IN (...)` list (MySQL performs one index seek per listed value, in a single query/round-trip rather than N separate queries) — **no new index or schema change is needed; `DB_VERSION` stays 11.**

**`EXPLAIN` acceptance criterion, strengthened in Rev. 3 (MEDIUM fix)** — "not a full table scan" was too weak an acceptance bar; a technically-non-`ALL` plan can still be pathological. WP-M24-4 (§27) must run `EXPLAIN` against both branches at N=100 during implementation and record, for each branch: `type`, `key`, `possible_keys`, estimated `rows`, and `Extra`. Explicit expectations, not just an absence-of-`ALL` check:
- **Variation branch** (`pol.variation_id IN (...)`): expect `key` to resolve to the `variation_id` index (or an equivalent composite the optimizer legitimately prefers) — reject if `key` is null, reject if `type` is `ALL`.
- **Product branch** (`pol.variation_id = 0 AND pol.product_id IN (...)`): expect `key` to resolve to the `product_id` index driving the access path — **reject a plan that only avoids `type=ALL` by keying on the near-universal, low-selectivity constant `variation_id = 0` instead of the `IN (...)`-bearing `product_id` column.** A plan can be technically non-`ALL` and still scan most of the table if the optimizer picks the wrong low-cardinality index; that must be caught, not waved through.

The exact MySQL plan is not prescribed in advance — it must be measured, not assumed — but a pathological plan that passes only a naive "not `ALL`" check is a stop condition (§35), and the recorded evidence (all five fields, both branches) goes into `docs/checklists/m24-release-readiness.md` (§29).

**Residual note (§34):** "flat query *count*" (this repo's established D12 definition of "bulk," used consistently since M3) is not the same claim as "flat query *execution time*" — a wide `IN (...)` list still costs proportionally more index-seek work than a narrow one, even at a constant query count. This is accepted, consistent with how every prior bulk method in this codebase (`get_positions_bulk()` included) has always been measured, and is not a new risk M24 introduces — but it is called out explicitly here rather than left implicit, per review feedback.

## 15. Duplicate behavior

Unchanged: explicitly deferred (Option D, §3), evidence-gated for M27.

## 16. State/persistence decision

Unchanged: no new table/transient/session. The plan recomputes fresh on every GET; the only cross-request state is the `item_ids` GET param from the bulk-action redirect.

## 17. Security model

- **Viewing the Planning tab (GET, both entry points):** gated by `Purchasing_Caps::VIEW_PO` (confirmed pattern: `po-admin.php:958` uses `VIEW_PO` for viewing, `po-admin.php:826` uses `EDIT_PO` for saving).
- **The bulk-action POST:** reuses the existing `check_admin_referer('bulk-wc-inventory-items')` (confirmed at `class-wc-inventory-overview-overview-controller.php:208`) — no new nonce action — re-gated on `VIEW_PO` in the handler (mandatory, independent of the §10 visibility hiding, which is UX-only).

## 18. Mutation ownership

Unchanged: `PO_Service::create_draft()` remains the sole PO-creation mutation entry point, byte-for-byte untouched. No code path in M24 calls it, `Purchase_Orders::create_draft()`, `Purchase_Order_Lines::create()`, or `PO_Events::add()`. Enforced by an architecture-guard test.

## 19. Transaction/partial-failure model

**N/A — M24 performs no mutation of any kind.**

## 20. Query/performance contract (corrected in Rev. 2, downgraded from a pre-declared count to an observed baseline in Rev. 3)

The original draft's ≤6-query claim covered only four services and silently excluded the (now-removed, §9.3) `PO_Product_Validator::validate()` re-check and any product-loading cost. With that call removed and the gather pass now capturing name/sku/parent_id directly from already-loaded objects (§9.4), the *entire* `build_plan()` resolution phase — discovery through supplier/quantity resolution — is covered. **Rev. 3 correction:** the discovery-step numbers below are no longer pre-declared exact counts (Rev. 2's "exactly 1" for scoped discovery was not a safe claim, §9.2) — they are **observed, cold-cache baselines to be measured and recorded at WP-M24-1/WP-M24-3**, with the requirement being flatness across N, not a specific pre-chosen number:

| Step | Queries |
|---|---|
| Discovery — catalog-wide (`item_ids` empty), gather phase | ≤ 40 (pre-existing `Summary` ceiling, unchanged, inherited — not newly introduced by M24) |
| Discovery — catalog-wide, classify phase (`classify_needs_reorder_bulk()`, one call over the full gathered set, §9.5) | ≤ 2 (unchanged from the existing, inherited pattern — the gather/classify split means this stays flat even though truncation now happens after this step) |
| Discovery — `item_ids`-scoped (§9.2/§9.4 `include`-based lookup) | **observed cold-cache baseline, measured at N=10/50/100, required flat across those sizes — not pre-declared as exactly 1** (§9.2) |
| `get_positions_bulk()` (Position) | ≤ 2 |
| `Replenishment_Defaults::get_bulk()` | ≤ 1 |
| `distinct_supplier_history_for_items_bulk()` | ≤ 2 (§14.1) |
| `Suppliers::list_by_ids()` | ≤ 1 |
| Product/name/SKU loading for classification & display | **0 additional** — captured from the objects already loaded by the discovery step (§9.3/§9.4); no second `wc_get_product()`/`PO_Product_Validator::validate()` pass |

**Resolution-input size bound (§9.5, MAJOR fix — this is the part that actually protects against the ~8,000-candidate ceiling):** regardless of whether the catalog-wide discovery/classify phase (≤40+≤2 queries above) surfaces 5 or 5,000 needs-reorder items, everything from `get_positions_bulk()` downward — `Replenishment_Defaults::get_bulk()`, `distinct_supplier_history_for_items_bulk()`, `Suppliers::list_by_ids()`, and the display sort (§10.3) — only ever receives **≤500 items** (the catalog-wide path) or **≤100 items** (the scoped path), because `Summary::get_needs_reorder_items()`'s `$limit` truncates the classified result *before* handing it to resolution, never after.

`test-replenishment-planning-query-count.php` must measure the **complete** `build_plan()` call (not isolated service calls in sequence), with cold-cache setup before each measurement (mirroring the existing `tests/integration/reorder-signal/test-summary-query-count.php` pattern), at N = 10/50/100 (item_ids-scoped) and N = 10/50/200/500 (catalog-wide candidates surfaced, all still bounded to ≤500 resolution input by §9.5) — asserting the observed query count for every row above stays flat as N grows, and separately asserting no per-ID query is ever observed. There is no longer a "must equal exactly K" assertion for the discovery-step rows — the assertion is flatness of the *observed* baseline, established once and then held to on every subsequent run.

## 21. Exact data/method contracts (corrected)

```php
// includes/class-wc-inventory-overview-repository.php (additive)
// query_products() gains 'include' passthrough -- see §9.2 for the exact diff.

// includes/class-wc-inventory-overview-supplier-preference-resolver.php (new)
class WC_Inventory_Overview_Supplier_Preference_Resolver {
    public static function decide(
        int $preferred_supplier_id,
        bool $preferred_supplier_eligible,
        array $eligible_history_supplier_ids
    ): array;
    // => array{supplier_id:int, preferred_was_stale:bool, history_outcome:'not_consulted'|'none'|'single'|'ambiguous'}
}

// includes/class-wc-inventory-overview-purchase-order-lines.php (additive)
public static function distinct_supplier_history_for_items_bulk( array $product_ids, array $variation_ids ): array;
// => array<int $item_id, array<int> $supplier_ids> most-recent-order-date first. See §14.1 for SQL shape.

// includes/class-wc-inventory-overview-replenishment-defaults.php (additive)
public static function get_bulk( array $item_post_ids ): array;
// => array<int $item_id, array{preferred_supplier_id:int, default_qty:float}>

// includes/class-wc-inventory-overview-summary.php (refactor + additive) -- see §9.4/§9.5 for full detail
private static function gather_low_stock_candidates( array $base_params, array $item_ids = array() ): array;
public static function get_needs_reorder_items( array $base_params, array $item_ids = array(), int $limit = 0 ): array;
// => array{items: array<array{...}>, truncated: bool} -- $limit truncates the CLASSIFIED
// result in gather order, BEFORE resolution, not after (§9.5). $limit=0 = unbounded
// (scoped path only, already bounded to <=100 by the bulk-action cap).
// scan_low_stock_and_needs_reorder() refactored to call gather_low_stock_candidates() with no item_ids;
// output/query-count byte-identical (characterization-proven, §24).

// includes/class-wc-inventory-overview-replenishment-planning-service.php (new)
class WC_Inventory_Overview_Replenishment_Planning_Service {
    const MAX_LINES = 500; // Passed as $limit+1=501 to Summary::get_needs_reorder_items()
                            // on the catalog-wide path (§9.5) -- bounds RESOLUTION input, not just display.
    const MAX_BULK_ACTION_SELECTION = 100; // §10.1
    public static function build_plan( array $base_params = array(), array $item_ids = array() ): array;
    // => array{
    //   groups: array<int $supplier_id, array{
    //     supplier_id:int, supplier_name:string, currency:string,
    //     lines: array<array{
    //       product_id:int, variation_id:int, name:string, sku:string,
    //       on_hand:float, incoming:float, position:float, threshold:float,
    //       qty_suggested:float, preferred_supplier_stale:bool
    //     }>
    //   }>,  // ordered per §10.3
    //   unresolved: array<array{product_id:int, variation_id:int, name:string, sku:string, reason:'no_supplier'|'multiple_suppliers'}>, // ordered per §10.3, after groups
    //   truncated: bool,
    //   candidate_count: int,
    // }
}

// includes/class-wc-inventory-overview-purchasing-page.php (additive)
const TAB_PLANNING = 'planning';
// + new private render_planning_tab(): void

// includes/class-wc-inventory-overview-list-table.php (additive)
// get_bulk_actions() gains 'wc_io_plan_replenishment' => __(...) ONLY when
// Purchasing_Caps::current_user_can( Purchasing_Caps::VIEW_PO ) is true (§10).

// includes/class-wc-inventory-overview-overview-controller.php (additive)
// detect_bulk_action()'s whitelist gains 'wc_io_plan_replenishment';
// maybe_handle_bulk() gains a branch: cap enforcement (100, §10.1),
// bounded include-based variable-parent filtering (§10.2, no per-ID
// wc_get_product() loop), PRG redirect -- no mutation loop.
```

## 22. BR-M24 matrix (updated)

- **BR-M24-1 (corrected in Rev. 3)**: `build_plan()` with empty `$item_ids` discovers current needs-reorder items catalog-wide via `Summary::get_needs_reorder_items()`, in deterministic gather order, up to the resolution cap (§9.5) — **not** a claim to resolve literally every needs-reorder item in the catalog; beyond the cap, `truncated = true` is set (BR-M24-11) and the excess is out of scope for M24 (future pagination/filtering, not this milestone).
- **BR-M24-2**: `build_plan()` with non-empty `$item_ids` scopes discovery to exactly those ids via the `include`-passthrough single-query path (§9.2), still re-validating/re-classifying each from scratch.
- **BR-M24-3**: An id in `$item_ids` that no longer qualifies, or no longer exists, is silently dropped, not surfaced as an error.
- **BR-M24-4 (corrected)**: Supplier resolution for every plan line matches, byte-for-byte, **both the resolved `supplier_id` and the semantic notice/outcome**, what `Reorder_Prefill_Service::resolve()` alone would produce for that item.
- **BR-M24-5**: A configured, currently-eligible preferred supplier is used directly with zero committed-history query contribution for that item.
- **BR-M24-6**: A configured-but-ineligible preferred supplier falls back to committed history and sets `preferred_supplier_stale = true`, surfaced as a per-line UI badge (§10.4).
- **BR-M24-7**: Zero eligible history suppliers, no preferred supplier → `unresolved`, `reason = 'no_supplier'`.
- **BR-M24-8**: More than one eligible history supplier, no preferred supplier → `unresolved`, `reason = 'multiple_suppliers'`.
- **BR-M24-9**: A configured `default_qty > 0` populates `qty_suggested`; unset leaves it `0.0`.
- **BR-M24-10**: Every group's `currency` equals its supplier's `default_currency` at read time.
- **BR-M24-11 (corrected in Rev. 3)**: `truncated = true` and only the first 500 needs-reorder items (in §9.5's gather-order truncation, applied before resolution — not the §10.3 display order) are ever handed to resolution/display when the classified catalog-wide set exceeds `MAX_LINES`; the returned `groups`/`unresolved` output is then sorted per §10.3's display ordering on top of that already-capped set.
- **BR-M24-12**: The Planning tab renders with zero mutation for any request shape.
- **BR-M24-13 (corrected)**: The bulk-action POST rejects selections over **100** ids with an explanatory notice, never silently truncating.
- **BR-M24-14**: Viewing the Planning tab requires `Purchasing_Caps::VIEW_PO`.
- **BR-M24-15**: The bulk-action POST reuses the existing `bulk-wc-inventory-items` nonce.
- **BR-M24-16**: Variable parent products are never eligible plan candidates via either discovery path.
- **BR-M24-17**: `scan_low_stock_and_needs_reorder()`'s existing counts and query count are byte-identical before and after the `gather_low_stock_candidates()` extraction.
- **BR-M24-18 (new)**: A selected variable-parent id in a bulk-action submission is dropped with an explicit `wc_io_plan_skipped` count surfaced to the merchant, never silently expanded or silently dropped without explanation (§10.2).
- **BR-M24-19 (new)**: Plan output (groups, unresolved, and lines within each) is fully deterministic per the §10.3 ordering contract, independent of DB row order.
- **BR-M24-20 (revised in Rev. 3)**: The `item_ids`-scoped discovery path (`Repository::query_products()` with `include`) costs a flat, N-independent, cold-cache-measured SQL count (observed baseline established at WP-M24-3, §20 — not pre-declared as exactly 1), never a per-id lookup and never the full catalog-wide page loop.
- **BR-M24-21 (new)**: Regardless of how many needs-reorder items are classified catalog-wide (up to the inherited ~8,000-candidate ceiling), the inputs to `Replenishment_Defaults::get_bulk()`, `distinct_supplier_history_for_items_bulk()`, and `Suppliers::list_by_ids()` never exceed 500 items (§9.5) — resolution cost is bounded by `MAX_LINES`, not by catalog size.
- **BR-M24-22 (new)**: A variation id passed through the scoped `include` discovery path is returned as that exact variation (not silently dropped, not collapsed to its parent) — proven by the WP-M24-1 characterization test (§9.4) before any production code relies on it.
- **BR-M24-23 (new)**: The bulk-action POST's variable-parent filter (§10.2) identifies variable-parent selections via the same bounded `include`-based lookup as scoped discovery — never a per-selected-id `wc_get_product()` loop.

## 23. INV-M24 matrix (updated)

- **INV-M24-1**: Zero mutation anywhere in M24's new code.
- **INV-M24-2**: Never reimplements Position math — always `get_positions_bulk()`.
- **INV-M24-3**: Never reimplements needs_reorder comparison — always `Reorder_Signal_Resolver::resolve()`.
- **INV-M24-4**: Supplier precedence exists in exactly one place, `Supplier_Preference_Resolver::decide()`.
- **INV-M24-5**: The resolution-phase query count (§20) is flat across N up to 100 (scoped) / 500 (catalog-wide) — proven via an *observed, cold-cache baseline* rather than a pre-declared count (Rev. 3), and covers the *entire* resolution path including product loading (§20 correction).
- **INV-M24-6**: No new schema/table/column; `DB_VERSION` stays 11 (§14.1 index verification).
- **INV-M24-7**: No new capability constant; `VIEW_PO` reused unmodified.
- **INV-M24-8**: `Replenishment_Defaults`'s two existing single-item getters remain byte-for-byte unmodified.
- **INV-M24-9**: `Purchase_Order_Lines::distinct_supplier_history_for_item()` remains byte-for-byte unmodified.
- **INV-M24-10**: `Reorder_Prefill_Service::resolve()`'s external return contract (including notice text) is unchanged after the `Supplier_Preference_Resolver` extraction.
- **INV-M24-11**: `Summary` remains the sole full-catalog/scoped low-stock/needs-reorder scanner — no second scan-loop implementation.
- **INV-M24-12 (new)**: No code path in `build_plan()` calls `PO_Product_Validator::validate()` or a second `wc_get_product()` for an id already resolved by the discovery step (§9.3).
- **INV-M24-13 (new)**: `Repository::query_products()`'s existing pagination-based callers (list table, dashboard, etc.) are unaffected by the additive `include` passthrough — verified by the existing repository test suite re-run unmodified and green.
- **INV-M24-14 (new)**: Resolution inputs (`Replenishment_Defaults::get_bulk()`, `distinct_supplier_history_for_items_bulk()`, `Suppliers::list_by_ids()`, display sort) never receive more than 500 items on the catalog-wide path or 100 on the scoped path, regardless of how many needs-reorder items exist catalog-wide (§9.5, BR-M24-21).
- **INV-M24-15 (new)**: No per-selected-id `wc_get_product()` loop exists anywhere in `maybe_handle_bulk()`'s new branch — the variable-parent filter uses the same bounded `include`-based lookup as scoped discovery (§10.2, BR-M24-23).

## 24. Characterization strategy

Three suites, written against **unmodified** code (items 1-2) or against existing `Repository` behavior directly (item 3, not yet exercised by any M24 code), before any production file is touched or any M24 code relies on the result:
1. `Reorder_Prefill_Service::resolve_supplier()`'s exact behavior across the full precedence matrix — supplier id, notice count/text, query count per case.
2. `Summary::scan_low_stock_and_needs_reorder()`'s exact counts and query count across a representative fixture set.
3. **(New, Rev. 3, MAJOR)** `Repository::query_products(['include' => [...]])`'s exact return set for a mixed simple/variable-parent/variation id fixture (§9.4) — must be resolved before `Summary::get_needs_reorder_items()` or the bulk-action variable-parent filter (§10.2) can be implemented, since both depend on its outcome.

Suites 1-2 must stay green, unmodified, after their respective extractions (WP-M24-2/3). Suite 3's outcome determines which of the two bounded discovery designs (§9.4) WP-M24-3 actually implements.

## 25. Test matrix (updated)

**Unit** (`tests/unit/replenishment-planning/`): `Supplier_Preference_Resolver::decide()` truth table, no DB.

**Integration** (`tests/integration/replenishment-planning/`):
- `test-repository-include-variation-proof.php` — **(new, Rev. 3)** §24 item 3, written and green *before* `Summary`'s refactor — proves BR-M24-22, decides which of §9.4's two discovery designs WP-M24-3 implements.
- `test-summary-extraction-characterization.php` — BR-M24-17.
- `test-repository-include-passthrough.php` — INV-M24-13, plus the cold-cache observed-baseline measurement for the scoped discovery path at N=10/50/100 (BR-M24-20, revised — no longer a single-query proof, a flatness proof).
- `test-build-plan-eligibility.php` — full eligibility matrix, variable-parent exclusion, non-stock-managed exclusion, covered_by_incoming exclusion.
- `test-build-plan-supplier-resolution.php` — BR-M24-5..8, plus the corrected BR-M24-4 cross-check (supplier id **and** notice/outcome parity against `Reorder_Prefill_Service::resolve()`).
- `test-build-plan-quantity.php` — BR-M24-9.
- `test-build-plan-grouping-currency.php` — BR-M24-10, multi-supplier multi-currency fixture.
- `test-build-plan-item-ids-scope.php` — BR-M24-2/3, including a scoped id that becomes stale between selection and render.
- `test-build-plan-resolution-cap.php` — **(new, Rev. 3)** BR-M24-1/11/21: a >500-needs-reorder-item catalog-wide fixture proving resolution inputs (Defaults/history/supplier calls) never exceed 500 items, and that truncation happens in gather order before the §10.3 display sort.
- `test-build-plan-ordering.php` — BR-M24-19, asserting the §10.3 display-stage ordering contract against a deliberately-scrambled DB insertion order fixture, run on top of an already-truncated set.
- `test-build-plan-truncation.php` — BR-M24-11 at 500/501-item fixtures, gather-order truncation (§9.5), pre-display-sort.
- `test-planning-tab-capability.php` — BR-M24-14.
- `test-planning-tab-visibility.php` — bulk action hidden from a non-`VIEW_PO` viewer (§10).
- `test-planning-bulk-action-nonce-and-cap.php` — BR-M24-13/15, plus the explicit worst-case 100×10-digit-ID URL-length assertion (§10.1).
- `test-planning-bulk-action-variable-parent-filter.php` — BR-M24-18/23, asserting the bounded `include`-based filter mechanism, not a per-ID loop.
- `test-replenishment-planning-query-count.php` — the full corrected §20 matrix, measuring the complete `build_plan()` call with cold-cache setup, at N=10/50/100 (scoped) and N=10/50/200/500 (catalog-wide candidates surfaced, resolution input always ≤500), asserting flatness of the observed baseline rather than an exact pre-declared count.
- `test-replenishment-planning-architecture.php` — INV-M24-1..4/11/12/14/15 sole-owner/no-mutation/no-revalidation/no-per-ID-loop grep guards.

**Regression**: full `reorder-signal`, `reorder-prefill`, `replenishment-defaults`, `supplier-merge`, `purchase-orders` suites, plus the full pre-existing repository/list-table test suite (INV-M24-13), re-run unmodified, must stay green.

## 26. Lifecycle Level A/B decision

**Level A**, with two WPs (touching previously-frozen `Reorder_Prefill_Service` and `Summary`) held to Level-B-caliber scrutiny — characterization-first, byte-identical proof — even though the overall milestone carries no mutation risk.

## 27. WP-M24-0…9 implementation ladder (updated)

**WP-M24-0 — Preflight.** Branch `feature/m24-replenishment-planning` from `main` at `6965262`. Confirm clean tree, full inherited suite green. Write this plan to `docs/milestones/m24-implementation-plan.md`, commit alone. **Stop** if any inherited test is red.

**WP-M24-1 — Characterization tests.** All three suites from §24, including the new `test-repository-include-variation-proof.php` (BR-M24-22) against existing, unmodified `Repository::query_products()` behavior. Zero production files touched. **Stop** if any assertion can't be written without touching production code first, or if the variation-ID proof's outcome is ambiguous (in which case resolve it with additional fixtures before proceeding — do not guess).

**WP-M24-2 — Extract `Supplier_Preference_Resolver`; add `Repository::query_products()`'s `include` passthrough.** New resolver file (§21); refactor `Reorder_Prefill_Service::resolve_supplier()`/`resolve_supplier_from_history()` to delegate. Additive `include` param on `Repository::query_products()` (§9.2), covered by `test-repository-include-passthrough.php`. WP-M24-1's `Reorder_Prefill_Service` characterization suite and the full pre-existing repository test suite must stay green, unmodified. BRs: supports BR-M24-4/5/6/20. INVs: INV-M24-4/10/13. **Stop** if any characterization assertion fails, any query count moves, or any existing repository caller's behavior changes.

**WP-M24-3 — Extract `Summary::gather_low_stock_candidates()`; add `get_needs_reorder_items()` with gather-order `$limit` truncation.** Refactor per §9.4/§9.5/§21, including the additive `name`/`sku`/`parent_id` candidate fields, the scoped-vs-catalog-wide gather branching (using whichever design WP-M24-1's `test-repository-include-variation-proof.php` selected — a single mixed `include` call, or the two-call product/variation fallback), built on WP-M24-2's `include` passthrough. WP-M24-1's `Summary` characterization suite must stay green, unmodified. Tests: `test-summary-extraction-characterization.php` (BR-M24-17), a parity test proving `get_needs_reorder_items()`'s itemized needs_reorder count matches `scan_low_stock_and_needs_reorder()`'s own count on the same fixture (unbounded case), `test-repository-include-passthrough.php`'s cold-cache observed-baseline measurement at N=10/50/100 (BR-M24-20), and `test-build-plan-resolution-cap.php`'s >500-item fixture proving truncation happens before any resolution-shaped call (BR-M24-21). BRs: BR-M24-1, 2, 11, 17, 20, 21, 22. INVs: INV-M24-11, 12, 14. **Stop** if the refactored `scan_low_stock_and_needs_reorder()` output or query count changes at all, if the observed scoped-discovery baseline is not flat across N=10/50/100, or if any per-ID query is observed.

**WP-M24-4 — Bulk repository methods.** Additive `Purchase_Order_Lines::distinct_supplier_history_for_items_bulk()` (exact SQL shape per §14.1, both branches) and `Replenishment_Defaults::get_bulk()`. Run `EXPLAIN` on both `distinct_supplier_history_for_items_bulk()` branches at N=100 and record `type`/`key`/`possible_keys`/`rows`/`Extra` for each branch in the PR description (§14.1's strengthened criteria — not merely "not `ALL`"). Tests: parity with N individual single-item calls, fixed-query-count proof at N=10/50/100. BRs: supports BR-M24-4/5/6/9. INVs: INV-M24-6/8/9. **Stop** if either bulk method's result disagrees with its single-item sibling on any fixture, if `EXPLAIN` shows `type=ALL` on either branch, if `key` is null on either branch, or if the product branch's plan is keyed on the low-selectivity `variation_id=0` constant instead of the `product_id IN (...)` predicate (§14.1).

**WP-M24-5 — `Replenishment_Planning_Service::build_plan()`.** New orchestrator (§21) composing `get_positions_bulk()`, `Reorder_Signal_Resolver::resolve()`, WP-M24-2's `decide()`, WP-M24-3's `get_needs_reorder_items()` (called with `$limit = self::MAX_LINES + 1` on the catalog-wide path, `$limit = 0` on the scoped path — the gather-order truncation itself lives in `Summary`, §9.5, not here), WP-M24-4's two bulk methods, `Suppliers::list_by_ids()`. **No `PO_Product_Validator::validate()` call** (§9.3/INV-M24-12). `build_plan()`'s own job is: pass the correct `$limit`, feed the (already ≤500/≤100, already-capped) `items` result into resolution, then apply the §10.3 **display**-stage ordering (supplier name, then product name) on top of that capped set — it must never re-run discovery/classification against the uncapped candidate pool. Tests: `test-build-plan-eligibility.php`, `test-build-plan-supplier-resolution.php` (incl. corrected BR-M24-4 notice-parity cross-check), `test-build-plan-quantity.php`, `test-build-plan-grouping-currency.php`, `test-build-plan-item-ids-scope.php`, `test-build-plan-resolution-cap.php`, `test-build-plan-ordering.php`, `test-build-plan-truncation.php`. BRs: BR-M24-1..3, 7, 8, 10, 11, 16, 19, 21. INVs: INV-M24-2, 3, 6, 12, 14. **Stop** if any line's supplier/qty/notice ever disagrees with what `Reorder_Prefill_Service::resolve()` alone would produce, if ordering is non-deterministic across repeated calls on identical fixtures, or if resolution ever receives more than 500 (catalog-wide) / 100 (scoped) items.

**WP-M24-6 — Admin UI + entry points, including the MAJOR/MEDIUM UX fixes.** `Purchasing_Page::TAB_PLANNING` + `render_planning_tab()` (grouped sections in §10.3 display order, `preferred_supplier_stale` badge per §10.4, `wc_io_plan_skipped` notice per §10.2). `List_Table::get_bulk_actions()` gains `wc_io_plan_replenishment`, gated on `VIEW_PO` visibility (§10). `Overview_Controller::detect_bulk_action()`/`maybe_handle_bulk()` gain the new branch: nonce check → 100-id cap enforcement (§10.1) → variable-parent filtering via the bounded `include`-based lookup, not a per-ID `wc_get_product()` loop (§10.2, corrected in Rev. 3) → PRG redirect. Tests: `test-planning-tab-render.php`, `test-planning-tab-visibility.php`, `test-planning-bulk-action-redirect.php`, `test-planning-bulk-action-variable-parent-filter.php` (asserting the bounded mechanism specifically, not just the outcome). BRs: BR-M24-12, 13, 18, 23. INVs: INV-M24-1, 15. **Stop** if the new bulk-action branch ever calls `$product->save()` or any other mutator, if a non-`VIEW_PO` viewer can still see/execute the action, or if any per-selected-id `wc_get_product()` call is observed in the variable-parent filter.

**WP-M24-7 — Security/capability tests.** `test-planning-tab-capability.php` (BR-M24-14), `test-planning-bulk-action-nonce-and-cap.php` (BR-M24-13/15, including the worst-case URL-length assertion at 100×10-digit ids, §10.1). Confirm an `edit_products`-only viewer cannot reach the Planning tab. **Stop** if any capability check can be bypassed by omitting a query arg.

**WP-M24-8 — Performance + architecture guards.** `test-replenishment-planning-query-count.php` (the corrected §20 matrix, full `build_plan()` call with cold-cache setup, N=10/50/100 scoped and N=10/50/200/500 catalog-wide candidates surfaced), `test-build-plan-resolution-cap.php` (resolution input never exceeds 500 regardless of catalog-wide candidate count, §9.5), `test-replenishment-planning-architecture.php` (INV-M24-1..4/11/12/14/15 grep/reflection guards). BRs: proves BR-M24-11/20/21 at scale. INVs: INV-M24-1..5, 11, 12, 14, 15. **Stop** if any observed query-count baseline fails to stay flat across N, if any per-ID query is observed anywhere in the resolution or bulk-action-filter paths, if resolution input ever exceeds 500/100 items, or if any new class contains a write-capable `$wpdb` call or a `PO_Product_Validator`/second `wc_get_product` reference.

**WP-M24-9 — Docs/version/freeze/CI.** Version `1.40.0` → `1.41.0` (development target — see §32 for the corrected release strategy); `DB_VERSION` untouched at 11; `## [1.41.0] - Unreleased` CHANGELOG entry; `CLAUDE.md` Implementation Status row (marked "frozen, awaiting M25 for combined train release" per §32); `docs/checklists/m24-release-readiness.md`; brief purchasing/admin guide note. Full validation per §28. **Stop** condition: any BR/INV unsatisfied — remediate within scope; never merge/tag/release under an unresolved finding.

## 28. Validation strategy

Per-WP: narrow/targeted PHPUnit filter runs only, fix failures immediately, continue. One comprehensive pass at WP-M24-9 only: full unit suite; full M1–M24-focused suite; full integration suite; M24-specific suite; M22/M23 regression suites plus the full repository/list-table suite (INV-M24-13) re-run unmodified; `--list-tests` proof; PHPCS lint clean; `composer validate --strict`; `docker compose config`; `scripts/release-audit.sh --development`; push branch, open draft PR, obtain green CI. AI-driven runtime acceptance via `wp eval-file`.

## 29. Documentation plan

- `CHANGELOG.md` — new `## [1.41.0] - Unreleased` entry.
- `CLAUDE.md` — new M24 row, noted as frozen-awaiting-train per §32.
- `docs/milestones/m24-implementation-plan.md` — this plan, materialized at WP-M24-0.
- `docs/checklists/m24-release-readiness.md` — Level A completion review, including the `EXPLAIN`-plan evidence from WP-M24-4 (§14.1) and the URL-length test evidence from WP-M24-7 (§10.1).
- Purchasing/admin guide — brief description of the Planning tab, its two entry points, the 100-id bulk-action cap, and variable-parent-selection behavior.

## 30. Version recommendation

`1.41.0` as the development target (internal versioning during the M24 branch), consistent with convention — but see §32 for when it actually gets tagged/published.

## 31. DB_VERSION recommendation

Stays `11`. No schema change; §14.1 confirms existing indexes are sufficient for the new bulk SQL.

## 32. Release/feature-train strategy (corrected — PROCESS fix)

**Bundle M24+M25 as one feature train, mirroring the exact M22+M23 precedent — do not release M24 standalone.** The original draft recommended a standalone M24 release on the grounds that the Planning screen is independently useful; that's true, but M25 is already clearly identified as the mutation half that consumes M24's exact output contract (`build_plan()`'s return shape, §21), and shipping v1.41.0 for M24 alone followed shortly by a separate v1.42.0 for M25 creates avoidable release/deployment/rollback-rehearsal work for two milestones that are really one workflow story to a merchant. **Revised plan:** M24 completes its own full WP0-WP4 cycle (implementation, independent audit, remediation, freeze) on `feature/m24-replenishment-planning` and stays frozen-but-unreleased, exactly as M22 did while awaiting M23 (the `1.39.0` intermediate version was never tagged/published standalone — same pattern applies here, M24's `1.41.0` development version likely stays untagged until M25 joins it). M24's own Level A completion review and readiness checklist still happen at freeze time, independently of M25's later Level B review — only the WP6 *release/tag* step is deferred and combined. If M25's scope or timeline turns out to be substantially delayed, standalone release remains an available fallback, but it is not the default plan.

## 33. M25 boundary

Unchanged: M24 does not create any Purchase Order, call `create_draft()`, provide a submit/commit button, persist a plan beyond one request/redirect, decide partial-failure semantics, re-check eligibility at a mutation-time POST (none exists), or let a merchant edit a suggested quantity before commit. M25 will re-run `build_plan()` fresh at its own submit time, never trusting a value cached from M24's earlier render.

## 34. Risks/findings (updated)

- **Refactor risk (WP-M24-2, WP-M24-3)**: two previously-frozen files touched. Mitigated by characterization-first ordering, full regression re-runs, Level-B-caliber scrutiny (§26).
- **IN-list execution-time scaling (§14.1)**: flat query *count* is proven, but per-query cost still grows somewhat with `IN (...)` list size at the 100-id bound — accepted, consistent with this repo's established measurement convention, called out explicitly rather than left implicit.
- **Full-catalog scan cost**: the pre-existing 40-page/200-per-page ceiling (inherited, unmodified) could theoretically miss needs-reorder items beyond page 40 (8,000 low-stock candidates) — not a new M24 risk.
- **No in-screen deselection**: the only way to scope the plan is pre-selecting on Inventory Overview before navigating (now bounded to 100), or viewing the full catalog-wide plan (bounded to 500 via `MAX_LINES`); acceptable for read-only M24.
- **Variable-parent-selection UX**: rejecting with a notice (§10.2) means a merchant who selects a variable parent gets zero of its variations planned automatically; they must expand and reselect. Documented in the admin guide (§29) as intended behavior, not a bug.
- **(New, Rev. 3) Discovery-design fork risk**: §9.4's characterization test could go either way (single mixed `include` call vs. two-call product/variation fallback) — WP-M24-3's estimate/complexity is mildly uncertain until WP-M24-1 resolves this. Both outcomes stay within the "bounded, N-independent, no per-ID loop" contract, so the risk is scoping/complexity only, not correctness.
- **(New, Rev. 3) A very unbalanced catalog could still classify many items before truncation kicks in**: §9.5's `classify_needs_reorder_bulk()` call still runs once over the full gathered (≤8,000) low-stock candidate set before truncation is applied — this is unchanged, inherited behavior (flat query count, in-memory PHP cost proportional to gathered candidates) and was never the resource risk this revision addresses; only the *resolution* stage (supplier/defaults/history/sort) is newly bounded to ≤500.

## 35. Stop conditions (updated, Rev. 3)

- Any inherited M1–M23 test goes red at any point.
- Any characterization assertion fails to stay green after its corresponding extraction, including the new pre-refactor `test-repository-include-variation-proof.php` (§9.4) — if its outcome is ambiguous, resolve with further fixtures before WP-M24-3 proceeds.
- Any observed cold-cache query-count baseline (discovery, scoped or catalog-wide) fails to stay flat across N=10/50/100 at WP-M24-3/WP-M24-8, or any per-ID product query is observed anywhere in the discovery or resolution paths.
- Resolution input (to `Replenishment_Defaults::get_bulk()`, `distinct_supplier_history_for_items_bulk()`, `Suppliers::list_by_ids()`, or the display sort) ever exceeds 500 items on the catalog-wide path or 100 on the scoped path (§9.5).
- `EXPLAIN` shows `type=ALL`, a null `key`, or a plan keyed on the low-selectivity `variation_id=0` constant instead of `product_id IN (...)` on either branch of `distinct_supplier_history_for_items_bulk()` at WP-M24-4 (§14.1).
- Any new class contains a write-capable `$wpdb`/`update_post_meta`/`->save()` call, a `PO_Product_Validator`/second `wc_get_product()` reference inside `build_plan()`'s per-item loop, or a per-selected-id `wc_get_product()` call in the bulk-action variable-parent filter.
- The worst-case 100×10-digit-ID bulk-action redirect URL exceeds 2,048 characters at WP-M24-7.
- Any BR-M24/INV-M24 item is unsatisfied at WP-M24-9's freeze gate.

## 36. Self-review findings (updated)

1-12 as in the original draft (duplication avoided, scales, no mutation risk, appropriate security gate, no needless nonce, eligibility rule reused not reinvented, no stale data trusted, currency handling non-speculative, quantity default honest, duplicate detection explicitly deferred, stands alone as merchant value, WP ladder implementation-grade) — all reconfirmed under the corrected design; §12's currency/eligibility claims are unaffected by the amendment.
13. **Frozen-code touches given commensurate care despite Level A?** Yes, and now correctly scoped to **two** WPs (M24-2 for `Reorder_Prefill_Service`'s `Repository` dependency and `resolve_supplier()`, M24-3 for `Summary`) rather than the original draft's one — the review that produced this revision is itself the proof that the extra scrutiny was warranted, since it caught that `Summary`'s extraction needed to be more substantial than a visibility change.
14. **(New) Was the original query-count claim actually measuring the full resolution path?** No — corrected in §20 to explicitly include product-loading cost and to remove the double-counted `PO_Product_Validator` re-check entirely rather than trying to make it cheap.
15. **(New) Is the redirect URL genuinely safe at the new cap?** Yes, verified by direct arithmetic in §10.1 with margin, and pinned by an explicit worst-case test (WP-M24-7) rather than an estimate.
16. **(New) Is scoped discovery genuinely bounded, not hand-waved?** Yes — resolved to a concrete, verified WooCommerce-native mechanism (`wc_get_products()`'s `include` arg) with an exact one-line additive diff (§9.2), not left as an open question.
17. **(New) Is the variable-parent checkbox case actually possible, and is the chosen behavior explicit?** Yes — confirmed via `column_cb()`'s unconditional rendering, and resolved to reject-with-notice, not left implicit.
18. **(New, Rev. 3) Was "exactly one query" ever a claim this plan could actually stand behind?** No — corrected to an observed, cold-cache, N-independent baseline (§9.2/§20), which is the honest version of the same underlying requirement (no per-ID scaling), not a weaker one.
19. **(New, Rev. 3) Was variation-ID support through the new `include` path verified, or assumed because it seemed likely?** Assumed in Rev. 2 (WooCommerce's own type-array handling made it plausible, but plausible is not proven) — corrected to a mandatory pre-refactor characterization test with a named fallback design, so WP-M24-3 never discovers this mid-implementation (§9.4).
20. **(New, Rev. 3) Did `MAX_LINES = 500` actually protect against the inherited ~8,000-candidate ceiling, or only against the display list?** Only the display list, in Rev. 2 — the real fix moves truncation between classification and resolution (§9.5), so the expensive `IN (...)`-shaped resolution work is bounded to ≤500 regardless of how large the catalog-wide candidate pool gets.

## 37. Exact implementation base SHA

`6965262bb035697c66427b6f907480042a03e5e6` (main, successor to tag `v1.40.0`).

## 38. Exact next operation after approval

`git checkout -b feature/m24-replenishment-planning 6965262bb035697c66427b6f907480042a03e5e6`, then begin WP-M24-0.

---

### Critical files for implementation

- `includes/class-wc-inventory-overview-reorder-prefill-service.php` (extraction target, WP-M24-2)
- `includes/class-wc-inventory-overview-summary.php` (extraction target, WP-M24-3)
- `includes/class-wc-inventory-overview-repository.php` (`include` passthrough, WP-M24-2)
- `includes/class-wc-inventory-overview-inventory-position-service.php` (bulk-pattern model, `get_positions_bulk()`)
- `includes/class-wc-inventory-overview-purchase-order-lines.php` (new bulk history method target, index-verified §14.1)
- `includes/class-wc-inventory-overview-replenishment-defaults.php` (new `get_bulk()` target)
- `includes/class-wc-inventory-overview-po-product-validator.php` (confirmed NOT to be called from `build_plan()`, §9.3)
- `includes/class-wc-inventory-overview-list-table.php` (`column_cb()` variation-checkbox confirmation, §10.2)
- `includes/class-wc-inventory-overview-overview-controller.php` (bulk-action wiring, cap + variable-parent filter)
- `includes/class-wc-inventory-overview-purchasing-page.php` (new Planning tab)
- `includes/class-wc-inventory-overview-suppliers.php` (`list_by_ids()`, `is_eligible_for_selection()`)

M24 PLANNING COMPLETE — READY FOR REVIEW
