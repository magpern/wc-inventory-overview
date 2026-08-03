# Test Suite — Quick Start

This directory contains all test infrastructure and characterization tests for WC Inventory Overview.

## Quick Start

### Run the full test suite (Docker-based)

```bash
cd tests/docker
docker compose -f docker-compose.test.yml up -d
docker compose -f docker-compose.test.yml run --rm phpunit \
  /var/www/html/wp-content/plugins/wc-inventory-overview/vendor/bin/phpunit
```

### Run PHPCS (local)

```bash
composer install
./vendor/bin/phpcs --standard=phpcs.xml.dist
```

### Clean up test containers

```bash
cd tests/docker
docker compose -f docker-compose.test.yml down
```

## Structure

- **`bootstrap.php`** — Boots WordPress, WooCommerce, and the plugin for tests.
- **`includes/`** — Test helpers (base test case, fixtures, assertions).
- **`unit/`** — Pure-logic tests (currently only DB-transaction helper).
- **`integration/`** — Full-integration characterization tests.
- **`fixtures/`** — Golden fixture data (frozen expected outputs).
- **`docker/`** — Ephemeral test environment configuration.

## Key concepts

- **Golden fixtures** — Frozen records of current behavior. Never change a fixture value without citing the authorizing milestone/architecture/ADR.
- **Characterization tests** — Integration-level tests that verify the system behaves exactly as documented. These are the regression spine.
- **DB-transaction helper** — A simple wrapper around `wpdb` transactions, tested in isolation and ready for M4 to wire into receipt posting.

## For more details

See [docs/testing.md](../docs/testing.md) for the complete testing strategy, fixture governance, and how to add new scenarios.

---

**Remember:** If a test fails, the expected value in the fixture is the truth. Production code is what must change, never the fixture.
