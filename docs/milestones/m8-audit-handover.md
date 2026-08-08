# M8 Audit Handover — Hardening & GA (v1.25.0)

**Purpose:** direct an independent reviewer to the areas of `feature/m8-hardening-ga` that carry real architectural or regression risk, so audit time isn't spent re-verifying the ~80% of the branch that is mechanical (test-signature fixes, doc updates, CI config). Full narrative: `docs/architecture-audit.md`'s M8 section. Full implementation trace: git log on this branch (13 commits, one logical unit each).

---

## 1. Executive Summary

M8 is a hardening/cleanup milestone, not a feature milestone: it physically removed M6-deprecated dead code, fixed one computed-value bug (`PO_Delay`), added one repo-wide conformance test, repaired 11 pre-existing test-content bugs, and promoted the integration suite to CI-blocking. **Zero schema change, zero public API change, zero new domain concepts.** Net effect on production code is **−1,186 lines** (deletion-heavy). The two operations worth real audit attention are WP1 (the only change with genuine runtime-behavior risk, because it deletes code) and WP5 (a CI-policy change that now blocks every future PR on a suite that was previously advisory). Everything else is either additive-only tests or documentation.

---

## 2. Repository Baseline

| | |
|---|---|
| Branch | `feature/m8-hardening-ga`, 13 commits ahead of `main` |
| Base commit | `0d6257c` (`main`, unchanged by this branch) |
| Plugin version | 1.24.0 → **1.25.0** |
| `DB_VERSION` | **10 → 10 (unchanged)** |
| Working tree | Clean; branch unpushed, unmerged, untagged |
| Net production-code diff | 6 files touched, **+40 / −1,226 lines** (`includes/` + `wc-inventory-overview.php`) |

---

## 3. Work Packages Completed

- **WP1** — physically removed the M6-deprecated Batch Intake create/apply surface (fixture rewrite first, 4 deletion criteria verified)
- **WP2** — `PO_Delay` now flags `partially_received` POs as delayed (previously `placed`-only)
- **WP3** — new repo-wide sibling-plugin-coupling conformance guard
- **WP4** — 11 pre-existing golden-test bugs fixed (test-code only, zero production change)
- **WP5** — integration suite promoted from advisory to CI-blocking
- **WP6** — CI filter gap closed, one PHP 8.4 deprecation fixed, PHP version aligned across workflows
- **WP7** — public API PHPDoc audit (found + fixed 2 doc inaccuracies, zero code change)
- **Release Readiness Gate** — GA-scale (200-item) performance re-confirmation; full suite/guard/schema/regression/scope sweep, all green
- **WP8** — documentation and release-prep pass (CLAUDE.md, baseline doc, audit doc, runbook, checklists, rollback plan, CHANGELOG, readme.txt, release notes)

---

## 4. Highest-Risk Implementation Areas

### 4.1 WP1 — Batch Intake Retirement (highest risk: the only code deletion)

**What changed:** `WC_Inventory_Overview_Batch_Intake_Service` lost 5 methods + 7 private helpers (kept only 2 delegation shims); `WC_Inventory_Overview_Batch_Intake_UI` deleted entirely; `Plugin::ajax_batch_preview()`/`handle_batch_apply_post()` deleted; the `wc_io_batch_msg` admin-notice block and `RESTOCK_VIEW_BATCH` constant removed as downstream consequences.

**Why this is the risk center:**
- It's the only change that deletes reachable-in-principle code rather than adding tests or fixing a predicate.
- The deleted code's own test fixture (`create_legacy_batch()`) had to be rewritten to reproduce the same DB rows and stock/cost mutation *without* calling the deleted method — a reimplementation, not a refactor, and the one place a subtle divergence could hide.
- One existing architecture guard (`test_only_service_calls_restock_mutation_methods` in M4's guard file) had to be edited to remove a whitelist entry — auditors should confirm this narrowing is correct and not a weakening.

**What to verify independently:**
- Read `tests/includes/test-case.php`'s `create_legacy_batch()` (new version) side-by-side with the deleted `apply_batch_from_post()`/`build_preview_from_post()` (visible in the diff of commit `e8fa7df`) — confirm the weighted-average math, landed-cost allocation, and movement-note format are reproduced exactly, not approximated.
- Confirm the M6 historical-integrity golden tests (`tests/integration/batch-migration/test-batch-migration-historical-integrity.php`) still assert byte-for-byte stock/cost equality and actually ran against the *new* fixture builder (check the commit order: `14f3cfb` rewrites the fixture, `e8fa7df` deletes the code — in that order).
- Confirm no admin-post/AJAX hook anywhere still points at the deleted methods (`grep -rn "ajax_batch_preview\|handle_batch_apply_post" includes/` should return only the (removed) definitions — currently returns nothing).
- Confirm the legacy `wc_io_purchase_batches*` tables and rows are untouched — no `DROP`, no `TRUNCATE`, no data-shape change.

### 4.2 WP4 — Historical Test Repairs (risk: silently changing behavior instead of fixing tests)

**What changed:** 11 test files corrected across FX, Movements, Costing, and Cost Adjustment characterization suites — wrong class names, stale method signatures, wrong column names, a stale return-shape assumption.

**Why this needs a second look:** the golden-fixture governance rule (M0.14) explicitly forbids "fix the test by changing the expected value" without citation — the risk is that a fix quietly redefines correct behavior rather than correcting a test bug.

**What to verify independently:**
- For each fixed test, confirm the **expected value did not change** — only the mechanism reaching it (method signature, class name, column name, assertion target) changed. Diff commit `abd0b8b` file-by-file against the corresponding production class to confirm the "real" signature cited in each fix comment is actually what `includes/` defines.
- Spot-check `tests/integration/costing/test-costing-characterization.php` particularly — it had the most severe bug (a `class_exists('Restock_Service')` guard against an unprefixed class name that is *never* true, meaning the mutation call never ran and every downstream assertion checked untouched state). Confirm the fixed version's `WC_Inventory_Overview_Restock_Service` calls actually mutate and that the numeric expectations (weighted averages, stock deltas) were already correct pre-fix, not adjusted.

### 4.3 WP5 — CI Promotion (risk: this is a policy change with teeth, not just code)

**What changed:** `tests.yml`'s `continue-on-error: true` removed from the integration-suite step.

**Why this matters beyond the diff:** from this point forward, every future PR that touches anything the integration suite covers (costing, FX, movements, cost adjustment, all M1–M8 integration paths) will be blocked on a suite that was previously advisory. If the suite has any latent flakiness not caught by two local clean runs, this could produce false-red PRs.

**What to verify independently:**
- Confirm the claimed "two consecutive clean runs" actually happened (commit `1930638`'s message states the counts; auditor should re-run `docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite integration` at least once more independently).
- Confirm the `Test_DB_Transaction` "risky" classification (7 tests, a pre-existing `wp_test_txn_scratch` table-reuse artifact) does not escalate to a hard failure under this suite — it's currently only surfaced in the unit/focused suites, not integration; confirm this is still true.

---

## 5. New Architecture Guards

| Guard | File | Asserts |
|---|---|---|
| Repo-wide sibling-plugin coupling (**new in M8**) | `tests/unit/conformance/test-no-sibling-plugin-coupling.php` | Every `class_exists()`/`function_exists()` symbol in `includes/` is on a closed WordPress/WooCommerce/PHP-core allowlist; zero `remove_filter()`/`remove_action()` calls; zero hardcoded sibling-plugin identifiers |
| Stock/cost sole-mutator (**narrowed in M8**) | `tests/unit/goods-receipt/test-goods-receipt-architecture.php` | Now a literal single-caller assertion for `apply_purchase_line_change()`/`apply_purchase_line_reversal()` — the `Batch_Intake_Service` exception that existed since M4 is gone |
| `PO_Delay` truth table (**extended in M8**) | `tests/unit/purchase-orders/test-po-delay.php` | Adds `partially_received` (delayed) and `received` (never delayed) cases to the existing shared PHP/SQL-equivalence fixture |

Pre-existing guards (M2–M7, unmodified) are not relisted here — see `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §4 for the full table.

---

## 6. Scope Confirmations

| Claim | Evidence |
|---|---|
| **No schema change** | `git diff main...HEAD -- includes/class-wc-inventory-overview-install.php` is empty (0 lines) |
| **`DB_VERSION` unchanged** | `const DB_VERSION = '10'` — identical on both branches |
| **No public API change** | `class-wc-inventory-overview-inventory-position-service.php` and `class-wc-inventory-overview-expected-delivery-service.php` have zero diff on this branch — only their *documentation* was corrected |
| **No new settings** | `grep -E "add_action\(|add_filter\(|register_setting|OPTION_" ` on the full `includes/` diff returns nothing added |
| **No new domain concepts** | Confirmed by file-level scope: only `Batch_Intake_Service`, `Batch_Intake_UI` (deleted), `Plugin`, `PO_Delay`, `Suppliers` touched — no new class, no new table, no new concept introduced anywhere |

---

## 7. Exact Files That Deserve Manual Review

In priority order:

1. `tests/includes/test-case.php` — the rewritten `create_legacy_batch()` (§4.1)
2. `includes/class-wc-inventory-overview-batch-intake-service.php` — confirm the diff removes exactly what's claimed and nothing else survives unreachable
3. `includes/class-wc-inventory-overview-po-delay.php` — the delay-predicate status-gate change (both the PHP and SQL versions must agree)
4. `tests/unit/goods-receipt/test-goods-receipt-architecture.php` — the narrowed guard assertion
5. `.github/workflows/tests.yml` — confirm `continue-on-error` is actually gone, not just reworded
6. `tests/unit/conformance/test-no-sibling-plugin-coupling.php` — confirm the allowlist is genuinely closed (an attacker/careless future contributor could otherwise widen it silently)
7. `includes/class-wc-inventory-overview-plugin.php` — confirm the deleted notice-rendering block truly had no other trigger path

---

## 8. Questions the Auditor Should Explicitly Answer

1. Does `create_legacy_batch()`'s rewritten weighted-average and landed-cost-allocation math produce **identical** results to the deleted `apply_batch_from_post()` for every scenario the M6 migration suite exercises — not just the ones that happen to pass?
2. Is there any code path — admin, CLI, AJAX, cron, or a sibling-plugin integration — that could still reach the deleted Batch Intake methods, that a source-scan grep would miss (e.g., a dynamically-constructed method name, a `call_user_func` reference)?
3. Does the `PO_Delay` fix's exclusion of `received` (relying on `outstanding = 0` rather than an explicit status check) hold under every code path that writes `qty_received`, including the reconciliation CLI's `--fix` mode?
4. Are the two consecutive clean integration-suite runs cited in commit `1930638` sufficient evidence of stability, or should CI history be checked for intermittent failures on this suite going back further?
5. Does the sibling-plugin-coupling allowlist in `test-no-sibling-plugin-coupling.php` correctly distinguish "this plugin's own `WC_Inventory_Overview_*` classes" from "third-party symbols" in all cases, or could a future addition slip through as a false negative?
6. Is deferring the `class-wc-inventory-overview-plugin.php` god-class split past GA an acceptable risk posture, given it remains the single largest unreviewed surface in the codebase?
