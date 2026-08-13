# M19 Definitive Implementation Plan — Admin Controller Decomposition, Phase 2

## Context

M18 (frozen at `2862b8c` on `feature/m18-admin-controller-decomposition`, unreleased, Level A, CI-green) extracted the two fully self-contained tabs — Dashboard and Settings — out of the 2,706-line `WC_Inventory_Overview_Plugin` god class, using a characterization-tests-first discipline. It explicitly deferred the remaining five tabs (Overview, Restock, Movements, Order Profit, Product Profitability) to "Phase 2, a future dedicated milestone" (`docs/architecture-audit.md:585`). Plugin is now 1,561 lines. This plan determines the correct Phase 2 slice by mapping every remaining method in the class from the actual M18-frozen source (not from the assumed tab list) and selecting the largest cluster that stays genuinely coherent and Level A.

The mapping (§2 below) overturns two assumptions embedded in the initial ask: the "inline stock/cost-adjust AJAX" is not one Restock-owned thing — `ajax_save_inline_stock` is Overview's inline list-edit feature and `ajax_get_cost_adjustment_preview` is Restock's own preview feature, unrelated to each other. And "bulk actions" is entirely an Overview feature (`maybe_handle_bulk`/`detect_bulk_action`, called only from Overview's own load hook) — Restock and the reporting tabs have no bulk-action code at all. Once the method inventory is corrected against the real source, three of the five remaining tabs — **Movements, Order Profit, Product Profitability** — turn out to be structurally identical, read-only, zero-mutation, zero-AJAX, zero-bulk-action, zero-raw-SQL-in-Plugin report screens that already delegate everything to existing `*_List_Table`/`*_Query` domain classes. Overview and Restock, by contrast, each carry real mutation surface (Overview: bulk product-status/visibility/stock mutation + inline-stock AJAX mutation; Restock: two admin-post handlers that call `Restock_Service`/`Cost_Adjustment_Service` to change stock and weighted-average cost) and are legitimately harder, riskier, and better left for a separate future phase — exactly the same "bound the risk, don't fake urgency into one milestone" reasoning M18 itself used to defer four of six original tabs.

M19 therefore extracts the three read-only reporting tabs into one new `WC_Inventory_Overview_Reporting_Controller`, continuing the M18 branch lineage (M18 is unreleased, so M19 branches from the M18 frozen tip, not `main`), and explicitly leaves Overview and Restock — and the god-class decomposition project as a whole — incomplete, to be named honestly in the updated architecture-audit entry as "Phase 3, future milestone."

---

## 1. Verified post-M18 baseline

Confirmed directly against the repository (2026-08-12, `git fetch origin` run, read-only):

| Check | Result |
|---|---|
| `feature/m18-admin-controller-decomposition` tip | `2862b8c38ada5f147c1f715ed1b370990c2cd7e6` — "docs(m18): record final CI and freeze evidence" |
| M18 plan immutability | `docs/milestones/m18-implementation-plan.md` touched by exactly one commit (`0d06456`, WP-M18-0); untouched since |
| M18 release-readiness artifact | `docs/checklists/m18-release-readiness.md` present at M18 tip, Status: "Frozen and CI-Green (verified)" |
| Draft PR #22 | `state: OPEN`, `isDraft: true` — not merged |
| `main` | `bfdde20` — "docs(release): record v1.34.0 publication" — unchanged since M18 branched; M18 not merged into it |
| Plugin version @ M18 tip | `1.35.0` |
| `DB_VERSION` @ M18 tip | `'11'` (unchanged) |
| M19 branch | None exists (`git branch -a` has no `*m19*`) |
| M19 plan file | None exists (`docs/milestones/` has no `*m19*`) |
| M19 implementation | None exists (`find . -iname '*m19*'` empty outside `.git`) |

No repository-reality contradiction found. This plan uses the M18-frozen `includes/class-wc-inventory-overview-plugin.php` (1,561 lines) as its design baseline, read in full.

---

## 2. Remaining Plugin method inventory (complete, from M18-frozen source)

Every method in `class-wc-inventory-overview-plugin.php` at `2862b8c`, classified per the taxonomy in the ask (A–M), with exact line ranges, LOC, hooks, callers, and dependency notes.

### A. Tab routing / top-level shell (stays on Plugin permanently — INV-M19-1)

| Method | Vis | Lines | LOC | Notes |
|---|---|---|---|---|
| `instance()` | public static | 43–48 | 6 | Singleton |
| `init()` | public | 50–67 | 18 | Registers all remaining hooks; bootstraps `Settings_Controller`/`Purchasing_Page`/etc. |
| `register_menu()` | public | 69–78 | 10 | `add_submenu_page` → `render_inventory_profit_shell` |
| `get_tabs_definition()` | protected | 129–160 | 32 | Tab slug/label/cap map — all 7 tabs incl. Dashboard/Settings |
| `get_requested_tab()` | public | 167–178 | 12 | Already promoted public in M18 |
| `admin_url_tab()` | public | 187–198 | 12 | Already promoted public in M18 |
| `on_load_inventory_profit_page()` | public | 252–274 | 23 | Single `load-woocommerce_page_*` hook; dispatches to per-tab `on_load_*` methods by tab (**3 of its 4 dispatch branches move in this plan — see §12**) |
| `render_inventory_profit_tabs()` | protected | 350–363 | 14 | Nav-tab markup, iterates `get_tabs_definition()` |
| `render_tab_placeholder()` | protected | 370–373 | 4 | **Dead code** — zero callers anywhere in the repo (confirmed by full-repo grep). Not moved, not deleted (out of scope; flagged for a future cleanup, not this plan) |
| `render_inventory_profit_shell()` | public | 425–486 | 62 | Page-callback shell + tab switch; **3 of its case-blocks move in this plan — see §12** |

### J. Legacy redirect / compatibility (stays on Plugin)

| Method | Vis | Lines | LOC | Notes |
|---|---|---|---|---|
| `redirect_legacy_inventory_admin_pages()` | public | 83–101 | 19 | Hooked `admin_init`; old-slug bookmarks |
| `redirect_to_inventory_profit_tab()` | protected | 106–122 | 17 | Helper, only caller is the method above |

### C. Restock (excluded from M19 — see §5)

| Method | Vis | Lines | LOC | Hooks/mutation |
|---|---|---|---|---|
| `get_restock_subview()` | protected | 208–221 | 14 | Helper |
| `render_restock_subnav()` | protected | 228–247 | 20 | |
| `on_load_restock_screen()` | public | 339–343 | 5 | Dispatched from `on_load_inventory_profit_page` |
| `enqueue_restock_assets()` | public | 493–562 | 70 | Own `admin_enqueue_scripts` registration (self-contained, not shared) |
| `handle_restock_post()` | public | 576–622 | 47 | **Mutation**: `admin_post_wc_io_restock` → `Restock_Service::process_purchase_restock()` |
| `handle_cost_adjustment_post()` | public | 627–669 | 43 | **Mutation**: `admin_post_wc_io_cost_adjustment` → `Cost_Adjustment_Service::process()` |
| `render_restock_panel()` | protected | 674–782 | 109 | Two forms (restock + cost-adjustment) |
| `ajax_get_cost_adjustment_preview()` | public | 1281–1340 | 60 | `wp_ajax_wc_io_get_cost_adjustment_preview` — **read-only**, Restock-scoped (nonce `wc_io_cost_adj_preview`, localized only in `enqueue_restock_assets`) |

Restock subtotal ≈ 368 LOC across 8 methods, 3 hooks (1 own `admin_enqueue_scripts`, 2 `admin_post_*`), 1 AJAX. Two genuine mutation admin-post handlers.

### B/H/I. Overview + its exclusive bulk-action and inline-AJAX infrastructure (excluded from M19 — see §5)

| Method | Vis | Lines | LOC | Hooks/mutation |
|---|---|---|---|---|
| `on_load_screen()` | public | 908–924 | 17 | Dispatched from `on_load_inventory_profit_page`; calls the two below |
| `maybe_export_csv()` | protected | 1030–1134 | 105 | CSV export, **hand-built `fputcsv`**, not the `List_Table::export_csv_to_stream` pattern the other three tabs use |
| `get_query_params_from_request()` | protected | 1141–1165 | 25 | Feeds `maybe_export_csv()` only. **Note:** `WC_Inventory_Overview_List_Table::prepare_items()` independently re-derives the same GET/REQUEST params rather than calling this method — pre-existing duplicate logic, not a call dependency; out of scope to fix here |
| `maybe_handle_bulk()` | protected | 1167–1224 | 58 | **Mutation**: loops `$_REQUEST['post']` IDs, sets product status/visibility/stock-status — confirmed Overview-exclusive (only caller is `on_load_screen()`) |
| `detect_bulk_action()` | protected | 1229–1241 | 13 | Helper for the above |
| `ajax_save_inline_stock()` | public | 1243–1276 | 34 | **Mutation**: `wp_ajax_wc_io_save_inline_stock` — confirmed Overview-exclusive (nonce `wc_io_inventory`, localized only when `TAB_OVERVIEW` in `enqueue_assets()`) |
| `render_summary_cards()` | **public static** | 1411–1472 | 62 | Only caller in the repo is Overview's own render method (`self::render_summary_cards(...)` at line 1501) — not actually shared despite being `public static` |
| `render_inventory_overview_panel()` | protected | 1477–1512 | 36 | |
| (top-level filter) `set_screen_option_wc_io_per_page` | — | 1515–1525 | 11 | Overview's own per-page screen option |

Overview subtotal ≈ 361 LOC across 8 methods + 1 filter, 2 mutation surfaces (bulk actions, inline AJAX), plus a share of `enqueue_assets()` (below).

### D/E/F/G — Movements, Order Profit, Product Profitability + their CSV export (SELECTED for M19 — see §5)

| Method | Vis | Lines | LOC | Tab |
|---|---|---|---|---|
| `on_load_movements_screen()` | public | 279–294 | 16 | Movements |
| `render_movements_panel()` | protected | 787–809 | 23 | Movements |
| `maybe_export_movements_csv()` | protected | 929–958 | 30 | Movements |
| (top-level filter) `set_screen_option_wc_io_movements_per_page` | — | 1527–1537 | 11 | Movements |
| `on_load_order_profit_screen()` | public | 299–314 | 16 | Order Profit |
| `render_order_profit_panel()` | protected | 814–865 | 52 | Order Profit |
| `maybe_export_order_profit_csv()` | protected | 963–993 | 31 | Order Profit |
| (top-level filter) `set_screen_option_wc_io_order_profit_per_page` | — | 1539–1549 | 11 | Order Profit |
| `on_load_product_profitability_screen()` | public | 319–334 | 16 | Product Profitability |
| `render_product_profitability_panel()` | protected | 870–906 | 37 | Product Profitability |
| `maybe_export_product_profitability_csv()` | protected | 998–1028 | 31 | Product Profitability |
| (top-level filter) `set_screen_option_wc_io_product_profitability_per_page` | — | 1551–1561 | 11 | Product Profitability |

Selected-cluster subtotal ≈ **285 LOC** across 9 methods + 3 filters, **0 hooks of their own** (all reached only via the shared `on_load_inventory_profit_page`/`render_inventory_profit_shell` dispatchers, which stay on Plugin), **0 mutation**, **0 AJAX**, **0 raw SQL in Plugin** (confirmed: `grep` for `$wpdb`/`global $wpdb` inside `class-wc-inventory-overview-plugin.php` returns zero matches in any of these nine methods — all delegate to `WC_Inventory_Overview_Movements_List_Table`, `WC_Inventory_Overview_Order_Profit_{Query,List_Table}`, `WC_Inventory_Overview_Product_Profitability_{Query,List_Table}`, which independently own their `$wpdb` usage today and are unaffected by this plan).

### K/G. Shared bootstrap / cross-tab asset infrastructure (split required — see §8)

| Method | Vis | Lines | LOC | Spans |
|---|---|---|---|---|
| `enqueue_assets()` | public | 1342–1406 | 65 | **Dashboard + Overview + Movements**, one `admin_enqueue_scripts` callback with internal tab branching |

### L. Generic helper used only by Overview

| Method | Vis | Lines | LOC | Notes |
|---|---|---|---|---|
| `render_summary_cards()` | public static | 1411–1472 | 62 | Listed under Overview above; included here only to note its `public static` visibility is unused externally — no change needed, stays with Overview |

**Grand total remaining in Plugin at M18 tip: 1,561 lines.** Selected-for-M19 ≈ 285 LOC (methods/filters) + a small slice of `enqueue_assets()` (~15 LOC). Excluded-but-remaining: Restock ≈ 368 LOC, Overview ≈ 361 LOC + rest of `enqueue_assets()`, permanent shell/bootstrap (category A/J) ≈ 530 LOC. These three groups sum to ≈ 1,561, consistent with the full-file line count (rounding from LOC-per-method estimates against blank lines/comments/braces not itemized above).

---

## 3. Dependency graph

```
on_load_inventory_profit_page()  [stays: Plugin, A]
  ├─ on_load_screen()                         → Overview cluster [excluded]
  │    ├─ maybe_export_csv() ── get_query_params_from_request()
  │    └─ maybe_handle_bulk() ── detect_bulk_action()
  ├─ on_load_restock_screen()                 → Restock cluster [excluded]
  ├─ on_load_movements_screen()               → Reporting cluster [SELECTED]
  │    (no export inside on_load; export is its own if() on wc_io_mv_export)
  ├─ on_load_order_profit_screen()            → Reporting cluster [SELECTED]
  └─ on_load_product_profitability_screen()   → Reporting cluster [SELECTED]

render_inventory_profit_shell()  [stays: Plugin, A]
  ├─ case TAB_DASHBOARD  → Dashboard_Controller::render()          [M18, done]
  ├─ case TAB_OVERVIEW   → render_inventory_overview_panel()       [excluded]
  ├─ case TAB_RESTOCK    → render_restock_panel()                  [excluded]
  ├─ case TAB_MOVEMENTS  → render_movements_panel()                [SELECTED]
  ├─ case TAB_ORDER_PROFIT → render_order_profit_panel()           [SELECTED]
  ├─ case TAB_PRODUCT_PROFITABILITY → render_product_profitability_panel() [SELECTED]
  └─ case TAB_SETTINGS   → Settings_Controller::render()           [M18, done]

enqueue_assets()  [admin_enqueue_scripts, Plugin, K]  — 3-way shared, must split (§8)
  ├─ TAB_DASHBOARD branch  → logically Dashboard_Controller's, physically still here (M18 left this alone — its own data contract only covered render/date-filters, not enqueue)
  ├─ TAB_OVERVIEW branch   → Overview [excluded, stays]
  └─ TAB_MOVEMENTS branch  → Reporting cluster [SELECTED — must move]

ajax_save_inline_stock()          → Overview only (nonce wc_io_inventory, localized only for TAB_OVERVIEW in enqueue_assets)  [excluded]
ajax_get_cost_adjustment_preview() → Restock only (nonce wc_io_cost_adj_preview, localized only in enqueue_restock_assets) [excluded]

maybe_export_movements_csv() / _order_profit_ / _product_profitability_
  — each independent, no shared export helper (unlike Overview's maybe_export_csv, each of
    these three calls `List_Table::export_csv_to_stream()` directly with that tab's own
    filters; no coupling to each other or to Overview's CSV path)
```

**Tight clusters identified:**
- **Movements + Order Profit + Product Profitability** — no coupling to each other beyond structural symmetry (same pattern, no shared call path); this is the coherent cluster.
- **Overview's bulk actions + Overview's CSV export + Overview's inline-AJAX** — all Overview-exclusive, tightly self-coupled (all reachable only from `on_load_screen()` or `enqueue_assets()`'s Overview branch), but NOT coupled to any of the three reporting tabs.
- **Restock's two mutation handlers + preview AJAX + own enqueue** — self-coupled, NOT coupled to Overview or the reporting tabs.
- **`enqueue_assets()`** is the only method that crosses cluster boundaries (Dashboard/Overview/Movements) and requires a deliberate split (§8), not a move.

**Correction to the ask's framing:** Option E in the original ask ("Extract Restock + its inline stock/cost-adjust AJAX and bulk actions") does not match the code. `ajax_save_inline_stock` and bulk actions are Overview's, not Restock's; Restock's only AJAX is the unrelated `ajax_get_cost_adjustment_preview`. No candidate combining Restock with "inline stock AJAX and bulk actions" is coherent, because that AJAX and those bulk actions belong to a different tab entirely.

---

## 4. Phase 2 candidate comparison

| Option | Scope | LOC removed | Methods | Hooks moved | Mutations | AJAX | Raw SQL in Plugin | Cross-tab coupling to resolve | Level |
|---|---|---|---|---|---|---|---|---|---|
| A | Movements only | ~80 | 4 | 0 (dispatch-only) | 0 | 0 | 0 | `enqueue_assets()` Movements branch | A |
| B | Movements + its CSV | same as A (CSV already included) | — | — | — | — | — | — | A |
| C | Order Profit + Product Profitability | ~207 | 8 | 0 | 0 | 0 | 0 | none | A |
| D | Order Profit + Product Profitability + shared CSV | same as C (no separately-shared CSV helper exists between them) | — | — | — | — | — | — | A |
| E | Restock + "inline stock/cost-adjust AJAX" + bulk actions | **not coherent — see §3 correction**; the named AJAX/bulk actions aren't Restock's | 368 (Restock alone) | 3 (2 admin-post + 1 own enqueue) | **2** | 1 | 0 | none (self-contained) | **B** (real mutation relocation) |
| F | Overview only | 361 | 8 + 1 filter | 0 dedicated (shares `enqueue_assets`, `ajax_save_inline_stock`, bulk hooks registered in `init()`) | **2** (bulk + inline AJAX) | 1 | 0 | `enqueue_assets()` Overview branch | **B** (mutation + duplicate-params debt) |
| G | All remaining tabs (Overview+Restock+Movements+Order Profit+Product Profitability) | ~1,014 | 25 | 5 | 4 | 2 | 0 | full `enqueue_assets()` split, `on_load_inventory_profit_page` full rewrite, `render_inventory_profit_shell` full rewrite | **B**, and a disguised multi-milestone refactor — rejected per §5 default principle |
| **I (selected)** | **Movements + Order Profit + Product Profitability** | **~285 (+~15 from `enqueue_assets` split)** | **9 + 3 filters** | **0 new** (dispatch-only, same pattern as M18) | **0** | **0** | **0** | **`enqueue_assets()` Movements branch only — small, mechanical** | **A** |

Option I is not literally any of the ask's pre-listed A–H options; it is the corrected, code-grounded version of D extended to include Movements, which the actual dependency graph shows belongs in the same "read-only report" cluster (identical structural pattern, zero mutation, zero coupling to Overview/Restock — only coupling is the small mechanical `enqueue_assets()` split, which is no harder for Movements than it would be to leave Movements out and still have to touch that method for nothing).

---

## 5. Selected M19 scope

**Name:** M19 — Admin Controller Decomposition, Phase 2 (Reporting Tabs: Movements, Order Profit, Product Profitability)

**New class:** `WC_Inventory_Overview_Reporting_Controller` (`includes/class-wc-inventory-overview-reporting-controller.php`), following the exact `instance()`/`init()`-optional pattern established by `Dashboard_Controller` (no dedicated hooks of its own beyond the small `admin_enqueue_scripts` slice described in §8) and `Settings_Controller` (own file, own singleton).

**In scope:** the 9 methods + 3 top-level screen-option filters in the "SELECTED" table in §2, plus the Movements-specific branch of `enqueue_assets()` (§8).

**Explicitly excluded (per §4's default decision principle — prefer one coherent cluster over maximum reduction):**
- **Overview** — real mutation surface (bulk actions, inline-stock AJAX), a pre-existing params-duplication wrinkle with `List_Table`, and a share of `enqueue_assets()`. Left for a future Phase 3.
- **Restock** — two genuine mutation admin-post handlers calling `Restock_Service`/`Cost_Adjustment_Service` (real stock/costing writes), its own AJAX preview, its own enqueue and subnav. Left for a future Phase 3.
- `on_load_inventory_profit_page()`, `render_inventory_profit_shell()`, `render_inventory_profit_tabs()`, `get_tabs_definition()`, `get_requested_tab()`, `admin_url_tab()`, `register_menu()`, `init()`, `instance()`, legacy-redirect methods — INV-M19-1: Plugin remains sole tab-routing owner, exactly as INV-M18-1 established for Dashboard/Settings.
- `render_tab_placeholder()` — dead code, not moved, not deleted (out of scope).
- `WC_Inventory_Overview_Movements_List_Table`, `Order_Profit_{Query,List_Table}`, `Product_Profitability_{Query,List_Table}` — reused unchanged, exactly as `Settings`/`Exchange_Rates`/`Data_Reset` were reused unchanged in M18.

**Expected LOC reduction:** ≈300 lines out of Plugin's remaining 1,561 (≈19%), bringing Plugin to ≈1,260 lines. Combined with M18, Plugin will have shrunk from 2,706 → ≈1,260 lines (≈53% total) across the two phases, with Overview (≈361 LOC) and Restock (≈368 LOC) — the two mutation-bearing tabs — still to be addressed in a future Phase 3.

---

## 6. Lifecycle classification

**LEVEL A.**

Justification against the ask's own Level-B triggers:
- Complex mutation paths — **none**; the selected cluster is 100% read-only.
- Security-sensitive AJAX/admin-post relocation — **none**; zero AJAX, zero admin-post handlers move.
- Bulk stock/cost operations — **none**; bulk actions are Overview's and are explicitly excluded.
- Query ownership changes — **none**; all `$wpdb` usage already lives in `List_Table`/`Query` classes untouched by this plan, confirmed by direct grep.
- Cross-tab coupling — **one small, mechanical case** (`enqueue_assets()`'s Movements branch), fully specified in §8, not a redesign.
- Raw SQL relocation — **none exists to relocate**.
- High-risk CSV/export behavior — the three CSV exports are each already isolated, single-purpose, `List_Table::export_csv_to_stream`-delegating methods; no new export logic is introduced, only relocated verbatim.

This is, if anything, a *lower*-risk extraction than M18's Settings half (which carried two real mutation handlers — save settings, exchange-rate CRUD — and one destructive Danger-Zone-Apply deletion path). The same Level A completion-review process that closed M18 applies here.

---

## 7. Canonical implementation base

M18 is **unreleased** (frozen, draft PR #22, not merged into `main`). Per the ask's explicit instruction, M19 branches from the **M18 frozen tip**, not `main`:

**Canonical base SHA:** `2862b8c38ada5f147c1f715ed1b370990c2cd7e6` (tip of `feature/m18-admin-controller-decomposition`)

**New branch:** `feature/m19-admin-controller-decomposition-phase2`, created from that exact commit.

WP-M19-0 must re-verify this SHA is still the tip of the M18 branch (and that the M18 branch still has not been merged/tagged/released) before creating the M19 branch — if M18 has been released or altered since this plan was written, that is a stop condition (§21).

---

## 8. Ownership map / shared-helper policy

| Concept | Owner after M19 |
|---|---|
| Tab slugs, labels, cap map, current-tab resolution, tab URL building, page registration, page shell/dispatch, legacy redirects | `WC_Inventory_Overview_Plugin` (unchanged — INV-M19-1) |
| Movements screen bootstrap, rendering, CSV export | `WC_Inventory_Overview_Reporting_Controller` (new) |
| Order Profit screen bootstrap, rendering, CSV export | `WC_Inventory_Overview_Reporting_Controller` (new) |
| Product Profitability screen bootstrap, rendering, CSV export | `WC_Inventory_Overview_Reporting_Controller` (new) |
| Movements/Order-Profit/Product-Profitability's own shared JS/CSS enqueue (see below) | `WC_Inventory_Overview_Reporting_Controller` (new) |
| Overview (rendering, bulk actions, inline-stock AJAX, CSV export) | `WC_Inventory_Overview_Plugin` (unchanged, deferred) |
| Restock (rendering, mutation handlers, preview AJAX) | `WC_Inventory_Overview_Plugin` (unchanged, deferred) |
| Movement/Order-Profit/Product-Profitability domain queries and raw SQL | `WC_Inventory_Overview_Movements_List_Table`, `Order_Profit_{Query,List_Table}`, `Product_Profitability_{Query,List_Table}` (unchanged) |

**`enqueue_assets()` shared-helper decision (Policy A-variant — matches the M18 `Settings_Controller` precedent exactly):**

`Plugin::enqueue_assets()` currently branches on tab for Dashboard, Overview, and Movements inside one `admin_enqueue_scripts` callback, and additionally registers the base `wc-inventory-overview-admin` stylesheet shared across all three. `Settings_Controller` already established the precedent of a controller independently re-registering that same stylesheet handle in its own `admin_enqueue_scripts` callback (`enqueue_settings_shipping_assets`) rather than depending on Plugin's copy — `wp_enqueue_style()` is idempotent per handle, so two callbacks registering the identical handle/deps/version is harmless (already proven in production by M18).

Decision: `Reporting_Controller` registers its **own** `admin_enqueue_scripts` callback (hooked in a small `init()`, mirroring `Settings_Controller`), guarded by `hook_suffix === 'woocommerce_page_' . Plugin::PAGE_SLUG && Plugin::instance()->get_requested_tab() === Plugin::TAB_MOVEMENTS`, which enqueues both the shared `wc-inventory-overview-admin` handle and `wc-io-movements-table`. `Plugin::enqueue_assets()` loses the `TAB_MOVEMENTS` case entirely (both the `if()` guard's `TAB_MOVEMENTS` term and the `if (self::TAB_MOVEMENTS === $tab)` block), leaving it a 2-way Dashboard/Overview method. This is **strategy A** from the ask's §8 menu ("keep helper on Plugin and expose... accessor") inverted to its already-proven sibling form (duplicate the *registration call*, not any *business logic* — zero logic duplication, matching the ask's "no copy/paste duplication of business logic" constraint) — not strategy D (moving the whole cluster together), since Dashboard/Overview are explicitly out of scope.

No other shared helper crosses the M19 selection boundary. `get_query_params_from_request()` is Overview-only (confirmed, §2/§3). `render_summary_cards()` is Overview-only despite `public static` visibility (confirmed, §2). Order Profit and Product Profitability each already call their own `*_Query::get_filters_from_request()` — no shared filter-parsing helper exists between them or with Movements to decide on.

---

## 9. Raw SQL / query ownership

**No raw SQL exists inside any of the nine selected methods.** Confirmed by direct grep of `class-wc-inventory-overview-plugin.php` for `$wpdb`/`global $wpdb` — zero matches anywhere in the Movements/Order-Profit/Product-Profitability method bodies. Each method's only data access is instantiating and calling `WC_Inventory_Overview_Movements_List_Table`, `WC_Inventory_Overview_Order_Profit_Query::get_filters_from_request()` + `WC_Inventory_Overview_Order_Profit_List_Table`, or the Product-Profitability equivalents — all three of which independently own their `$wpdb` usage today (confirmed present in `class-wc-inventory-overview-movements-list-table.php`) and are **not modified by this plan**.

Therefore: **mechanically moves with the controller, no query-layer redesign, remains Level A.** There is no point at which this plan risks silently becoming an architecture redesign — the moved code is 100% presentation/dispatch glue calling pre-existing, unmodified domain objects.

---

## 10. Mutation / security surface

**None in the selected cluster.** All nine methods and the `enqueue_assets()` slice are read-only:

| Method | Capability | Nonce | Mutation | Output |
|---|---|---|---|---|
| `on_load_movements_screen` | `manage_woocommerce` | n/a (GET screen-option registration only) | none | `add_screen_option` |
| `on_load_order_profit_screen` | `manage_woocommerce` | n/a | none | `add_screen_option` |
| `on_load_product_profitability_screen` | `manage_woocommerce` | n/a | none | `add_screen_option` |
| `render_movements_panel` | `manage_woocommerce` (via shell's case-block gate, unchanged) | n/a | none | HTML |
| `render_order_profit_panel` | `manage_woocommerce` | n/a | none | HTML |
| `render_product_profitability_panel` | `manage_woocommerce` | n/a | none | HTML |
| `maybe_export_movements_csv` | `manage_woocommerce` | `wc_io_movements_export_csv` / `_wc_io_mv_export_nonce` | none (read + stream) | CSV download, `exit` |
| `maybe_export_order_profit_csv` | `manage_woocommerce` | `wc_io_order_profit_export_csv` / `_wc_io_op_export_nonce` | none | CSV download, `exit` |
| `maybe_export_product_profitability_csv` | `manage_woocommerce` | `wc_io_product_profitability_export_csv` / `_wc_io_pp_export_nonce` | none | CSV download, `exit` |

No admin-post handlers, no AJAX handlers, no transaction behavior, no redirect/PRG behavior (CSV exports terminate with `exit` after streaming, same as today) to preserve beyond exact-string/exact-order reproduction.

---

## 11. BR-M19-* matrix (behavior-equivalence contracts)

| # | Rule |
|---|---|
| BR-M19-1 | Movements panel: renders exactly the same markup as today — heading, description, filter form (hidden `page`/`tab`/`orderby`/`order` fields sourced from `Movements_List_Table::get_request_args()`), scroll wrapper, `Movements_List_Table::prepare_items()`/`display()`. Capability gate (`manage_woocommerce`) unchanged, enforced both by the shell's case-block (unchanged, stays on Plugin) and the panel method's own `wp_die()` guard (BR-M19-1a: both checks are preserved verbatim, not deduplicated). |
| BR-M19-2 | Order Profit panel: renders identical filter form (date-from/date-to/status dropdown sourced from `Order_Profit_Query::get_filters_from_request()`, `wc_get_order_statuses()`), the exact long description string verbatim (character-for-character, including the internal snapshot-field explanation), scroll wrapper, `Order_Profit_List_Table`. |
| BR-M19-3 | Product Profitability panel: renders identical filter form (date-from/date-to via `Product_Profitability_Query::get_filters_from_request()`), the exact description string verbatim, scroll wrapper, `Product_Profitability_List_Table`. |
| BR-M19-4 | Movements CSV export: triggers only when `$_GET['wc_io_mv_export'] === 'csv'` **and** `$_GET['tab'] === TAB_MOVEMENTS`; nonce `wc_io_movements_export_csv`/`_wc_io_mv_export_nonce` required; UTF-8 BOM + `Content-Type: text/csv` + `Content-Disposition: attachment; filename=wc-inventory-movements-{Y-m-d}.csv`; delegates entirely to `Movements_List_Table::export_csv_to_stream( $out )`; `exit` after. |
| BR-M19-5 | Order Profit CSV export: same trigger/nonce/header pattern (`wc_io_op_export`, `wc_io_order_profit_export_csv`, filename `wc-order-profit-{Y-m-d}.csv`); passes `Order_Profit_Query::get_filters_from_request()` into `Order_Profit_List_Table::export_csv_to_stream( $out, $filters )`. |
| BR-M19-6 | Product Profitability CSV export: same pattern (`wc_io_pp_export`, `wc_io_product_profitability_export_csv`, filename `wc-product-profitability-{Y-m-d}.csv`); passes `Product_Profitability_Query::get_filters_from_request()` into `Product_Profitability_List_Table::export_csv_to_stream()`. |
| BR-M19-7 | Screen options: `wc_io_movements_per_page`/`wc_io_order_profit_per_page`/`wc_io_product_profitability_per_page` each register via `add_screen_option('per_page', ...)` with the same label/default(20)/option-name, and each `set_screen_option_wc_io_*_per_page` filter clamps to `[1, 500]` — identical to today. |
| BR-M19-8 | `on_load_inventory_profit_page()`'s dispatch to these three `on_load_*` methods, and `render_inventory_profit_shell()`'s dispatch to these three `render_*_panel` methods, remain gated by the exact same `current_user_can( 'manage_woocommerce' )` checks at the exact same point in the switch/if chain, only the call target changes (`$this->render_movements_panel()` → `WC_Inventory_Overview_Reporting_Controller::instance()->render_movements()`, etc.) — INV-M19-11 (mechanical-move discipline). |
| BR-M19-9 | `enqueue_assets()`'s remaining Dashboard/Overview behavior is byte-identical; the Movements branch (`wc-io-movements-table` script + shared `wc-inventory-overview-admin` style) moves to `Reporting_Controller`'s own callback with the exact same enqueue args (handle, src, deps, version, in-footer flag), gated by the exact same `hook_suffix`/tab check. |

---

## 12. INV-M19-* matrix (architectural invariants)

| # | Invariant | Guard |
|---|---|---|
| INV-M19-1 | `WC_Inventory_Overview_Plugin` remains sole tab-routing owner — `PAGE_SLUG`, `TAB_*` constants, `get_tabs_definition()`, `get_requested_tab()`, `admin_url_tab()`, `on_load_inventory_profit_page()`, `render_inventory_profit_shell()`, `register_menu()` stay on `Plugin`; `Reporting_Controller` only calls `Plugin`'s (already-public) tab-routing methods, never redefines them. | Architecture-guard test |
| INV-M19-2 | Zero behavior change — every BR above byte-identical pre/post extraction. | Characterization tests rerun unchanged |
| INV-M19-3 | Zero schema change — `DB_VERSION` remains `'11'`. | Targeted rerun of the v11 schema test |
| INV-M19-4 | Zero new capability — only pre-existing `manage_woocommerce` referenced. | Architecture-guard grep |
| INV-M19-5 | Zero new public API — no `do_action`/`apply_filters` introduced in the new file. | Architecture-guard test (M17/M18-style substring count) |
| INV-M19-6 | Zero new domain concept — no `docs/OWNERSHIP.md` change. | Manual check |
| INV-M19-7 | Query-count invariance — every DB-touching call inside the moved methods is unchanged (same target class/method/args); baseline captured pre-extraction must match exactly post-extraction. | Query-count characterization |
| INV-M19-8 | No presentation SQL — `Reporting_Controller` contains no `global $wpdb`/`$wpdb->`. | Architecture-guard grep |
| INV-M19-9 | No new capability-check/nonce ordering — each CSV export's `current_user_can()` → param-check → `check_admin_referer()` sequence is reused verbatim, not reordered. | Architecture-guard substring-extraction check |
| INV-M19-10 | Nonce/action-string stability — every `wp_nonce_field()`/`check_admin_referer()` literal reused verbatim. | Architecture-guard grep diff |
| INV-M19-11 | Mechanical-move discipline — the extraction diff is a pure move: wrap in a new class, substitute exactly the established call-site patterns (`$this->admin_url_tab(...)` → `WC_Inventory_Overview_Plugin::instance()->admin_url_tab(...)`, any `self::PAGE_SLUG`/`self::TAB_*` reference → `WC_Inventory_Overview_Plugin::PAGE_SLUG`/`WC_Inventory_Overview_Plugin::TAB_*`). No other line changes. **This is the exact invariant M18 violated once (six unqualified `self::` constants in `Dashboard_Controller`) — see §16's structural mitigation.** | Manual diff review + pre-move symbol audit (§16) + architecture-guard substring comparison |
| INV-M19-12 | `Plugin::init()`'s existing bootstrap order is unchanged; if `Reporting_Controller` needs an `init()` call (for its `enqueue_assets` slice, §8), it is appended after the existing bootstrap block, never interleaved. | Manual diff review |
| INV-M19-13 | Overview and Restock methods are **not touched** by any commit in this milestone — confirmed by diff review showing only the nine selected methods, three filters, and the `enqueue_assets()` Movements branch changed in Plugin. | Diff review at WP checkpoint |
| INV-M19-14 | Characterization tests genuinely predate extraction (WP-M19-1/2 run and proven green against *unmodified* code before WP-M19-3 touches anything) — the specific discipline gap that let M18's incomplete characterization fixes go undetected until the full-suite closure pass. | WP checkpoint order enforced by this plan's ladder (§19) |
| INV-M19-15 | Every post-extraction characterization-test change is classified as INVOCATION-SEAM ONLY, FIXTURE CORRECTION, or (if it would be a) BEHAVIORAL ASSERTION CHANGE requiring a stop — no change goes unclassified in the implementation report. | Implementation report requirement |

---

## 13. Data / method contracts

### `WC_Inventory_Overview_Reporting_Controller`
**File:** `includes/class-wc-inventory-overview-reporting-controller.php` (new)

| Method | Visibility | Source | Notes |
|---|---|---|---|
| `instance()` | public static | new | Singleton, mirrors `Dashboard_Controller`/`Settings_Controller` |
| `init()` | public | new | Registers the one `admin_enqueue_scripts` callback (§8); no other hooks — Movements/Order-Profit/Product-Profitability have no dedicated hooks to register, matching their current hookless state |
| `on_load_movements()` | public | `on_load_movements_screen()` verbatim | Renamed to drop the redundant `_screen` suffix now that it's scoped inside a tab-specific class (naming choice, not a behavior change — confirm no test/doc references the old name before renaming; if any external reference exists, keep the original name instead — WP-M19-3 checkpoint decides) |
| `on_load_order_profit()` | public | `on_load_order_profit_screen()` verbatim | Same naming note |
| `on_load_product_profitability()` | public | `on_load_product_profitability_screen()` verbatim | Same naming note |
| `render_movements()` | public | `render_movements_panel()` verbatim | `Plugin::PAGE_SLUG`/`::TAB_MOVEMENTS` qualification |
| `render_order_profit()` | public | `render_order_profit_panel()` verbatim | `Plugin::PAGE_SLUG`/`::TAB_ORDER_PROFIT` qualification |
| `render_product_profitability()` | public | `render_product_profitability_panel()` verbatim | `Plugin::PAGE_SLUG`/`::TAB_PRODUCT_PROFITABILITY` qualification |
| `export_movements_csv()` | public | `maybe_export_movements_csv()` verbatim | `Plugin::TAB_MOVEMENTS` qualification |
| `export_order_profit_csv()` | public | `maybe_export_order_profit_csv()` verbatim | `Plugin::TAB_ORDER_PROFIT` qualification |
| `export_product_profitability_csv()` | public | `maybe_export_product_profitability_csv()` verbatim | `Plugin::TAB_PRODUCT_PROFITABILITY` qualification |
| `enqueue_reporting_assets( $hook_suffix )` | public | new — extracted from `enqueue_assets()`'s Movements branch | Guards on `hook_suffix`/`Plugin::instance()->get_requested_tab() === Plugin::TAB_MOVEMENTS`; enqueues `wc-inventory-overview-admin` + `wc-io-movements-table` verbatim |

**Naming caution (explicit, not to be resolved by the implementing agent without checking):** unlike M18's `Dashboard_Controller`/`Settings_Controller`, which renamed `render_dashboard_panel()`→`render()`/`render_settings_panel()`→`render()` (single entry point per class), this controller owns *three* render entry points and *three* export entry points, so no single bare `render()`/`export()` name is possible — the ask's "exact ordering" and "exact... semantics" BRs govern behavior, not method names, so renaming is permitted, but every call site (the two `Plugin` dispatchers) must be updated in the same commit as the rename, and WP-M19-1/2's characterization tests must call the **final** names to avoid the exact invocation-seam churn that cost extra cleanup time in M18.

### `WC_Inventory_Overview_Plugin` changes
- `enqueue_assets()`: loses the `TAB_MOVEMENTS` term from its top guard and its `if ( self::TAB_MOVEMENTS === $tab )` block (9 lines removed).
- `init()`: gains `WC_Inventory_Overview_Reporting_Controller::instance()->init();`, appended after the existing bootstrap block (after the `Settings_Controller::instance()->init();` call added in M18), per INV-M19-12.
- `on_load_inventory_profit_page()`: its three `TAB_MOVEMENTS`/`TAB_ORDER_PROFIT`/`TAB_PRODUCT_PROFITABILITY` branches call `WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements()` etc. instead of `$this->on_load_movements_screen()` etc.
- `render_inventory_profit_shell()`: its three corresponding `case` blocks call `WC_Inventory_Overview_Reporting_Controller::instance()->render_movements()` etc. (capability-gate wrapping in each `case` block stays exactly as-is, unchanged, per BR-M19-8).
- Loses the 9 methods + 3 top-level filters listed in §2's "SELECTED" table (~285 lines), plus the `enqueue_assets()` slice (~9 lines).

### Schema
**No schema change.** `DB_VERSION` stays `'11'`. `Movements_List_Table`/`Order_Profit_{Query,List_Table}`/`Product_Profitability_{Query,List_Table}` are not modified.

---

## 14. Performance / query contract

No query logic changes anywhere — every call inside the moved methods targets the same class/method/arguments as today (§9). Therefore, exactly as M18's §12:

- The query count captured as a baseline during WP-M19-1/2 (characterization, against *current* code) for Movements/Order-Profit/Product-Profitability's render and CSV-export paths at empty/small/larger-catalog scale must be **exactly equal** post-extraction (WP-M19-3) — not "bounded." A mismatch of even one query is a stop condition (§21).
- No specific number is pre-invented here; WP-M19-1/2 measures and records the actual baseline using the same `$before = $wpdb->num_queries; ...; $wpdb->num_queries - $before` delta pattern already used across M9/M11/M12/M14/M15/M17/M18.
- No N+1 is introduced — no loop structure changes, only method-container relocation.

---

## 15. Characterization strategy (M18 discipline, tightened)

Every characterization test in this milestone must:
1. Be written and run **green against unmodified pre-extraction code** before any WP-M19-3+ file is touched (INV-M19-14) — the WP-M19-1/2 checkpoint is a hard gate, not a formality.
2. Be committed as its own commit before extraction begins.
3. After extraction, have every change classified in the implementation report as exactly one of:
   - **A. INVOCATION-SEAM ONLY** — e.g. `$plugin->render_movements_panel()` → `$controller->render_movements()`. Assertions unchanged.
   - **B. FIXTURE CORRECTION** — the pre-extraction test is shown to have been vacuous/wrong (as happened three times in M18's closure pass — leftover `use ($plugin)` closures, a wrong-type assertion, a wrong POST field name never recognized by production code). If this class of defect appears again, it must be logged the same way M18's was: root cause named, class of defect named, not silently absorbed into "tests updated."
   - **C. BEHAVIORAL ASSERTION CHANGE** — not permitted; return to planning if one is needed.

**M18 lesson applied structurally (not just documented):** M18's own closure pass found that "tests unchanged" was claimed twice before it was actually true, because (a) a test's `use ($plugin)` closure was never updated when its assignment was renamed, and (b) one test's fixture value (a POST field name) had never been validated against the real target method's contract. For M19, WP-M19-1/2's characterization tests must be **run to green against the pre-extraction code as their own explicit checkpoint** (not merely written), and WP-M19-3's mechanical edits must be a 1:1 substitution captured in a single reviewable diff — not spread across multiple "fix" commits discovered by a later full-suite run. If the implementing agent cannot get a WP-M19-1/2 test green against unmodified code, that is FIXTURE CORRECTION territory *before* extraction, not after.

---

## 16. Pre-move symbol audit (structural mitigation for M18's one miss)

M18 missed six unqualified `self::PAGE_SLUG`/`self::TAB_*`/`self::RESTOCK_VIEW_*` references inside `Dashboard_Controller` because the manual diff review at the WP-M18-4 checkpoint did not exhaustively grep the moved method bodies for every `self::`/constant reference before considering the move complete — it was only caught by the M18 closure pass's full, unfiltered test run.

**Structural fix for M19:** WP-M19-3 (extraction) must include, as an explicit sub-step before considering the WP complete, a grep-based audit of the exact new file for any symbol that cannot resolve inside the new class:

```
grep -nE 'self::|static::|\$this->[a-zA-Z_]+\(' includes/class-wc-inventory-overview-reporting-controller.php
```

Every `self::`/`static::` hit must be checked against the new class's own members (§13's method table — the new class defines no constants, so **any** `self::TAB_*`/`self::PAGE_SLUG`/`self::RESTOCK_VIEW_*` hit is automatically wrong and must become `WC_Inventory_Overview_Plugin::...`). Every `$this->` hit must be checked against the new class's own method list — any call to a Plugin-only method (there should be none among the nine selected methods, per §2/§3's confirmed zero-cross-cluster-coupling finding, but the grep is the enforcement, not the assumption).

Per-method symbol inventory (from the confirmed source, so the implementing agent has this already done rather than needing to re-derive it):

| Method | `self::`/`static::` refs found in source | `$this->` refs found in source | Other |
|---|---|---|---|
| `on_load_movements_screen` | none | none | none |
| `render_movements_panel` | `self::PAGE_SLUG`, `self::TAB_MOVEMENTS` | none | calls `Movements_List_Table::get_request_args()` (static) |
| `maybe_export_movements_csv` | `self::TAB_MOVEMENTS` | none | calls `Movements_List_Table::export_csv_to_stream()` (static) |
| `on_load_order_profit_screen` | none | none | none |
| `render_order_profit_panel` | `self::PAGE_SLUG`, `self::TAB_ORDER_PROFIT` | none | calls `Order_Profit_Query::get_filters_from_request()`, instantiates `Order_Profit_List_Table` |
| `maybe_export_order_profit_csv` | `self::TAB_ORDER_PROFIT` | none | calls `Order_Profit_Query::get_filters_from_request()`, `Order_Profit_List_Table::export_csv_to_stream()` |
| `on_load_product_profitability_screen` | none | none | none |
| `render_product_profitability_panel` | `self::PAGE_SLUG`, `self::TAB_PRODUCT_PROFITABILITY` | none | calls `Product_Profitability_Query::get_filters_from_request()`, instantiates `Product_Profitability_List_Table` |
| `maybe_export_product_profitability_csv` | `self::TAB_PRODUCT_PROFITABILITY` | none | calls `Product_Profitability_Query::get_filters_from_request()`, `Product_Profitability_List_Table::export_csv_to_stream()` |
| `enqueue_assets()`'s Movements branch | `self::PAGE_SLUG` (in the outer guard, not moved), `self::TAB_MOVEMENTS` | none | `plugins_url(..., WC_INVENTORY_OVERVIEW_FILE)`, `WC_INVENTORY_OVERVIEW_VERSION` — both global constants, not `Plugin`-scoped, resolve unchanged in any file |

**Zero `$this->` calls, zero calls to other Plugin private/protected helpers, zero closures capturing `$this` implicitly, zero callback arrays, zero hook registrations inside the nine methods themselves** (their hooks — the shared `admin_enqueue_scripts`/`load-woocommerce_page_*` — are registered once, elsewhere, in `Plugin::init()`, and stay there per INV-M19-1/§13). This is a materially simpler symbol surface than Dashboard_Controller's (which had the six-constant miss) or Settings_Controller's (which had capability/nonce/transient/option keys to preserve) — the only relocation risk is exactly the eight `self::PAGE_SLUG`/`self::TAB_*` hits enumerated above, all now pre-identified.

No nonce strings, no capability strings beyond `manage_woocommerce` (unchanged, reused), no transient keys, no option keys beyond the three screen-option names (unchanged, reused), no asset handles beyond `wc-io-movements-table`/`wc-inventory-overview-admin` (unchanged, reused), no script localization keys, no CSS classes to rename, no redirect URLs (none of these methods redirect), no `$wpdb` global usage to preserve.

---

## 17. Test matrix

| # | File | Timing | Behaviors covered |
|---|---|---|---|
| 1 | `tests/integration/admin-decomposition/test-movements-rendering-characterization.php` | **Pre-extraction** (WP-M19-1) | BR-M19-1, BR-M19-4, BR-M19-7 (Movements screen-option), BR-M19-8 (dispatch/capability), query-count baseline |
| 2 | `tests/integration/admin-decomposition/test-order-profit-rendering-characterization.php` | **Pre-extraction** (WP-M19-1) | BR-M19-2, BR-M19-5, BR-M19-7 (Order Profit screen-option), query-count baseline |
| 3 | `tests/integration/admin-decomposition/test-product-profitability-rendering-characterization.php` | **Pre-extraction** (WP-M19-1) | BR-M19-3, BR-M19-6, BR-M19-7 (Product Profitability screen-option), query-count baseline |
| 4 | `tests/unit/admin-decomposition/test-reporting-controller-architecture.php` | **Post-extraction** (WP-M19-4) | INV-M19-1, INV-M19-5, INV-M19-8, INV-M19-9, INV-M19-10, INV-M19-11 (glob/grep-based, M17/M18-style: no `$wpdb`, no new `do_action`/`apply_filters`, `Plugin` no longer `method_exists()`s the nine moved methods, `Reporting_Controller` doesn't redefine tab-routing) |
| 5 | (covered within #1–#3) | — | Security: capability-gate assertions are part of each render/export characterization test (`wp_die()`/`WPDieException` on missing `manage_woocommerce`, invalid-nonce `WPDieException` on each CSV export) — no separate security file needed given zero AJAX/admin-post in this cluster |
| 6 | (no new file) | — | CSV/export: covered by #1–#3's export tests (header assertions, `Content-Disposition` filename, delegate-call assertions) — CSV *content* correctness is already owned by each `List_Table::export_csv_to_stream()`'s own existing tests, unmodified |
| 7 | (n/a) | — | Bulk-action tests: not applicable — no bulk actions in this cluster |
| 8 | (covered within #1–#3) | — | Query-count tests: one `test_query_count_*` method per characterization file, same delta pattern as M18 |
| 9 | Targeted rerun (no new file) | **After WP-M19-3** | Regression for non-selected tabs: rerun `tests/unit/conformance/test-no-sibling-plugin-coupling.php` (repo-wide glob, auto-picks-up the new file) and any existing Movements-domain test (`tests/integration/movements/test-movements-characterization.php`) to confirm the domain layer is untouched — these should require zero code changes to pass, and a failure here is a stop condition (§21) |
| 10 | `tests/unit/admin-decomposition/test-reporting-controller-architecture.php` (same as #4) | Post-extraction | Bootstrap/hook-registration: assert `Plugin::init()` still calls the pre-existing bootstrap calls in the pre-existing order (INV-M19-12), assert `Reporting_Controller::init()` registers exactly one `admin_enqueue_scripts` callback |
| 11 | `tests/docker/run-phpunit.sh` filter regex | **WP-M19-5** | CI discovery: add the new M19 test-class prefixes (`Test_WC_IO_Movements_Rendering_`, `Test_WC_IO_Order_Profit_Rendering_`, `Test_WC_IO_Product_Profitability_Rendering_`, `Test_WC_IO_Reporting_Controller_`) to the existing filter alternation, verified via `--list-tests` |

**Expected test count added:** ≈9 characterization tests (3 per tab: render, export-success, export-capability/nonce-denied — mirroring M18's Dashboard characterization density) + ≈7 architecture-guard tests (mirroring M18's Dashboard_Controller_Architecture's 7) ≈ 16 new tests, smaller than M18's 47 new tests, proportional to the smaller LOC moved.

---

## 18. Manual acceptance

`WP_UnitTestCase` does not dispatch real HTTP requests and cannot verify actual browser-downloaded CSV file content/headers as a browser would receive them, nor real WordPress screen-options UI interaction. Before this milestone is considered done, manually verify on `https://dev.biopentra.eu` (or the local dev stack):

1. Load Movements tab — table renders, screen-options "per page" control present and functional, filter form present.
2. Trigger Movements CSV export — file downloads with correct filename/date, correct `Content-Type`, opens with correct row content matching the on-screen table.
3. Load Order Profit tab — date-range + status filters render and filter correctly; CSV export downloads correctly.
4. Load Product Profitability tab — date-range filters render and filter correctly; CSV export downloads correctly.
5. Confirm Dashboard and Overview tabs are visually and functionally unchanged (regression spot-check, since `enqueue_assets()` is touched).
6. Confirm Restock tab is unchanged (no code touched, but the shared `render_inventory_profit_shell()`/`on_load_inventory_profit_page()` dispatchers are edited — a quick regression load is cheap insurance).

No mutation paths exist in this cluster, so no disposable-state setup/teardown is required — CSV exports and renders are side-effect-free and safe to trigger repeatedly against real data.

---

## 19. WP-M19-0…N implementation ladder

**WP-M19-0 — Preflight + branch + plan materialization**
Re-verify §1's baseline (M18 tip SHA, unmerged/untagged, no M19 artifacts). Create branch `feature/m19-admin-controller-decomposition-phase2` from `2862b8c` (§7). Materialize this approved plan into `docs/milestones/m19-implementation-plan.md`. Commit that file alone. Checkpoint: `git status` clean except the one new file; `git log -1` confirms the branch point is `2862b8c`.

**WP-M19-1 — Movements + Order Profit characterization tests (pre-extraction)**
Create `test-movements-rendering-characterization.php` and `test-order-profit-rendering-characterization.php` per §17. Cover BR-M19-1/4/7/8 and BR-M19-2/5/7/8. Record query-count baselines. **Checkpoint (hard gate, INV-M19-14): run both files against the unmodified M19-0 code; both must be green before WP-M19-2 starts.**

**WP-M19-2 — Product Profitability characterization tests (pre-extraction)**
Create `test-product-profitability-rendering-characterization.php`. Cover BR-M19-3/6/7/8. Record query-count baseline. **Checkpoint: green against unmodified code.**

**WP-M19-3 — Extract `Reporting_Controller`**
Create `includes/class-wc-inventory-overview-reporting-controller.php` per §13. Modify `wc-inventory-overview.php` (add one `require_once`), `includes/class-wc-inventory-overview-plugin.php` (remove the 9 methods + 3 top-level filters, remove the `TAB_MOVEMENTS` branch from `enqueue_assets()`, add the `Reporting_Controller::instance()->init();` bootstrap call, update the three dispatch call-sites in `on_load_inventory_profit_page()` and the three `case` blocks in `render_inventory_profit_shell()`). Run the §16 symbol-audit grep against the new file and resolve every hit before proceeding. Checkpoint: `php -l` on both touched files; rerun WP-M19-1/2 tests **unchanged except invocation-seam edits** — must stay green; manual diff review confirms INV-M19-11/13.

**WP-M19-4 — Architecture guard tests**
Create `tests/unit/admin-decomposition/test-reporting-controller-architecture.php` per §17 item 4/10, following the `test-dashboard-controller-architecture.php` technique. Covers INV-M19-1/5/8/9/10/11/12. Checkpoint: new tests green.

**WP-M19-5 — CI wiring**
Update `tests/docker/run-phpunit.sh`'s filter regex with the new M19 class-name prefixes. Checkpoint: `--list-tests` confirms discovery.

**WP-M19-6 — Targeted regression pass**
Rerun (no code changes expected): `tests/unit/conformance/test-no-sibling-plugin-coupling.php`, `tests/integration/movements/test-movements-characterization.php`, the `tests/integration/install/` v11 schema test. Any failure means §3's dependency map missed a coupling — stop and reassess (§21), do not silently patch around it.

**After WP-M19-6 (all implementation WPs complete):**
1. Run the full new `tests/unit/admin-decomposition/` + `tests/integration/admin-decomposition/` set plus the WP-M19-6 targeted regression set.
2. Run **one** complete final validation sequence — full PHPUnit unit suite, M1–M19 focused suite, full PHPUnit integration suite, PHPCS (advisory), php-parallel-lint, `composer validate --strict`, `docker compose config`, `scripts/release-audit.sh --development` — via the Docker test harness, exactly the closure-pass sequence M18 just completed.
3. Fix any defects found with the narrowest justified fix; rerun only the directly affected test; rerun a broader suite only if that fix touches executable code shared beyond `admin-decomposition/`.
4. Push branch, open/update the draft CI PR, confirm GitHub Actions green.
5. Write the final implementation report, including the mandatory characterization-test-change classification (§15).

**Implementation stops here.** No merge, no tag, no release, no deployment.

---

## 20. Fast implementation / testing strategy

Per the ask's explicit instruction and the lesson already learned in M18's closure pass:

- **WP boundaries are NOT user-approval boundaries.** Once this plan is approved, the implementing agent executes WP-M19-0 through WP-M19-6 sequentially without stopping for per-WP approval.
- Narrow, targeted tests/checks run **during** each WP (as specified in each WP's own checkpoint above) — `php -l` on touched files, the specific characterization file(s) relevant to that WP, `--list-tests` for WP-M19-5.
- **Do not** run the full unit/M1-M19-focused/integration suites between WPs. Run them **once**, after WP-M19-6, as the single expensive validation pass.
- If a defect is found during that one pass, fix it with the narrowest justified change and rerun only the directly affected test(s); rerun a broad suite again only if the fix touches code shared beyond the new controller/tests (e.g., if a `Plugin::enqueue_assets()` edit needs correcting, rerun Dashboard/Overview-adjacent tests, not the entire integration suite).
- Proceed directly through the Level A completion review and freeze/checklist steps once the single validation pass is clean — do not insert an additional review cycle beyond what M18's own Level A process used.

---

## 21. Documentation plan

| Document | Action |
|---|---|
| `CLAUDE.md` | Add M19 row to the Implementation Status table at freeze time (not during implementation). |
| `CHANGELOG.md` | Add a dev/unreleased M19 entry — internal refactor, explicitly no user-facing behavior change. |
| `docs/architecture-audit.md` | Update the "Large god class" entry (currently lines 585/597, post-M18 wording) to record: Phase 2 (M19) complete — Movements, Order Profit, Product Profitability extracted into `Reporting_Controller`. **Correct the framing to be honest that Phase 2 was a subset, not the full remainder** — explicitly name Overview and Restock as still-open, reserved for a future Phase 3, rather than implying the decomposition project is closed. |
| `docs/OWNERSHIP.md` | No change — no new domain concept (INV-M19-6). |
| `docs/testing.md` | Add the new `admin-decomposition/` test files to its inventory if one is maintained (verify at freeze time, same as M18's approach). |
| `docs/rollback-plan.md` | Add a one-line M19 entry: "safe code-only rollback, no data implications." |
| `docs/checklists/m19-release-readiness.md` | Created at freeze time (WP4-equivalent), following the exact structure of `m18-release-readiness.md`, including a Closure Phase Evidence section with the same characterization-integrity rigor M18's closure pass established. |
| CI filter regex (`run-phpunit.sh`) | Updated at WP-M19-5. |

**Explicit accuracy requirement:** do not declare the god-class tech debt "closed" — after M19, Plugin still owns Overview (~361 LOC, real mutation) and Restock (~368 LOC, real mutation) plus its permanent tab-routing/bootstrap shell (~530 LOC), landing at ≈1,260 lines. State this plainly in both the CHANGELOG entry and the architecture-audit update.

---

## 22. Rollback / release implications

- **Expected development version:** `1.36.0` (sequential bump from M18's `1.35.0`, matching the M9→M17 sequential-dev-version convention).
- **`DB_VERSION`:** unchanged, `11`.
- **Feature-train recommendation:** M19 joins M18 in one **Admin Controller Decomposition** feature train (Option A from the ask's §18 menu). Both milestones are Level A, carry zero schema/capability/public-API/behavior change, and are purely internal — bundling them into a single eventual release (e.g. `v1.36.0`) is lower-overhead than releasing M18 standalone first, exactly mirroring the M9–M12 and M13–M15 bundling precedent. This assumes M19 completes at its planned Level A risk; if implementation reveals Level B characteristics (stop condition, §21 below), reassess whether M18 should release ahead of a higher-risk M19 rather than block it in the same train.
- **Code rollback:** fully reversible — pure code reorganization, `git revert`/checkout restores prior behavior with zero data implications (no schema/data mutation introduced by this milestone at all, unlike M18 which at least touched pre-existing Danger-Zone-Apply calling code).
- **Data rollback:** not applicable — zero mutation surface in the selected cluster (§10).
- **Backup requirement:** standard pre-deploy backup; no elevated requirement.

---

## 23. Stop conditions

- M18 baseline (§1/§7) no longer matches at WP-M19-0 time (M18 released/merged/tagged/altered since this plan was written).
- Any WP discovers a caller of `Plugin::render_movements_panel()`/`render_order_profit_panel()`/`render_product_profitability_panel()`/the three `on_load_*`/`maybe_export_*_csv` methods beyond the two dispatchers named in §3 — halt, reassess.
- Any WP discovers `get_query_params_from_request()`/`render_summary_cards()`/any Overview or Restock method is actually called from within the selected cluster (contradicting §2/§3's confirmed-zero-coupling finding) — halt; the dependency map was wrong and needs re-verification, not silent accommodation.
- Any WP surfaces a need to touch a `wc_io_*` table, column, or `DB_VERSION` — halt immediately; contradicts INV-M19-3.
- Post-extraction query count for any characterized path differs from the WP-M19-1/2 baseline by even one query — halt; do not loosen the assertion.
- A WP-M19-1/2 characterization test cannot be made to pass honestly against unmodified code (INV-M19-14) — halt, do not fake a green test by weakening what it asserts, and do not proceed to WP-M19-3 until it is genuinely green.
- The `enqueue_assets()` split (§8) reveals additional shared state beyond the `wc-inventory-overview-admin` handle and `wc-io-movements-table` script (e.g., an undiscovered shared JS global or inline script depending on Dashboard/Overview-only state) — halt, reassess; do not paper over with a broader shared-utility class invented ad hoc.
- Any WP would require changing an approved BR (§11) or INV (§12) to proceed — halt, return to planning.
- Characterization-test defects of the same class M18's closure pass found (leftover renamed-variable closures, wrong-type assertions, fixture field names never validated against the real target) are found **after** WP-M19-1/2's hard gate has already been declared green — this indicates the hard gate itself was not honestly enforced; halt and re-run WP-M19-1/2 properly rather than patching forward.

---

## 24. Deferred decomposition work

1. **God-class Decomposition Phase 3** (Overview + Restock) — the two remaining tabs, both carrying real mutation surface (Overview: bulk product mutation + inline-stock AJAX; Restock: two admin-post handlers writing stock/cost). Materially higher-risk than M19's cluster — likely warrants its own characterization-heavy milestone, and given the two tabs are *not* coupled to each other (confirmed §3), a future planning pass should decide whether Phase 3 is one milestone or two (Overview alone; Restock alone), using this same corrected-dependency-graph discipline rather than assuming they must ship together.
2. **`render_tab_placeholder()` dead-code removal** — zero callers confirmed; a trivial cleanup item, out of scope for this refactor-focused milestone, candidate for bundling into a future hardening-style pass (alongside the `MANAGE_SUPPLIERS` dead-capability cleanup already noted in M17's plan).
3. **Overview's duplicate query-params logic** (`Plugin::get_query_params_from_request()` vs. `List_Table::prepare_items()`'s independent re-derivation) — pre-existing debt, not introduced by this plan, worth flagging for whichever future milestone extracts Overview.
4. All items already listed in M18's own deferred-candidates section (Coverage/Forecast, Reservations, Inbound Shipment, Warehouse Locations, `MANAGE_SUPPLIERS` cleanup) remain unchanged and are not re-evaluated here.

---

## 25. Hostile self-review (applied before finalizing)

- **Is the selected Phase 2 scope actually one coherent unit?** Yes — three tabs, identical structural pattern (on_load screen-option → CSV export → List_Table render), zero mutation, zero AJAX, zero bulk actions, zero coupling to each other beyond the pattern itself, zero coupling to the excluded tabs. This is a tighter, lower-risk cluster than M18's own Settings+Dashboard pairing (which combined a mutation-heavy tab with a read-only one).
- **Did I map every remaining Plugin method?** Yes — all ~46 methods + 4 top-level filter closures, every one classified A–L against the actual source, with line ranges read directly from the M18-frozen file, not assumed from the tab list.
- **Did I identify every `self::`/`static::`/helper dependency?** Yes, per-method, in §16 — and confirmed the selected cluster has zero `$this->` calls and zero cross-cluster method calls, the simplest symbol surface of any controller extracted so far in this project.
- **Are characterization tests feasible pre-extraction?** Yes — the existing `test-movements-characterization.php` (domain-level, unrelated) confirms the test harness/fixture conventions are already established for this general area; the new tests follow the exact WP-M18-1/2 pattern verified working.
- **Are mutations/security mapped precisely?** Yes — §10 shows zero mutation in-scope; the two tabs that *do* have mutation (Overview, Restock) are explicitly excluded, correcting the ask's own Option E mischaracterization in the process.
- **Is raw SQL ownership understood?** Yes — confirmed zero raw SQL inside any of the nine methods, all delegated to pre-existing, unmodified domain classes (§9).
- **Will non-selected tabs truly remain untouched?** Yes, with one qualified exception: `enqueue_assets()` (shared with Dashboard/Overview) is edited, but only to remove the Movements branch — Dashboard's and Overview's own branches inside that method are untouched line-for-line (INV-M19-13, verified by diff review at WP-M19-3).
- **Are query contracts measurable?** Yes — same delta-pattern used across seven prior milestones; no numbers invented here, left for WP-M19-1/2 to measure.
- **Could Sonnet implement without asking design questions?** Yes — every method's destination, new name, constant-qualification, and hook-registration decision is specified in §13/§16; the one open naming question (whether to rename `on_load_movements_screen` → `on_load_movements` or keep the `_screen` suffix) is flagged explicitly as a WP-M19-3 checkpoint decision with a stated default and reasoning, not left silently ambiguous.
- **Does the lifecycle risk level match actual complexity?** Yes — Level A is justified point-by-point against the ask's own Level-B triggers (§6), and is if anything more conservative than M18's own Level A classification (M18 had two real mutation admin-post handlers; M19 has zero).
- **Is feature-train topology correct?** Yes — M19 branches from M18's unreleased tip (not `main`), and both are recommended for one bundled release, consistent with `docs/process/milestone-lifecycle.md`'s no-Release-Trigger rule and the repo's established train-bundling precedent (M9–M12, M13–M15).

No revisions required after this review — the plan as written already reflects the corrections this review would otherwise have produced (the Option E mischaracterization fix and the `enqueue_assets()` split were both identified during the initial mapping pass, not discovered late).

---

## 26. Final implementation-readiness verdict

**Ready.** Every method in the selected cluster, its exact line range, its exact symbol dependencies, its exact hook relationship, and its exact new home is enumerated from the actual M18-frozen source above — no design decision is left for the implementing agent to invent. The one real structural risk M18 exposed (unqualified `self::` constants surviving a mechanical move undetected until a later full-suite run) has a named, mechanical, grep-based mitigation built into WP-M19-3 itself (§16), not merely a documentation reminder. The characterization-tests-first sequencing is a hard gate (INV-M19-14) rather than an assumed step. An implementation agent can execute WP-M19-0 through WP-M19-6 sequentially, stopping only at the named stop conditions (§23).

M19 PLAN COMPLETE — AWAITING APPROVAL
