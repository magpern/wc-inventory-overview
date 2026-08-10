# Milestone M13 Implementation Plan — Printable Purchase Order

**Status:** Approved. This document is the immutable implementation specification for Milestone M13, materialized from the approved Plan-mode document before any implementation code was written, per `docs/process/milestone-lifecycle.md` WP0 step 5 / Permanent Repository Rule 1. Once committed, this file is never edited, replaced, or repurposed — any future freeze/readiness record belongs in `docs/checklists/m13-release-readiness.md`, not here.

## Materialization note

Materialized substantively faithfully from the approved Plan-mode document (`milestone-m13-discovery-sunny-kurzweil.md`, round-2-revised and user-approved) after pre-flight verification on `feature/m13-printable-purchase-order` at `67321bb0180860c386c2642f10e7d092508d7680` (plugin `1.29.0`, `DB_VERSION` `10`, tag `v1.29.0` latest and released, M9–M12 recorded released, CI recovery infrastructure present, GitHub Actions baseline green). No design amendments at materialization time.

# M13 — Printable Purchase Order (Definitive Plan)

## Context

v1.29.0 (M0–M12) is released and deployed; GitHub Actions is green; the M9–M12 feature train is closed (`docs/checklists/feature-train-m9-m12-release-readiness.md`: *"Plan the next milestone only with explicit approval. Do not start M13 without a new approved plan."*). A fresh, evidence-based discovery pass (three parallel research passes over core architecture/process docs, the full text of the M9–M12 implementation plans, and a repository-wide grep for deferred/TODO/future markers) found that every open backlog item has already been evaluated and explicitly rejected by some prior milestone's own planning — except **Printable Purchase Order**, which has never been rejected on its merits. It has been an explicitly reserved capability since Architecture v1.0 (`CLAUDE.md` D17: *"a capability reserved, not built (§11.2)"*), M11's own discovery called it *"a good candidate for a future milestone,"* and direct code inspection confirmed it needs **zero new domain ownership** — every read path it needs already exists. The user confirmed this selection directly and approved a round-2-revised plan correcting architecture, security, and completeness gaps identified in the first draft.

---

## PART A — Verified repository state

| Check | Result |
|-------|--------|
| Path | `/opt/biopentra/dev/wc-inventory-overview` |
| Branch | `feature/m13-printable-purchase-order` |
| Base SHA | `67321bb0180860c386c2642f10e7d092508d7680` (== `origin/main` at branch-cut time) |
| Sync | Matches `origin/main`; working tree clean at branch-cut |
| Plugin | `1.29.0` (target for M13: `1.30.0`) |
| `DB_VERSION` | `10` — unchanged by M13 |
| Last release | `v1.29.0` (tagged, published, deployed) |
| M9–M12 | Recorded released together per `CHANGELOG.md [1.29.0]` and `docs/checklists/feature-train-m9-m12-release-readiness.md` |
| CI recovery infrastructure | Present: deterministic DB reset, `tests/unit/db-transaction/test-db-transaction.php`, `scripts/release-audit.sh` development/release modes, M1–M12 focused blocking suite |
| CI baseline | Latest `main`-push CI/Tests/Release workflows all `completed success` |
| `docs/milestones/m13-implementation-plan.md` | Did **not** exist before this commit (correct for Plan mode) |
| Stale doc wording found in planning | `readme.txt:28` — *"Not individually released — joins the unreleased M9–M12 feature train pending a bundled release"* — stale, corrected in WP-M13-6 (documentation-only, not product scope). `CLAUDE.md` verified already current (not stale) at planning time. |

No material mismatch. Proceed.

---

## PART B — Discovery findings (summary)

Every other named backlog candidate remains legitimately deferred for a reason already on record — undesigned (supplier spend/order-history), architecturally blocked (Coverage/Forecast needs sales-velocity data the plugin doesn't collect; Reservations forbidden by D16 until a concrete consumer exists), higher mutation/audit risk (supplier merge — cross-table FK reassignment), or explicitly ruled "too small alone" / "unrelated subsystem polish" by M12's own discovery pass (suggestion-source transparency, PO-delay grace-days Settings UI, Position supplier drilldown). None of this is reopened, resolved, or narrowed by M13.

---

## PART C — Selected M13 and rationale

**Printable Purchase Order.** LOW-RISK EXTENSION. Composes three already-existing, unmodified read owners (`Purchase_Orders::get()`, `Purchase_Order_Lines::list_for_po()`, `Suppliers::get()`) plus one already-existing label helper (`PO_Statuses::label()`) into a new, capability- and nonce-gated, read-only, standalone-HTML print view. No schema change, no mutation, no new domain owner, no storefront exposure, no new capability, no speculative hook.

---

## PART D — Problem statement, goals, non-goals

**Problem statement:** Every placed Purchase Order needs, at some point, to be handed to a supplier, a warehouse team, or an accounting file as a clean document — and today the only way to do that is to screenshot or manually retype the wp-admin edit screen. This is the single highest-frequency "missing" interaction in the purchasing workflow, and the architecture has reserved a capability for it since v1.0 without ever building it.

**Goals:**
- A read-only, printable HTML view of a single Purchase Order, reachable from the existing PO detail screen for any PO in a printable status (Part D.1).
- Zero duplication of PO/line/supplier read logic — compose existing sole owners only.
- Browser print / "Save as PDF" is the entire PDF story; no library, no generated file, no storage.

**Non-goals (explicit):**
- No PDF-generation library or dependency.
- No email-sending, no attachment storage.
- **No new hook, filter, or public API surface.** The `admin_post_` action used to route the request is WordPress-standard internal request routing (the same mechanism `PO_Admin::init()` already uses for `save`/`place`/`cancel`/etc.) — it is not a new plugin API contract, and no `wc_io_po_print_actions`-style hook (or equivalent) is introduced. `CHANGELOG.md:329`'s note that this was deliberately *not* added at M2 stands; M13 does not revisit that decision.
- No change to PO lifecycle, statuses, events, quantities, or any mutation path — printing never writes to the PO, its lines, receipts, stock, cost, Inventory Position, or supplier records.
- No printable Goods Receipt — PO only, matching D17's original reservation scope exactly.
- No new capability — reuses `VIEW_PO`.
- No storefront/customer-facing exposure.
- No tax/shipping/discount fields — the current PO domain has none; M13 does not expand the PO domain to add them.
- No new persistence to improve historical product-reference snapshots — the existing `name_snapshot`/`sku_snapshot` line columns already solve this (Part D.3).

### D.1 — Exact printable states

`WC_Inventory_Overview_PO_Statuses` defines exactly six states: `draft`, `placed`, `partially_received`, `received`, `cancelled`, `closed_short`.

- **Print available:** `placed`, `partially_received`, `received`, `cancelled`, `closed_short` — every status that represents a PO that was actually committed/sent, including its terminal states. INV-6 (auditability — nothing is hard-deleted, corrections are lifecycle transitions with full history) supports keeping a cancelled or closed-short PO printable as a historical record.
- **Print unavailable:** `draft` — a draft was never placed/sent; it is still fluid, unconfirmed, and has no commitment behind it. Printing a draft could be mistaken by its recipient for a real order. The print entry point does not render for a draft PO; the print handler itself also re-checks status server-side (never trust the UI state alone) and denies with `wp_die()` on `draft`.
- Printing performs **zero status transition** in either direction.

### D.2 — Product/line data resolution

`class-wc-inventory-overview-purchase-order-lines.php` and the schema already carry `name_snapshot` and `sku_snapshot` columns, captured at line-creation time. `PO_Admin::render_line_row()` already displays product identity from these snapshot columns directly, never via a live `wc_get_product()` call. This is the established repository convention for PO-line product display, and M13 reuses it exactly:

- Product/variation description = `name_snapshot` (fallback `—`).
- SKU = `sku_snapshot` (fallback `—`).
- **Consequence:** a deleted product or variation is a non-issue by construction — no live WooCommerce product lookup is performed anywhere in the print path.
- Supplier's own item identifier (`supplier_sku`) printed as-is (fallback `—`).
- If `Suppliers::get()` fails: the print view still renders using `supplier_name_snapshot` already stored on the PO header row, and omits email/phone/reference rather than failing the whole view. No new persistence added.

### D.3 — Merchant/store identity

Uses only `get_bloginfo( 'name' )` as the merchant/store identity line. No new settings field, no new option.

---

## PART E — Ownership model

| Role | Owner | Notes |
|---|---|---|
| Business-rule owner | `WC_Inventory_Overview_PO_Service` / `PO_Lifecycle` | **Unchanged, not touched.** |
| Read owner | `Purchase_Orders::get()`, `Purchase_Order_Lines::list_for_po()`, `Suppliers::get()` | **Unchanged, not touched.** |
| Mutation owner | *None* | Print performs no writes anywhere. |
| Presentation owner | **New:** `WC_Inventory_Overview_PO_Print_Renderer` | Presentation-only. |

Render model assembled by `PO_Admin` itself (composes the same three read owners it already uses for `render_detail()`); no intermediate `PO_Print_View_Model`/`PO_Print_Data` class is introduced.

---

## PART F — Architecture invariants

- **INV-M13-1** — Printable PO rendering is read-only and MUST NOT mutate PO, receipt, stock, cost, Inventory Position, supplier, settings, or options state, under any code path including error paths.
- **INV-M13-2** — `PO_Print_Renderer` is presentation-only: zero `$wpdb` access, zero calls to `Purchase_Orders`/`Purchase_Order_Lines`/`Suppliers`/any other repository class, zero PO business rules beyond pure display arithmetic (`qty_ordered * unit_cost` per line, plain sum for the total).
- **INV-M13-3** — Print data is obtained only through the three established read owners plus `PO_Statuses::label()`; no duplicated PO/supplier aggregation SQL anywhere in the print path.
- **INV-M13-4** — Print access requires **both** `VIEW_PO` capability **and** a valid, action-and-PO-scoped nonce before any purchasing or supplier information is read/rendered.

---

## PART G — Security contract

1. **Capability:** `WC_Inventory_Overview_Purchasing_Caps::current_user_can( VIEW_PO )`.
2. **Nonce:** standard WordPress nonce, action string `'wc_io_po_print_' . $po_id` — **not** `PO_Request_Token` (that class is a one-shot, 600s, POST-double-submit guard for mutating flows; wrong semantics for a reloadable read view).
3. **PO existence:** `Purchase_Orders::get( $po_id )` must return a valid row.
4. **PO state:** must be one of the printable statuses (Part D.1).

Any failure of 1–4 stops processing immediately via `wp_die()` — no partial rendering, no purchasing/supplier data constructed before all four checks pass.

Print entry point built with `wp_nonce_url()` / `add_query_arg()` + `wp_create_nonce()`.

---

## PART H — Output / escaping contract

Standalone HTML document, own `<!DOCTYPE html>`, not wrapped in wp-admin chrome.

- Every dynamic value contextually escaped: `esc_html()` for text content, `esc_attr()` for attributes, `esc_url()` for URLs. No raw echo of a stored/user-influenced value.
- No `wp_kses_post()` substituted for correct escaping — every field is plain text by domain definition.
- No external JS/CSS/CDN/web fonts/tracking. Print CSS is a small inline `<style>` block.
- Dates use existing plain `Y-m-d` string values.
- Status uses `PO_Statuses::label()`.
- Confidence uses existing raw vocabulary values (`exact`/`estimated`/`unknown`).
- Money formatted as `number_format( (float) $amount, 2 ) . ' ' . $currency_code` — no `wc_price()` (would format in store base currency, wrong for a PO's supplier currency).

---

## PART I — Document content contract

**Header:** store name (`get_bloginfo('name')`), PO number, PO status label, order date, expected date + confidence, currency.

**Supplier:** name (`supplier_name_snapshot`); reference/email/phone from `Suppliers::get()` when it resolves, omitted otherwise.

**Lines (per line):** `name_snapshot`, `sku_snapshot`, `supplier_sku`, `qty_ordered`, `qty_received`, `unit_cost`, line total (`qty_ordered * unit_cost`).

**Totals:** PO total = sum of line totals, in the PO's own currency. No tax/shipping/discount fields.

No field beyond this list. This is the complete, final content contract.

---

## PART J — Print UX

- "Print" link on the existing PO detail screen, rendered only for printable statuses, hidden for `draft`.
- Opens the standalone printable view via the nonce-carrying URL.
- Visible on-screen "Print" button calling `window.print()` when JS is available; page remains fully usable without JS via the browser's native Print command.
- `@media print` hides the on-screen button and any screen-only chrome.
- Browser print → Save as PDF is the entire PDF mechanism. No library, no generated file, no new attachment storage.

---

## PART K — Schema / mutation / version decision

- Schema change: **No.** `DB_VERSION`: **unchanged, stays 10.** Migration: **No.** Persistent data introduced: **No.** Existing data mutated: **No.**
- Rollback: unconditionally safe code rollback, same classification as M6–M12.
- **Development-version target: `1.30.0`** (next integer after M12's tagged `1.29.0`, following the established per-milestone target-bump pattern).
- Public API impact: none. Security/capability impact: reuses `VIEW_PO`, no new capability.

---

## PART L — Production files / classes affected

**New:** `includes/class-wc-inventory-overview-po-print-renderer.php` — `WC_Inventory_Overview_PO_Print_Renderer`.

**Modified:** `includes/class-wc-inventory-overview-po-admin.php` — add `admin_post_wc_io_po_print` registration, `handle_print()`, print entry point in the detail screen.

**Not touched:** `PO_Service`, `PO_Lifecycle`, `PO_Statuses` (consumed via `label()` only), `PO_Confidence`, `PO_Delay`, `PO_Events`, `PO_Numbering`, `PO_Quantities`, `PO_Receiving_Sync`, `Purchase_Orders`, `Purchase_Order_Lines`, `Suppliers`, `Supplier_Lead_Time_Service`, `Expected_Date_Suggestion_Service`, `Expected_Deadline`, `Purchasing_Caps` (consumed via `VIEW_PO` constant only), any Inventory Position or storefront class, any schema/install file.

---

## PART M — Hooks / integration points

None new. `admin_post_wc_io_po_print` is WordPress-standard internal request routing, registered identically to the six existing `admin_post_wc_io_po_*` actions.

---

## PART N — Query / performance contract

Three point reads per print request: `Purchase_Orders::get()`, `Purchase_Order_Lines::list_for_po()`, `Suppliers::get()` (when `supplier_id` resolves) — identical shape/cost to the existing detail screen. No aggregate query, no N+1, no per-line product/supplier query.

---

## PART O — Backward compatibility

Fully additive — no existing behavior, output, or contract changes.

---

## PART P — Rollback strategy

Code-only rollback is unconditionally safe: revert the two changed/new files, no data was ever written, no schema was ever touched.

---

## PART Q — Work packages

- **WP-M13-0** — Architecture guard scaffold: `tests/unit/po-print/test-po-print-architecture.php`.
- **WP-M13-1** — `WC_Inventory_Overview_PO_Print_Renderer`.
- **WP-M13-2** — `PO_Admin::handle_print()` + `admin_post_wc_io_po_print` registration.
- **WP-M13-3** — Print entry point on the PO detail screen.
- **WP-M13-4** — Unit tests.
- **WP-M13-5** — Integration tests (full HTTP round trip matrix).
- **WP-M13-6** — Documentation (see below).

---

## PART R — Unit-test plan

- `PO_Print_Renderer` output-correctness (every Part I field present, correctly escaped, `—` fallback for missing values).
- `PO_Print_Renderer` zero-dependency test (no repository type-hints in its API).
- Money-formatting unit test.
- Status-gate unit test on `PO_Admin::handle_print()` (all six statuses exercised; only `draft` denied).

## PART S — Integration-test plan

Full HTTP round trip through `admin_post_wc_io_po_print`: authorized+valid → success; missing/invalid nonce → denied, no data rendered; unauthorized user → denied; nonexistent PO → denied; draft → denied; each of `placed`/`partially_received`/`received`/`cancelled`/`closed_short` → allowed; deleted product line → renders via snapshot; unresolvable supplier → renders via header snapshot, contact fields omitted; existing PO Admin tests unaffected.

## PART T — Architecture guards

INV-M13-1 (zero mutation tokens), INV-M13-2 (zero `$wpdb`/repository calls in renderer), INV-M13-3 (sole-caller allowlist for the three read owners within the print path), INV-M13-4 (capability+nonce ordering precedes any repository read — ordering assertion, not just presence).

## PART U — Performance tests

Not required beyond the existing detail-screen baseline (single-PO point-read feature, not list/aggregate).

## PART V — Manual / browser acceptance

Print visible/renders correctly for all five printable statuses; absent for draft; direct-URL attempts denied for draft/bad nonce/no capability/nonexistent PO; on-screen Print button triggers `window.print()`; button hidden under `@media print`; native Ctrl/Cmd+P works without JS; deleted-product line still renders via snapshot.

## PART W — Regression requirements

Full M1–M13 focused suite green; existing PO detail-screen behavior unaffected (pre-existing PO Admin suite re-run unmodified).

## PART X — Documentation deliverables

`CLAUDE.md` status table, `CHANGELOG.md`, `readme.txt` (including the stale-wording fix), `docs/ARCHITECTURE_BASELINE_v1.24.0.md` (§3, §6), `docs/architecture-audit.md`, `docs/checklists/validation-checklist.md`, `docs/rollback-plan.md`, `docs/release-runbook.md`, new PO admin guide (or section) documenting printable statuses, security model, browser-print/PDF behavior, snapshot resilience, read-only nature, INV-M13-1..4.

## PART Y — Acceptance criteria

All Part I fields present and correctly escaped; print unavailable/denied exactly per Part D.1/Part G; zero schema change; `DB_VERSION` unchanged at 10; all four architecture guards pass; full CI contract green.

## PART Z — Definition of Done

Implementation complete, all work packages closed, full test matrix green, architecture guards green, documentation updated, WP4 freeze checklist created, Level A completion review passed.

---

## CI contract

PHP lint green; unit suite green; M1–M13 focused suite green (M13 tests added to the focused filter list); integration suite green; 0 risky/0 failures/0 errors; M13-intended tests actually discovered by the runner; `scripts/release-audit.sh --development` green; GitHub Actions green — all required before WP4 freeze.

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| A future contributor adds a repository read call directly inside `PO_Print_Renderer` | INV-M13-2 architecture guard test fails the build |
| Nonce reused across PO ids | Nonce action string explicitly includes the PO id |
| Merchant expects tax/shipping fields | Explicitly out of scope; documented in the admin guide |

## Explicit deferred work

Unchanged from Part B — nothing in the existing backlog is resolved, narrowed, or touched by M13.

## Commit strategy

One commit per work package, no scope expansion.

## Stop conditions

Schema change needed → stop. `name_snapshot`/`sku_snapshot` unreliable → stop. Any CI contract item red at WP4 → stop, remediate, never accept red as known condition.

## Final implementation-report contract

WP4 freeze report states: schema/DB_VERSION unchanged (10); zero mutation confirmed by architecture guards; all four PART T guards passing; full CI contract green; development version 1.30.0; Level A classification confirmed; explicit deferred-work list unchanged.

---

## Risk/lifecycle classification

**LEVEL A** — lightweight completion review + freeze. No schema, no migration, no mutation, no public API, no ownership-boundary change, no destructive operation, no security/capability change beyond reusing an existing capability, no storefront exposure, no concurrency complexity.

## Release recommendation

**Option A — M13 starts a new, unreleased feature train.** No schema/API/ownership/storefront/security impact, so no release trigger applies.
