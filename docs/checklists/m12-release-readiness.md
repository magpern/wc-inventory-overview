# M12 Release Readiness — Supplier List Performance Surface

**Status:** Level A freeze complete.  
**Date:** 2026-08-09  
**Branch tip (at freeze):** `feature/m12-supplier-list-performance`  
**CI proof PR:** https://github.com/magpern/wc-inventory-overview/pull/12 (**DRAFT — DO NOT MERGE**)

## Freeze record

| Item | Value |
|------|--------|
| M12 implementation | Complete |
| Level A completion review | Complete |
| Plugin development version | `1.29.0` |
| `DB_VERSION` | `10` (unchanged; no schema migration) |
| GitHub Actions | Green — Tests run `31338450692`, CI run `31338450699` |
| Schema change | None |
| Mutation change | None |
| New public API | None |
| Immutable plan | `docs/milestones/m12-implementation-plan.md` @ `7551859` — untouched after materialization |
| Frozen M9 tip | `e918757` — unchanged |
| Frozen M10 tip | `aa7e214` — unchanged |
| Frozen M11 tip | `d7574e8` — unchanged |
| Individually released | **No** — intentional |
| Feature-train composition | **M9 + M10 + M11 + M12** (+ CI recovery on base) |
| Next authorized process step | **FEATURE-TRAIN CLOSURE (WP6)** — do **not** start M13 |

## Level A completion review (focused)

Reviewed the M12 diff against `docs/milestones/m12-implementation-plan.md`:

- Scope matches: list columns only; reuses `get_stats_bulk()` + usability predicates.
- No scope creep into spend analysis, order history, merge, Settings UI, Position column, storefront, printable PO, etc.
- No schema / `DB_VERSION` change; no mutation path; no unexpected public API.
- No duplicated statistics formula in the list table; INV-M12-1/2 guarded.
- Exactly one `get_stats_bulk()` per `prepare_items()`; query tests assert **exact-query invariant** (one `observed_days` SQL per non-empty page at 10/40/200; zero on empty page) — matching the existing service contract.
- M9/M10/M11 frozen tips untouched; immutable M12 plan untouched after WP-M12-0.
- CI fully green on the draft PR.

## Explicit non-actions at this freeze

- Do not merge PR #12 into `main`
- Do not tag `v1.29.0`
- Do not publish a GitHub Release
- Do not deploy
- Do not perform the train-level WP6 audit in this session
- Do not start M13

## Local quality gates (pre-push)

| Gate | Result |
|------|--------|
| PHP Parallel Lint | Pass (165 files) |
| Composer validate | Pass (`./composer.json is valid`) |
| Docker Compose config | Pass |
| Unit suite | OK — 270 tests, 1604 assertions, 0 risky |
| M1–M12 focused suite | OK — 550 tests, 2701 assertions, 0 risky |
| Integration suite | OK — 291 tests, 1140 assertions, 0 risky |
| M12 arch + list filter | OK — 20 tests |
| `release-audit.sh --development` | Pass |
