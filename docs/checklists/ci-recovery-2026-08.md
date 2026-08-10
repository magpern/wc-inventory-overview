# CI Recovery Record — 2026-08

**Branch:** `chore/ci-green-recovery` (from frozen `feature/m11-supplier-on-time-rate`)  
**Scope:** Test harness / CI / release-audit process only. No product behavior change. No M12.

## Original failures

| Source | Failure | Classification |
|--------|---------|----------------|
| GitHub Actions `Tests` / PHPUnit → unit suite | Exit non-zero: 7 risky `Test_DB_Transaction` tests (`failOnRisky=true`) — unexpected WordPress HTML output `Table 'wp_test_txn_scratch' already exists` | **C** test isolation / cleanup defect |
| Same workflow | Focused + integration steps skipped after unit failure | cascade of above |
| Local / docs | Repeated runs on long-lived MariaDB risk stale schema / dbDelta drift | **C** / **E** environment isolation (prevented; CI already used tmpfs + `down -v`) |
| `scripts/release-audit.sh` | Always required `docs/GITHUB_RELEASE_NOTES_{VERSION}.md` — fails on feature-train heads (e.g. 1.28.0) without notes | **F** release-process mismatch |
| Workflow naming | Step still labeled "M2-focused" while default filter is M1–M11 | hygiene / clarity (not a silent miss) |

No genuine production defect found. Filter audit: default regex discovers all intended M1–M11 classes; `Test_WC_IO_Expected_Deadline` (no trailing `_`) correctly matches both `Expected_Deadline` and `Expected_Deadline_Architecture`. M0 golden classes are intentionally only in the full integration suite.

## Root causes

1. **Risky tests:** MySQL `TEMPORARY` tables persist for the connection (one PHPUnit process). `setUp()` re-`CREATE`d without `DROP`, WordPress printed DB errors to stdout → PHPUnit risky → CI red.
2. **DB isolation:** WP bootstrap reinstalls tables each process, but a reused MariaDB container can accumulate plugin schema drift; CI was already tmpfs-backed, local long-lived containers were not equivalently reset.
3. **Release audit:** Assumed every development version ships a GitHub Release notes file; feature-train lifecycle (WP5 continue / WP6 batch release) makes that false until tag time.

## Fixes

- `tests/unit/db-transaction/test-db-transaction.php` — drop/recreate scratch table per method; force-rollback in `tearDown`.
- `tests/docker/run-phpunit.sh` — `DROP DATABASE` / `CREATE DATABASE` before each suite; filter comments for trailing-underscore trap.
- `tests/docker/docker-compose.phpunit.yml` — pass root password for reset.
- `scripts/release-audit.sh` — explicit `--development` / `--release` modes.
- `.github/workflows/{ci,tests,release}.yml` — development audit in CI; `--release` on tag; step rename.
- Docs: `docs/testing.md`, `tests/README.md`, `docs/process/milestone-lifecycle.md`, `docs/release-runbook.md`.

## Validation

### Local (identical sequence ×2, same long-lived db container, no manual cleanup)

| Gate | Run 1 | Run 2 |
|------|-------|-------|
| Unit (`--testsuite unit`) | OK 260 / 1574 | OK 260 / 1574 |
| M1–M11 focused (default filter) | OK 535 / 2632 | OK 535 / 2632 |
| Integration (`--testsuite integration`) | OK 286 / 1101 | OK 286 / 1101 |
| Risky / failures / errors | 0 | 0 |
| `composer validate` | valid | — |
| PHP parallel-lint (161 files) | 0 errors | — |
| `release-audit.sh --development` | pass (notes deferred) | — |
| `release-audit.sh --release` | correctly fails (missing 1.28.0 notes) | — |

### GitHub Actions

PR: https://github.com/magpern/wc-inventory-overview/pull/10

| Check | Result | Run |
|-------|--------|-----|
| PHP Parallel Lint | success | [Tests #31336625732](https://github.com/magpern/wc-inventory-overview/actions/runs/31336625732) |
| PHPUnit (unit 260/1574 + focused 535/2632 + integration 286/1101) | success | same |
| PHP lint and build ZIP + `release-audit --development` | success | [CI #31336625640](https://github.com/magpern/wc-inventory-overview/actions/runs/31336625640) |

**Verdict: all required GitHub Actions checks green.**

## Topology / integration

CI fixes landed on `chore/ci-green-recovery` branched from the frozen M11 tip so M9/M10/M11 freeze branch tips stay historically accurate.

**Integrated 2026-08-09:** canonical development head is now `feature/feature-train-m9-m11` (same commits as the CI-recovery tip; ancestry preserved). PR #10 was **closed without merging** into `main` — merging would have prematurely landed the unreleased M9–M11 train. See [`feature-train-development-head.md`](feature-train-development-head.md). M12 must branch from `feature/feature-train-m9-m11`.
