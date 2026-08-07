# Testing Strategy and Golden Fixtures

## Overview

WC Inventory Overview has two layers of automated testing:

1. **Infrastructure** (M0 deliverable): PHPUnit, PHPCS, CI/CD, and the DB-transaction helper.
2. **Behaviour Lock** (M0 deliverable): Characterization ("golden") tests that freeze current costing, FX, allocation, and movement behavior before any future changes.

Both layers are mandatory. Infrastructure without Behaviour Lock is an empty harness; Behaviour Lock without Infrastructure has nowhere to run.

## The Golden Fixture Governance Rule (M0.14)

This rule is **canonical** and appears in no other form — always refer back to this section for the authoritative statement.

> **A failing characterization test must never be resolved simply by changing its expected fixture value.**
> 
> Fixture expected values are the recorded truth about current production behavior. Expected values may change **only** when:
> 
> 1. An approved milestone in the [Delivery Roadmap](../CLAUDE.md) explicitly authorizes the change, **OR**
> 2. The change implements a specific section of the [Architecture v1.0](../CLAUDE.md), **OR**
> 3. An approved [Architecture Decision Record (ADR)](adr/) covers the deviation.
> 
> Any fixture diff carrying **none** of these citations is not reviewable and must be rejected outright, regardless of how small or plausible the change looks.

### Within M0 itself

Since M0 touches no production code, this rule has an absolute form:

**No fixture value may ever be adjusted during M0 implementation to make a test pass.** If a golden test fails during M0's own development, the test or the fixture-authoring is wrong, never the (untouched) production behavior it describes.

## Running tests locally

### Prerequisites

- Docker and Docker Compose (required for PHPUnit).
- For PHPCS: `composer install` in the plugin root.

### PHPUnit (Docker harness — primary)

M2+ uses the dedicated PHPUnit stack under `tests/docker/`:

```bash
cd tests/docker
docker compose -f docker-compose.phpunit.yml up -d db
docker compose -f docker-compose.phpunit.yml run --rm phpunit
```

The default run filters M1/M2/M3-focused suites plus schema/supplier smoke tests. Pass explicit PHPUnit args to override:

```bash
docker compose -f docker-compose.phpunit.yml run --rm phpunit --testsuite=unit
docker compose -f docker-compose.phpunit.yml run --rm phpunit --filter='Test_WC_IO_PO_Lifecycle'
docker compose -f docker-compose.phpunit.yml run --rm phpunit --filter='Test_WC_IO_Inventory_Position_'
```

Bootstrap script `tests/docker/run-phpunit.sh` downloads WordPress, WooCommerce, and PHPUnit polyfills into ephemeral container volumes.

### PHPUnit (legacy full-stack compose)

An older WordPress+WooCommerce compose file remains for manual integration work:

```bash
cd tests/docker
docker compose -f docker-compose.test.yml up -d
docker compose -f docker-compose.test.yml run --rm wordpress \
  wp plugin activate wc-inventory-overview
docker compose -f docker-compose.test.yml run --rm phpunit \
  /var/www/html/wp-content/plugins/wc-inventory-overview/vendor/bin/phpunit
```

### PHPCS linting (local)

```bash
cd wc-inventory-overview
composer install
./vendor/bin/phpcs --standard=phpcs.xml.dist
```

### Run specific test suite

```bash
# From tests/docker — M2 purchase-order unit tests only.
docker compose -f docker-compose.phpunit.yml run --rm phpunit --testsuite=unit --filter='Test_WC_IO_PO_'
```

## Extending the test suite

### Adding a new characterization scenario

1. **Create a fixture file** under `tests/fixtures/<area>/fixture-<scenario-name>.php`:
   ```php
   return array(
       'scenario_name' => 'my_scenario',
       'scenario_description' => 'What this tests',
       'setup' => array( /* initial state */ ),
       'operation' => array( /* what we do */ ),
       'expected' => array( /* what we expect */ ),
       'metadata' => array(
           'citation' => 'Part I §X or Roadmap §Y or ADR-001',
           'note' => 'Additional context',
       ),
   );
   ```

2. **Add a test method** in the corresponding integration test file (`tests/integration/<area>/test-<area>-characterization.php`):
   ```php
   /**
    * @test
    */
   public function test_my_scenario(): void {
       $fixture = WC_Inventory_Overview_Fixtures::load( 'area/fixture-my-scenario.php' );
       // Load fixture, set up state, call production code, assert outcome matches fixture.
   }
   ```

3. **Document the scenario's business purpose** in a comment above the test method.

### Fixture file conventions

- File names follow the pattern `fixture-<descriptive-name>.php`.
- Each file is a single `return array()` with keys:
  - `scenario_name` — kebab-case identifier (used in error reporting).
  - `scenario_description` — one-line English description.
  - `setup` — initial product/rate/batch state.
  - `operation` — the action being characterized.
  - `expected` — exact expected output values (never an epsilon range).
  - `metadata` — `citation` (required; the architecture section or roadmap milestone that mandates this behavior) and any additional `note`.

### Decimal precision

Golden tests compare formatted decimal strings, not floating-point values:

- **6 decimal places** for unit costs and averages (EUR).
- **4 decimal places** for stock quantities and inventory values.

This matches the plugin's own `wc_format_decimal()` precision throughout the codebase.

## Test structure

```
tests/
├── bootstrap.php              # Loads WP core, WooCommerce, plugin, and test helpers
├── includes/
│   ├── test-case.php         # Base test class (WC_Inventory_Overview_Test_Case)
│   ├── fixtures.php          # Fixture loader and helpers
│   └── assertions.php        # Custom domain-specific assertions
├── unit/
│   ├── db-transaction/       # Pure-logic tests (DB-transaction helper)
│   ├── purchase-orders/      # M2 PO lifecycle, numbering, service, validation, admin
│   ├── suppliers/            # Supplier value-normalization tests
│   ├── inventory-position/   # M3 Resolver unit tests + D12 architecture guards
│   ├── goods-receipt/        # M4 numbering, lifecycle, architecture guards (sole-mutator, throw_if_error bridge, no row locking, no po_line_id/qty_received, no PO writes)
│   └── po-receiving/         # M5 full INV-4 formula, PO_Statuses::recompute_for_receiving(), architecture guards (qty_received sole-mutator chain)
├── integration/
│   ├── costing/              # Weighted-average costing characterization
│   ├── exchange-rates/       # FX rate resolution characterization
│   ├── batch-intake/         # Batch preview/apply parity and rollback
│   ├── movements/            # Ledger record creation characterization
│   ├── cost-adjustment/      # Cost-adjustment (average/value without stock change)
│   ├── suppliers/            # M1 supplier CRUD, migration, admin PRG
│   ├── install/              # Schema-shape assertion (v6/v7/v8/v9)
│   ├── inventory-position/   # M3 open-line repository, Service, Inventory Overview list-table, query-scaling
│   ├── goods-receipt/        # M4 repositories, costing/allocation, Restock reversal, post/void transactional + rollback, idempotency, capability
│   └── po-receiving/         # M5 increment_qty_received(), PO_Receiving_Sync (apply_line_delta/reconcile_line), PO-linked post/void + rollback + void-order regressions, validation, M3 Incoming regression, performance
├── fixtures/
│   ├── costing/              # Golden fixture data
│   └── exchange-rates/
└── docker/
    ├── docker-compose.phpunit.yml   # Primary PHPUnit harness (M2+)
    ├── run-phpunit.sh               # Provisioning + default filter
    ├── docker-compose.test.yml      # Legacy full-WP-stack setup -- not functional
    │                                 # for automated validation, kept for manual reference only
    └── seed.sh                      # Legacy setup script for docker-compose.test.yml
```

## CI/CD Integration

GitHub Actions workflows:

| Workflow | Trigger | Gates |
|----------|---------|-------|
| `.github/workflows/ci.yml` | push/PR to `main` | PHP syntax lint on all `.php` files; release ZIP build via `scripts/build-zip.sh` |
| `.github/workflows/tests.yml` | push/PR to `main`, `develop` | `lint`: PHP Parallel Lint (blocking). `phpunit`: unit suite (blocking) + M2-focused suite (blocking) + cumulative integration suite (visible, non-blocking — see below). |

**PHPCS is not CI-gated today** — run locally before merge:
`./vendor/bin/phpcs --standard=phpcs.xml.dist` after `composer install` (or
see [tests/README.md](../tests/README.md#phpcs) for the Docker invocation
that doesn't require PHP on the host).

The `phpunit` CI job runs three PHPUnit invocations against the same
`tests/docker/docker-compose.phpunit.yml` stack a developer runs locally,
so CI and local results are identical by construction:

1. **Unit suite** (`--testsuite unit`) — must pass.
2. **M1/M2/M3/M4/M5-focused suite** (default filter: PO, schema assertion, suppliers, DB-transaction, Inventory Position, Goods Receipt) — must pass; this is the suite that gates M1–M5 changes specifically, now 290 tests / 1,305 assertions. M4 contributed 230 tests / 1,039 assertions across numbering, lifecycle, repositories, costing/allocation, Restock reversal, transactional post/void (including forced-failure rollback and the intervening-receipt void regression), idempotency, capability, and architecture guards. M5 added 60 tests / 266 assertions across the full INV-4 formula, the status-recompute function, `PO_Receiving_Sync` (both `apply_line_delta()` and `reconcile_line()`), PO-linked post/void (including the forced-failure rollback test and both mandatory intervening-receipt void regressions), pre-transaction validation, `Receipt_Lines` po_line_id persistence, the M3 Incoming regression, and architecture guards for the qty_received sole-mutator chain.
3. **Cumulative integration suite** (`--testsuite integration`) — executed and its full output kept visible in the Actions log, but marked `continue-on-error: true` so it doesn't block the job. This is a **temporary, documented exception**: the suite currently carries pre-existing failures in M0-era golden characterization tests (see [Known test-content issues](#known-test-content-issues) below), unrelated to M2 or to this infrastructure hotfix. The suite is never silently reported as green — its real pass/fail result and output remain visible, flagged with a warning in the Actions UI. Removing this exception (making the full suite blocking) is a follow-up once the itemized issues below are fixed.

The golden characterization suite remains the regression spine for costing/FX/allocation/movement behavior; M2 adds PO unit tests that must pass in the Docker harness.

## Debugging a failing test

1. **Check the test output** — the error message names the fixture and scenario.
2. **Inspect the fixture** (`tests/fixtures/<area>/fixture-<scenario>.php`).
3. **Verify the expected value** against Part I's documented behavior (costing formula, FX rules, etc.).
4. **Run the test in isolation** (`--filter='Test_Scenario_Name::test_method_name'`).
5. **Check recent commits** — did a change to production code alter the output?

If the production code changed intentionally (a new milestone), the fixture's expected value is the only place to update it — with the mandatory citation (roadmap milestone, architecture section, or ADR).

## Maintenance

- Fixtures are checked in alongside test files and reviewed in every PR.
- Fixture changes must cite the authorizing milestone/architecture/ADR (M0.14 rule).
- Characterization tests run forever, never scoped down or made optional.
- New milestones extend the suite but never delete prior milestones' tests (cumulative regression coverage).

## PHPCS status

PHPCS runs successfully (verified in the Test Infrastructure Hotfix, v1.19.1)
but is **not** a CI merge gate. Running it against this codebase currently
reports approximately 559 errors and 634 warnings across 47 files —
pre-existing violations that predate this fix. Because WordPress core and
WooCommerce are provisioned into a container-only Docker volume rather than
a host-visible directory under `tests/`, PHPCS never scans them; no
`phpcs.xml.dist` exclude pattern is needed for this on `main` (unlike some
Docker test-harness designs where a host-bind-mounted WordPress core tree
would need to be explicitly excluded).

A `.phpcs-baseline.xml` already exists in the repository root, dated
2026-08-03, describing a ratchet mechanism — but it is **empty**
(`<file></file>`) and **not referenced anywhere in `phpcs.xml.dist`**, so it
currently suppresses nothing. Standard PHP_CodeSniffer has no built-in
baseline-file feature (unlike e.g. PHPStan/ESLint); populating and wiring
this up would mean either adopting a third-party baseline tool or converting
the affected files into per-file `<exclude-pattern>` entries by hand. Making
the codebase PHPCS-clean, or actually implementing this baseline mechanism,
is out of scope for infrastructure repair and is left as a follow-up.

## Known harness limitations

- **WooCommerce version is not pinned.** `tests/docker/run-phpunit.sh`
  downloads WordPress core at a pinned version (`WP_VERSION`, default
  `6.7.2`), but WooCommerce is always downloaded as
  `woocommerce.latest-stable.zip`. This means the exact WooCommerce version
  under test can silently change between runs as new WooCommerce releases
  ship, independent of any change to this repository. Pre-existing (not
  introduced by the Test Infrastructure Hotfix); pinning `WC_VERSION` the
  same way `WP_VERSION` is pinned is a reasonable follow-up.

## Known test-content issues

Because the documented PHPUnit workflow could not run to completion until
the Test Infrastructure Hotfix (v1.19.1) fixed a missing `composer install`
step in `tests/docker/run-phpunit.sh`, several tests in the cumulative
integration suite had — as far as can be determined — never actually
executed successfully in CI or from a clean checkout. Running them for the
first time surfaced pre-existing defects in the *test code itself* (not in
production `includes/` code, and not in M2/Purchase-Orders code). Per this
document's own governance rule, fixing test-content bugs is not an
infrastructure change and is deliberately left to a future fix pass —
listed here so they are not mistaken for new regressions:

| Test | Symptom | Root cause |
|---|---|---|
| `Test_FX_Characterization::test_latest_before_date_no_interpolation` | Returns a `WP_Error` instead of the expected rate | The test's own seed step inserts the fixture row using column name `rate_value`, but the `wc_io_exchange_rates` table's actual column (per `class-wc-inventory-overview-install.php`) is `exchange_rate`. The insert silently matches no column, the seed row is never usable by the lookup, and the subsequent query finds no historical rate. Confirmed by reading the schema DDL directly; test-only, not a production defect. |
| `Test_FX_Characterization::test_eur_to_eur_passthrough` | Asserts `1.0 === $result` | `Exchange_Rates::get_exchange_rate_to_eur()` returns an array (`rate`/`source`/`rate_date`), not a bare float — the test was written against a different return shape than the shipped implementation. |
| `Test_Movements_Characterization` (3 tests) | `TypeError: Argument #1 ($r) must be of type array, int given` | Test fixture helper passes a product ID where the `Movements::insert_*()` methods expect an array. |
| `Test_Costing_Characterization` (4 tests) | `TypeError` / assertion mismatches | Fixture/assertion values don't match current `Restock_Service`/costing behavior; needs investigation independent of this milestone. |
| `Test_Cost_Adjustment_Characterization` (2 tests) | Assertion mismatches (average cost not updated) | Same category as above. |
| `Test_Batch_Intake_Characterization` (2 tests, skipped) | `Batch_Intake_Service class not found` | The actual class is `WC_Inventory_Overview_Batch_Intake_Service`; the test's guard check uses the unprefixed name. |

These are the same failure categories (same tests, same root causes) found
independently while auditing a separate, non-released M2 implementation
branch — since all of these tests live in the M0-era golden characterization
suite that predates the M2 fork entirely, this is strong evidence they are
pre-existing defects in shared test content, not regressions introduced by
M2 or by this infrastructure hotfix.

Separately, `Test_DB_Transaction`'s `setUp()` logs (but does not fail on) a
`WordPress database error: Table 'wp_test_txn_scratch' already exists`
warning on tests after the first, within the same PHPUnit process — harmless
and pre-existing; see [tests/README.md](../tests/README.md#troubleshooting).

The M1/M2/M3-focused suite (default `run-phpunit.sh` filter: PO lifecycle,
schema assertion, suppliers, DB-transaction, Inventory Position — 150 tests
as of M3/v1.20.0) and the full unit suite (97 tests, including the M3
Resolver/architecture-guard tests) pass cleanly under the fixed
infrastructure. The cumulative integration suite (66 tests, including the
31 M3 Inventory Position integration tests) has 53 passing and the same
4 errors + 7 failures + 2 skips itemized above, unchanged by M3 — see the
Test Infrastructure Hotfix milestone record for exact executed counts and
commands as of v1.19.1.

The M1–M4-focused suite (default `run-phpunit.sh` filter, now including the
`Test_WC_IO_Goods_Receipt_`/`Test_WC_IO_Goods_Receipts_`/`Test_WC_IO_Receipt_Lines_`/
`Test_WC_IO_Restock_Service_Reversal` prefixes) was 230 tests / 1,039
assertions as of M4/v1.21.0, all passing. The full cumulative suite (243
tests total) had the identical 4 errors + 7 failures + 2 skips itemized
above, unchanged in count and identity by M4 — M4 introduced zero new
failures anywhere in the suite.

The M1–M5-focused suite (default `run-phpunit.sh` filter — the same prefix
set already matches every new M5 test class, `Test_WC_IO_PO_Receiving_*`/
`Test_WC_IO_Goods_Receipt_Service_PO_Linked_*`/`Test_WC_IO_Receipt_Lines_PO_Line_Id`,
with no filter-regex change needed) is **290 tests / 1,305 assertions as of
M5/v1.22.0, all passing.** The full unit test suite (`--testsuite=unit`) is
148 tests / 773 assertions, all passing. The full integration test suite
(`--testsuite=integration`) is 155 tests / 543 assertions, with the
**identical 4 errors + 7 failures + 2 skips** itemized above — same tests,
same root causes, unchanged in count and identity by M5. M5 introduced
**zero new failures** anywhere in the suite.

**Post-release audit remediation** (same v1.22.0, pre-tag — four gaps an
independent audit found against the M5 plan's own Definition of Done, fixed
on this branch; see `docs/architecture-audit.md`'s "M5 audit remediation"
subsection): the focused suite grew to **292 tests / 1,317 assertions**, the
unit suite to **148 tests / 774 assertions**, and the integration suite to
**160 tests / 576 assertions** — the added tests are
`tests/integration/po-receiving/test-close-short-with-qty-received.php` (new
file, 3 tests) and two new methods in
`tests/integration/po-receiving/test-po-receiving-performance.php` proving
the pre-transaction PO-line validation query cost is now constant regardless
of line count, exercised at the plan's own named ~100-line scale. The
integration suite's **4 errors + 7 failures + 2 skips remain byte-for-byte
identical** to the baseline above — zero new failures. One pre-existing
architecture guard (`test_po_service_never_writes_receiving_status_or_qty_received`
in `tests/unit/purchase-orders/test-po-service.php`) was deliberately
narrowed from a blanket "never reference qty_received" substring check to a
"never write qty_received" check, since `close_short()`'s fix legitimately
reads an already-fetched line's `qty_received` to compute a correct
`qty_cancelled` — the guard still fails on any write attempt.

New M5 test directories: `tests/unit/po-receiving/` (full INV-4 formula,
`recompute_for_receiving()` direction-agnostic behavior, architecture guards
for the qty_received sole-mutator chain) and `tests/integration/po-receiving/`
(`increment_qty_received()` in isolation, `PO_Receiving_Sync` end-to-end for
both `apply_line_delta()` and `reconcile_line()`, PO-linked post/void
including the forced-failure rollback test and both mandatory intervening-
receipt regression scenarios — post-A/post-B/void-A and post-A/post-B/void-B/
void-A — pre-transaction validation, `Receipt_Lines` po_line_id persistence,
the M3 Incoming regression, and a linear-query-growth performance guard).

## See also

- [Architecture v1.0](../CLAUDE.md) — domain concepts, invariants, and decision history.
- [Delivery Roadmap v1.0](../CLAUDE.md) — milestone sequencing, dependencies, and quality gates.
- [Milestone M0 Implementation Plan](../CLAUDE.md) — M0.14 (fixture governance, canonical form).
