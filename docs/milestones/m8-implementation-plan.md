# Milestone M8 — Hardening & General Availability (v1.25.0)

## Context

M0–M7 shipped the complete inventory/purchasing/receiving/storefront platform
(plugin 1.24.0, `DB_VERSION` 10), frozen as of
`docs/ARCHITECTURE_BASELINE_v1.24.0.md`. That baseline and the M6/M7
implementation plans already name several concrete, unfinished items on the
record — not speculative wishlist entries, but commitments the project made
to itself and explicitly deferred to "M8":

- M6's own governance rule: physically delete the Batch-Intake code it marked
  `@deprecated`, once M8 confirms a full release cycle with zero
  migration-related incidents (`docs/architecture-audit.md`, `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §12).
- M5 left `PO_Delay`'s delayed-detection predicate not extending to
  `partially_received` POs, "documented rather than silent," "recorded as M8
  follow-up" (`docs/architecture-audit.md`, restated in the M7 plan).
- M7 explicitly cut a "no sibling-plugin name in `includes/`" architecture
  guard, calling it "codebase hygiene belonging to M8's conformance audit."
- `docs/testing.md` documents 13 pre-existing test-content bugs (4 errors, 7
  failures, 2 skipped — all in five M0-era golden-characterization files,
  zero in any M1–M7 milestone suite) kept deliberately non-blocking in CI
  "as a temporary, documented exception... a follow-up once the itemized
  issues below are fixed."
- The M7 plan states M8's charter directly: *"M8 scope: broad cleanup,
  general caching optimization, unrelated technical debt, Batch Intake
  physical deletion, GA conformance audit, general storefront redesign"* —
  i.e. hardening and finishing, not new domain concepts.

I re-verified every one of these claims directly against the current
repository (ran both PHPUnit suites live, greped every `@deprecated`/TODO
marker, confirmed the CI filter regex and workflow files) rather than trusting
the docs at face value — findings below cite exact counts and locations. Two
scoping calls were resolved with you: **target v1.25.0** (not the stale
"2.0.0" placeholder in CLAUDE.md — nothing in this milestone is a breaking
change), and **exclude the `Plugin` god-class split** from M8, recording it
as a deliberate post-1.0 deferral rather than attempting it under GA time
pressure.

A further review pass separated two goals that don't belong in one work
package (test-content repair vs. CI policy), demoted a pure verification
step out of the work-package list into an explicit release gate, and made
the one technically risky work package's deletion criteria a checklist
rather than implied narrative.

**Target:** plugin v1.25.0. **Schema:** `DB_VERSION` stays `10` — zero schema
change. **New domain concepts:** zero. This is the smallest milestone that
lets M0–M7 be called production-finished.

---

## Work packages

### WP1 — Physically remove M6-deprecated Batch Intake code

**Objective:** Delete the `@deprecated` Batch Intake create/apply code path
that M6 disabled but could not delete, per the governance rule reserving
physical deletion for M8.

**Rationale:** Directly named in `docs/architecture-audit.md` and formalized
as a governance rule in `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §12 ("do not
delete it earlier without revisiting that decision first" — M8 is that
revisit). Confirmed: exactly 4 files carry the M6 retirement's
`@deprecated` tags:

- `includes/class-wc-inventory-overview-batch-intake-service.php` —
  `build_preview_from_post()`, `apply_batch_from_post()`,
  `rollback_batch_apply()` (protected), `build_movement_note_for_line()`
  (protected), `render_preview_markup()`.
- `includes/class-wc-inventory-overview-batch-intake-ui.php` — `render_panel()`.
- `includes/class-wc-inventory-overview-plugin.php` — `ajax_batch_preview()`,
  `handle_batch_apply_post()` (hooks already unregistered since M6; methods
  only reachable directly).

**Explicitly excluded from this deletion** (confirmed not part of the M6
retirement commitment):
- `landed_cost_type_labels()` / `allowed_cost_types()` on
  `Batch_Intake_Service` — also tagged `@deprecated` but are live delegation
  shims to `Landed_Cost_Types` (extracted in M6), not dead code. Deleting
  them would remove a working re-export, not clean up debt.
- `Settings::OPTION_DEFAULT_ACTUAL_SHIPPING_COST` — an unrelated legacy-option
  migration shim, not named anywhere in the M6 retirement list, no removal
  date attached. Left untouched; flagged as a separate, smaller,
  not-yet-justified candidate for some future pass.

**Architectural impact:** None on the frozen architecture — this is pure
subtraction of already-unreachable code. Legacy data
(`wc_io_purchase_batches`/`_lines`/`_costs`) is untouched (D14, frozen
forever); nothing here drops or truncates a table.

**Deletion criteria — every condition below must hold before any line of
the deprecated implementation is removed. This is a checklist, not a
narrative:**

1. **Every replacement path is already exercised by tests.** The rewritten
   `create_legacy_batch()` fixture builder (replacing the deprecated apply
   path — see Implementation approach) has run, green, through the full
   test suite at least once before deletion begins.
2. **Historical-integrity tests pass unchanged.** The M6 batch-migration
   golden tests (byte-for-byte stock/cost across simple/USD/blended-average/
   multi-batch scenarios) pass against the rewritten fixture builder with no
   change to their expected values.
3. **No remaining production code references the deprecated implementation.**
   A grep across all of `includes/` for each of the five method names being
   removed returns zero call sites outside the methods' own definitions.
4. **No remaining CLI/Admin/UI path can invoke it.** Confirmed: the
   `admin_post_wc_io_batch_apply`/`wp_ajax_wc_io_batch_preview` hook
   registrations were already removed in M6 (`init()` no longer registers
   them); re-confirm at implementation time that no admin screen renders any
   link, button, or form action that could reach `Plugin::ajax_batch_preview()`/
   `handle_batch_apply_post()`, and that no WP-CLI command calls into
   `Batch_Intake_Service`'s deprecated methods.

Only once all four are independently verified does the deletion step run.

**Implementation approach:**
1. **Rewrite the test fixture builder first.**
   `tests/includes/test-case.php`'s `create_legacy_batch()` calls
   `apply_batch_from_post()` directly to build realistic pre-M6 fixture rows,
   and is used **47 times** across 9 integration files under
   `tests/integration/batch-migration/` plus
   `tests/integration/goods-receipt/test-goods-receipt-migrated-void-guard.php`.
   Rewrite it to construct the same batch header/lines/costs rows, stock/cost
   mutation, and movement note directly (via the repository classes /
   `Restock_Service` the deprecated method itself calls), producing
   byte-identical fixture state without going through the method being
   deleted.
2. Run the full M6 batch-migration suite (unit + integration, including the
   historical-integrity golden tests) against the rewritten fixture builder
   and confirm zero behavior change.
3. **Verify all four deletion criteria above explicitly** (the grep-for-
   call-sites and hook/UI-reachability checks are not implied by step 2 —
   run them separately and record the result).
4. Delete the five `Batch_Intake_Service` methods, `Batch_Intake_UI::render_panel()`,
   and `Plugin::ajax_batch_preview()`/`handle_batch_apply_post()`. If a class
   becomes empty or near-empty after deletion (verify at implementation
   time), remove the class file and its `require_once`; otherwise retain the
   shell holding only the two live delegation shims.
5. Retire `tests/integration/batch-intake/test-batch-intake-characterization.php`'s
   two tests (`test_preview_apply_parity_single_line`,
   `test_rollback_on_mid_operation_failure`) — their subject method no longer
   exists, so they move from "skipped due to a test bug" (their current
   state) to "deleted because the code path is gone," recorded in the
   milestone plan with that rationale, not silently dropped.

**Testing:** Full unit + integration suites green with the rewritten fixture
builder before deletion; unchanged pass count after deletion (minus the two
retired batch-intake tests, accounted for explicitly).

**Acceptance criteria:** All four deletion criteria verified and recorded.
The five methods, `render_panel()`, and the two `Plugin` handlers no longer
exist in `includes/`. `create_legacy_batch()` no longer references any of
them. M6's historical-integrity golden tests still pass unchanged. Legacy
tables' row counts and content are untouched by this work package.

**Rollback:** Code-only, no data/schema involved — revert the commit(s). No
special procedure beyond the plugin's normal code rollback.

---

### WP2 — Fix the `partially_received` delay-predicate gap

**Objective:** Extend `PO_Delay`'s delayed-detection predicate to correctly
flag a `partially_received` PO line as delayed, closing the gap M5 left open
and M7 worked around defensively rather than fixed.

**Rationale:** Named explicitly in three places
(`docs/architecture-audit.md` §Known risks, the M7 plan's hard-prohibitions
list, `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §Invariants) as a real,
admin-visible bug: `PO_Delay::sql_line_delayed_predicate()` still gates on
`po.status = 'placed'` only, so a partially-received PO's remaining
outstanding line can be genuinely overdue and never show the "Delayed" badge.
M5 already fixed the *sibling* query
(`Purchase_Order_Lines::query_open_lines()`'s `WHERE po.status IN (...)` list)
to include `partially_received`/`received` for Incoming purposes — this
applies the same fix to the delay predicate specifically.

**Architectural impact:** None — INV-5 ("Delayed" is always computed, never
stored) is unaffected; this changes what the existing computation outputs
for one previously-mis-handled status, not the computation's shape or
ownership. M7's `Expected_Delivery_Resolver` already defends against this
gap independently for the storefront (Invariant M7-1) and is not touched —
this fixes the admin-badge root cause `PO_Delay` still has.

**Implementation approach:** Extend `sql_line_delayed_predicate()`'s status
gate to include `partially_received` (mirroring M5's own fix pattern in
`query_open_lines()`), computing delay against the line's current
outstanding rather than its original ordered quantity.

**Testing:** New unit/integration test(s) for `PO_Delay` proving a
partially-received PO line with a past-due expected date now flags delayed,
and that a fully-received or on-time partially-received line does not.
Confirm the existing M3 Inventory Position delayed-flag tests and M7's
Invariant M7-1 tests are unaffected (M7 defends independently and doesn't
regress either way).

**Acceptance criteria:** A partially-received PO line past its
grace-adjusted expected date shows "Delayed" in the admin (PO detail,
Inventory Overview drill-down) exactly as a `placed` line would. No other
status's delayed behavior changes.

**Rollback:** Trivial — single predicate change, no schema/data impact,
instant revert.

---

### WP3 — Add the cut sibling-plugin-coupling conformance guard

**Objective:** Add the repo-wide architecture guard M7 explicitly deferred:
no file under `includes/` may reference a sibling plugin by name.

**Rationale:** `docs/milestones/m7-implementation-plan.md` names this
verbatim as M8 work ("codebase hygiene belonging to M8's conformance
audit"), distinct from M7's own narrower, load-bearing coupling guard. Every
milestone's per-feature architecture guard already asserts "no sibling-plugin
coupling" *within its own surface* (M4–M7 guard files each check this for
their own new classes) — this closes the one remaining gap: nothing today
checks the *entire* `includes/` tree at once.

**Architectural impact:** None — this is a test-only addition enforcing an
already-stated, already-believed-true property (ADR-0003's central claim).
If it finds a real violation, the fix is to remove that one coupling point,
not a design change.

**Implementation approach:** New test (e.g.
`tests/unit/conformance/test-no-sibling-plugin-coupling.php`) that
source-scans every file in `includes/` for `class_exists(`/`function_exists(`
against a non-WordPress/non-WooCommerce third-party symbol, any hardcoded
sibling-plugin slug (`biopentra-storefront`, `mp-commerce-fulfillment`, etc.),
and `remove_filter(`/`remove_action(` against a hook this plugin didn't
register itself.

**Testing:** The guard test itself, run against current `main` first to
confirm it passes cleanly (expected, per ADR-0003's audit claims) before
being wired into the CI-blocking filter.

**Acceptance criteria:** New guard test passes on the current codebase with
zero violations found (confirming ADR-0003's claim mechanically, not just by
prose), and is added to `run-phpunit.sh`'s blocking filter so future
regressions are caught automatically.

**Rollback:** Test-only; no rollback risk beyond removing the test file.

---

### WP4 — Repair the historical golden/characterization test suite

**Objective:** Fix the 11 remaining pre-existing test-content bugs (13 minus
the 2 retired by WP1) in the M0-era golden characterization suite. This work
package is scoped to test-content repair only — it does **not** change CI
policy (see WP5).

**Rationale:** `docs/testing.md`'s own "Known test-content issues" section
names this backlog explicitly. I re-ran the full integration suite directly
and confirmed the exact current state: **245 tests, 796 assertions, 4
errors, 7 failures, 2 skipped** — unchanged from what's documented, all
confined to five files (`test-fx-characterization.php`,
`test-movements-characterization.php`, `test-costing-characterization.php`,
`test-cost-adjustment-characterization.php`,
`test-batch-intake-characterization.php`), **zero failures in any M1–M7
milestone-specific suite.** Each root cause is already diagnosed in
`docs/testing.md`:

| File | Issue | Fix |
|---|---|---|
| FX (2 tests) | Fixture seeds column `rate_value`; real column is `exchange_rate`. `test_eur_to_eur_passthrough` asserts a bare float; `get_exchange_rate_to_eur()` returns an array. | Fix seed column name; fix assertion to read the `rate` key. |
| Movements (3 tests) | Fixture passes an `int` where `insert_purchase()`/`insert_purchase_batch()`/`insert_cost_adjustment()` expect an array. | Fix fixture call shape. |
| Costing (4 tests: 1 error + 3 failures) | Fixture/assertion values don't match current `Restock_Service`/costing behavior. | Investigate each against current, already-verified-correct production behavior; correct the test/fixture — never adjust production behavior to fit a stale test. |
| Cost Adjustment (2 tests) | Same category as Costing. | Same approach. |
| Batch Intake (2 tests, skipped) | **Not fixed here** — retired in WP1 because their subject method (`apply_batch_from_post()`) is deleted. | N/A |

**Architectural impact:** None — these are all confirmed test-code bugs
(wrong column name, wrong argument type, stale return-shape assumption), not
production defects. Per the M0.14 golden-fixture governance rule, any
expected-value change requires a citation; here the citation for every fix
is `docs/testing.md`'s own pre-existing diagnosis plus direct verification
against current, unmodified production code at implementation time — never
a change to what production code does.

**Implementation approach:** Fix each listed bug individually, verifying
against actual current production behavior (not just making the assertion
pass) before changing any expected value. Re-run the full integration suite
after each file's fix to confirm no new breakage.

**If a fix reveals a genuine production bug** (not a test-content bug), stop
and treat it as its own separately-justified fix outside this work package's
scope — do not fold a production-behavior change into a "test repair." The
milestone can pause after WP4 at that point without proceeding to WP5 if the
finding warrants wider review.

**Testing:** The fixed tests themselves are the testing; run the full suite
(`--testsuite integration`) locally after each fix.

**Acceptance criteria:** `--testsuite integration` reports 0 errors, 0
failures (skips only where a real, documented reason exists — none expected
after WP1/WP4). CI policy (making this step blocking) is explicitly out of
scope for WP4 — see WP5.

**Rollback:** Test-only changes; reverting is safe at any point.

---

### WP5 — Promote the integration suite to a CI-blocking gate

**Objective:** Once WP4 has made the full integration suite demonstrably
clean, remove `tests.yml`'s `continue-on-error: true` exception so it runs
as a normal blocking gate like the unit and M1–M8-focused suites.

**Rationale:** `docs/testing.md`'s own stated unblock condition: *"Removing
this exception... is a follow-up once the itemized issues below are
fixed."* Kept as its own work package, separate from WP4, specifically so
the milestone's two independent goals — repairing test content vs. changing
CI policy — aren't coupled. If WP4 turns up a genuine production bug
requiring more than a trivial fix, WP5 can be deferred to a follow-up
release without blocking the rest of M8, or without pressuring an
under-scoped fix just to flip a CI flag on schedule.

**Architectural impact:** None — CI configuration only.

**Implementation approach:**
1. Confirm WP4 is complete: `--testsuite integration` reports 0 errors, 0
   failures, across at least two consecutive clean runs (ruling out
   flakiness distinct from `Test_DB_Transaction`'s already-understood,
   harmless risky-but-passing behavior).
2. Remove `continue-on-error: true` from `.github/workflows/tests.yml`'s
   integration step.

**Testing:** The CI run itself, observed as a normal blocking gate on a test
PR/branch before merging to `main`.

**Acceptance criteria:** `tests.yml`'s integration step has no
`continue-on-error`; a deliberately-broken test in that suite fails the CI
check (spot-checked, then reverted).

**Rollback:** Config-only; trivial. If a previously-unseen flake surfaces
after promotion, re-add `continue-on-error: true` and investigate the flake
as its own bug — not necessarily a WP4 regression.

---

### WP6 — CI pipeline hardening (small, config-only fixes)

**Objective:** Close two concrete gaps found in the CI configuration itself.

**Rationale (both independently confirmed by direct inspection, not
speculative):**
1. `tests/integration/po-receiving/test-close-short-with-qty-received.php`
   (added during M5's own post-implementation audit remediation) does not
   match `run-phpunit.sh`'s blocking-filter regex — it only runs in the
   non-blocking `--testsuite integration` step. It currently passes, so this
   is a silent coverage gap, not a hidden failure: a future regression in
   `close_short()`'s `qty_received` handling would not be caught by the
   blocking gate.
2. The plugin already emits a live PHP 8.4 deprecation notice on every test
   run (`WC_Inventory_Overview_Suppliers::validate()`:
   `array $existing = null` should be `?array $existing = null`) — the only
   occurrence of this pattern in `includes/`, confirmed by direct grep.
   `tests.yml` runs PHP 8.4 while `ci.yml`/`release.yml` run PHP 8.2 —
   inconsistent, and worth aligning so lint/build exercises the same PHP
   version tests actually run under.

**Architectural impact:** None — configuration and a one-line type-hint fix.

**Implementation approach:**
1. Add `Test_WC_IO_Close_Short_With_Qty_Received` (or a pattern matching it)
   to `run-phpunit.sh`'s blocking filter regex, alongside WP3's new
   conformance guard.
2. Fix the single nullable-parameter type-hint
   (`array $existing = null` → `?array $existing = null`) in
   `class-wc-inventory-overview-suppliers.php`.
3. Align `ci.yml`/`release.yml` to the same PHP version `tests.yml` already
   exercises (8.4), so lint/build/release don't validate against a version
   nothing else in CI actually runs tests on.

**Testing:** Confirm the previously-uncovered test now appears in the
blocking-filter's matched test list; confirm the PHP 8.4 deprecation notice
no longer appears in test output; confirm lint/build still pass on the
aligned PHP version.

**Acceptance criteria:** Blocking filter includes the close-short test; zero
deprecation notices in a clean test run; all three workflows use one
consistent PHP version.

**Rollback:** Config-only; trivial revert.

**Explicitly excluded from WP6 (evaluated, not justified for M8):**
- Wiring up `.phpcs-baseline.xml` or making PHPCS CI-blocking — the codebase
  currently reports ~559 errors/634 warnings; closing that gap is a
  disproportionate, open-ended cleanup project relative to "smallest,
  cleanest milestone," not a hardening fix. Left as a documented, separate
  future initiative.
- Retiring the legacy `docker-compose.test.yml` full-stack harness — already
  correctly documented as "kept for manual reference only" in
  `docs/testing.md`; no current gap or risk it creates.
- Pinning `WC_VERSION` in `run-phpunit.sh` (WooCommerce is downloaded as
  `latest-stable`, unpinned) — a genuine reproducibility question, but with
  no concrete failure on record; flagged as worth a future look, not
  included as a work package on its own.

---

### WP7 — Public API conformance review (Inventory Position, Expected Delivery)

**Objective:** Audit the platform's two frozen public APIs against their
documented contracts, without adding capability.

**Rationale:** `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §7 names these as the
only two components with a formal public contract. "API hardening" for a GA
milestone means confirming the contract is honestly held, not extending it —
consistent with D16 and the explicit non-goal of REST/GraphQL expansion.

**Architectural impact:** None expected — this is a review pass. Any finding
gets a narrowly-scoped fix (e.g. a PHPDoc correction), never a contract
change.

**Implementation approach:**
1. Re-read `Inventory_Position_Service::get_position()`/`get_positions_bulk()`
   and `Expected_Delivery_Service::get_for_product()`/`get_for_products_bulk()`
   against `docs/api-expected-delivery.md` and baseline §7.1–7.2; confirm
   PHPDoc type-hints match actual signatures and return shapes exactly.
2. Grep the full codebase for any call site that branches on
   `Result::api_version()` — the interface's own contract says this must
   never happen; confirm zero hits.
3. Confirm both services' existing edge-case test coverage (empty input,
   deleted/missing product ID, negative/unmanaged stock — already tested per
   M7) is complete; add coverage only where a genuine gap is found, not
   speculative new cases.

**Testing:** Any newly-added edge-case test; otherwise this work package is
review-plus-documentation, verified by the existing suite continuing to pass
unchanged.

**Acceptance criteria:** PHPDoc for both public APIs is verified accurate;
zero `api_version()` runtime branches found anywhere; no behavior change.

**Rollback:** Documentation/PHPDoc-only changes are trivially revertible; any
test addition is test-only.

---

### WP8 — Documentation finalization, GA conformance sign-off, and release prep

**Objective:** Bring every process document up to the same per-milestone
standard M1–M7 established.

**Rationale:** `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §12 rule 7: "No
milestone plan ships without updating this document if it changes any fact
recorded here." Every one of WP1–WP7 changes a fact the baseline records
(Batch Intake code presence, PO_Delay behavior, guard-test inventory, CI
config, test counts). This work package is the closing pass that keeps the
project's own documentation-consistency discipline intact.

**Architectural impact:** None — documentation only.

**Implementation approach:**
1. **`CLAUDE.md`:** update the Implementation Status table's M8 row to
   `✅ Complete`, target release `1.25.0` (correcting the stale `2.0.0`
   placeholder), with a Notes column summarizing WP1–WP7.
2. **`docs/ARCHITECTURE_BASELINE_v1.24.0.md`:** update per its own §12 rule —
   add M8 to the completed-milestones table, note the removed Batch Intake
   code (§4/§8 no longer need to carve out "frozen but code-reachable"
   language), note the `PO_Delay` fix, note the new conformance guard.
   Recommend an in-place update rather than a new
   `ARCHITECTURE_BASELINE_v1.25.0.md`, since M8 changes no frozen boundary,
   only closes prior gaps.
3. **`docs/architecture-audit.md`:** add a `## Milestone M8 — Hardening & GA`
   section following the exact shape every prior milestone section uses
   (Status/Scope/component-by-component/Architecture guards/Testing),
   documenting the code removed, the predicate fixed, and the new guard.
4. **`docs/release-runbook.md`, `docs/checklists/validation-checklist.md`,
   `docs/rollback-plan.md`:** add M8 sections following the established
   per-milestone template (schema-version check — expect unchanged at 10;
   Batch Intake removal verified with no admin-visible change since it was
   already unreachable; `PO_Delay` fix verified on a real partially-received
   PO; full CI suite green including the now-blocking integration step).
5. **`docs/GITHUB_RELEASE_NOTES_1.25.0.md`, `CHANGELOG.md`, `readme.txt`:**
   standard release-note pass per `docs/release-runbook.md`'s existing
   process, summarizing WP1–WP7 as the "Hardening & GA" release.
6. **GA conformance sign-off:** record the result of all architecture guard
   tests (the six existing per-milestone files plus WP3's new one) and
   confirm the Release Readiness Gate below has been passed — recorded as
   the explicit "Version 1.0 / GA readiness" statement in the M8 section of
   `docs/architecture-audit.md`, not a separate new document.

**Testing:** N/A (documentation); the sign-off step re-runs existing test
suites, doesn't add new ones.

**Acceptance criteria:** Every cross-reference the baseline document's own
consistency rule requires is updated; `docs/architecture-audit.md` has an M8
section matching the established shape; release runbook/checklist/rollback
docs have M8 subsections; release notes exist and match CHANGELOG.md.

**Rollback:** Documentation-only; trivial.

---

## Release Readiness Gate

*(Not a work package — nothing is implemented here. This is pure
verification, run once after WP1–WP8 land, before tagging. Kept out of the
work-package list deliberately, so the milestone stays focused on changing
the repository rather than measuring it.)*

- **GA-scale performance confirmation:** re-confirm the platform's existing
  bounded-query invariants (D12's no-N+1 guarantee, Invariant M7-3) hold at a
  larger, GA-representative scale than their current ~20–40-item proof.
  Extend the existing query-count equality test pattern
  (`tests/integration/inventory-position/*performance*`,
  `tests/integration/expected-delivery/test-expected-delivery-performance.php`)
  to a larger fixture size (e.g. 200+ mixed simple/variable items), asserting
  the same query-count equality property already asserted at smaller scale —
  the same technique, not a new one. This is confirmatory only: no new
  caching or optimization work (explicitly out of M8's scope per the M7
  plan). If a regression is found, it becomes its own narrowly-scoped bug
  fix, justified on its own, not folded into this gate or into general
  performance work.
- Full test suite (unit + M1–M8-focused + integration) green, integration
  step blocking (WP5's outcome).
- All seven architecture guard test files (six existing + WP3's new one)
  pass.
- `DB_VERSION` confirmed unchanged at `10`; schema-shape assertion `ok: true`.
- Every prior milestone's validation-checklist item still holds (Quick
  Restock, Cost Adjustment, Goods Receipts, PO Receiving, Batch Migration
  CLI, Supplier admin, Inventory Position, Storefront Expected Delivery all
  function exactly as in v1.24.0).

Only once every item above passes does WP8's GA conformance sign-off get
written and the release proceeds to tagging.

---

## Risk assessment

| Risk | Work package | Mitigation |
|---|---|---|
| Deleting Batch Intake code breaks a still-needed test fixture path | WP1 | Fixture builder is rewritten and fully validated *before* any deletion; the four explicit deletion criteria are checked and recorded, not assumed |
| `PO_Delay` fix incorrectly flags/unflags an unrelated status | WP2 | Narrowly scoped predicate change with dedicated before/after tests; mirrors an already-proven M5 fix pattern |
| New sibling-coupling guard finds a real, unexpected violation | WP3 | Low probability (ADR-0003 already audited this by hand); if found, fix is a small, isolated removal, not a design change |
| "Fixing" a golden test papers over an actual production regression instead of a test bug | WP4 | Each fix requires verifying current production behavior directly before changing an expected value (M0.14 citation discipline); a genuine production finding pauses the milestone rather than being folded in |
| Promoting the integration suite to blocking before it's truly stable | WP5 | Gated on two consecutive clean runs, and only proceeds after WP4 is independently declared complete — not bundled into the same commit as the fixes themselves |
| CI PHP-version alignment surfaces a real 8.4-only incompatibility in lint/build | WP6 | Low risk (only one deprecation notice found repo-wide, already being fixed in the same work package) |
| Larger-scale performance check reveals a genuine O(n) query path | Release Readiness Gate | Treated as a real, narrowly-scoped bug fix if found — not a signal to open general caching work (explicitly out of scope) |
| Documentation drift across the many touched docs | WP8 | Single closing work package specifically to keep the baseline's own §12 consistency rule intact |

**Overall risk profile:** low. No schema change, no new domain concept, no
public API change. The highest-risk single action is WP1's code deletion,
and it's explicitly gated behind four independently-verified deletion
criteria with the historical-integrity golden suite as the ultimate go/no-go
check before any production code is removed.

---

## Release strategy

- **Version:** 1.25.0. `DB_VERSION` unchanged at `10` — the schema-shape
  assertion continues to assert v10; no `expected_schema_v11()` is added.
- **Sequencing:** WP1 → WP4 (WP4's test repair depends on WP1's fixture
  rewrite and batch-intake test retirement landing first) → WP5 (depends on
  WP4 being independently complete and stable) → WP2, WP3, WP6, WP7
  (independent of the above chain and of each other; can proceed in any
  order or in parallel) → Release Readiness Gate → WP8 (closing
  documentation pass, depends on everything above being done so it can
  document what actually happened and record the gate's result).
- **The milestone can stop after WP4** without proceeding to WP5 if WP4
  surfaces a production bug wide enough to need separate review — WP5 is a
  deliberate, independently-gated switch, not an automatic consequence of
  WP4.
- **Commits:** one logical commit per work package (or per natural sub-step
  within a work package), matching the discipline used in M1–M7 — never one
  monolithic commit.
- **CI gates before tagging:** unit suite green, M1–M8-focused blocking suite
  green, integration suite green and blocking (WP5), Release Readiness Gate
  fully passed.
- **Follow the existing `docs/release-runbook.md` process verbatim**: version
  bump, changelog, `docs/GITHUB_RELEASE_NOTES_1.25.0.md`, tag `v1.25.0`,
  deploy to dev.biopentra.eu, validation checklist, standard rollback
  awareness note (this release is code/test/CI-only — no data or schema
  written, so rollback is as clean as M6/M7's, arguably cleaner since even
  the one new setting-row precedent from M7 doesn't apply here).

## Validation strategy

Follow the same **operational validation pattern** every prior milestone's
`docs/checklists/validation-checklist.md` section uses, scoped to what M8
actually changes, plus the Release Readiness Gate above as the final,
scale/performance-focused check:

- Schema unchanged: `DB_VERSION` still `10`, schema-shape assertion `ok: true`.
- Batch Intake: confirm the tab/UI is exactly as absent as it was in v1.24.0
  (no visible change, since the code was already unreachable — this
  validates *nothing broke*, not a new behavior).
- `PO_Delay`: a real partially-received, past-due PO line now shows
  "Delayed" where it previously didn't.
- Full CI (unit + M1–M8-focused + integration) green, integration step now
  blocking.
- Quick Restock, Cost Adjustment, Goods Receipts, PO Receiving, Batch
  Migration CLI, Supplier admin, Inventory Position, Storefront Expected
  Delivery — all continue to function exactly as in v1.24.0 (the standard
  "unaffected" check every milestone's checklist runs).

## Definition of Done

- [ ] WP1: Batch Intake create/apply code physically deleted; all four
      deletion criteria verified and recorded; fixture builder rewritten and
      validated first; legacy data untouched.
- [ ] WP2: `PO_Delay` correctly flags delayed `partially_received` lines;
      dedicated tests pass.
- [ ] WP3: Sibling-plugin-coupling conformance guard added, passes with zero
      violations, wired into the CI-blocking filter.
- [ ] WP4: All 11 fixable golden-test bugs corrected (citations verified
      against real production behavior); 2 batch-intake tests retired via
      WP1.
- [ ] WP5: Integration suite promoted to a CI-blocking gate (verified stable
      across two clean runs first).
- [ ] WP6: Close-short test added to the blocking filter; PHP 8.4
      deprecation notice fixed; CI PHP versions aligned.
- [ ] WP7: Both public APIs' PHPDoc verified accurate; zero `api_version()`
      runtime branches confirmed.
- [ ] WP8: CLAUDE.md, architecture baseline, architecture-audit.md, release
      runbook, validation checklist, rollback plan, release notes,
      CHANGELOG, readme.txt all updated; GA conformance sign-off recorded.
- [ ] Release Readiness Gate passed: GA-scale performance confirmation, full
      suite green and blocking, all seven architecture guards pass,
      `DB_VERSION` unchanged, every prior milestone's checklist still holds.
- [ ] `DB_VERSION` confirmed unchanged at `10`; no new public API surface;
      no new domain concept.
- [ ] Tagged `v1.25.0`, GitHub Release published, deployed and validated on
      dev.biopentra.eu.

---

## Process note (per your instructions)

Once this plan is approved: create a feature branch, materialize this
document verbatim into `docs/milestones/m8-implementation-plan.md`, and
commit that document **by itself**, before any WP1–WP8 implementation work
begins.
