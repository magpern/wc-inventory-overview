# Changelog — WC Inventory Overview

## [1.17.3] - 2026-08-03

**Milestone M0 — Delivery Foundations** — automated test suite infrastructure and characterization tests (zero functional changes).

### Added

- **Test infrastructure**: PHPUnit, PHPCS, GitHub Actions CI/CD workflow.
- **Docker-based test environment**: ephemeral WordPress+WooCommerce stack for isolated testing (`tests/docker/docker-compose.test.yml`).
- **Golden fixtures and characterization tests**: Frozen behavior specifications for weighted-average costing, FX resolution, landed-cost allocation, batch preview/apply parity, movement records, and cost adjustments.
- **DB-transaction helper**: Reusable database transaction wrapper with SAVEPOINT support (built in M0, integrated into M4+).
- **Release rehearsal templates**: Release runbook, deployment, rollback, and validation checklists (reused by every future release).
- **Test documentation**: Philosophy, fixture governance rule, running and extending tests.

### Technical

- `composer.json`, `composer.lock` — development dependencies (PHPUnit, PHPCS, WordPress coding standards).
- `phpunit.xml.dist`, `phpcs.xml.dist`, `.phpcs-baseline.xml` — test configurations.
- `.github/workflows/tests.yml` — CI workflow (PHPUnit + PHPCS + PHP Lint).
- `includes/class-wc-inventory-overview-db-transaction.php` — transaction helper (inert until M4).
- `docs/testing.md`, `docs/release-runbook.md`, `docs/checklists/` — documentation and reusable release templates.

### Notes

- **No plugin behavior changes** — version 1.17.3 functions identically to 1.17.2. Test infrastructure is a pure-tooling addition excluded from the release ZIP.
- The test suite, while comprehensive, is **not** part of the distributed plugin; it ships only in the GitHub repository.
- Golden fixtures lock current behavior as the regression baseline for all future milestones.

## [1.17.2] - 2026-05-19

**Standalone repository releases** — canonical GitHub home is [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview) with `v*` tags.

### Added

- `includes/class-github-updater.php` — queries this repo's GitHub Releases (`/releases/latest`); installs `wc-inventory-overview-X.Y.Z.zip` only.
- `.github/workflows/ci.yml` and `.github/workflows/release.yml`.
- Disable on dev: `WC_INVENTORY_OVERVIEW_DISABLE_GITHUB_UPDATER` or filter `wc_inventory_overview_github_updater_enabled`.

### Notes

- No intentional plugin behavior changes vs **1.17.1**.

## [1.17.1] - 2026-05-19

**Packaging-only release** — production ZIP and GitHub Release automation for the `biopentra-custom-plugins` monorepo. **No intentional plugin behavior changes** vs 1.17.0.

### Added

- Monorepo release tooling: `scripts/build-one-plugin-zip.sh`, `scripts/release-audit-plugin.sh`, `scripts/lib/verify-release-zip.py`.
- GitHub Actions workflow `.github/workflows/release-wc-inventory-overview.yml` (tag `wc-inventory-overview-v*`).
- Distribution files: `readme.txt`, `LICENSE`, this changelog.

### Changed

- Production ZIP excludes `cli/` and other dev-only paths; ships runtime code and `assets/` only.

### Notes

- WP-CLI maintenance scripts remain in the Git repository under `cli/` — not included in the distributed ZIP.
- Tag format: `wc-inventory-overview-v{version}`.

## [1.17.0]

Prior feature releases tracked in the WooCommerce project; see monorepo git history for `plugins/wc-inventory-overview/`.
