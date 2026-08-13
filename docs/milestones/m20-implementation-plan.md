# M20 Definitive Implementation Plan — Admin Controller Decomposition, Phase 3

## Context

M18 and M19 decomposed five of the seven tabs of the `WC_Inventory_Overview_Plugin` god class (2,706 → 1,230 lines) into `Dashboard_Controller`, `Settings_Controller`, and `Reporting_Controller`. Both milestones' own documentation (`docs/architecture-audit.md:585/597`, `CLAUDE.md`'s M19 row, and `docs/milestones/m19-implementation-plan.md` §24 "Deferred decomposition work") explicitly reserve **Overview** and **Restock** — the two tabs still carrying real mutation surface — for "a future Phase 3, which may itself split into two milestones, since the two tabs are not coupled to each other." This plan is that Phase 3 planning pass, answering the question M19 left open: whether Overview and Restock belong in one milestone or two, and exactly how each extracts.

Every fact below was verified directly against the current repository source (line numbers, method bodies, hook registrations, nonce strings, JS contracts) — not inferred from the M19 plan's predictions, though those predictions (§2's method inventory) were cross-checked and confirmed accurate to the current tree.

---

## 1. Executive recommendation

Extract **both** Overview and Restock in this single milestone, M20, as **two independent controller classes** — `WC_Inventory_Overview_Restock_Controller` and `WC_Inventory_Overview_Overview_Controller` — following the exact M18 `Dashboard_Controller`/`Settings_Controller` precedent (two unrelated controllers extracted in one milestone, each with its own full characterization → extraction → guard sub-sequence), not the M19 `Reporting_Controller` precedent (one controller merging three *coupled-by-symmetry* tabs). Overview and Restock are confirmed structurally and functionally independent of each other (§3, §12) — merging them into one "Operations_Controller" would mix two different risk profiles (catalog-field bulk mutation vs. financial/stock admin-post mutation) into one class for no coherence benefit, and is explicitly rejected.

Extraction order: **Restock first, then Overview** (ascending risk — Restock's mutation fully delegates to already-tested, unmodified domain services and has a self-contained asset-enqueue registration; Overview's bulk-action mutation is inline in Plugin with no service layer, and its asset enqueue must be split from Dashboard's, mirroring M19's `enqueue_assets()` split). Sequential, not interleaved — Restock's full sub-ladder (characterize → extract → guard) completes and is reviewable as its own commit set before Overview's begins.

Classified **Level A** (§23) — every candidate Level-B trigger is checked against source and found absent or already-precedented at M18 (which extracted two real mutation admin-post handlers plus a destructive Danger-Zone-Apply path, and remained Level A). This is a purely mechanical relocation: zero business logic changes, zero new domain concepts, zero schema change.

Recommended dev version: **1.37.0**. `DB_VERSION` unchanged: **11**. Base SHA: **`8539ec9d201d3f9baab8508f02c2b8fb187fd623`** (current `main`/`origin/main`).

No unresolved architecture remains — every open question the ask raised (controller count, naming, the `enqueue_assets()` split, the cross-cutting supplier-picker/`Purchasing_Page` AJAX dependency, method renaming policy) is resolved below with explicit reasoning, not left to the implementing agent.

---

## 2. Verified baseline

Confirmed directly against the repository (2026-08-13, read-only):

| Check | Result |
|---|---|
| `main` HEAD | `8539ec9d201d3f9baab8508f02c2b8fb187fd623` — "docs(release): record v1.36.0 publication" |
| `origin/main` | identical SHA — up to date |
| Working tree | clean (`git status --short` empty) |
| Plugin version constant | `1.36.0` (`wc-inventory-overview.php:18`) |
| `DB_VERSION` | `'11'` (`includes/class-wc-inventory-overview-install.php:15`, comment: "Unchanged in M18/M19") |
| `readme.txt` Stable tag | `1.36.0` |
| M18 status | Released, part of v1.36.0 |
| M19 status | Released, part of v1.36.0 |
| M20 branch/plan/implementation | None exists — clean slate |
| Recent commit log | `8539ec9` docs(release) → `b9c978c` Release v1.36.0 → `c978685`…`9c44dd0` (M18/M19 freeze/release docs) — matches CLAUDE.md's implementation-status table exactly |

**No repository-reality contradiction found.** The prompt's stated baseline (`v1.36.0`, merge `b9c978c`, canonical head `8539ec9`) is exactly correct. This plan uses current `main` (not an unmerged feature branch, unlike M19 which branched from M18's unreleased tip) as its design baseline — the entire tree was read in the relevant sections.

---

## 3. Current Plugin decomposition state

`includes/class-wc-inventory-overview-plugin.php` — **1,229 lines** (down from 2,706 pre-M18, ~1,230 stated in M19's freeze, off by one line from natural drift, immaterial).

| Category | Owner today | Status |
|---|---|---|
| Tab routing/shell (menu, `get_requested_tab`, `admin_url_tab`, `render_inventory_profit_shell` dispatch, legacy redirects) | `Plugin` | Permanent (INV-M18-1/INV-M19-1, unchanged by M20) |
| Dashboard | `Dashboard_Controller` | Done (M18) |
| Settings | `Settings_Controller` | Done (M18) |
| Movements, Order Profit, Product Profitability | `Reporting_Controller` | Done (M19) |
| **Restock / Cost Adjustment** | `Plugin` | **M20 target** |
| **Inventory Overview** | `Plugin` | **M20 target** |

No base `Controller` class or interface exists anywhere in the repo (confirmed by grep for `abstract class.*Controller`/`interface.*Controller` — zero hits). Every controller (`Settings_Controller`, `Dashboard_Controller`, `Reporting_Controller`) independently repeats the same bare-singleton boilerplate: private static `$instance`, public static `instance()`, and (where the controller owns hooks) a public `init()` calling `add_action`. `Dashboard_Controller` has no `init()` at all (zero hooks; called directly from the tab-switch). M20's two new controllers replicate this exact convention — no new base class introduced (would be scope creep beyond "zero new domain concept").

File naming convention: `includes/class-wc-inventory-overview-<slug>-controller.php` → `WC_Inventory_Overview_<Slug>_Controller`. Flat `includes/` directory, no subdirectories, no PSR-4 namespacing outside `tests/`.

---

## 4. Exact Overview responsibility map

All line numbers verified against current `includes/class-wc-inventory-overview-plugin.php` (1,229 lines).

| Method | Vis (today) | Lines | Role |
|---|---|---|---|
| `on_load_screen()` | public | 725–741 | Screen-option registration + dispatch to export/bulk |
| `maybe_export_csv()` | protected | 743–847 | CSV export (hand-built `fputcsv`, own header/column logic) |
| `get_query_params_from_request()` | protected | 854–878 | GET/REQUEST → repository params (feeds CSV export only; `List_Table::prepare_items()` independently re-derives the same params — pre-existing duplicate logic, out of scope to fix) |
| `maybe_handle_bulk()` | protected | 880–937 | **Mutation**: 4 bulk actions on selected product IDs |
| `detect_bulk_action()` | protected | 942–954 | Helper — reads/validates `action`/`action2` |
| `ajax_save_inline_stock()` | public | 956–989 | **Mutation**: `wp_ajax_wc_io_save_inline_stock` |
| `enqueue_assets()`'s Overview branch | public (shared method) | part of 1055–1109 | Overview-only JS/localization, entangled with Dashboard's branch in one shared method |
| `render_summary_cards( array $stats )` | public static | 1114–1175 | Summary cards markup; sole caller is Overview's own render |
| `render_inventory_overview_panel()` | protected | 1180–1215 | Single render entry point |
| top-level filter `set_screen_option_wc_io_per_page` | — | 1218–1228 | Clamps per-page value to `[1,500]` |

Overview subtotal: 8 methods + 1 filter + a slice of `enqueue_assets()` ≈ 361 LOC (matches M19's own estimate exactly).

**Screen bootstrap:** `register_menu()` (`plugin.php:70-79`, cap `edit_products`) → WP renders via `render_inventory_profit_shell()` (`:366-427`, cap `edit_products` re-checked at `:367-369`) → `switch($tab){ case TAB_OVERVIEW: $this->render_inventory_overview_panel(); ... default: $this->render_inventory_overview_panel(); }` — **note the fallback `default:` case at `:421-423` is a second call site to the same method**, both must be updated at extraction. `on_load_inventory_profit_page()` (`:253-275`, hooked `load-woocommerce_page_wc-inventory-profit`) dispatches `TAB_OVERVIEW` to `on_load_screen()` at `:259-262` — this is where screen options/CSV export/bulk-action side effects actually run, separate from rendering.

**Renderer chain:** `render_inventory_overview_panel()` → `new WC_Inventory_Overview_List_Table()` → `prepare_items()` (unmodified, external class) → `self::render_summary_cards($list_table->summary_stats)` → `$list_table->search_box()` / `render_top_tablenav()` / `render_table_main()`. `WC_Inventory_Overview_List_Table` (`includes/class-wc-inventory-overview-list-table.php`, 1,346 lines) is a separate, already-independent class — **not extracted by M20**, only called by the new controller exactly as Plugin calls it today.

**Screen options:** `add_screen_option('per_page', ['default'=>20,'option'=>'wc_io_per_page'])` (`:730-737`), clamped by the file-scope filter at `:1218-1228`. No column-visibility screen option exists.

**Filters/search (rendered by `List_Table::extra_tablenav()`, unmodified):** search box (`s`), category (`wc_io_product_cat`), stock status (`wc_io_stock_status`), exclude-private checkbox (`wc_io_exclude_private`), CSV download link (nonce `wc_io_export_csv`/`_wc_io_export_nonce`).

**Data-read paths (all inside unmodified `List_Table`/`Repository`/`Summary`/`Inventory_Position_Service` — Overview_Controller calls these exactly as Plugin does today, zero query-layer change):** `Repository::query_products()` (main page + per-variable-parent children), `Summary::build()` (6 count queries + `count_low_sellable_lines()`), `wc_get_products()` bulk parent fetch, `Inventory_Position_Service::get_positions_bulk()` (gated `manage_woocommerce`, 2 bulk repository calls, no N+1 — M3's own guard already covers this). CSV export path independently calls `Repository::iterate_products_for_export()` (chunked generator, 300/page).

---

## 5. Exact Restock responsibility map

| Method | Vis (today) | Lines | Role |
|---|---|---|---|
| `get_restock_subview()` | protected | 209–222 | Sub-view resolution (`quick`\|`adjust`, default `quick`; stale `restock_view=batch` bookmarks fall back to `quick`, M6 retirement) |
| `render_restock_subnav()` | protected | 229–248 | Two-item subnav markup |
| `on_load_restock_screen()` | public | 280–284 | Capability-only bootstrap stub (no CSV/bulk logic, unlike Overview) |
| `enqueue_restock_assets( $hook_suffix )` | public | 434–503 | Own, self-contained `admin_enqueue_scripts` registration |
| `handle_restock_post()` | public | 517–563 | **Mutation**: `admin_post_wc_io_restock` |
| `handle_cost_adjustment_post()` | public | 568–610 | **Mutation**: `admin_post_wc_io_cost_adjustment` |
| `render_restock_panel()` | protected | 615–723 | Single render entry point (two inline forms) |
| `ajax_get_cost_adjustment_preview()` | public | 994–1053 | Read-only AJAX preview (nonce only localized in `enqueue_restock_assets`, so functionally Restock's despite being physically positioned between Overview methods in the file today) |

Restock subtotal: 8 methods ≈ 368 LOC (matches M19's own estimate exactly).

**Screen bootstrap:** same shared menu/shell as Overview. `on_load_inventory_profit_page()` dispatches `TAB_RESTOCK` (gated `current_user_can('manage_woocommerce')` at the dispatch site, `:263`) to `on_load_restock_screen()`. `render_inventory_profit_shell()`'s `TAB_RESTOCK` case (`:386-392`) re-checks `manage_woocommerce` before calling `render_restock_panel()`.

**Renderer:** `render_restock_panel()` → version notice → `$_GET['wc_io_restock_msg']`/`wc_io_adj_msg` notice rendering (query-arg based, not transient) → `render_restock_subnav()` → branch on `get_restock_subview()` → one of two inline `<form method="post" action="admin-post.php">` blocks (Quick Restock / Cost Adjustment), each with its own `wp_nonce_field()`.

**Legacy redirect:** `redirect_legacy_inventory_admin_pages()` (`:84-102`) — `?page=wc-inventory-restock` → `admin_url_tab(TAB_RESTOCK, ['restock_view'=>RESTOCK_VIEW_QUICK])`, gated `manage_woocommerce`. **This method stays on `Plugin` in its entirety** (it's a shared legacy-URL dispatcher covering both Overview's and Restock's old slugs, per §14) — only its *destination* constants require `Plugin::`-qualification if it were moved, which it is not.

---

## 6. Mutation-path map

CURRENT → PROPOSED authoritative-mutation-owner comparison. **The controller must not become a new business-logic owner** — every mutation's actual domain effect stays exactly where it is today; only the HTTP/admin glue relocates.

| Mutation | CURRENT: HTTP handler → validation → domain effect | PROPOSED: controller handler → validation → domain effect | Owner change? |
|---|---|---|---|
| Bulk set-draft/hide-catalog/mark-(in\|out-of)-stock | `Plugin::maybe_handle_bulk()` → per-ID `current_user_can('edit_product')` → `$product->set_status()`/`set_catalog_visibility()`/`set_stock_status()` + `save()` (inline, no service) | `Overview_Controller::maybe_handle_bulk()` → same inline `WC_Product` calls | **None** — inline mutation code itself is relocated verbatim, not delegated to a new service (none exists, none introduced) |
| Inline stock edit | `Plugin::ajax_save_inline_stock()` → `current_user_can('edit_product')` → `$product->set_stock_quantity()` + optional `Costing::maybe_sync_inventory_value_after_stock_change()` + `save()` | `Overview_Controller::ajax_save_inline_stock()` → identical | **None** |
| Quick Restock | `Plugin::handle_restock_post()` → `WC_Inventory_Overview_Restock_Service::process_purchase_restock()` (**shared domain service — also called by `Goods_Receipt_Service::post()`**) | `Restock_Controller::handle_restock_post()` → **same, unmodified** `Restock_Service::process_purchase_restock()` call | **None** — `Restock_Service` itself is explicitly **not moved**; it is shared domain infrastructure, not screen-owned code |
| Cost Adjustment | `Plugin::handle_cost_adjustment_post()` → `WC_Inventory_Overview_Cost_Adjustment_Service::process()` | `Restock_Controller::handle_cost_adjustment_post()` → same, unmodified | **None** — `Cost_Adjustment_Service` not moved (single caller today, stays a caller after move, just from a different class) |

**Confirmed no accidental duplicate mutation ownership risk:** `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()` have exactly two existing call sites — `Restock_Service::process_purchase_restock()` (Restock screen) and `Goods_Receipt_Service::post()`/`void()` (M4/M5) — confirmed by direct grep; M20 adds no third caller, only relocates the first caller's own HTTP wrapper.

**Confirmed no coupling between Overview's and Restock's mutation paths:** the stock-movements table (`wc_io_inventory_movements`) is written by `insert_purchase()` (Restock only), `insert_cost_adjustment()` (Cost Adjustment only), `insert_goods_receipt[_void]()` (M4/M5 only) — **never** by Overview's inline-stock AJAX, which calls only `set_stock_quantity()`+`save()` with **no movement row written at all**. This is a genuine pre-existing asymmetry (Overview's inline edit is an unaudited mutation by contrast to every other stock-changing path in the plugin) — **out of scope to fix**, documented here only so M20 does not accidentally "improve" it.

---

## 7. AJAX / admin-post endpoint map

| Endpoint | Type | Current owner | M20 owner | Belongs to |
|---|---|---|---|---|
| `wp_ajax_wc_io_save_inline_stock` | AJAX | `Plugin::ajax_save_inline_stock()` | `Overview_Controller` | Overview |
| `admin_post_wc_io_restock` | admin-post | `Plugin::handle_restock_post()` | `Restock_Controller` | Restock |
| `admin_post_wc_io_cost_adjustment` | admin-post | `Plugin::handle_cost_adjustment_post()` | `Restock_Controller` | Restock |
| `wp_ajax_wc_io_get_cost_adjustment_preview` | AJAX (read-only) | `Plugin::ajax_get_cost_adjustment_preview()` | `Restock_Controller` | Restock (despite physical proximity to Overview methods in the current file) |
| `wp_ajax_wc_io_search_suppliers` | AJAX | `Purchasing_Page::ajax_search_suppliers()` | **unchanged — stays on `Purchasing_Page`** | Neither — see §9 cross-cutting note |
| `wp_ajax_wc_io_quick_create_supplier` | AJAX | `Purchasing_Page::ajax_quick_create_supplier()` | **unchanged — stays on `Purchasing_Page`** | Neither — see §9 cross-cutting note |

No `nopriv` variants anywhere in scope. CSV export (Overview) is `admin_init`-timed (via `load-woocommerce_page_*` → `on_load_screen()`), not a distinct hook — confirmed the only Overview/Restock hooks are the six rows above plus the two `admin_enqueue_scripts` registrations (`enqueue_assets` shared-with-Dashboard, `enqueue_restock_assets` self-contained) and the two `load-*`/`admin_menu` shell hooks that stay on `Plugin`.

---

## 8. Security matrix

Every mutation/AJAX endpoint, validation order verified from source (not assumed):

| Handler | Capability | Order | Nonce (action/field) | Request keys | Failure | Success |
|---|---|---|---|---|---|---|
| `maybe_handle_bulk()` | `edit_products` (page-level, enforced by shell dispatch) then per-ID `edit_product` | cap(page)→detect-action→**nonce**→per-ID-cap→mutate | `bulk-wc-inventory-items` / `_wpnonce` (WP_List_Table default, derived from singular/plural table args) | `action`/`action2`, `post[]` | Silent no-op if no valid action or missing nonce param (before `check_admin_referer` even runs — `:885-887`); `wp_die()` on nonce mismatch (WP core) | `wp_safe_redirect` with `wc_io_bulk_done=<n>` |
| `ajax_save_inline_stock()` | `edit_products` then `edit_product` (per-item) | cap→**nonce**→param-validate→mutate | `wc_io_inventory` / `nonce` | `product_id`, `stock_qty` | `wp_send_json_error` 403 (cap) / 400 (invalid product/unmanaged stock/missing qty) | `wp_send_json_success({formatted, badgesHtml})` |
| `handle_restock_post()` | `manage_woocommerce` | **cap→nonce**→param-parse→(line_id presence check)→service-call | `wc_io_restock` / default `_wpnonce` | `wc_io_line_id`, `wc_io_qty`, `wc_io_unit_cost`, `wc_io_supplier`, `wc_io_note` | `wp_die()` (cap); redirect `wc_io_restock_msg=missing_product\|error(+err)` | redirect `wc_io_restock_msg=success` |
| `handle_cost_adjustment_post()` | `manage_woocommerce` | **cap→nonce**→param-parse→(line_id check)→service-call | `wc_io_cost_adjustment` / default `_wpnonce` | `wc_io_adj_line_id`, `wc_io_new_avg_cost`, `wc_io_adj_note` | `wp_die()` (cap); redirect `wc_io_adj_msg=missing_product\|error(+err)` | redirect `wc_io_adj_msg=success` |
| `ajax_get_cost_adjustment_preview()` | `manage_woocommerce` | **cap→nonce**→param-validate→read | `wc_io_cost_adj_preview` / `nonce` | `product_id` | `wp_send_json_error` 403 (cap) / 400 (invalid/wrong type) | `wp_send_json_success({stock_display, avg_display, avg_input, value_html})` |

**Every mutation/AJAX handler checks capability strictly before nonce** (confirmed by line-position comparison, same pattern the M18/M19 architecture guards assert with `strpos`). This ordering must survive extraction byte-for-byte (INV-M20-9). No capability constant class is used for Overview/Restock (unlike Purchasing's `Purchasing_Caps`) — every check is a raw string literal (`edit_products`, `edit_product`, `manage_woocommerce`); these literals are preserved verbatim, no constant is introduced (would be a gratuitous, unrequested refactor).

**No transaction/row-locking anywhere in this surface** — see §20.

---

## 9. Asset / JS contract map

| PHP enqueue method | Handle(s) | Localized object | Keys | AJAX action(s) | JS file |
|---|---|---|---|---|---|
| `enqueue_assets()` Overview branch (to become `Overview_Controller::enqueue_overview_assets()`) | `wc-inventory-overview-admin` (style, shared handle — see split note below), `wc-inventory-overview-admin` (script) | `wcIoInventory` | `ajaxUrl`, `nonce` (`wc_io_inventory`), `strings.error` | `wc_io_save_inline_stock` | `assets/admin.js` |
| `enqueue_restock_assets()` (to become `Restock_Controller::enqueue_restock_assets()`, unchanged name) | `woocommerce_admin_styles`, `wc-inventory-overview-admin` (style), `wc-enhanced-select`, `wc-io-restock-cost-adj`, `wc-io-supplier-picker` | `wcIoCostAdj`, `wcIoSupplierPicker` | `wcIoCostAdj`: `ajaxUrl`, `nonce` (`wc_io_cost_adj_preview`), `strings.{selectProduct,loading,error}`. `wcIoSupplierPicker`: `ajaxUrl`, `nonce` (`wc_io_search_suppliers`), `quickNonce` (`wc_io_quick_create_supplier`), `strings.{newSupplier,loading,error}` | `wc_io_get_cost_adjustment_preview`, and (via `Purchasing_Page`, not this controller) `wc_io_search_suppliers`/`wc_io_quick_create_supplier` | `assets/restock-cost-adj.js`, `assets/supplier-picker.js` |

**JS↔PHP contract verified matching** by direct read of `assets/admin.js` and `assets/restock-cost-adj.js`: action names, nonce field names, and response-shape consumption (`json.data.formatted`/`badgesHtml`; `d.stock_display`/`avg_display`/`avg_input`/`value_html`) all match the PHP side exactly (§4/§5, §6). No mismatch found.

**`enqueue_assets()` split (mandatory decision, resolved here — Policy A-variant, exact M19 §8 precedent):** the shared method currently branches Dashboard/Overview inside one `admin_enqueue_scripts` callback, with an unconditional `wp_enqueue_style('wc-inventory-overview-admin', ..., $tab===DASHBOARD ? ['dashicons'] : [], VERSION)` registration whose `deps` argument differs by tab. Since `wp_enqueue_style()`/`wp_enqueue_script()` are idempotent per handle (already proven safe in production by `Settings_Controller`'s independent re-registration of the same stylesheet handle), the split is:
- **`Overview_Controller::enqueue_overview_assets( $hook )`** (new method, new registration) — guarded `hook==='woocommerce_page_'.Plugin::PAGE_SLUG && Plugin::instance()->get_requested_tab()===Plugin::TAB_OVERVIEW`; re-registers `wp_enqueue_style('wc-inventory-overview-admin', ..., [], VERSION)` (empty deps — today's non-Dashboard branch, verbatim) + `wp_enqueue_script('wc-inventory-overview-admin', 'assets/admin.js', ...)` + the `wcIoInventory` localize block, verbatim.
- **`Plugin::enqueue_assets()`** shrinks to Dashboard-only: guard becomes `if (self::TAB_DASHBOARD !== $tab) return;`, keeps its own `wp_enqueue_style(..., ['dashicons'], ...)` call (Dashboard's own deps variant, unaffected) + the chartjs/dashboard-charts.js block, verbatim.

This duplicates a registration *call*, never business logic — zero risk of a behavior difference, matching the ask's "no copy/paste duplication of business logic" constraint exactly as M19's own split did.

**Cross-cutting decision (mandatory, resolved here): supplier-picker/`Purchasing_Page` AJAX.** `enqueue_restock_assets()` creates and localizes nonces (`wc_io_search_suppliers`, `wc_io_quick_create_supplier`) for AJAX actions whose **handlers live on `Purchasing_Page`**, not `Plugin`/Restock. Decision: **the AJAX handler registration stays on `Purchasing_Page`, untouched.** Only the enqueue/localize *glue* (which was already on the Restock side, not Purchasing's) moves to `Restock_Controller::enqueue_restock_assets()` verbatim — this is pure nonce-creation (idempotent, no hook-registration conflict), not a handler relocation, so there is no ownership change and no duplicate-registration risk. **Pre-existing observation, explicitly out of scope:** the current Restock form markup (`render_restock_panel()`) has no visible `.wc-io-supplier-search` element for `supplier-picker.js` to bind to (the supplier field is a plain text input, `:676-678`) — this looks like a possibly-orphaned enqueue, but it is **pre-existing behavior** and diagnosing/fixing it is explicitly out of scope (zero-behavior-change principle; flagged in §32).

---

## 10. Current domain/service ownership (unaffected by M20)

| Class | Role | M20 touches it? |
|---|---|---|
| `WC_Inventory_Overview_Restock_Service` | Weighted-average cost + stock mutation for restock (shared with Goods Receipt) | **No** — called unchanged from the new controller |
| `WC_Inventory_Overview_Cost_Adjustment_Service` | Average-cost-only mutation | **No** |
| `WC_Inventory_Overview_Costing` | Cost-meta read helpers (`META_AVG`/`META_VAL`) | **No** |
| `WC_Inventory_Overview_Movements` | Stock-movement ledger writes | **No** |
| `WC_Inventory_Overview_List_Table` | Overview grid rendering, bulk-action *labels*, inline-edit markup | **No** — called unchanged |
| `WC_Inventory_Overview_Repository` / `Summary` / `Inventory_Position_Service` | Overview's data reads | **No** |
| `WC_Inventory_Overview_Purchasing_Page` | Supplier autocomplete/quick-create AJAX handlers | **No** (§9) |
| `WC_Inventory_Overview_Settings` | `auto_update_inventory_value_on_stock_edit()` flag read by inline-stock AJAX | **No** |

No raw SQL exists in any of the 16 methods being moved (confirmed by grep for `$wpdb`/`global $wpdb` across both clusters — zero hits; all data access already delegates to the classes above, unmodified).

---

## 11. Proposed controller architecture

**Two controllers**, per §1's reasoning:

- `WC_Inventory_Overview_Restock_Controller` — `includes/class-wc-inventory-overview-restock-controller.php`
- `WC_Inventory_Overview_Overview_Controller` — `includes/class-wc-inventory-overview-overview-controller.php`

**Naming note (explicit, per M19's own precedent of flagging naming decisions instead of leaving them implicit):** `Overview_Controller` for the "Inventory Overview" tab reads as a doubled word (`WC_Inventory_Overview_Overview_Controller`) because the plugin's own top-level name already contains "Overview." This is judged the correct choice anyway — it is the literal, consistent application of the established `<Tab>_Controller` naming rule (`Dashboard_Controller`, `Settings_Controller`, `Reporting_Controller`), and inventing a different name for this one tab only (e.g. `Inventory_List_Controller`, `Catalog_Controller`) would be an unrequested, inconsistent deviation. Kept as-is.

Both are bare singletons (`instance()` + `init()`), no constructor arguments, no base class — replicating §3's established convention exactly.

`Restock_Controller::init()` registers: `admin_enqueue_scripts` (`enqueue_restock_assets`), `admin_post_wc_io_restock` (`handle_restock_post`), `admin_post_wc_io_cost_adjustment` (`handle_cost_adjustment_post`), `wp_ajax_wc_io_get_cost_adjustment_preview` (`ajax_get_cost_adjustment_preview`).

`Overview_Controller::init()` registers: `admin_enqueue_scripts` (`enqueue_overview_assets`), `wp_ajax_wc_io_save_inline_stock` (`ajax_save_inline_stock`). The top-level `add_filter('set_screen_option_wc_io_per_page', ...)` closure is **not** inside `init()` — it stays a file-scope call at the bottom of the new file, exactly matching `Reporting_Controller`'s own three screen-option filters' placement (confirmed precedent).

**Renaming policy (explicit, resolved — minimal-renaming to reduce invocation-seam risk):** every relocated method keeps its **exact current name**, with one exception per controller: the single render entry point is renamed to `render()`, exactly matching the `Dashboard_Controller`/`Settings_Controller` precedent (both of which also had exactly one render entry point, unlike `Reporting_Controller`'s three). `render_restock_panel()` → `Restock_Controller::render()`; `render_inventory_overview_panel()` → `Overview_Controller::render()`. No other method is renamed — `on_load_screen()`, `on_load_restock_screen()`, `enqueue_restock_assets()`, `handle_restock_post()`, `handle_cost_adjustment_post()`, `ajax_get_cost_adjustment_preview()`, `ajax_save_inline_stock()`, `maybe_export_csv()`, `get_query_params_from_request()`, `maybe_handle_bulk()`, `detect_bulk_action()`, `render_summary_cards()`, `get_restock_subview()`, `render_restock_subnav()` all keep their current names verbatim. This deliberately deviates from M19's optional `_screen`-suffix trimming (which required an extra "check no external reference" caveat) — minimizing invented renames minimizes the symbol-audit and caller-search surface, which matters more here given M20's larger mutation surface.

Plugin finishes M20 as: composition root, menu registration, top-level tab routing/dispatch, legacy-slug redirects, Dashboard's own asset enqueue — i.e. exactly the outcome the ask anticipated, confirmed correct by source (§3).

---

## 12. Exact extraction boundary

See §13/§14 for the literal method lists. Boundary rule: **everything currently reachable only from Overview's or Restock's own hook registrations/dispatch branches moves; everything reachable from more than one tab, or that is tab-routing/shell infrastructure, stays on `Plugin`.** `WC_Inventory_Overview_List_Table` and all domain/service classes (§10) are external dependencies, called identically, never modified or moved.

---

## 13. Exact methods to move

### To `Restock_Controller` (verbatim names except the one noted rename)

| Method | New vis | `self::`/`static::` refs requiring `Plugin::`-qualification | `$this->` refs requiring `Plugin::instance()->`-qualification |
|---|---|---|---|
| `get_restock_subview()` | protected (unchanged) | `self::TAB_RESTOCK`, `self::RESTOCK_VIEW_QUICK` (×2), `self::RESTOCK_VIEW_ADJUST` | `$this->get_requested_tab()` |
| `render_restock_subnav()` | protected (unchanged) | `self::RESTOCK_VIEW_QUICK`, `self::RESTOCK_VIEW_ADJUST` (array keys — **stay unqualified**, they're the new class's own local `$items` array keys, not `Plugin` constants — verify against §16 before qualifying), `self::TAB_RESTOCK` (in `admin_url_tab()` call) | `$this->get_restock_subview()` (same-class, no change), `$this->admin_url_tab(...)` |
| `on_load_restock_screen()` | public (unchanged) | none | none |
| `enqueue_restock_assets( $hook_suffix )` | public (unchanged) | `self::PAGE_SLUG`, `self::TAB_RESTOCK` | `$this->get_requested_tab()` |
| `handle_restock_post()` | public (unchanged) | `self::TAB_RESTOCK`, `self::RESTOCK_VIEW_QUICK` | `$this->admin_url_tab(...)` |
| `handle_cost_adjustment_post()` | public (unchanged) | `self::TAB_RESTOCK`, `self::RESTOCK_VIEW_ADJUST` | `$this->admin_url_tab(...)` |
| `render_restock_panel()` → **`render()`** | **public (promoted from protected)** | none | `$this->render_restock_subnav()` (same-class, no change), `$this->get_restock_subview()` (same-class, no change) |
| `ajax_get_cost_adjustment_preview()` | public (unchanged) | none | none |

### To `Overview_Controller` (verbatim names except the one noted rename)

| Method | New vis | `self::`/`static::` refs requiring `Plugin::`-qualification | `$this->` refs requiring `Plugin::instance()->`-qualification |
|---|---|---|---|
| `on_load_screen()` | public (unchanged) | none | `$this->maybe_export_csv()` (same-class), `$this->maybe_handle_bulk()` (same-class) |
| `maybe_export_csv()` | protected (unchanged) | none | `$this->get_query_params_from_request()` (same-class) |
| `get_query_params_from_request()` | protected (unchanged) | none | none |
| `maybe_handle_bulk()` | protected (unchanged) | `self::TAB_OVERVIEW` | `$this->detect_bulk_action()` (same-class), `$this->admin_url_tab(...)` |
| `detect_bulk_action()` | protected (unchanged) | none | none |
| `ajax_save_inline_stock()` | public (unchanged) | none | none |
| `enqueue_assets()` Overview branch → **new method `enqueue_overview_assets( $hook )`** | public (new) | `self::PAGE_SLUG` | `$this->get_requested_tab()` |
| `render_summary_cards( array $stats )` | public static (unchanged) | none | none |
| `render_inventory_overview_panel()` → **`render()`** | **public (promoted from protected)** | `self::PAGE_SLUG`, `self::TAB_OVERVIEW` (hidden form fields), `self::render_summary_cards(...)` (**stays `self::`, correct — resolves to this same new class after the move**) | none |
| top-level filter `set_screen_option_wc_io_per_page` | — (file-scope, unchanged) | none | none |

**Mandatory pre-completion step for both files (mirrors M19 §16's structural mitigation for M18's one real defect — six unqualified `self::` constants that survived undetected until a later full-suite run):**

```
grep -nE 'self::|static::|\$this->[a-zA-Z_]+\(' includes/class-wc-inventory-overview-restock-controller.php
grep -nE 'self::|static::|\$this->[a-zA-Z_]+\(' includes/class-wc-inventory-overview-overview-controller.php
```

Every hit is checked against the tables above — the two tables are complete per-method inventories derived directly from the current source, so the implementing agent has this pre-done, not to be re-derived. Any `self::`/`static::` hit not listed in a table, or any `$this->` hit calling a method not defined on the same new class, is a defect and must be fixed before the WP is considered complete (not deferred to a later closure pass).

---

## 14. Exact methods to retain in Plugin (unchanged, verbatim — for completeness/contrast)

`instance()`, `init()` (modified only to add/remove bootstrap lines, §25), `register_menu()`, `redirect_legacy_inventory_admin_pages()`, `redirect_to_inventory_profit_tab()`, `get_tabs_definition()`, `get_requested_tab()`, `admin_url_tab()`, `on_load_inventory_profit_page()` (modified only at its dispatch call sites, §25), `render_inventory_profit_tabs()`, `render_tab_placeholder()` (dead code, untouched), `render_inventory_profit_shell()` (modified only at its `TAB_OVERVIEW`/`TAB_RESTOCK`/`default` case bodies, §25), `enqueue_assets()` (shrunk to Dashboard-only, §9).

---

## 15. Business rules matrix (BR-M20-*)

| # | Rule |
|---|---|
| BR-M20-1 | Restock panel renders identical markup: version notice, `wc_io_restock_msg`/`wc_io_adj_msg` query-arg notices (exact same success/error/missing-product strings), subnav, one of two forms selected by `get_restock_subview()`. Capability gate (`manage_woocommerce`) enforced identically at both the shell case-block and the panel-render level, byte-for-byte (mirrors BR-M19-1a's "both checks preserved, not deduplicated" precedent). |
| BR-M20-2 | Quick Restock form: identical field set/names (`wc_io_line_id`, `wc_io_qty`, `wc_io_unit_cost`, `wc_io_supplier`, `wc_io_note`), identical nonce action `wc_io_restock`, identical `admin-post.php` target, identical product-search widget markup/data-action. |
| BR-M20-3 | Cost Adjustment form: identical field set (`wc_io_adj_line_id`, `wc_io_new_avg_cost`, `wc_io_adj_note`), identical nonce action `wc_io_cost_adjustment`, identical current-values placeholder markup consumed by `restock-cost-adj.js`. |
| BR-M20-4 | `get_restock_subview()`/`render_restock_subnav()`: identical default (`quick`), identical `restock_view=batch` → `quick` fallback, identical subnav link/current-item markup. |
| BR-M20-5 | `handle_restock_post()`: capability→nonce→param-parse (including the `[0]` array-fallback quirk for `wc_io_line_id`) →missing-product redirect→`Restock_Service::process_purchase_restock()` call with identical argument order/types→identical success/error redirect targets and query args, verbatim. |
| BR-M20-6 | `handle_cost_adjustment_post()`: identical structure/contract to BR-M20-5 for `wc_io_adj_line_id`/`Cost_Adjustment_Service::process()`. |
| BR-M20-7 | Both handlers: `wp_die()` on capability failure (not a redirect), `check_admin_referer()` on the default `_wpnonce` field, exact same order (cap before nonce). |
| BR-M20-8 | `ajax_get_cost_adjustment_preview()`: identical capability (`manage_woocommerce`)/nonce (`wc_io_cost_adj_preview`/`nonce`) order, identical product-type rejection rules (variable/grouped/external → 400; non-variation/simple → 400), identical response JSON shape (`stock_display`, `avg_display`, `avg_input`, `value_html`), identical error-message strings and HTTP status codes (403/400). |
| BR-M20-9 | `enqueue_restock_assets()`: identical hook-suffix/tab/capability guard, identical handle/src/deps/version/footer args for every enqueued style/script, identical localized object names/keys/nonce-action-strings for `wcIoCostAdj` and `wcIoSupplierPicker` (including the cross-cutting `wc_io_search_suppliers`/`wc_io_quick_create_supplier` nonces, §9). |
| BR-M20-10 | Overview panel renders identical markup: bulk-done notice (`wc_io_bulk_done` query-arg, exact `_n()` string), heading, `List_Table` search/filter/table, summary cards — in the exact same order. |
| BR-M20-11 | `render_summary_cards()`: identical 7-card set (`total`, `in_stock`, `out_of_stock`, `on_backorder`, `low_stock`, `draft`, `hidden`), identical alert-threshold logic, identical markup/CSS classes/`data-wc-io-metric` attributes. |
| BR-M20-12 | `on_load_screen()`: identical screen-option registration (`wc_io_per_page`, default 20), identical dispatch order (export-check before bulk-check). |
| BR-M20-13 | `maybe_export_csv()`: identical trigger (`$_GET['wc_io_export']==='csv'`), identical nonce (`wc_io_export_csv`/`_wc_io_export_nonce`), identical column set (cost/value columns gated `manage_woocommerce`, exact same conditional order), identical filename pattern (`wc-inventory-overview-{Y-m-d}.csv`), identical BOM/header bytes, identical delegate to `Repository::iterate_products_for_export()` with identical params from `get_query_params_from_request()`. |
| BR-M20-14 | `maybe_handle_bulk()`: identical 4-action whitelist, identical detection order (`action` before `action2`), identical silent-no-op-on-missing-nonce guard *before* `check_admin_referer()` runs, identical per-ID `wc_get_product()`+`current_user_can('edit_product', $id)` gate (skip, not abort, on failure), identical mutation calls per action slug, identical `$sendback`/`remove_query_arg`/`add_query_arg`/redirect sequence, identical `wc_io_bulk_done=<n>` counter semantics (counts only successful mutations). |
| BR-M20-15 | `ajax_save_inline_stock()`: identical capability (`edit_products` then `edit_product`)/nonce (`wc_io_inventory`/`nonce`) order, identical validation sequence (product_id→managing_stock→stock_qty presence), identical conditional `Costing::maybe_sync_inventory_value_after_stock_change()` call gated on `Settings::auto_update_inventory_value_on_stock_edit()`, identical response shape (`formatted`, `badgesHtml` via `List_Table::render_status_badges()`), identical error messages/status codes. |
| BR-M20-16 | `enqueue_overview_assets()` (split from `enqueue_assets()`): identical hook/tab guard, identical style/script handle/src/deps/version, identical `wcIoInventory` localized object (keys, nonce action `wc_io_inventory`, error string) — see §9's split decision. |
| BR-M20-17 | `Plugin::enqueue_assets()` residual (Dashboard-only): identical Dashboard-branch behavior (chartjs + dashboard-charts.js enqueue, `['dashicons']` style deps) — byte-identical, only the guard condition and the removed Overview conditional block change. |
| BR-M20-18 | Both `render_inventory_profit_shell()` case blocks (`TAB_OVERVIEW`, `TAB_RESTOCK`) **and** the `default:` fallback case (which also currently calls `render_inventory_overview_panel()`, `:421-423`) are updated to call the new controllers — all three call sites, not just two. |
| BR-M20-19 | `on_load_inventory_profit_page()`'s `TAB_OVERVIEW` and `TAB_RESTOCK` dispatch branches call the new controllers' `on_load_screen()`/`on_load_restock_screen()`; capability gates at the dispatch site (`current_user_can('manage_woocommerce')` for Restock, page-level `edit_products` already checked earlier in the method for Overview) unchanged. |
| BR-M20-20 | `redirect_legacy_inventory_admin_pages()` stays on `Plugin`, unmodified, and continues to redirect both legacy slugs (`wc-inventory-overview`→Overview tab, `wc-inventory-restock`→Restock tab) to the same destinations with the same capability gates. |

---

## 16. Architectural invariants matrix (INV-M20-*)

| # | Invariant | Guard |
|---|---|---|
| INV-M20-1 | `Plugin` remains sole tab-routing/shell owner (`PAGE_SLUG`, `TAB_*`, `RESTOCK_VIEW_*`, `get_tabs_definition()`, `get_requested_tab()`, `admin_url_tab()`, `on_load_inventory_profit_page()`, `render_inventory_profit_shell()`, `register_menu()`, `redirect_legacy_inventory_admin_pages()` stay on `Plugin`). Both new controllers only call `Plugin`'s already-public methods, never redefine them. | Architecture-guard test, both controllers |
| INV-M20-2 | Zero behavior change — every BR in §15 byte-identical pre/post extraction. | Characterization tests rerun unchanged (invocation-seam edits only) |
| INV-M20-3 | Zero schema change — `DB_VERSION` remains `'11'`. | Targeted rerun of the v11 schema test |
| INV-M20-4 | Zero new capability — only pre-existing `edit_products`/`edit_product`/`manage_woocommerce` literals referenced; no capability constant class introduced. | Architecture-guard regex-extract-all-`current_user_can()` + `assertSame()`, per controller (M19 §17's stronger pattern, not M18's weak "not-empty" check) |
| INV-M20-5 | Zero new public API — no `do_action`/`apply_filters` introduced in either new file. | Architecture-guard substring-count check |
| INV-M20-6 | Zero new domain concept — no `docs/OWNERSHIP.md` change; `Restock_Service`/`Cost_Adjustment_Service`/`Movements`/`Costing`/`List_Table`/`Repository`/`Summary`/`Inventory_Position_Service` remain unmodified, uncoupled to the new controllers beyond being called exactly as today. | Manual check + architecture-guard grep confirming zero new methods on any domain class |
| INV-M20-7 | `Restock_Service`'s and `Cost_Adjustment_Service`'s caller sets gain zero new entries beyond the relocated `handle_restock_post()`/`handle_cost_adjustment_post()` (still exactly one caller each, now on a different class). No third caller introduced anywhere. | Architecture-guard grep, repo-wide |
| INV-M20-8 | Query-count invariance — every DB-touching call inside the moved methods is unchanged (same target class/method/args); pre-extraction baseline (§19) must match exactly post-extraction, for both clusters independently. | Query-count characterization, per cluster |
| INV-M20-9 | No new capability-check/nonce ordering — every handler's `current_user_can()`→(nonce)→mutate sequence reused verbatim, capability strictly before nonce where both exist. | Architecture-guard positional (`strpos`) ordering check, per handler |
| INV-M20-10 | Nonce/action-string stability — every `wp_nonce_field()`/`check_admin_referer()`/`check_ajax_referer()`/`wp_create_nonce()` literal reused verbatim (`wc_io_restock`, `wc_io_cost_adjustment`, `wc_io_inventory`, `wc_io_cost_adj_preview`, `wc_io_export_csv`, `bulk-wc-inventory-items`, `wc_io_search_suppliers`, `wc_io_quick_create_supplier`). | Architecture-guard grep-diff, per controller |
| INV-M20-11 | Mechanical-move discipline — every relocation is the exact substitution pattern in §13's tables (`self::X`→`WC_Inventory_Overview_Plugin::X`, `$this->plugin_method()`→`WC_Inventory_Overview_Plugin::instance()->plugin_method()`), no other line changes, per the pre-move symbol audit (§13). This is the exact invariant M18 violated once (six unqualified `self::` constants) — mitigated here the same structural way M19 mitigated it (grep-based audit as an explicit WP sub-step, not a documentation reminder). | Manual diff review + pre-move symbol audit + architecture-guard substring comparison |
| INV-M20-12 | `Plugin::init()`'s bootstrap order is unchanged for existing entries; `Restock_Controller::instance()->init()` and `Overview_Controller::instance()->init()` are appended after the existing bootstrap block (after `Reporting_Controller::instance()->init();`, before `Expected_Delivery_Service::register();`), never interleaved. | Manual diff review + architecture-guard bootstrap-order `strpos`/`assertLessThan` chain (extends the existing 3-way check to 5-way) |
| INV-M20-13 | Dashboard/Settings/Movements/Order-Profit/Product-Profitability methods are **not touched** by any M20 commit — confirmed by diff review showing only the 16 moved methods + 1 filter + the `enqueue_assets()` Overview-branch removal changed in `Plugin`. | Diff review at each extraction WP checkpoint |
| INV-M20-14 | Every characterization test genuinely predates its extraction — Restock's characterization (WP-M20-1) is green against unmodified code before WP-M20-2 touches anything; Overview's characterization (WP-M20-4) is green against unmodified code (which by then already contains the *Restock* extraction, since extraction is sequential, not the Overview extraction) before WP-M20-5 touches anything. | WP checkpoint order enforced by the ladder (§24) |
| INV-M20-15 | Every post-extraction characterization-test change is classified as INVOCATION-SEAM ONLY, FIXTURE CORRECTION, or (prohibited) BEHAVIORAL ASSERTION CHANGE — no change goes unclassified in the implementation report. | Implementation report requirement |
| INV-M20-16 | No DB transaction is introduced around any Restock/Cost-Adjustment/bulk/inline-stock mutation that didn't have one today — the existing hand-rolled compensating-rollback pattern (`Restock_Service::restore_snapshot()`/`Cost_Adjustment_Service::restore_meta_snapshot()`) and the existing non-transactional per-ID bulk-save loop are preserved exactly, not "improved." | Architecture-guard grep confirming zero new `DB_Transaction`/`START TRANSACTION` references in either new controller file |
| INV-M20-17 | The AJAX handlers for `wc_io_search_suppliers`/`wc_io_quick_create_supplier` remain registered on `Purchasing_Page`, not on either new controller — only the pre-existing nonce-creation/localization glue for those actions (already on the Restock side) relocates. | Architecture-guard grep confirming no `wp_ajax_wc_io_search_suppliers`/`wp_ajax_wc_io_quick_create_supplier` hook registration exists in `Restock_Controller` |
| INV-M20-18 | `WC_Inventory_Overview_List_Table` gains zero new methods and zero modified methods — called identically by `Overview_Controller` as it is today by `Plugin`. | Architecture-guard diff/hash check on `class-wc-inventory-overview-list-table.php` (must be byte-identical to the pre-M20 baseline) |
| INV-M20-19 | The pre-existing M19 regression test `test_overview_and_restock_remain_on_plugin()` (in `tests/unit/admin-decomposition/test-reporting-controller-architecture.php`) is deliberately updated to assert the new, post-M20 boundary — this is an intentional invariant change from M19 to M20 (M19's own text names Phase 3 as the reason this test exists in its current form), explicitly classified as a REGRESSION-TEST UPDATE, not a characterization-assertion violation under INV-M20-15 (which governs only M20's own new pre-extraction tests). | WP-M20-8, explicit line item |
| INV-M20-20 | Restock and Overview extractions are sequenced strictly (Restock's full characterize→extract→guard sub-ladder completes before Overview's begins) — no interleaving of the two clusters' commits. | Commit-order review at freeze |

---

## 17. Characterization-test matrix

All new files live in `tests/integration/admin-decomposition/` (integration, `WP_UnitTestCase`-based, matching M18/M19's location) unless noted. Every file/method below is written and run green against **unmodified** pre-extraction code before its cluster's extraction WP begins (INV-M20-14).

| # | File | Cluster | Covers | Timing |
|---|---|---|---|---|
| 1 | `test-restock-rendering-characterization.php` (`Test_WC_IO_Restock_Rendering_Characterization`) | Restock | BR-M20-1/2/3/4/20; bootstrap/render/subnav/notices/legacy-redirect regression; capability-denial `WPDieException`; query-count baseline | Pre-extraction (WP-M20-1) |
| 2 | `test-restock-mutation-characterization.php` (`Test_WC_IO_Restock_Mutation_Characterization`) | Restock | BR-M20-5/6/7; success/validation-failure/capability-denial/nonce-denial for both `handle_restock_post()` and `handle_cost_adjustment_post()`; redirect-target/query-arg assertions; confirms `Restock_Service`/`Cost_Adjustment_Service` still the sole mutators (no duplicate call) | Pre-extraction (WP-M20-1) |
| 3 | `test-restock-cost-adjustment-preview-characterization.php` (`Test_WC_IO_Restock_Cost_Adjustment_Preview_Characterization`) | Restock | BR-M20-8; success/validation-failure/capability-denial/nonce-denial; response-shape assertions | Pre-extraction (WP-M20-1) |
| 4 | `tests/unit/admin-decomposition/test-restock-controller-architecture.php` (`Test_WC_IO_Restock_Controller_Architecture`) | Restock | INV-M20-1/4/5/9/10/11/12/16/17 | Post-extraction (WP-M20-3) |
| 5 | `test-overview-rendering-characterization.php` (`Test_WC_IO_Overview_Rendering_Characterization`) | Overview | BR-M20-10/11/12/18/19; render/summary-cards/screen-option; capability-denial; query-count baseline at empty/small/larger catalog scale (reuse the M3/M8 at-scale fixture pattern) | Pre-extraction (WP-M20-4) |
| 6 | `test-overview-bulk-action-characterization.php` (`Test_WC_IO_Overview_Bulk_Action_Characterization`) | Overview | BR-M20-14; all 4 action slugs' success path, invalid-action no-op, missing-nonce silent-return, per-ID capability-skip behavior, redirect/`wc_io_bulk_done` counter correctness | Pre-extraction (WP-M20-4) |
| 7 | `test-overview-inline-stock-ajax-characterization.php` (`Test_WC_IO_Overview_Inline_Stock_Ajax_Characterization`) | Overview | BR-M20-15; success, missing-qty, unmanaged-stock, invalid-product, capability-denial, nonce-denial; the conditional `Costing::maybe_sync_inventory_value_after_stock_change()` branch both ways | Pre-extraction (WP-M20-4) |
| 8 | `test-overview-csv-export-characterization.php` (`Test_WC_IO_Overview_Csv_Export_Characterization`) | Overview | BR-M20-13; header bytes, column set (with/without `manage_woocommerce`), nonce-denial, delegate-call assertion to `Repository::iterate_products_for_export()` | Pre-extraction (WP-M20-4) |
| 9 | `tests/unit/admin-decomposition/test-overview-controller-architecture.php` (`Test_WC_IO_Overview_Controller_Architecture`) | Overview | INV-M20-1/4/5/9/10/11/12/16/18 | Post-extraction (WP-M20-6) |
| 10 | (update, not new) `tests/unit/admin-decomposition/test-reporting-controller-architecture.php` | Both | INV-M20-19 — `test_overview_and_restock_remain_on_plugin()` rewritten to assert the new boundary | WP-M20-7 |
| 11 | Targeted regression rerun (no new file) | Both | `Test_WC_IO_No_Sibling_Plugin_Coupling`, `Test_Cost_Adjustment_Characterization`, `Test_WC_IO_Restock_Service_Reversal`, `Test_Movements_Characterization`, `Test_WC_IO_Inventory_Position_List_Table`, v11 schema test — must require zero code changes to pass | WP-M20-7 |

**No pre-existing admin-glue-layer characterization exists for either cluster** (confirmed by both exploration passes) — unlike M19, which could point to prior domain-level tests as harness-convention proof, M20's characterization tests are written from scratch. The domain-level tests (`Test_Costing_Characterization`, `Test_Cost_Adjustment_Characterization`, `Test_WC_IO_Restock_Service_Reversal`, `Test_Movements_Characterization`) already prove the harness/fixture conventions work for this exact domain area, so no new convention needs to be invented, only the HTTP/admin-glue layer needs new coverage.

**Expected test count:** ≈13 characterization tests (Restock: render/notices, mutation×2 handlers×~4 scenarios each, preview×4 scenarios ≈ 3 files) + ≈16 characterization tests (Overview: render, bulk×4 actions+edge cases, inline-AJAX×6 scenarios, CSV×4 scenarios ≈ 4 files) + ≈16 architecture-guard tests (8 per controller, mirroring `Reporting_Controller`'s 10-test density scaled to two smaller classes) ≈ **45 new tests**, larger than M19's 16 (proportional to the larger, mutation-bearing scope) but smaller than M18's 47 (each individual cluster is smaller than M18's Settings half).

---

## 18. Architecture-guard matrix

Both `test-restock-controller-architecture.php` and `test-overview-controller-architecture.php` follow the `test-reporting-controller-architecture.php` template (the most elaborate existing guard file, 10 tests) exactly:

1. No `global $wpdb`/`$wpdb->` in the controller file (INV-M20-6/18).
2. No new `do_action(`/`apply_filters(` (INV-M20-5).
3. Strict capability-set assertion — regex-extract every `current_user_can()` call, `assertSame()` against the known literal set (INV-M20-4).
4. Nonce-string preservation — `assertStringContainsString()` for every nonce action literal (INV-M20-10).
5. Capability→nonce ordering per handler, via `strpos()` position comparison inside each extracted method body (INV-M20-9).
6. `Plugin` no longer owns the moved methods — `assertStringNotContainsString('function <name>', $plugin_file)` for each of the 8 (Restock) / 8+1-filter (Overview) relocated symbols (mirrors INV-M18/19-11's pattern).
7. `Plugin` still owns tab-routing (`TAB_RESTOCK`/`TAB_OVERVIEW`, `PAGE_SLUG`, `get_requested_tab()`, `admin_url_tab()` still present in `plugin.php`) (INV-M20-1).
8. Bootstrap-order check — 5-way `strpos`/`assertLessThan` chain: `Purchasing_Page` < `Settings_Controller` < `Reporting_Controller` < `Restock_Controller`/`Overview_Controller` < `Expected_Delivery_Service` (INV-M20-12).
9. Dispatch call-site check — `Plugin`'s `on_load_inventory_profit_page()`/`render_inventory_profit_shell()` (all three case-block sites, including the `default:` fallback per BR-M20-18) contain the new `<Controller>::instance()->` call strings (INV-M20-1/M20-11).
10. No `DB_Transaction`/`START TRANSACTION` reference in either new file (INV-M20-16).
11. (`Restock_Controller` only) No `wp_ajax_wc_io_search_suppliers`/`wp_ajax_wc_io_quick_create_supplier` registration present (INV-M20-17).
12. (`Overview_Controller` only) `enqueue_assets()`'s Overview branch is verifiably gone from `plugin.php` via method-body-extraction + substring-absence check, mirroring `Reporting_Controller`'s own Movements-branch-removal guard (INV-M20-11).
13. Repo-wide `Test_WC_IO_No_Sibling_Plugin_Coupling` re-run (auto-picks-up both new files via its existing glob).

---

## 19. Query/performance baseline plan

**No number is invented here** — every baseline is measured during characterization (WP-M20-1/4), using the established `$before = $wpdb->num_queries; ...; $wpdb->num_queries - $before` delta pattern (already used across M9/M11/M12/M14/M15/M17/M18/M19).

- **Restock cluster:** each mutation path (`handle_restock_post()`, `handle_cost_adjustment_post()`) is expected to be a small, bounded, deterministic query count (one product load+save, one movement insert, plus WC-core internal meta reads) — no loop, no catalog-scale dependency. `ajax_get_cost_adjustment_preview()` is read-only, similarly bounded. Baseline captured once per handler at WP-M20-1; must match exactly post-extraction (WP-M20-2).
- **Overview cluster:** the render path is already a known multi-query, bulk-safe pattern (M3's own no-N+1 guard already covers `Inventory_Position_Service`; `Summary::build()` issues a fixed 6+pagination-bounded query set regardless of catalog size). Baseline must be captured at three scales — empty catalog, small (≈20 mixed simple/variable, reusing M3's own fixture convention), and larger (≈100+, reusing M8's GA-scale convention) — and must match exactly post-extraction. The bulk-action per-ID save loop is **pre-existing O(n) behavior in n selected IDs** — characterize it at a fixed small n (e.g. 5) as a baseline only, **not** a ceiling to further optimize (zero-behavior-change principle — M20 does not batch or otherwise "fix" this loop).
- Extraction introduces **no new query, no N+1, no duplicate repository read, no duplicate screen-bootstrap work, no duplicate AJAX processing, no repeated mutation call** — every call inside every moved method targets the identical, unmodified class/method/args it does today (confirmed zero raw SQL in scope, §10).

---

## 20. Transaction / failure-preservation plan

**No DB transaction exists anywhere in this surface today, and M20 introduces none** (INV-M20-16) — confirmed by direct grep: `Restock_Service` and `Cost_Adjustment_Service` never reference `WC_Inventory_Overview_DB_Transaction`/`START TRANSACTION` (unlike `Goods_Receipt_Service`/`PO_Service`, which do).

- **Restock/Cost-Adjustment mutation owner:** the domain services themselves (`Restock_Service::process_purchase_restock()`, `Cost_Adjustment_Service::process()`), unchanged by M20 — the controller is a thin, unmodified-in-substance HTTP wrapper around them, exactly as `Plugin` is today.
- **Failure behavior (documented, must survive verbatim):** both services use a **hand-rolled compensating rollback**, not a real transaction — the product mutation (`set_stock_quantity()`/meta+`save()`) happens *first*, then the movement-ledger insert is attempted; on movement-insert failure, `restore_snapshot()`/`restore_meta_snapshot()` manually reverts the product to its pre-mutation state and a `WP_Error` (including the raw `$wpdb->last_error`) is returned, surfaced verbatim in the redirect's `wc_io_restock_err`/`wc_io_adj_err` query arg.
- **Overview bulk-action failure behavior:** no transaction, no compensating rollback — a sequential per-ID loop; if a later ID in the batch fails validation (capability/missing product), earlier IDs' mutations are **not** rolled back, the loop simply continues to the next ID. This is existing, intentional-by-omission behavior and must not be "fixed" by M20.
- **Overview inline-stock AJAX failure behavior:** single product, no movement write, no compensation needed (validation happens entirely before the single mutating call).

---

## 21. Version recommendation

**1.37.0** — sequential bump from M19's `1.36.0`, matching the established M9→M19 sequential-dev-version convention. Bumped in `wc-inventory-overview.php`'s `WC_INVENTORY_OVERVIEW_VERSION` constant only; `readme.txt`'s `Stable tag` stays at `1.36.0` until an actual tagged release (confirmed convention: readme's Stable tag reflects the last *released* version, not in-development versions — unchanged by M18/M19 during their own unreleased-development phase either).

---

## 22. DB_VERSION recommendation

**Unchanged: `11`.** No schema touched anywhere in this plan — confirmed no table/column referenced by any moved method changes shape, only which PHP class calls the unchanged repository/service methods.

---

## 23. Level A / B classification

**LEVEL A**, justified point-by-point against the same Level-B triggers M18/M19 used:

| Trigger | Present in M20? |
|---|---|
| Complex mutation paths | Real mutation exists (bulk actions, inline-stock AJAX, restock, cost adjustment) but it is **relocated verbatim** — zero business-logic change, zero new validation, zero new mutation call. M18 precedent: two real admin-post mutation handlers plus a destructive Danger-Zone-Apply path, still Level A, because the relocation was mechanical. |
| Security-sensitive AJAX/admin-post relocation | Four endpoints relocate (§7), but every capability/nonce/ordering contract is preserved byte-for-byte (§8, INV-M20-9/10) — no security *semantics* change, only *location*. |
| Bulk stock/cost operations | Present (Overview's 4 bulk actions, Restock's stock/cost writes) — but, again, code moves, logic doesn't. |
| Query ownership changes | None — zero raw SQL in scope (§10); all data access already delegated to unmodified domain classes. |
| Cross-tab coupling requiring redesign | One small, mechanical case per cluster: `enqueue_assets()`'s Overview-branch split (§9, exact M19 precedent) and the supplier-picker/`Purchasing_Page` nonce-glue relocation (§9, decided, not a redesign — handler ownership doesn't move). Neither is a redesign. |
| Raw SQL relocation | None exists to relocate. |
| High-risk CSV/export behavior | Overview's CSV export is already an isolated, single-purpose method; only relocated, not modified. |

This is **larger in surface area** than either M18 or M19 individually (two controllers, 16 methods, zero pre-existing admin-glue test coverage to build from) but **not qualitatively riskier** in the sense the Level A/B rubric measures — every mutation's actual domain effect is already fully validated/owned by pre-existing, unmodified code (`Restock_Service`/`Cost_Adjustment_Service` for Restock; direct, already-reviewed `WC_Product` calls for Overview, unchanged since before M18 even began). The larger surface area is compensated for by more WPs (10 vs. M19's 7) and per-cluster sequential gating (§16 INV-M20-20), not by accepting a higher risk classification.

---

## 24. WP-M20-0…9 implementation ladder

**WP-M20-0 — Preflight + branch + plan materialization**
Re-verify §2's baseline (`main`==`origin/main`==`8539ec9d`, clean tree, no M20 artifacts). Create branch `feature/m20-admin-controller-decomposition-phase3` from `8539ec9d`. Materialize this approved plan into `docs/milestones/m20-implementation-plan.md`. Commit that file alone (`docs(m20): add approved admin controller decomposition Phase 3 plan`). **Checkpoint:** `git status` clean except the one new file; `git log -1` confirms branch point.

**WP-M20-1 — Restock characterization tests (pre-extraction)**
Create files #1–#3 from §17 (rendering, mutation, cost-adjustment-preview). Cover BR-M20-1…9/20. Record query-count baselines (§19). **Checkpoint (hard gate, INV-M20-14): all three files green against unmodified WP-M20-0 code before WP-M20-2 starts.** Commit: `test(m20): add Restock characterization tests (pre-extraction)`.

**WP-M20-2 — Extract `Restock_Controller`**
Create `includes/class-wc-inventory-overview-restock-controller.php` per §11/§13. Modify `wc-inventory-overview.php` (`require_once`), `includes/class-wc-inventory-overview-plugin.php` (remove the 8 Restock methods + 5 hook registrations from `init()`, add `Restock_Controller::instance()->init();`, update the `TAB_RESTOCK` dispatch call-site in `on_load_inventory_profit_page()` and the `TAB_RESTOCK` case in `render_inventory_profit_shell()`). Run the §13 symbol-audit grep against the new file; resolve every hit. **Checkpoint:** `php -l` on both touched files; rerun WP-M20-1's three files — must stay green with invocation-seam edits only; manual diff review confirms INV-M20-11/13. Commit: `refactor(m20): extract Restock_Controller from Plugin`.

**WP-M20-3 — Restock architecture guard tests**
Create `tests/unit/admin-decomposition/test-restock-controller-architecture.php` per §18 (items 1–11, minus item 12 which is Overview-only). **Checkpoint:** new tests green. Commit: `test(m20): add Restock_Controller architecture guard tests`.

**WP-M20-4 — Overview characterization tests (pre-extraction)**
Create files #5–#8 from §17 (rendering, bulk-action, inline-stock-AJAX, CSV-export). Cover BR-M20-10…19. Record query-count baselines at empty/small/larger catalog scale (§19). **Checkpoint (hard gate, INV-M20-14): all four files green against the current tree (post-Restock-extraction, pre-Overview-extraction) before WP-M20-5 starts.** Commit: `test(m20): add Overview characterization tests (pre-extraction)`.

**WP-M20-5 — Extract `Overview_Controller`**
Create `includes/class-wc-inventory-overview-overview-controller.php` per §11/§13, including the `enqueue_assets()` split (§9). Modify `wc-inventory-overview.php` (`require_once`), `includes/class-wc-inventory-overview-plugin.php` (remove the 8 Overview methods + 1 filter + 2 hook registrations from `init()`, shrink `enqueue_assets()` to Dashboard-only, add `Overview_Controller::instance()->init();`, update the `TAB_OVERVIEW` dispatch call-site in `on_load_inventory_profit_page()`, and **both** the `TAB_OVERVIEW` case **and** the `default:` fallback case in `render_inventory_profit_shell()` per BR-M20-18). Run the §13 symbol-audit grep; resolve every hit. **Checkpoint:** `php -l`; rerun WP-M20-4's four files — invocation-seam edits only; manual diff review confirms INV-M20-11/13; confirm `Dashboard`'s branch of `enqueue_assets()` is byte-identical (INV-M20-13). Commit: `refactor(m20): extract Overview_Controller from Plugin`.

**WP-M20-6 — Overview architecture guard tests**
Create `tests/unit/admin-decomposition/test-overview-controller-architecture.php` per §18 (all 13 items, including item 12's `enqueue_assets()`-branch-removal check). **Checkpoint:** new tests green. Commit: `test(m20): add Overview_Controller architecture guard tests`.

**WP-M20-7 — Update pre-existing M19 regression test + targeted regression pass**
Rewrite `test_overview_and_restock_remain_on_plugin()` in `tests/unit/admin-decomposition/test-reporting-controller-architecture.php` to assert the new post-M20 boundary (INV-M20-19) — classify this explicitly in the implementation report as a deliberate regression-test update, not a violation of INV-M20-15. Rerun (no code changes expected): `Test_WC_IO_No_Sibling_Plugin_Coupling`, `Test_Cost_Adjustment_Characterization`, `Test_WC_IO_Restock_Service_Reversal`, `Test_Movements_Characterization`, `Test_WC_IO_Inventory_Position_List_Table`, the v11 schema test. Any unexpected failure means §6/§10's coupling map missed something — stop and reassess (§33), do not silently patch around it. Commit: `test(m20): update M19 boundary regression test and confirm targeted regression`.

**WP-M20-8 — CI wiring**
Update `tests/docker/run-phpunit.sh`'s filter regex with the new M20 class-name prefixes: `Test_WC_IO_Restock_Rendering_`, `Test_WC_IO_Restock_Mutation_`, `Test_WC_IO_Restock_Cost_Adjustment_Preview_`, `Test_WC_IO_Restock_Controller_`, `Test_WC_IO_Overview_Rendering_`, `Test_WC_IO_Overview_Bulk_Action_`, `Test_WC_IO_Overview_Inline_Stock_Ajax_`, `Test_WC_IO_Overview_Csv_Export_`, `Test_WC_IO_Overview_Controller_` — each verified via `--list-tests` to not already be covered by an existing broader prefix (none of these collide with the existing `Test_WC_IO_Restock_Service_Reversal` literal entry). **Checkpoint:** `--list-tests` confirms discovery of every new class. Commit: `ci(m20): add M20 test class prefixes to focused-suite filter`.

**WP-M20-9 — Final validation + documentation + freeze**
1. Run the full new `tests/unit/admin-decomposition/` + `tests/integration/admin-decomposition/` set plus WP-M20-7's targeted regression set.
2. Run **one** comprehensive final validation sequence: full PHPUnit unit suite, M1–M20 focused suite, full PHPUnit integration suite, PHPCS (advisory), php-parallel-lint, `composer validate --strict`, `docker compose config`, `scripts/release-audit.sh --development` — via the Docker test harness, exactly the M18/M19 closure-pass sequence.
3. Fix any defect found with the narrowest justified change; rerun only the directly affected test(s); rerun a broader suite only if the fix touches code shared beyond the two new controllers/their tests.
4. Push branch, open/update the draft CI PR, confirm GitHub Actions green.
5. Update documentation (§31), create `docs/checklists/m20-release-readiness.md` (WP4-equivalent freeze record, mirroring `m19-release-readiness.md`'s structure exactly, including a Closure Phase Evidence section).
6. Write the final implementation report, including the mandatory characterization-test-change classification (§17/INV-M20-15) and the WP-M20-7 regression-test-update classification (INV-M20-19).

**Implementation stops here.** No merge, no tag, no release, no deployment.

---

## 25. Exact file-by-file change plan

**New files:**
- `includes/class-wc-inventory-overview-restock-controller.php`
- `includes/class-wc-inventory-overview-overview-controller.php`
- `tests/integration/admin-decomposition/test-restock-rendering-characterization.php`
- `tests/integration/admin-decomposition/test-restock-mutation-characterization.php`
- `tests/integration/admin-decomposition/test-restock-cost-adjustment-preview-characterization.php`
- `tests/unit/admin-decomposition/test-restock-controller-architecture.php`
- `tests/integration/admin-decomposition/test-overview-rendering-characterization.php`
- `tests/integration/admin-decomposition/test-overview-bulk-action-characterization.php`
- `tests/integration/admin-decomposition/test-overview-inline-stock-ajax-characterization.php`
- `tests/integration/admin-decomposition/test-overview-csv-export-characterization.php`
- `tests/unit/admin-decomposition/test-overview-controller-architecture.php`
- `docs/milestones/m20-implementation-plan.md` (this plan, materialized at WP-M20-0)
- `docs/checklists/m20-release-readiness.md` (at freeze, WP-M20-9)

**Modified files:**
- `wc-inventory-overview.php` — 2 new `require_once` lines (both controllers), version constant `1.36.0`→`1.37.0`.
- `includes/class-wc-inventory-overview-plugin.php` — loses 16 methods + 1 filter (~657 LOC per §4/§5's subtotals), `init()` gains 2 bootstrap calls and loses 5 hook-registration lines, `on_load_inventory_profit_page()` and `render_inventory_profit_shell()` update their dispatch call sites (3 call sites in the shell, per BR-M20-18), `enqueue_assets()` shrinks to Dashboard-only. Estimated end size ≈ 1,229 − 657 + ~15 (net call-site edits) ≈ **≈590 lines** — consistent with the ≈530 LOC "permanent shell" M19 already estimated plus Dashboard's still-resident asset-enqueue slice.
- `tests/unit/admin-decomposition/test-reporting-controller-architecture.php` — `test_overview_and_restock_remain_on_plugin()` rewritten (INV-M20-19).
- `tests/docker/run-phpunit.sh` — filter regex extended.
- `CLAUDE.md` — M20 row added to the Implementation Status table (at freeze only, per M18/M19's own "not during implementation" discipline).
- `CHANGELOG.md` — new `[1.37.0] - Unreleased` entry.
- `docs/architecture-audit.md` — the "Large god class" entry (lines ~585/597) updated to record Phase 3 (M20) complete: Overview and Restock extracted, decomposition project fully closed (Plugin now pure composition-root/shell).
- `docs/rollback-plan.md` — one-line M20 entry: "safe code-only rollback, no data implications."
- `docs/testing.md` — new test files added to inventory, if maintained (verify at freeze, same as M18/M19's approach).

**Not modified:** `includes/class-wc-inventory-overview-list-table.php`, `includes/class-wc-inventory-overview-restock-service.php`, `includes/class-wc-inventory-overview-cost-adjustment-service.php`, `includes/class-wc-inventory-overview-movements.php`, `includes/class-wc-inventory-overview-costing.php`, `includes/class-wc-inventory-overview-repository.php`, `includes/class-wc-inventory-overview-summary.php`, `includes/class-wc-inventory-overview-inventory-position-service.php`, `includes/class-wc-inventory-overview-purchasing-page.php`, `assets/*` (all JS/CSS files, byte-identical), `readme.txt` (Stable tag unchanged until release), any schema/install file.

---

## 26. CI discovery plan

Per WP-M20-8: add 9 new `Test_WC_IO_*` prefixes to `tests/docker/run-phpunit.sh`'s `--filter` alternation, each with an explanatory comment following the file's own established convention (why it's a distinct prefix, confirmed non-collision with existing entries via `--list-tests`). Verify via `vendor/bin/phpunit --list-tests --filter '<new-regex>'` that every new test class is discovered exactly once, matching the M18/M19/every-prior-milestone discipline.

---

## 27. Targeted checkpoint strategy

Per WP boundary in §24 — narrow, targeted checks only, run **during** each WP:
- `php -l` on every touched file, at each extraction WP.
- The specific characterization file(s) relevant to that WP's cluster only.
- `--list-tests` at WP-M20-8 only.
- The symbol-audit grep (§13) at each extraction WP, before considering it complete.

**Do not** run the full unit/M1-M20-focused/integration suites between WPs. This is a hard instruction from the ask, consistent with M18/M19's own established discipline (§20/§24 of those plans) — full suites run exactly once, at WP-M20-9.

---

## 28. Final validation strategy

One comprehensive sequence at WP-M20-9 (§24 item 9.2): full PHPUnit unit suite, M1–M20 focused suite, full PHPUnit integration suite, PHPCS (advisory), php-parallel-lint, `composer validate --strict`, `docker compose config`, `scripts/release-audit.sh --development`, GitHub Actions (draft PR). Any defect found here is fixed with the narrowest justified change and reverified narrowly, escalating to a broader rerun only if the fix touches shared code — exactly the M18/M19 closure-pass discipline (M18 needed one such fix commit; M19 needed none).

---

## 29. Manual acceptance checklist

`WP_UnitTestCase` cannot verify real browser AJAX round-trips, actual CSV downloads, or real admin-post form submissions end-to-end. Before this milestone is considered done, manually verify on `https://dev.biopentra.eu` (or the local dev stack), using **safe, disposable fixture products/variations** — never real catalog/business data:

**Overview:**
1. Load the Overview tab — grid renders, summary cards match, search/category/stock-status filters work, screen-options per-page control functions.
2. Select a disposable product, use each of the 4 bulk actions in turn (set draft, hide from catalog, mark in stock, mark out of stock) — confirm the success notice count and the actual product state change; confirm a non-owner/insufficient-capability user cannot trigger them.
3. Inline-edit a disposable product's stock quantity via the pencil/save UI — confirm the AJAX round-trip updates the displayed value and status badges without a page reload; confirm an invalid quantity is rejected with the expected message.
4. Trigger CSV export — file downloads with correct filename/date/columns (cost/value columns present only for a `manage_woocommerce` user), content matches the on-screen grid.
5. Confirm Dashboard tab is unaffected (regression spot-check, since `enqueue_assets()` was split).

**Restock:**
6. Load the Restock tab, Quick Restock sub-view — form renders; submit a restock against a disposable variation/simple product — confirm stock and average cost update as expected, a movement row is recorded, and the success notice appears.
7. Submit an invalid restock (missing product, zero quantity) — confirm the expected validation error notice and that no state changed.
8. Switch to the Cost Adjustment sub-view — select a product, confirm the preview AJAX populates current stock/avg/value; submit a cost adjustment — confirm average cost/inventory value update, stock quantity does **not** change, a movement row is recorded.
9. Confirm a non-`manage_woocommerce` user cannot reach either Restock form or its admin-post targets.
10. Confirm Movements/Order Profit/Product Profitability/Settings/Dashboard tabs are all unaffected (full regression spot-check, since the shared shell dispatcher and `Plugin::init()` bootstrap were both touched).

**Cleanup:** delete/reset every disposable fixture product/variation created for steps 6–8 and its associated movement rows after verification; review PHP/WooCommerce logs for unexpected warnings/notices during the manual pass. No destructive testing against meaningful business data is required or permitted.

---

## 30. Rollback strategy

- **Code rollback:** fully reversible — pure code reorganization (16 methods + 1 filter relocated, zero business logic changed). `git revert`/checkout restores prior behavior with zero data implications, exactly as M18/M19.
- **Data rollback:** any disposable fixture data created during manual acceptance (§29) must be cleaned up **separately** from and **before** any code rollback consideration — it is not schema/migration state, just ordinary product/movement rows.
- **Schema rollback:** not applicable — `DB_VERSION` unchanged at 11 throughout.
- **Release-train rollback path:** M20 train tip → `v1.36.0` → M20 train tip (identical shape to the ask's expectation) — no schema rollback needed at any point.
- **Backup requirement:** standard pre-deploy backup; no elevated requirement (matches M18/M19).

---

## 31. Documentation changes

| Document | Action |
|---|---|
| `docs/milestones/m20-implementation-plan.md` | This plan, materialized verbatim at WP-M20-0; immutable thereafter per the milestone-lifecycle Rule 1. |
| `CLAUDE.md` | Add M20 row to Implementation Status table **at freeze**, not during implementation. Update the M18/M19 row's prose (currently states Overview/Restock "remain on Plugin... reserved for later phases") to reflect Phase 3 closure. |
| `CHANGELOG.md` | New `[1.37.0] - Unreleased` entry, following the exact M18/M19 entry format/depth (Changed/Testing/Notes sections), explicitly stating "the Admin Controller Decomposition project is now complete — Plugin is a pure composition-root/shell." |
| `docs/architecture-audit.md` | Update the "Large god class" tech-debt entry (lines ~585/597) to record Phase 3 (M20) complete — Overview and Restock extracted into `Restock_Controller`/`Overview_Controller`; correct the framing to state the decomposition project is now fully closed (no Phase 4 named — nothing remains on `Plugin` beyond the permanent shell). |
| `docs/OWNERSHIP.md` | No change — zero new domain concept (INV-M20-6). |
| `docs/testing.md` | Add the 11 new test files to its inventory, if maintained (verify at freeze). |
| `docs/rollback-plan.md` | One-line M20 entry per §30. |
| `docs/checklists/m20-release-readiness.md` | Created at freeze (WP-M20-9), mirroring `m19-release-readiness.md`'s structure exactly, including a Closure Phase Evidence section with the same characterization-integrity rigor. |
| `tests/docker/run-phpunit.sh` | Updated at WP-M20-8, with the file's own established commenting convention for new prefix entries. |

---

## 32. Explicit out-of-scope list

Unless a WP-time discovery proves otherwise (a stop condition, §33): new inventory features; Coverage/Forecasting; Reservations; inbound shipment redesign; warehouse locations; supplier features/purchasing-workflow changes; new stock/cost semantics; UI redesign; unrelated performance optimization; unrelated PHPCS cleanup; schema cleanup; public API redesign; M21+ work. Additionally, specific to this milestone's own findings:

- `render_tab_placeholder()` dead-code removal (zero callers, flagged by M19, still out of scope).
- Overview's duplicate query-params logic (`get_query_params_from_request()` vs. `List_Table::prepare_items()`'s independent re-derivation) — pre-existing debt, not fixed.
- The apparently-orphaned `supplier-picker.js` enqueue on the Restock form (no matching `.wc-io-supplier-search` DOM target found in current markup, §9) — flagged, not diagnosed or fixed.
- Overview's inline-stock AJAX not writing a movement row (asymmetric with every other stock-mutation path, §6) — flagged, not "fixed" to write one.
- Overview's non-transactional bulk-action loop and Restock's hand-rolled-rollback (non-transactional) pattern — flagged, explicitly **not** wrapped in a new transaction (§20/INV-M20-16).
- Any introduction of a `RESTOCK`/`COST_ADJUSTMENT`/`OVERVIEW` capability constant (Restock/Overview use raw literals today, unlike `Purchasing_Caps`) — out of scope, would be a gratuitous refactor beyond relocation.
- Any base `Controller` abstract class/interface — out of scope, would be scope creep beyond the established per-controller-boilerplate convention (§3).

---

## 33. Stop conditions

- `main`/baseline (§2) no longer matches at WP-M20-0 time.
- Any WP discovers a caller of any of the 16 relocated methods beyond the call sites named in §4/§5/§13/§25 — halt, reassess; the dependency map was wrong.
- Any WP discovers `Restock_Service`/`Cost_Adjustment_Service`/`List_Table`/`Repository`/`Summary`/`Inventory_Position_Service`/`Movements`/`Costing` needs modification to complete the extraction — halt; contradicts §10's confirmed-zero-touch finding.
- Any WP surfaces a need to touch a `wc_io_*` table, column, or `DB_VERSION` — halt immediately; contradicts INV-M20-3.
- Post-extraction query count for any characterized path (either cluster) differs from its WP-M20-1/4 baseline by even one query — halt; do not loosen the assertion.
- A WP-M20-1/4 characterization test cannot be made to pass honestly against unmodified code (INV-M20-14) — halt, do not weaken the assertion, do not proceed to the corresponding extraction WP until genuinely green.
- The `enqueue_assets()` split (§9) reveals additional shared state beyond the `wc-inventory-overview-admin` handle (e.g. an undiscovered shared JS global depending on Dashboard-only state) — halt, reassess.
- The supplier-picker/`Purchasing_Page` AJAX relationship (§9) turns out to require a handler-ownership change, not just a glue relocation — halt; contradicts INV-M20-17.
- Any WP would require changing an approved BR (§15) or INV (§16) to proceed — halt, return to planning.
- Mutation occurs twice for any single logical operation (e.g. a movement row inserted by both the old and new code path during a transitional state) — halt immediately; this is the specific "accidental duplicate mutation ownership" failure mode §6 was written to prevent.
- Any callback/hook is registered twice (e.g. both `Plugin` and a new controller registering `admin_post_wc_io_restock`) — halt; INV-M20-1 violated.
- Any asset disappears or double-loads (e.g. `wcIoInventory` localized twice, or not at all, after the `enqueue_assets()` split) — halt; §9 violated.
- Characterization-test defects of the M18-closure-pass class (leftover renamed-variable closures, wrong-type assertions, unvalidated fixture field names) are found **after** a hard gate (WP-M20-1 or WP-M20-4) has already been declared green — indicates the gate wasn't honestly enforced; halt and re-run that WP's characterization properly rather than patching forward.
- M18/M19's ownership (`Dashboard_Controller`/`Settings_Controller`/`Reporting_Controller`'s methods/hooks) would need any alteration to complete M20 — halt; contradicts INV-M20-13.
- The approved two-controller boundary (§11) proves structurally invalid at implementation time (e.g. a genuine, previously-undiscovered coupling between Overview and Restock surfaces) — halt, return to planning; do not silently merge them into one class as a workaround.

Ordinary coding/test failures within the approved design are **not** stop conditions — fix and continue per §27/§28.

---

## 34. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Zero pre-existing admin-glue characterization to build from (unlike M19) | §17 designs the full test matrix from scratch, using the proven-working domain-level test/fixture conventions as the harness template; hard pre-extraction gates (INV-M20-14) same as M18/M19. |
| Larger surface (16 methods across 2 controllers vs. M19's 9) increases the chance of a missed caller or a missed `self::`/`$this->` symbol | §13's per-method symbol-audit tables are pre-derived from direct source reads (not estimated), reducing this to a mechanical verification step rather than a discovery task; sequential (not interleaved) extraction keeps each diff small and independently reviewable. |
| `enqueue_assets()` split touches Dashboard-adjacent code a second time (Movements' branch was already removed in M19) | Exact same idempotent-duplicate-registration technique already proven safe in production twice (Settings_Controller, Reporting_Controller); guarded by an explicit architecture-guard substring-absence check (§18 item 12) plus a manual Dashboard-regression spot-check in §29 step 5. |
| Cross-cutting supplier-picker/`Purchasing_Page` AJAX dependency could tempt an implementer to "clean up" the apparent ownership mismatch | §9 resolves this explicitly in the plan itself (handler stays on `Purchasing_Page`, only glue moves) — no design decision left for implementation time; INV-M20-17 guards it. |
| `test_overview_and_restock_remain_on_plugin()` (M19's own guard) will fail the moment extraction begins, by design | Addressed as its own WP (WP-M20-7) with an explicit invariant (INV-M20-19) distinguishing "expected regression-test update" from "characterization violation" — not left to be discovered as a surprise CI failure. |
| Overview's bulk-action loop and both services' hand-rolled rollback are easy to mistake for something needing modernization mid-extraction | §20/INV-M20-16 explicitly names and forbids this; a stop condition exists if any WP is tempted to introduce a transaction boundary. |
| Larger milestone (10 WPs, 2 controllers) increases the chance implementation stalls partway, leaving Plugin in a mixed state | Sequential ordering means a stop after WP-M20-3 (Restock done, Overview not started) leaves the repository in a coherent, shippable-as-a-partial-milestone state if ever needed — though the plan's default is full completion through WP-M20-9. |

---

## 35. Independent self-review findings (hostile pass)

- **Did I map every Overview mutation endpoint?** Yes — bulk actions (4 slugs, one handler) + inline-stock AJAX (1 handler) — both confirmed via two independent direct reads (my own source read and the Explore agent's) plus cross-referenced against the M19 plan's own prior audit. No third Overview mutation path found anywhere (`ajax_get_cost_adjustment_preview`, though physically adjacent in the file, is confirmed Restock's).
- **Did I miss any Restock mutation endpoint?** No — `handle_restock_post()` and `handle_cost_adjustment_post()`, both admin-post, both confirmed as the only two `admin_post_wc_io_*` registrations touching Restock. `ajax_get_cost_adjustment_preview()` is confirmed read-only (no mutation, no `save()` call, no service call beyond read-only `Costing::get_average_float()`/`$product->get_meta()`).
- **Did I miss an AJAX callback?** No — repo-wide `wp_ajax_` grep (performed by the exploration pass) found exactly the 6 endpoints in §7; two (`wc_io_search_suppliers`/`wc_io_quick_create_supplier`) are deliberately out-of-scope and explicitly documented as staying on `Purchasing_Page` (§9/INV-M20-17), not silently ignored.
- **Did I miss an admin-post callback?** No — exactly `admin_post_wc_io_restock`/`admin_post_wc_io_cost_adjustment` are Restock's; no other `admin_post_*` registration exists in either cluster.
- **Did I miss an external caller?** The one genuinely external-to-Plugin caller found is `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()` being called by `Goods_Receipt_Service` — this is precisely why `Restock_Service` itself is **not** moved (§6/§10), the single most important finding of this audit.
- **Did I move business logic into a controller?** No — every mutation's actual effect (product field writes, movement inserts, weighted-average math) stays in its current unmodified location; the controllers are confirmed thin HTTP/admin wrappers, verified by reading every method body directly, not inferred.
- **Did I preserve exact security ordering?** Yes, verified per-handler by direct line-position reading (§8) — capability strictly precedes nonce in every case, matching the established M18/M19 guard-test pattern (§18 item 5) which will re-verify this mechanically post-extraction.
- **Did I preserve stock/cost semantics?** Yes — zero change to `Restock_Service`/`Cost_Adjustment_Service`/the inline bulk-mutation code; every formula, validation rule, and error message is relocated verbatim (§13's tables specify exactly which lines move and which symbols need only namespace-qualification, nothing else).
- **Did I preserve transaction ownership?** Yes — §20 documents the genuinely-non-transactional, compensating-rollback reality (a fact this plan discovered and made explicit, since it's easy to wrongly assume a "restock" mutation is transactional) and forbids introducing one.
- **Did I account for JS localization contracts?** Yes — §9's table is built from direct reads of both PHP enqueue methods and both consumer JS files, confirming action names/nonce field names/response-shape consumption match exactly; the one apparent asset anomaly (orphaned `supplier-picker.js` enqueue) is flagged, not silently fixed.
- **Are characterization tests truly pre-extraction?** Yes, per cluster — INV-M20-14 requires WP-M20-1's tests green before WP-M20-2 touches anything, and WP-M20-4's tests green (against the *already-Restock-extracted* tree, since extraction is sequential) before WP-M20-5 touches anything. This sequential nuance (Overview's "pre-extraction" baseline is measured against code that already contains the Restock extraction) is called out explicitly so it isn't misread as a violation.
- **Could an implementation pass the tests while still mutating twice?** No — §6 explicitly maps CURRENT→PROPOSED mutation ownership per operation and confirms no second caller is introduced anywhere; a stop condition (§33) exists specifically for this failure mode, and the architecture guards (§18) grep for exactly this class of defect (duplicate hook registration, `Restock_Service` caller-count growth).
- **Could callbacks be registered twice?** No — every hook named in §7 has exactly one registration location specified pre- and post-move (§11); guarded explicitly (§18 items covering `Plugin` no longer owning the methods, and the new controller's `init()` being the sole new registration point).
- **Could assets disappear or load twice?** The `enqueue_assets()` split is the one place this risk concentrates; §9 resolves it with the exact, already-twice-proven-safe idempotent-duplicate-registration technique, plus a dedicated architecture guard and manual regression step.
- **Are query baselines actually measured?** Not yet — correctly not invented here (§19 explicitly declines to pre-state a number), to be measured honestly at WP-M20-1/4, exactly matching the ask's own instruction and the established M9-onward convention.
- **Is the WP ladder detailed enough for implementation without redesign?** Yes — every extraction WP names its exact new/modified files, exact methods moved (with per-method symbol-qualification tables already computed, §13), exact test files, exact checkpoint, exact commit message pattern. No open design question remains — the controller-count decision, the naming decision, the `enqueue_assets()` split, the supplier-picker cross-cutting decision, and the renaming policy are all resolved in §9/§11 rather than deferred.
- **Is Level A genuinely justified?** Yes, argued point-by-point against the same Level-B trigger list M18/M19 used (§23), with an explicit acknowledgment that surface area is larger while risk-per-line is not — compensated for procedurally (more WPs, sequential gating) rather than by inflating the risk classification without cause.
- **Is the "both tabs in one milestone" call actually justified, or is this secretly forcing two milestones' worth of work into one?** Genuinely justified — M18 already precedents "two independent controllers, one milestone" (Dashboard+Settings); Overview and Restock are individually *smaller* than M18's Settings alone (~361/~368 LOC each vs. Settings' ~764 LOC) despite carrying comparable mutation risk; the combined LOC (≈729) is still smaller than M18's Dashboard+Settings combined (≈1,139). Sequential, independently-gated sub-ladders (§24) mean this is procedurally two M18-sized sub-milestones back to back, not one undifferentiated large milestone.

**One correction made during this review pass, before finalizing:** an earlier draft of this plan considered renaming `on_load_restock_screen()`/`on_load_screen()` to drop the `_screen` suffix (mirroring M19's optional Reporting_Controller renames). This was reversed in favor of the minimal-renaming policy (§11) specifically because M20's larger, first-ever-characterized mutation surface makes minimizing invented diffs more valuable here than the cosmetic consistency gain — recorded as a deliberate, reasoned deviation from the M19 precedent, not an oversight.

---

## 36. Repository-reality corrections to the ask's assumptions

None required — every stated assumption in the ask (v1.36.0 baseline, merge SHA `b9c978c`, canonical head `8539ec9d`, plugin version 1.36.0, `DB_VERSION` 11, M18/M19 scope/status) was verified exactly correct against the repository (§2). The ask's own suggested controller names (`WC_Inventory_Overview_Overview_Controller`/`WC_Inventory_Overview_Restock_Controller`) and suggested WP-ladder skeleton (WP-M20-0…9) both proved directly usable, adjusted only to reflect the two-controller sequential-extraction structure this audit determined is correct (the ask's WP-M20-3/4 "Overview extraction"/"Restock extraction" pairing is here expanded into a full characterize→extract→guard sub-ladder per cluster, WP-M20-1…6, rather than one WP per extraction).

---

## 37. Exact recommended implementation base SHA

**`8539ec9d201d3f9baab8508f02c2b8fb187fd623`** (current `main`, confirmed identical to `origin/main`, confirmed clean working tree, §2).

---

## 38. Exact next operation

Upon user approval of this plan: **WP-M20-0** — create branch `feature/m20-admin-controller-decomposition-phase3` from `8539ec9d201d3f9baab8508f02c2b8fb187fd623`, materialize this plan verbatim into `docs/milestones/m20-implementation-plan.md`, commit it alone, then proceed continuously through WP-M20-1…9 per §24/§27, stopping only at a named stop condition (§33) or at WP-M20-9's completion.

---

# M20 DEFINITIVE PLAN APPROVED FOR IMPLEMENTATION
