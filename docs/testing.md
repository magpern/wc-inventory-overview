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

The default run filters M2-focused suites plus schema/supplier smoke tests. Pass explicit PHPUnit args to override:

```bash
docker compose -f docker-compose.phpunit.yml run --rm phpunit --testsuite=unit
docker compose -f docker-compose.phpunit.yml run --rm phpunit --filter='Test_WC_IO_PO_Lifecycle'
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
│   ├── db-transaction/       # Pure-logic tests (DB-transaction helper only)
│   │   └── test-db-transaction.php
│   └── purchase-orders/      # M2 PO lifecycle, numbering, service, validation, admin
├── integration/
│   ├── costing/              # Weighted-average costing characterization
│   ├── exchange-rates/       # FX rate resolution characterization
│   ├── batch-intake/         # Batch preview/apply parity and rollback
│   ├── movements/            # Ledger record creation characterization
│   ├── cost-adjustment/      # Cost-adjustment (average/value without stock change)
│   ├── suppliers/              # M1 supplier CRUD, migration, admin PRG
│   └── install/                # Schema-shape assertion (v6/v7)
├── fixtures/
│   ├── costing/              # Golden fixture data
│   ├── exchange-rates/
│   ├── batch-intake/
│   ├── movements/
│   └── suppliers/
└── docker/
    ├── docker-compose.phpunit.yml   # M2+ primary PHPUnit harness
    ├── docker-compose.test.yml      # Legacy full WP stack
    ├── run-phpunit.sh               # Bootstrap + default filter
    ├── .env.test.example
    └── seed.sh                      # WordPress bootstrap script
```

## CI/CD Integration

GitHub Actions workflows:

| Workflow | Trigger | Gates |
|----------|---------|-------|
| `.github/workflows/ci.yml` | push/PR to `main` | PHP syntax lint on all `.php` files; release ZIP build via `scripts/build-zip.sh` |
| `.github/workflows/tests.yml` | push/PR to `main`, `develop` | PHP Parallel Lint via Composer (`parallel-lint`) |

**PHPUnit and PHPCS are not CI-gated today** — run locally before merge:

- **PHPUnit:** `tests/docker/docker-compose.phpunit.yml` (see above).
- **PHPCS:** `./vendor/bin/phpcs --standard=phpcs.xml.dist` after `composer install`.

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

## See also

- [Architecture v1.0](../CLAUDE.md) — domain concepts, invariants, and decision history.
- [Delivery Roadmap v1.0](../CLAUDE.md) — milestone sequencing, dependencies, and quality gates.
- [Milestone M0 Implementation Plan](../CLAUDE.md) — M0.14 (fixture governance, canonical form).
