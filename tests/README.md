# Test Suite — Quick Start

This directory contains all test infrastructure and characterization tests for WC Inventory Overview.

No PHP, Composer, or MySQL is required on the host — everything runs through
Docker. All commands below are run from the **repository root**.

## Prerequisites

- Docker with the Compose plugin (`docker compose version`).
- Outbound internet access the *first* time you provision the test
  environment (downloads WordPress core, the WordPress PHPUnit test library,
  WooCommerce, Composer, and `yoast/phpunit-polyfills`; all are cached in a
  Docker-managed volume afterwards — see [Caching](#caching)).
- No published ports and no shared state with `apps/wordpress/` — this stack
  is fully disposable and isolated.

## Quick Start

```bash
# 1. Start the database (waits until healthy)
docker compose -f tests/docker/docker-compose.phpunit.yml up -d db

# 2. Run the tests (provisions WordPress/WooCommerce/Composer deps on first
#    run automatically, then runs the default M2-focused filter)
docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit

# 3. Tear down
docker compose -f tests/docker/docker-compose.phpunit.yml down -v
```

Step 2 is idempotent — provisioning (WordPress core, WooCommerce, the plugin's
own `vendor/` via Composer) is skipped on subsequent runs if already present
in the `phpunit-tmp` volume or in the host-mounted `vendor/`.

### Running a specific suite

```bash
docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite unit

docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite integration

# A subset, by test name:
docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --filter='Test_WC_IO_PO_Lifecycle'
```

The **default** run (no arguments) uses `run-phpunit.sh`'s built-in filter —
`Test_WC_IO_Schema_Assertion|Test_WC_IO_PO_|Test_WC_IO_Suppliers_|Test_DB_Transaction`
— the M2-focused suite plus schema/supplier smoke tests. This is the suite
expected to pass cleanly at all times.

### PHPCS

```bash
docker run --rm -u 1000:1000 -v "$(pwd):/plugin" -w /plugin wordpress:cli-2.12.0-php8.4 \
  php -d memory_limit=512M vendor/bin/phpcs --standard=phpcs.xml.dist
```

(Requires `vendor/` to already be populated — run the PHPUnit Quick Start
above first, or `docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit true`
to provision dependencies without running tests.) PHPCS reports a substantial
number of pre-existing violations; see
[docs/testing.md](../docs/testing.md#phpcs-status). It is not currently a CI
gate.

### Clean up

```bash
docker compose -f tests/docker/docker-compose.phpunit.yml down -v
```

`vendor/` is a host bind mount (owned by uid 1000, same as the container
user) and safe to `rm -rf` directly for a full reset; WordPress core,
WooCommerce, and the PHPUnit test library live only inside the `phpunit-tmp`
Docker volume and are removed by `down -v`.

## Caching

| What | Where | Downloaded by |
|---|---|---|
| WordPress core + `wordpress-develop` PHPUnit test library | `phpunit-tmp` Docker volume (`/tmp` in-container) | `tests/docker/run-phpunit.sh` |
| WooCommerce | same volume | `tests/docker/run-phpunit.sh` |
| `yoast/phpunit-polyfills` | same volume (ad hoc Composer project, not a repo dependency) | `tests/docker/run-phpunit.sh` |
| Plugin's own Composer dev dependencies (PHPUnit, PHPCS...) | `vendor/` (gitignored, host bind mount) | `tests/docker/run-phpunit.sh` via `composer install` |

## Structure

- **`bootstrap.php`** — Boots WordPress, WooCommerce, and the plugin for
  tests via the `muplugins_loaded` pattern, then grants the test
  administrator user the `manage_woocommerce` capability (WooCommerce's own
  activation routine never runs here, since the plugin is `require`d
  directly rather than activated through WordPress).
- **`includes/`** — Test helpers (base test case, fixtures, assertions).
- **`unit/`** — Pure-logic tests: DB-transaction helper, supplier
  normalization, and the M2 Purchase Orders unit suite.
- **`integration/`** — Full-integration characterization tests (M0 golden
  suite, M1 Suppliers, M2 schema assertion).
- **`fixtures/`** — Golden fixture data (frozen expected outputs).
- **`docker/`** — `docker-compose.phpunit.yml` (primary PHPUnit harness) and
  `run-phpunit.sh` (provisioning + default filter). `docker-compose.test.yml`
  and `seed.sh` are an older, non-functional full-WordPress-stack setup kept
  for manual/legacy reference only — do not use for automated validation.

## Key concepts

- **Golden fixtures** — Frozen records of current behavior. Never change a fixture value without citing the authorizing milestone/architecture/ADR.
- **Characterization tests** — Integration-level tests that verify the system behaves exactly as documented. These are the regression spine.
- **DB-transaction helper** — A simple wrapper around `wpdb` transactions, tested in isolation and wired into production use by M2 (Purchase Orders).

## Troubleshooting

**`Could not open input file: vendor/bin/phpunit`**
Should no longer occur — `run-phpunit.sh` now runs `composer install` for the
plugin's own dependencies automatically before invoking PHPUnit. If you see
this, check that the `phpunit` service's bind mount (`../../:/plugin`) is
intact and that `composer.lock` is committed.

**A characterization test fails**
Per the fixture-governance rule (see `docs/testing.md`): a failing golden
test is fixed by fixing the test or the code, **never** by editing the
expected fixture value, unless the change is authorized by a cited milestone,
architecture section, or ADR. Several pre-existing characterization tests
have known, documented issues predating this infrastructure work — see
[docs/testing.md](../docs/testing.md#known-test-content-issues) for the
current, itemized list; none of them are infrastructure problems, and none
involve M2 (Purchase Orders) code.

**`WordPress database error Table 'wp_test_txn_scratch' already exists`**
Harmless, pre-existing: `Test_DB_Transaction`'s `setUp()` doesn't drop its
scratch temp table between test methods within the same PHP process. Logged
by WordPress's error handler but does not fail any assertion (all
`Test_DB_Transaction` tests pass). Not fixed here — it doesn't block
reproducibility.

## For more details

See [docs/testing.md](../docs/testing.md) for the complete testing strategy, fixture governance, CI details, and known limitations.

---

**Remember:** If a test fails, the expected value in the fixture is the truth. Production code is what must change, never the fixture (unless a cited, approved change says otherwise).
