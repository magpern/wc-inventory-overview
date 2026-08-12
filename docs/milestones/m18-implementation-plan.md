# M18 Definitive Implementation Plan — Admin Controller Decomposition, Phase 1

## Context

Every merchant-facing backlog candidate that a next milestone could plausibly pick up (Coverage/Forecast, Reservations, Inbound Shipment, Warehouse Locations, further PO/supplier operational work) turns out on inspection to be either already fully shipped (M1–M17), explicitly blocked by a missing data foundation, or explicitly premature for a single-warehouse shop with no concrete consumer. What *is* ready, and has been named as urgent tech debt in `docs/architecture-audit.md` at every milestone from M8 through M16 without ever being scheduled, is decomposing the 2,706-line "god class" `includes/class-wc-inventory-overview-plugin.php`. It keeps growing (M16 added to it again) and today has **zero dedicated regression test coverage** and **no in-repo precedent** for tab-level decomposition — which makes attempting the whole thing in one milestone genuinely risky. The user confirmed (2026-08-12) that M18 should take the bounded first slice: extract the two fully self-contained tabs (Dashboard, Settings) into their own controller classes, building characterization tests first as the regression safety net, and leave the remaining five tabs (which share CSV-export/bulk-action machinery) for a later phase.

This is a pure internal refactor: zero schema change, zero new capability, zero new public API, zero behavior change. Its "business rules" are therefore framed as **behavior-equivalence contracts** — exact strings, exact ordering, exact query shapes that must survive the move unchanged — rather than new product policy.

---

## 1. Verified baseline (Part A)

All nine baseline claims confirmed with no discrepancies (verified 2026-08-12):

| Check | Result |
|---|---|
| `git status` | Clean, `main`, up to date with `origin/main` |
| HEAD | `bfdde20f2f9a2adc4e09187ed7e00942882e6749` — "docs(release): record v1.34.0 publication" |
| `main` vs `origin/main` | Identical, no divergence |
| Plugin version | `1.34.0` (`wc-inventory-overview.php`) |
| `DB_VERSION` | `'11'` (`includes/class-wc-inventory-overview-install.php:15`, sole definition) |
| Latest tag | `v1.34.0` (annotated, points at merge commit `5a33eeb`, one docs commit behind HEAD) |
| M17 status | CLOSED/RELEASED — Level B freeze complete, `docs/checklists/m17-release-readiness.md` |
| M18 artifacts | None anywhere — no branch, no plan file, only forward-looking "do not start M18" guardrail text |
| Working tree | Zero untracked files |

No repository-reality contradiction found. Proceeding on this baseline.

---

## 2. Candidate analysis (Part B)

| Candidate | Verdict | Why |
|---|---|---|
| Coverage / Forecast | Blocked | `docs/architecture-audit.md:579` — "still blocked, no sales/consumption-velocity data anywhere in the schema"; called "a materially larger initiative" (`:419`) |
| Reservations | Blocked | `docs/architecture-audit.md:579` — "still blocked (D16, no concrete consumer)" |
| Inbound Shipment entity | Premature | D10 allows it additively later, but no concrete business driver surfaced; would be a new entity integrated with PO/receiving — multi-milestone |
| Warehouse Locations | Premature | Ownership pre-assigned in `docs/OWNERSHIP.md`, but shop is single-warehouse today — no concrete consumer; violates D16 ("no speculative schema... until a concrete consumer exists") |
| Further PO/supplier operational work | Mostly already shipped | Partial receipts, cancel/close-short, printable PO, order history, spend summary, merge — all delivered M2–M17; only unscoped item is ASN/barcode/warehouse-scanning, explicitly never-scoped |
| Inventory Position drilldowns | Mostly complete | M3 + M16 already added Supplier/Status columns; further extension (Reserved/Available/Coverage) is gated on Coverage/Forecast, which is blocked |
| **Admin god-class decomposition** | **Ready** | Repeatedly deferred (M8, M12, M13, M16 all list it under "explicitly excluded"); zero dependency on any blocked data foundation; bounded when phased |

Every merchant-value candidate is blocked, premature, or shipped. The god-class decomposition is the only candidate with no external dependency — but at 2,706 lines with zero test coverage and no in-repo tab-level-decomposition precedent, attempting it whole is itself a disguised multi-milestone initiative. Confirmed with the user: M18 takes **Phase 1** only.

---

## 3. Recommended M18 scope

**Name:** M18 — Admin Controller Decomposition, Phase 1 (Dashboard & Settings Extraction)

**Operator problem:** None directly — this is an internal-quality milestone. The indirect operator benefit is a smaller, more reviewable, better-tested admin controller, reducing the risk of regressions in every future Settings/Dashboard change (the Settings tab has been touched by M16 and is a likely target of future changes).

**Objective:** Extract the two tabs of `WC_Inventory_Overview_Plugin` that have zero coupling to the other five tabs — Dashboard and Settings — into two new, independently-owned controller classes, following the existing "separate class, own `init()`, self-registered hooks, instantiated from `Plugin::init()`" pattern already used by `WC_Inventory_Overview_Purchasing_Page`. Build characterization tests *before* moving any code, so the move is provably behavior-preserving despite starting from zero coverage.

**In scope:**
- New `WC_Inventory_Overview_Settings_Controller` class owning: settings save, exchange-rate add/delete, danger-zone preview/apply, their rendering, their asset enqueue, their AJAX exchange-rate lookup — 12 methods, ~752 lines.
- New `WC_Inventory_Overview_Dashboard_Controller` class owning: dashboard date-filter parsing, operational panels (low-stock table, quick actions), KPI/chart rendering — 3 methods, ~375 lines.
- Characterization tests for both tabs' current behavior, written and proven green *before* extraction.
- Architecture-guard tests proving the new classes are sole owners and the god class no longer contains the moved methods.
- Two `Plugin` methods (`get_requested_tab()`, `admin_url_tab()`) promoted from `protected` to `public` so the new controllers can call them (no signature/body change).

**Explicitly out of scope:**
- The other five tabs (Overview, Restock, Movements, Order Profit, Product Profitability) and their shared CSV-export/bulk-action/AJAX machinery — deferred to a future Phase 2 milestone (candidate M19), a materially larger unit of work.
- `Plugin::init()`'s bootstrapping of unrelated classes (`Order_Item_Snapshots`, `Order_Shipping_Admin`, `Purchasing_Page`, `Expected_Delivery_Service`/`Renderer`) — untouched, ordering preserved.
- Any behavior change, new field, new validation rule, new capability, or new hook.
- Any change to `WC_Inventory_Overview_Settings`, `WC_Inventory_Overview_Exchange_Rates`, or `WC_Inventory_Overview_Data_Reset` (the domain classes the moved handlers call into) — they are reused unchanged.
- Any schema change.

**Dependencies:** None outside this repository's current `main`.

**Schema changes required:** None. See Data Contracts (§12).

**Expected plugin version:** `1.35.0` (next sequential; actual release version decided at WP6 train time).

**Expected DB_VERSION:** `11` (unchanged).

**Lifecycle level: Level A.** No schema change, no new capability, no new public API, no new ownership boundary, no destructive-mutation *introduction* (the Danger-Zone-Apply deletion already exists unchanged; only its calling code moves), no difficult concurrency behavior. Matches the M10–M16 precedent. The identified risk (zero existing coverage) is mitigated by mandatory characterization-tests-first sequencing rather than by escalating to Level B — see Risks (§25).

**Release strategy:** Joins the next feature train (no Release Trigger in `docs/process/milestone-lifecycle.md` applies — no schema/migration/public-API/ownership-boundary/storefront/security/breaking change). Stops at WP5 (Continue Development); no standalone tag.

---

## 4. Repository architecture findings (Part D)

Source: `includes/class-wc-inventory-overview-plugin.php` (2,706 lines, class `WC_Inventory_Overview_Plugin`, singleton, `protected` methods throughout).

**Settings-related methods and exact current line ranges** (main @ bfdde20):

| Method | Lines | Kind |
|---|---|---|
| `handle_save_settings_post()` | 384–418 | `admin_post_wc_io_save_settings` handler |
| `handle_add_exchange_rate_post()` | 423–462 | `admin_post_wc_io_add_exchange_rate` handler |
| `handle_delete_exchange_rate_post()` | 467–514 | `admin_post_wc_io_delete_exchange_rate` handler |
| `handle_danger_reset_preview_post()` | 519–556 | `admin_post_wc_io_danger_reset_preview` handler |
| `handle_danger_reset_apply_post()` | 561–649 | `admin_post_wc_io_danger_reset_apply` handler |
| `render_settings_panel()` | 654–883 | rendering (public entry) |
| `render_exchange_rate_history_section()` | 888–961 | rendering |
| `render_settings_danger_zone()` | 966–988 | rendering |
| `render_danger_zone_preview_form()` | 993–1044 | rendering |
| `render_danger_zone_apply_form()` | 1050–1089 | rendering |
| `enqueue_settings_shipping_assets()` | 1629–1668 | `admin_enqueue_scripts` handler |
| `ajax_get_exchange_rate()` | 1673–1715 | `wp_ajax_wc_io_get_exchange_rate` handler |

Total ≈ 752 lines / 5 `admin_post_*` hooks / 1 `wp_ajax_*` hook / 1 `admin_enqueue_scripts` hook.

**Dashboard-related methods:**

| Method | Lines | Kind |
|---|---|---|
| `get_dashboard_date_filters_from_request()` | 1096–1118 | helper |
| `render_dashboard_operational_panels()` | 1125–1234 | rendering |
| `render_dashboard_panel()` | 1239–1480 | rendering (public entry) |

Total ≈ 375 lines. No dedicated hooks — pure GET-based rendering, dispatched only from `render_inventory_profit_shell()`'s tab switch.

**Combined: ~1,127 of 2,706 lines (~42%) move out in this phase.**

**Reused unchanged (called by, not moved into, the new controllers):**
- `WC_Inventory_Overview_Settings` (`includes/class-wc-inventory-overview-settings.php`) — options getters/`save_from_post()`.
- `WC_Inventory_Overview_Exchange_Rates` (`includes/class-wc-inventory-overview-exchange-rates.php`) — `insert_rate()`, `delete_rate()`, `list_rates()`, `get_exchange_rate_to_eur()`.
- `WC_Inventory_Overview_Data_Reset` (`includes/class-wc-inventory-overview-data-reset.php`) — `parse_request_payload()`, `preview_counts()`, `store_preview()`, `get_preview()`, `apply_reset()`, `delete_preview()`, `store_result_notice()`, `consume_result_notice()`.
- `WC_Inventory_Overview_Order_Profit_Query::get_dashboard_order_rollup()`, `WC_Inventory_Overview_Dashboard_Inventory_Metrics::compute_for_dashboard()`, `WC_Inventory_Overview_Summary::build()` / `::get_low_stock_lines_for_chart()`, `WC_Inventory_Overview_Dashboard_Charts_Data::build_admin_script_payload()`.

**Extended (visibility only, no behavior change):** `Plugin::get_requested_tab()` and `Plugin::admin_url_tab()`, `protected` → `public`.

**Newly created:** `WC_Inventory_Overview_Settings_Controller`, `WC_Inventory_Overview_Dashboard_Controller`.

**Explicitly forbidden from owning this behavior:** any other existing class. Neither new controller may contain `global $wpdb` or `$wpdb->` (both moved blocks are confirmed free of raw SQL in the current source — they only call into the domain classes above).

**Blast radius (callers of `Plugin`):**
- `wc-inventory-overview.php:175` — bootstrap `WC_Inventory_Overview_Plugin::instance()->init();`.
- The four list-table classes (`class-wc-inventory-overview-{list-table,movements-list-table,order-profit-list-table,product-profitability-list-table}.php`) reference `Plugin::PAGE_SLUG`/tab constants for links — unaffected, constants stay on `Plugin`.
- `tests/integration/batch-migration/test-batch-migration-retirement-regression.php` — instantiates `Plugin::instance()->init()` and asserts on `TAB_RESTOCK`/`get_restock_subview()` — unaffected (Restock is out of scope), but must be rerun as a targeted regression check (§17).
- `tests/unit/conformance/test-no-sibling-plugin-coupling.php` — repo-wide glob-based guard, auto-picks-up new files, rerun as a targeted check.

**File-loading mechanism:** No autoloader. `wc-inventory-overview.php` uses an ordered flat list of `require_once` calls inside a `plugins_loaded` (priority 11) closure; `class-wc-inventory-overview-plugin.php` is required last, then `WC_Inventory_Overview_Plugin::instance()->init();` is called. A new controller file needs one `require_once` line (placed before `class-wc-inventory-overview-plugin.php`'s own require) plus one bootstrap call inside `Plugin::init()`.

**Existing precedent to follow:** `WC_Inventory_Overview_Purchasing_Page` — separate class, own `init()`, self-registers its own hooks, instantiated via `WC_Inventory_Overview_Purchasing_Page::instance()->init();` from inside `Plugin::init()` (line 53, among the other bootstrap calls, *before* the `add_action` calls begin at line 57). The new controllers' bootstrap calls are appended to that same block, in the same style, without disturbing the existing bootstrap order (INV-M18-12).

**Existing test coverage of the god class today:** none dedicated — only incidental coverage via the two regression/conformance files above. This is the core risk this plan's WP sequencing (§19) is built to mitigate.

---

## 5. Ownership map

| Concept | Owner after M18 |
|---|---|
| Tab slugs, tab labels, tab capability map, current-tab resolution, tab URL building, top-level page registration, page shell/dispatch, legacy-URL redirects | `WC_Inventory_Overview_Plugin` (unchanged — sole tab-routing owner, INV-M18-1) |
| Settings save, exchange-rate CRUD orchestration, danger-zone preview/apply orchestration, their rendering, their enqueue, their AJAX lookup | `WC_Inventory_Overview_Settings_Controller` (new) |
| Dashboard date-filter parsing, dashboard rendering (KPIs, charts, low-stock table, quick actions) | `WC_Inventory_Overview_Dashboard_Controller` (new) |
| Settings option storage/validation | `WC_Inventory_Overview_Settings` (unchanged) |
| Exchange-rate row storage | `WC_Inventory_Overview_Exchange_Rates` (unchanged) |
| Danger-zone deletion logic | `WC_Inventory_Overview_Data_Reset` (unchanged) |
| All five remaining tabs (Overview, Restock, Movements, Order Profit, Product Profitability), CSV export, bulk actions, inline-stock/cost-adj AJAX | `WC_Inventory_Overview_Plugin` (unchanged, deferred to a future phase) |

No `docs/OWNERSHIP.md` registry change — this is an internal admin-surface reorganization, not a new business concept (INV-M18-6).

---

## 6. Business rules (behavior-equivalence contracts)

Each BR states behavior that must be **byte-identical** before and after extraction, verified by characterization tests written against the *current* code (§19, WP-M18-1/2) and rerun unchanged after extraction (WP-M18-3/4).

| # | Rule |
|---|---|
| BR-M18-1 | Settings save: valid POST persists via `Settings::save_from_post()`; redirects to `?tab=settings&wc_io_settings=saved`. On `WP_Error`, stores the message in transient `wc_io_settings_save_err_{user_id}` (120s TTL), redirects with `wc_io_settings=err`; the error notice is shown once, then the transient is deleted. |
| BR-M18-2 | Every Settings-tab `admin_post_*`/AJAX handler requires `manage_woocommerce`; failure is `wp_die()` (POST handlers) or `wp_send_json_error(..., 403)` (AJAX). |
| BR-M18-3 | Every Settings-tab `admin_post_*` handler's nonce action name and field name are reused verbatim (e.g. `wc_io_save_settings`/`_wc_io_settings_nonce`); invalid/missing nonce → `wp_die()` (WP core `check_admin_referer()` behavior, unchanged). |
| BR-M18-4 | Exchange-rate add: valid POST inserts via `Exchange_Rates::insert_rate(from, to, rate, date, note)`; `to` defaults to `Exchange_Rates::TO_CURRENCY` when the POST field is absent; success → redirect `wc_io_fx=added`; `WP_Error` → redirect `wc_io_fx_err=<urlencoded message>`, no row inserted. |
| BR-M18-5 | Exchange-rate delete: **id validation happens before the nonce check** — `id <= 0` → immediate redirect `wc_io_fx_err='Invalid exchange rate id.'` without ever calling `check_admin_referer()`. This exact ordering must be preserved. |
| BR-M18-6 | Exchange-rate delete with valid id: nonce action is id-scoped (`wc_io_delete_exchange_rate_{id}`); success → redirect `wc_io_fx=deleted`; `WP_Error` from `delete_rate()` → `wc_io_fx_err=<message>`. |
| BR-M18-7 | Danger-zone preview: capability → nonce (`wc_io_danger_reset_preview`) → `Data_Reset::parse_request_payload()`; invalid payload → `wc_io_reset_err=<message>`, no preview stored; valid → `preview_counts()` computed, token = `wp_generate_password(32, false, false)`, stored via `store_preview()`, redirect `wc_io_rst=<token>`. |
| BR-M18-8 | Danger-zone apply: validation order is fixed and must not be reordered — capability → nonce (`wc_io_danger_reset_apply`) → confirm-checkbox present → typed-confirmation exactly `'RESET'` (case-sensitive, `trim()`ped) → token resolves via `get_preview()` (not expired) → `apply_reset()`. Each failure produces its own specific error message (see source lines 569–627) and must not fall through to a later check. |
| BR-M18-9 | Danger-zone apply success: `delete_preview(token)`, `store_result_notice(user_id, {deleted, counts})`, redirect `wc_io_reset_applied=1`. |
| BR-M18-10 | Settings-panel notices are independently triggerable by their own query args: `wc_io_settings=saved\|err`, `wc_io_fx=added\|deleted`, `wc_io_fx_err`, `wc_io_reset_err`, `wc_io_reset_applied=1` (consumes the stored result notice once). |
| BR-M18-11 | Exchange-rate history list: `Exchange_Rates::list_rates(500)` cap; empty state text `'No rates yet. Add one above.'`; each row's "Created by" resolves via `get_userdata()`, falling back to `'—'` when `user_id <= 0` or the user no longer exists. |
| BR-M18-12 | Danger-zone form state: a present and *valid* (non-expired) preview token in `$_GET['wc_io_rst']` renders the Apply form (with only the count rows for truthy `$t[...]` targets); otherwise the Preview form renders; a present but *invalid/expired* token additionally shows an "expired or invalid" warning notice above the fresh Preview form. |
| BR-M18-13 | Settings assets (`wc-io-settings-shipping-fx` script + `admin.css`) enqueue only when `hook_suffix === 'woocommerce_page_' . PAGE_SLUG` **and** the requested tab is `TAB_SETTINGS` **and** `current_user_can('manage_woocommerce')` — never on other tabs. |
| BR-M18-14 | Exchange-rate AJAX lookup: capability + `wc_io_get_exchange_rate` nonce; `EUR` requested → `{rate: 1.0, source: 'eur', rate_date: null}` without a DB call; non-EUR → `Exchange_Rates::get_exchange_rate_to_eur(cur, date)`; `WP_Error` → `wp_send_json_error({message, code})`; success → `{rate, source, rate_date}` verbatim. |
| BR-M18-15 | Dashboard date filters: valid `Y-m-d` values accepted verbatim for `wc_io_dash_date_from`/`wc_io_dash_date_to`; malformed format silently reset to `''`. Defaulting from `Settings::get_default_report_date_bounds_local()` applies **only** when *neither* query param was present in the request at all (not merely resolved empty) — an explicitly-submitted empty date is honored as "no bound" and must not be defaulted. This presence-vs-value asymmetry must be preserved exactly. |
| BR-M18-16 | Dashboard KPIs: `order_count === 0` attaches a "no sales data" note to the Revenue/Gross-Profit/Gross-Margin/Orders tiles only, never to Inventory-Value/Potential-Revenue/Potential-Profit/Missing-Cost/Low-Stock tiles; `margin_percent === null` renders an em-dash, never `'0%'`. |
| BR-M18-17 | Dashboard low-stock panel: up to 15 rows via `Summary::get_low_stock_lines_for_chart($summary_base, 15)`; empty → dismissible "No low-stock lines" empty state; `qty <= 0` → "Out" (critical) badge, `qty > 0` → "Low" (warn) badge; Edit link renders only when `current_user_can('edit_product', $pid)` **and** an edit link resolves. |
| BR-M18-18 | Dashboard quick-actions panel: hidden behind a locked empty-state message when `!current_user_can('manage_woocommerce')`; otherwise renders exactly 4 fixed cards (Restock/Add Inventory, Adjust Product Cost, View Order Profit, View Product Profitability) with fixed URLs/icons in fixed order. |
| BR-M18-19 | Dashboard chart payload: the inline `wcIoDashboardCharts` script is emitted only when `wp_script_is('wc-io-dashboard-charts', 'enqueued')` is true; built via `Dashboard_Charts_Data::build_admin_script_payload($rollup, $profit_filters, $pp_filters, $summary_base)` unchanged. |
| BR-M18-20 | `render_inventory_profit_shell()`'s tab dispatch: the `TAB_DASHBOARD`/`TAB_SETTINGS` cases call the new controllers' `render()` entry points; the surrounding capability gate and "you do not have permission" notice for `TAB_SETTINGS` (`manage_woocommerce`) is unchanged, as is the hub-level `edit_products` gate that already covers `TAB_DASHBOARD`. |
| BR-M18-21 | No new capability string, nonce action name, transient/option key, GET/POST parameter name, or hook name is introduced anywhere — every string literal in the moved code is reused byte-for-byte. |

---

## 7. Architectural invariants

| # | Invariant | Guard |
|---|---|---|
| INV-M18-1 | `WC_Inventory_Overview_Plugin` remains the **sole tab-routing owner** — `PAGE_SLUG`, `TAB_*` constants, `get_tabs_definition()`, `get_requested_tab()`, `admin_url_tab()` stay on `Plugin`; neither new controller redefines or shadows tab-routing logic, only calls `Plugin`'s (now-public) methods. | Architecture-guard test (§16) |
| INV-M18-2 | Zero behavior change — every BR above is byte-identical pre/post extraction. | Characterization tests rerun unchanged (WP-M18-1/2 → WP-M18-3/4) |
| INV-M18-3 | Zero schema change — `DB_VERSION` remains `'11'`; no `dbDelta`/table/column change. | Targeted rerun of `tests/integration/install/` schema-v11 test (§17) |
| INV-M18-4 | Zero new capability — only pre-existing `edit_products`/`manage_woocommerce` are referenced. | Architecture-guard grep |
| INV-M18-5 | Zero new public API — no `do_action`/`apply_filters` introduced in either new file. | Architecture-guard test asserting `substr_count($source, 'do_action(') === 0` and same for `apply_filters(`, mirroring M17's technique |
| INV-M18-6 | Zero new domain concept / zero ownership-boundary change — `docs/OWNERSHIP.md` registry untouched. | Manual check at WP-M18-8 |
| INV-M18-7 | Query-count invariance — every DB-touching call inside moved methods is unchanged (same target class/method/arguments). | Query-count characterization, captured baseline must match exactly post-extraction (§13) |
| INV-M18-8 | No presentation SQL — neither new controller file contains `global $wpdb` or `$wpdb->`. | Architecture-guard grep |
| INV-M18-9 | Capability-check placement is preserved exactly relative to nonce checks (BR-M18-8's fixed ordering). | Architecture-guard substring-extraction check (M17-style) |
| INV-M18-10 | Nonce/token action-string stability — every `wp_nonce_field()`/`check_admin_referer()`/`check_ajax_referer()` literal is reused verbatim. | Architecture-guard grep diff |
| INV-M18-11 | Mechanical-move discipline — the WP-M18-3/4 diff is a pure move: wrap in a new class, and substitute exactly two call-site patterns (`$this->admin_url_tab(...)` → `WC_Inventory_Overview_Plugin::instance()->admin_url_tab(...)`, `$this->get_requested_tab()` → `WC_Inventory_Overview_Plugin::instance()->get_requested_tab()`). No other line changes. | Manual diff review at WP checkpoint + architecture-guard substring comparison |
| INV-M18-12 | `Plugin::init()`'s existing bootstrap order (`Order_Item_Snapshots`, `Order_Shipping_Admin`, `Purchasing_Page`, `Expected_Delivery_Service`, `Expected_Delivery_Renderer`) is unchanged; new controller bootstrap calls are appended after it, never interleaved. | Manual diff review |

---

## 8. Data contracts

### `WC_Inventory_Overview_Settings_Controller`
**File:** `includes/class-wc-inventory-overview-settings-controller.php` (new)

| Method | Visibility | Notes |
|---|---|---|
| `instance()` | `public static` | Singleton accessor, mirrors `Purchasing_Page` |
| `init()` | `public` | Registers the 5 `admin_post_*`, 1 `wp_ajax_*`, 1 `admin_enqueue_scripts` hooks at default priority (matches current — none of the source `add_action` calls specify a priority) |
| `render()` | `public` | Body = current `render_settings_panel()` verbatim (renamed as the public entry point); calls `$this->render_exchange_rate_history_section()`, `$this->render_settings_danger_zone()` |
| `render_exchange_rate_history_section()` | `protected` | Verbatim |
| `render_settings_danger_zone()` | `protected` | Verbatim |
| `render_danger_zone_preview_form( $form_action )` | `protected` | Verbatim |
| `render_danger_zone_apply_form( $token, array $preview )` | `protected` | Verbatim except the cancel link now reads `WC_Inventory_Overview_Plugin::instance()->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_SETTINGS )` |
| `handle_save_settings_post()` | `public` | Verbatim, `Plugin::PAGE_SLUG`/`::TAB_SETTINGS` qualification |
| `handle_add_exchange_rate_post()` | `public` | Verbatim |
| `handle_delete_exchange_rate_post()` | `public` | Verbatim |
| `handle_danger_reset_preview_post()` | `public` | Verbatim |
| `handle_danger_reset_apply_post()` | `public` | Verbatim |
| `enqueue_settings_shipping_assets( $hook_suffix )` | `public` | Verbatim except tab/slug checks reference `WC_Inventory_Overview_Plugin::TAB_SETTINGS`/`::PAGE_SLUG` and `WC_Inventory_Overview_Plugin::instance()->get_requested_tab()` |
| `ajax_get_exchange_rate()` | `public` | Verbatim (no `Plugin` references in current source) |

Mutation behavior: unchanged — this class *orchestrates* the same option/row/transient writes that `Plugin` used to (via `Settings::save_from_post()`, `Exchange_Rates::insert_rate()`/`delete_rate()`, `Data_Reset::apply_reset()`); it does not itself own any SQL. Error behavior: `WP_Error` returns from the domain classes are surfaced exactly as today (BR-M18-4/6/7/8). Empty behavior: BR-M18-11/12.

### `WC_Inventory_Overview_Dashboard_Controller`
**File:** `includes/class-wc-inventory-overview-dashboard-controller.php` (new)

| Method | Visibility | Notes |
|---|---|---|
| `instance()` | `public static` | Singleton accessor |
| `render()` | `public` | Body = current `render_dashboard_panel()` verbatim (renamed); `admin_url_tab()` calls qualified via `WC_Inventory_Overview_Plugin::instance()` |
| `get_dashboard_date_filters_from_request()` | `protected` | Verbatim, returns `array{date_from:string,date_to:string}` |
| `render_dashboard_operational_panels( array $summary_base )` | `protected` | Verbatim except `admin_url_tab()` qualification |

No `init()` — Dashboard owns no dedicated hooks; `render_inventory_profit_shell()` calls `render()` directly.

### `WC_Inventory_Overview_Plugin` changes

- `get_requested_tab()`: visibility `protected` → `public`. No signature/body change.
- `admin_url_tab( $tab, array $extra = array() )`: visibility `protected` → `public`. No signature/body change.
- `init()`: gains `WC_Inventory_Overview_Settings_Controller::instance()->init();`, appended to the existing bootstrap block (after the `Expected_Delivery_Renderer::register();` line, before the `add_action` calls begin) — same style as the existing `Purchasing_Page::instance()->init();` call.
- `init()`: loses the 7 Settings-related `add_action()` lines listed in §4.
- Loses the 12 Settings methods + 3 Dashboard methods (~1,127 lines).
- `render_inventory_profit_shell()`: `TAB_DASHBOARD` case calls `WC_Inventory_Overview_Dashboard_Controller::instance()->render();`; `TAB_SETTINGS` case calls `WC_Inventory_Overview_Settings_Controller::instance()->render();` (capability-gate wrapping unchanged).

### Schema

**No schema change.** `DB_VERSION` stays `'11'`. No `dbDelta()` call, table, column, index, default, or nullability changes anywhere in this milestone. No migration path, no fresh-install-vs-upgrade distinction to design — this milestone touches zero persisted structure, only PHP class organization. Explicitly verified: `WC_Inventory_Overview_Settings`/`Exchange_Rates`/`Data_Reset` (the classes that *do* touch the DB) are not modified.

---

## 9. UI / admin contract

Zero UI diff is the acceptance criterion. No new page, tab, section, field, label, empty state, action, capability requirement, confirmation, notice, PRG behavior, AJAX behavior, pagination, sorting, or accessibility change. Every visual/DOM output is byte-identical pre/post — proven by the characterization tests (§19) rather than re-specified here. Server-side enforcement (capability + nonce checks) remains entirely server-side and unchanged; JavaScript (the danger-zone custom-date-range toggle, the "select all" checkbox behavior) is unchanged and remains decorative only, per BR-M18-2/3/13.

---

## 10. Security contract

No change to the security posture: every `current_user_can()` check, every `check_admin_referer()`/`check_ajax_referer()` call, every capability string, and every nonce action name is reused verbatim (BR-M18-2/3/9/14, INV-M18-4/9/10). No new attack surface is introduced — the two new classes are `require_once`'d the same way as every other `includes/*.php` file, with no new entry point beyond the ones already registered today (just relocated).

---

## 11. Concurrency contract

No concurrency claim is made and none is tested. This milestone does not touch the Danger-Zone-Apply locking/idempotency behavior (if any exists in `WC_Inventory_Overview_Data_Reset`, it is unchanged) — only the calling code that invokes `apply_reset()` moves. If any WP discovers the singleton-instantiation pattern interacts with concurrency assumptions inside `Data_Reset` in a way not already true today, that is a stop condition (§18), not something this plan resolves speculatively.

---

## 12. Performance contract

No query logic changes — every call inside the moved methods targets the same class/method/arguments as today. Therefore:

- **Exact bound, not estimated:** the query count captured as a baseline during WP-M18-1/2 (characterization, against *current* code) for Settings-panel render, each Settings `admin_post_*`/AJAX handler, and Dashboard render at empty/small/200-product-catalog scale must be **exactly equal** post-extraction (WP-M18-3/4) — not merely "bounded," since nothing about the underlying calls changes. A mismatch of even one query is a stop condition (§18), not a tolerance to widen.
- No specific number is pre-invented here, per the instruction not to guess counts the current architecture doesn't already fix — WP-M18-1/2 measures and records the actual baseline (using the `$before = $wpdb->num_queries; ...; $wpdb->num_queries - $before` delta pattern already used across M9/M11/M12/M14/M15/M17).
- No N+1 is introduced because no loop structure changes — only method-container relocation.

---

## 13. Test matrix

| # | Area | Coverage |
|---|---|---|
| 1 | Business rules | BR-M18-1..21, via characterization tests below |
| 2 | Architecture guards | `test-settings-controller-architecture.php`, `test-dashboard-controller-architecture.php` (§16) |
| 3 | Authorization/security | Capability-denial branches covered inline in the characterization tests (BR-M18-2) |
| 4 | Repository/service behavior | Not applicable — no repository/service class is created or changed; `Settings`/`Exchange_Rates`/`Data_Reset` are reused unmodified |
| 5 | Integration | `tests/integration/admin-decomposition/*` characterization tests, rerun unchanged post-extraction |
| 6 | Schema/migration | Not applicable (no schema change) — targeted rerun of the existing v11 schema-upgrade test as a regression check only |
| 7 | Concurrency | Not applicable — no claim made, none tested (§11) |
| 8 | Failure injection | `WP_Error` branches for `save_from_post`/`insert_rate`/`delete_rate`/`parse_request_payload`/`apply_reset` covered as characterization scenarios |
| 9 | Performance/query counts | Baseline-equality assertions per §12 |
| 10 | UI rendering | HTML-output `assertStringContainsString`/structural assertions per BR, mapped 1:1; full visual/DOM diff is a **manual acceptance** item (below) |
| 11 | Regression of previous milestones | Targeted rerun: `test-batch-migration-retirement-regression.php`, `test-no-sibling-plugin-coupling.php`, `tests/unit/settings/test-settings-po-delay-grace-days.php`, `tests/integration/install/` v11 schema test |
| 12 | CI test discovery | New `tests/unit/admin-decomposition/` and `tests/integration/admin-decomposition/` are auto-discovered by `phpunit.xml.dist`'s directory-prefix scan (no XML change needed); the milestone-gating CI step's explicit `--filter` regex in `run-phpunit.sh` **must** be updated to include the new test class-name prefixes (WP-M18-7) |

**Manual acceptance requirement (stated honestly, not claimed as automated):** `WP_UnitTestCase` runs headless — it does not dispatch real HTTP requests through `admin-post.php`/`admin-ajax.php`, and does not execute browser JavaScript (the danger-zone custom-date-range toggle, Chart.js rendering, the "select all" checkbox script). A real end-to-end PRG round-trip and the JS-driven form behavior on both tabs must be manually verified in the dev environment (`https://dev.biopentra.eu`) before this milestone is considered done: save settings, add/delete an exchange rate, run Preview Reset then Apply Reset against a disposable scope, and load the Dashboard with and without date filters. This is explicitly **not** provable by the PHPUnit suite alone.

---

## 14. WP-M18-0…N implementation ladder

**WP-M18-0 — Preflight + branch + plan materialization**
Create branch `feature/m18-admin-controller-decomposition` from `main`. Materialize this approved plan into `docs/milestones/m18-implementation-plan.md`. Commit that file alone. Checkpoint: `git status` clean except the one new file.

**WP-M18-1 — Settings characterization tests (written against current, pre-extraction code)**
Create `tests/integration/admin-decomposition/test-settings-save-characterization.php`, `test-exchange-rate-crud-characterization.php`, `test-danger-zone-reset-characterization.php`. Cover BR-M18-1..14 (success, `WP_Error`, capability-denied, nonce-invalid, the id-before-nonce ordering of BR-M18-5, the fixed validation order of BR-M18-8). Before writing `$_POST`/redirect-interception scaffolding, grep the existing suite for any prior `admin_post_*` handler test to reuse its exact `wp_redirect`-interception / `wp_die`-to-exception convention (`WC_Inventory_Overview_Test_Case` or the WP test harness typically provides this) — do not invent a second mechanism if one already exists. Record query-count baselines per §12. Checkpoint: run these tests against the **unmodified** current code; all green (proves they characterize real behavior, not aspirational behavior).

**WP-M18-2 — Dashboard characterization tests (written against current, pre-extraction code)**
Create `tests/integration/admin-decomposition/test-dashboard-rendering-characterization.php` covering BR-M18-15..19 (date-filter presence-vs-value asymmetry, KPI note-string scoping, margin-null em-dash, low-stock badge/empty-state, quick-actions capability gating, chart-payload conditional emission). Record query-count baselines. Checkpoint: green against current code.

**WP-M18-3 — Extract `Settings_Controller`**
Create `includes/class-wc-inventory-overview-settings-controller.php` per §8. Modify `wc-inventory-overview.php` (add `require_once`), `includes/class-wc-inventory-overview-plugin.php` (remove the 12 methods + 7 `add_action` lines, add the `Settings_Controller::instance()->init();` bootstrap call, update `render_inventory_profit_shell()`'s `TAB_SETTINGS` case, promote `get_requested_tab()`/`admin_url_tab()` to `public`). Checkpoint: `php -l` on both touched files; rerun WP-M18-1 tests **unchanged** — must stay green; manual diff review confirms INV-M18-11.

**WP-M18-4 — Extract `Dashboard_Controller`**
Create `includes/class-wc-inventory-overview-dashboard-controller.php` per §8. Modify `wc-inventory-overview.php` (add `require_once`), `includes/class-wc-inventory-overview-plugin.php` (remove the 3 methods, update `render_inventory_profit_shell()`'s `TAB_DASHBOARD` case). Checkpoint: `php -l`; rerun WP-M18-2 tests unchanged — must stay green.

**WP-M18-5 — Architecture guard tests**
Create `tests/unit/admin-decomposition/test-settings-controller-architecture.php`, `test-dashboard-controller-architecture.php`, following the M17 `test-supplier-merge-architecture.php` technique (glob `includes/*.php`, grep-guard sole-owner call sites, substring-extract method bodies to assert no `global $wpdb`, assert zero `do_action(`/`apply_filters(` introduced, assert the god class no longer `method_exists()`s the moved methods). Covers INV-M18-1/5/8/9/10/11. Checkpoint: new tests green.

**WP-M18-6 — Targeted regression pass**
Rerun (no code changes expected): `test-batch-migration-retirement-regression.php`, `test-no-sibling-plugin-coupling.php`, `tests/unit/settings/test-settings-po-delay-grace-days.php`, the `tests/integration/install/` v11 schema test. If any fail, the failure means Part D's dependency map missed a coupling — halt and reassess (§18), do not silently patch around it.

**WP-M18-7 — CI wiring**
Update the milestone-gating CI step's explicit `--filter` regex (wherever `run-phpunit.sh` defines it, per `.github/workflows/tests.yml`) to include the new test class-name prefixes from WP-M18-1/2/5. Checkpoint: grep confirms the new prefixes are present in the regex.

**After WP-M18-7 (all implementation WPs complete):**
1. Run the full `tests/unit/admin-decomposition/` + `tests/integration/admin-decomposition/` set plus the WP-M18-6 targeted regression set.
2. Run **one** complete final validation sequence: full PHPUnit unit suite, full PHPUnit integration suite, PHPCS (`phpcs.xml.dist`), php-parallel-lint, via the Docker test harness (`docs/testing.md`).
3. Fix any defects found; rerun only the affected tests while fixing.
4. Rerun the full gate only if a fix touches executable code shared beyond `admin-decomposition/` — otherwise targeted reruns suffice.
5. Push branch, confirm CI green.
6. Write the final implementation report.

**Implementation stops here.** No merge, no tag, no release, no deployment — those belong to the repository lifecycle (§15), not this implementation plan.

---

## 15. Lifecycle after implementation

`WP-M18-0`…`WP-M18-7` above are **implementation-plan packages** (this plan's own sequencing). They are distinct from the **repository governance lifecycle** in `docs/process/milestone-lifecycle.md`:

- **WP0 Planning** — this planning pass (already in progress; completes when the user approves this plan and WP-M18-0 materializes it).
- **WP1 Implementation** — WP-M18-1 through WP-M18-7 above, executed sequentially without stopping for approval between them (per the fast-implementation strategy, §20).
- **WP2 Independent Audit** — **Level A**: a lightweight completion review (not a fresh-instance independent audit — that's reserved for Level B). Given the zero-existing-coverage risk this plan identified, the completion review should focus specifically on: (a) INV-M18-2 — spot-check that characterization tests genuinely exercised the pre-extraction code (not vacuously true), and (b) INV-M18-11 — diff review confirming the extraction was mechanical. Plus the standard repo-state/version-consistency checks.
- **WP3 Remediation** — fix only completion-review findings; no scope expansion.
- **WP4 Freeze** — create `docs/checklists/m18-release-readiness.md` recording implementation/review/remediation complete, repository frozen, current release status (not released).
- **WP5 Continue Development** — stop here. No release, no tag, no deployment. (No Release Trigger applies — see §17.)
- **WP6 Feature Train Release** — deferred to whenever the business batches M18 with future milestones (e.g. a Phase 2 god-class milestone). Outside this plan's authority.

---

## 16. Release / rollback analysis

- **Expected development version:** `1.35.0`.
- **DB_VERSION:** unchanged, `11`.
- **Standalone vs. train:** joins the feature train — no Release Trigger (`docs/process/milestone-lifecycle.md`) applies: no schema, no migration, no public-API change, no ownership-boundary change, no storefront-behavior change, no security fix, no breaking change.
- **Migration implications:** none.
- **Code rollback:** fully reversible. `git revert`/checkout to the pre-M18 commit fully restores prior behavior with zero data implications, because this milestone introduces no schema or data mutation of its own.
- **Data rollback:** not applicable — no schema/data change is introduced by M18 itself. Note: the Danger-Zone-Apply deletion capability already existed before M18 and is unchanged by it; a code rollback of M18 does **not** undo a reset a user already ran post-M18 — that is a pre-existing property of that feature, not something this milestone changes or claims to fix.
- **Backup requirement:** standard pre-deploy backup per `docs/deployment-checklist.md`; no elevated requirement, since this milestone carries no elevated data risk of its own.
- **Can rollback genuinely reverse this milestone's effects?** Yes, fully — it is a pure code reorganization.

---

## 17. Documentation plan

| Document | Action |
|---|---|
| `CLAUDE.md` | Add M18 row to the Implementation Status table (at WP-M18-8/completion time). Update the "Platform status"/baseline header line only at actual WP6 release time, not during WP1. |
| `CHANGELOG.md` | Add a dev/unreleased entry for M18 — internal refactor, explicitly no user-facing behavior change (Rule 4 permits unreleased entries). |
| `readme.txt` | No change (no user-facing behavior/version-support difference until the WP6 release bump). |
| `docs/architecture-audit.md` | Update the "Large god class" tech-debt entry (~line 585/597) to record Phase 1 complete (Dashboard + Settings extracted, ~42% of the class), Phase 2 (Overview/Restock/Movements/Order-Profit/Product-Profitability + CSV export + bulk actions) still open. Refine, do not delete. |
| `docs/OWNERSHIP.md` | No change — no new domain concept (INV-M18-6). |
| `docs/admin-guide-*.md` | No change — zero user-facing behavior difference; verify at WP-M18-8 that none reference internal class names. |
| `docs/testing.md` | No rule change; if it maintains a test-directory inventory, add the new `admin-decomposition/` area. |
| `docs/rollback-plan.md` | Add a one-line M18 entry: "safe code-only rollback, no data implications" (§16). |
| `docs/release-runbook.md` | No change — process unaffected. |
| `docs/checklists/validation-checklist.md` | No change needed (no new merchant-facing flow); optionally add a Settings/Dashboard smoke-test line if the checklist already tracks per-tab checks — verify at WP-M18-8. |
| `docs/milestones/m18-implementation-plan.md` | Created fresh at WP-M18-0 (this plan, materialized). Immutable once created. |
| `docs/checklists/m18-release-readiness.md` | Created at lifecycle WP4 — not part of the WP-M18-x implementation packages. |
| CI filter regex (`run-phpunit.sh`) | Updated at WP-M18-7. |

---

## 18. Stop conditions

- Repository state at WP-M18-0 no longer matches §1 (main not at the verified baseline).
- Any WP discovers a caller of `Plugin::render_settings_panel()`/`render_dashboard_panel()`/the moved `handle_*`/`ajax_*` methods beyond the files listed in §4's blast-radius — halt, reassess, do not silently accommodate.
- Any WP surfaces a need to touch a `wc_io_*` table, column, or `DB_VERSION` — halt immediately; contradicts INV-M18-3.
- A new mutation path (beyond what already exists in current `main`) is required to make the extraction work — halt; this plan claims zero new mutation.
- A WP discovers the singleton-instantiation pattern breaks an undocumented concurrency assumption inside `Data_Reset` — halt, reassess (do not paper over with locking logic invented ad hoc).
- Post-extraction query count for any characterized path differs from the WP-M18-1/2 baseline by even one query — halt; do not loosen the assertion to make it pass.
- Any WP discovers Settings/Dashboard code touches PO/receipt/movement/cost logic (it shouldn't, per §4's dependency read) — halt; this would mean Part D's map was wrong and needs re-verification, not silent adaptation.
- A characterization test cannot be implemented honestly (e.g. `wp_die()`/`exit` interception genuinely cannot be made to work in the harness) — halt, do not fake a green test by weakening what it asserts.
- Any WP would require changing an approved BR (§6) or INV (§7) to proceed — halt, return to planning; do not silently amend an approved rule mid-implementation.

---

## 19. Deferred candidates

1. **God-class Decomposition Phase 2** (Overview/Restock/Movements/Order-Profit/Product-Profitability + shared CSV-export/bulk-action/AJAX machinery) — natural M19 candidate once Phase 1's pattern is validated in production; materially larger due to shared cross-tab machinery.
2. **Coverage / Forecast** — blocked, no sales/consumption-velocity data in schema; needs its own data-modeling milestone(s) first.
3. **Reservations** — blocked per D16, no concrete consumer identified.
4. **Inbound Shipment entity** — additive per D10 but no concrete driver surfaced; multi-milestone if picked up.
5. **Warehouse Locations** — ownership pre-assigned in `docs/OWNERSHIP.md` but no consumer today (single-warehouse shop); speculative schema, forbidden by D16 until a concrete need exists.
6. **`MANAGE_SUPPLIERS` dead-capability cleanup** — small unrelated item noted in M17's plan; candidate for bundling into a future hardening-style milestone.

---

## 20. Risks

- **Primary:** zero pre-existing regression coverage for the exact code being moved. **Mitigation:** WP-M18-1/2 mandate characterization tests written and proven green against *current* code before any extraction — this is the plan's single non-negotiable risk control, chosen deliberately over escalating to Level B (which would add an independent-audit process cost without addressing the actual gap, which is coverage, not review depth).
- **Secondary:** hidden coupling not surfaced by §4's dependency scan. **Mitigation:** WP-M18-6's targeted regression pass + the explicit stop condition (§18) for any newly-discovered reference.
- **Tertiary:** CI filter-regex omission silently excludes new tests from the milestone-gating CI step. **Mitigation:** WP-M18-7 is a dedicated WP with its own grep-verified checkpoint.
- **Low:** promoting `get_requested_tab()`/`admin_url_tab()` from `protected` to `public` widens `Plugin`'s public surface. **Mitigation:** acceptable — these are internal admin-UI helpers, not part of any documented public API/hook contract (`docs/OWNERSHIP.md`'s "documented hooks/versioned read contracts only" governs cross-plugin integration, not intra-plugin method visibility); flagged for the WP2 completion review to confirm no PHPCS/architecture rule forbids it.

---

## 21. Final implementation-readiness verdict

**Ready.** The characterization-tests-first sequencing directly addresses the one real risk (zero existing coverage) without requiring product-policy invention — every BR/INV/data-contract in this plan specifies an exact existing string, exact existing ordering, or exact existing method shape, all read directly from the current source (`main` @ `bfdde20`). An implementation agent can execute WP-M18-0 through WP-M18-7 sequentially with no further design decisions: what to extract, in what order, with what exact visibility/method-name changes, and what exact behavior must remain provably unchanged are all fully specified above.
