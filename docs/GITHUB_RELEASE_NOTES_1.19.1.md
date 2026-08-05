# WC Inventory Overview 1.19.1

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.19.0** (M2 Purchase Orders, schema v6→v7).

## What changed

**Test Infrastructure Hotfix** — test/CI infrastructure repair only. No database schema, migration, or business-behavior change. `DB_VERSION` remains **7**.

- Fixed the Docker PHPUnit harness (`tests/docker/run-phpunit.sh`) never installing the plugin's own Composer dev dependencies, which made every run fail before executing a single test.
- Fixed the WordPress test bootstrap not granting the `manage_woocommerce` capability to the test administrator user, which made all Purchase Orders admin-handler tests fail with "Insufficient permissions."
- Fixed the Docker Compose `phpunit` service's hardcoded `user: "1000:1000"`, which worked on the dev VPS but broke on GitHub Actions hosted runners (different checkout-directory owner) — surfaced the first time CI actually executed this harness.
- Removed an invalid `failOnDeprecated` attribute from `phpunit.xml.dist` (not valid for the installed PHPUnit 9.x schema).
- Added an explicit Docker Compose project name to avoid collisions with other ephemeral stacks on a shared host.
- GitHub Actions now actually executes the PHPUnit unit suite and the M2-focused suite (both blocking), plus the cumulative integration suite (visible, non-blocking pending known pre-existing test-content issues — see `docs/testing.md`).
- Corrected `tests/README.md` and `docs/testing.md` to document the test/CI setup as it actually exists, including the confirmed root cause of a known FX characterization test failure and WooCommerce's unpinned test version as a known limitation.

**Explicitly not in this release:**

- No Purchase Order or Supplier business-logic changes.
- No schema or migration changes.
- No admin UI or asset changes.
- No M3 (Inventory Position) functionality.

## Install / upgrade

1. Download **`wc-inventory-overview-1.19.1.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. `DB_VERSION` remains **7** — no schema migration runs on this upgrade.

## Before tagging (infrastructure-only release)

Per [docs/release-runbook.md](release-runbook.md): confirm CI (`lint` + `phpunit` unit/M2-focused suites) passes, confirm `DB_VERSION` unchanged at `7`, confirm no `includes/`/`cli/`/`assets/`/`scripts/` diff against v1.19.0.

## Rollback

Restore prior plugin folder from backup (**1.19.0**). No schema change to reverse — v1.19.0 and v1.19.1 share `DB_VERSION` 7. See [docs/rollback-plan.md](rollback-plan.md).

Changelog: [CHANGELOG.md](../CHANGELOG.md)
