# Changelog — WC Inventory Overview

## [1.19.1] - 2026-08-05

**Test Infrastructure Hotfix** — test/CI infrastructure repair only. No database schema, migration, business-behavior, or UI changes. `DB_VERSION` remains `7`.

### Fixed

- `tests/docker/run-phpunit.sh` never ran `composer install` for the plugin's own `composer.json` (which already declares `phpunit/phpunit`, PHPCS, etc. as dev dependencies), so `vendor/bin/phpunit` never existed and every run failed immediately with `Could not open input file: vendor/bin/phpunit`. Added the missing install step.
- `tests/bootstrap.php` loads WooCommerce via a direct `require` rather than WordPress's normal `activate_plugin()` flow, so WooCommerce's own activation routine (which grants the `manage_woocommerce` capability to the administrator role) never ran. Every Purchase Orders admin-handler test failed with "Insufficient permissions." as a result. Added an explicit `WC_Install::create_roles()` call in the test bootstrap (standard practice in third-party WooCommerce extension test suites).
- `phpunit.xml.dist` declared `failOnDeprecated="true"`, an attribute that does not exist in any PHPUnit 9.x schema (confirmed against the installed `vendor/phpunit/phpunit/schema/*.xsd`); PHPUnit silently ignored it. Removed as a no-op cleanup.
- `tests/docker/docker-compose.phpunit.yml` had no explicit Compose project `name:`, defaulting to the generic directory name `docker` and risking collisions with other ephemeral stacks on a shared host. Added `name: wc-io-phpunit`. Also removed a stale, already-overridden `WP_TESTS_PHPUNIT_POLYFILLS_PATH` environment value that pointed at a path the provisioning script never actually uses.

### Added

- `.github/workflows/tests.yml` gained a `phpunit` job that runs the unit suite and the M2-focused suite (both blocking) plus the cumulative integration suite (visible in the Actions log, `continue-on-error: true` pending the known pre-existing test-content issues below — never silently reported as green).

### Documentation

- `tests/README.md` rewritten — it previously documented an old, non-functional `docker-compose.test.yml`/`seed.sh` workflow instead of the actual `docker-compose.phpunit.yml`/`run-phpunit.sh` harness.
- `docs/testing.md` updated: corrected CI/CD table, added PHPCS status section, added an itemized "Known test-content issues" section for pre-existing failures surfaced now that the suite can finally execute to completion (all in M0-era golden characterization tests -- costing, FX, movements, cost-adjustment, batch-intake -- none in M2/Purchase Orders code).

## [1.19.0] - 2026-08-05

**Milestone M2 — Purchase Orders** — PO aggregate, four-state lifecycle, events audit log, expected-receipt dates with confidence, delayed detection, Purchasing admin UI. Schema v7. **Prerequisite:** v1.18.1 (M1 Purchasing PRG hotfix). **No receiving** — stock and `qty_received` remain out of scope until M5.

### Added

- **Purchase Order aggregate** (schema v7): `wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_po_events` tables.
- **Four-state lifecycle:** `draft` → `placed` → `cancelled` | `closed_short`; terminal statuses (`cancelled`, `closed_short`) are absorbing.
- **PO numbering:** `PO-{YYYY}-{NNNN}` format, never reused; unique index on `po_number`. Concurrency model documented in ADR-0002.
- **PO Service** (`WC_Inventory_Overview_PO_Service`): transactional create, update, place, cancel, close short, duplicate, line CRUD; all mutations via explicit DB transactions.
- **PO events:** append-only audit log with optional reason codes (`supplier_change`, `price_change`, `quantity_change`, `schedule_change`, `manual`, `other`).
- **Expected receipt:** header and optional line-level `expected_date` + confidence (`exact` / `estimated` / `unknown`).
- **Delayed detection:** computed condition for placed POs past effective expected date + grace days (`WC_Inventory_Overview_PO_Delay`).
- **Purchasing → Purchase Orders admin tab:** list (status views, delayed filter, search), create/edit detail, event timeline, PRG `admin-post` handlers (save, place, cancel, close short, delete draft, duplicate) with nonces and request tokens.
- **Purchasing capabilities map** (`WC_Inventory_Overview_Purchasing_Caps`): filterable action→capability defaults (`manage_woocommerce`).
- **Assets:** `assets/purchasing.css`, `assets/po-admin.js` (product line editor on PO detail).

### Modified

- `DB_VERSION` '6' → '7'.
- `includes/class-wc-inventory-overview-install.php`: v7 DDL, `expected_schema_v7()`, canonical `wc_io_schema_assertion` option, forbidden-column guard (rejects `qty_received` on PO lines until M5).
- `includes/class-wc-inventory-overview-purchasing-page.php`: Purchase Orders tab (default), delegates PO panel to `PO_Admin`.
- `wc-inventory-overview.php`: require M2 PO classes (statuses, lifecycle, service, admin, list table, etc.).

### Technical

- New files: `includes/class-wc-inventory-overview-po-*.php`, `includes/class-wc-inventory-overview-purchase-orders.php`, `includes/class-wc-inventory-overview-purchase-order-lines.php`, `includes/class-wc-inventory-overview-purchase-orders-list-table.php`, `includes/class-wc-inventory-overview-purchasing-caps.php`, `assets/po-admin.js`, `assets/purchasing.css`, `docs/adr/0002-po-number-allocation-concurrency.md`, `docs/milestones/m2-implementation-plan.md`.
- Test files: `tests/unit/purchase-orders/test-po-*.php` (lifecycle, numbering, service, validation, delay, admin, architecture); extended `tests/integration/install/test-schema-shape-assertion.php`.
- Test harness: `tests/docker/docker-compose.phpunit.yml`, `tests/docker/run-phpunit.sh`.

### Notes

- **No stock or costing changes:** PO lifecycle actions do not mutate WooCommerce stock or weighted-average cost meta.
- **No receiving:** `qty_received`, Goods Receipt, and Receive-Against-PO arrive in M5; schema assertion actively forbids premature receiving columns.
- **No print hooks:** printable PO is a reserved future capability; no `wc_io_po_print_actions` or similar public hook added.
- **M0 golden suite:** passes unmodified; zero fixture changes to costing/FX/allocation/movement characterization tests.
- **Numbering concurrency:** duplicate-key failures under rare concurrent draft creates are a documented limitation (ADR-0002), not a correctness defect.

## [1.18.1] - 2026-08-05

**M1 hotfix** — supplier Purchasing admin PRG and list-table fixes. No schema change (remains v6).

### Fixed

- Supplier save / archive / reactivate admin-post handlers now call `wp_safe_redirect()` + `exit` (were incorrectly calling `wp_safe_remote_post()`, leaving a blank `admin-post.php` page).
- List-table Archive / Reactivate row actions use nonce-checked `admin-post` URLs and are handled by the same handlers (previously unnonced GET links that never routed).
- Active / Archived / All views from `get_views()` are rendered on the suppliers list.
- Create/update success redirect lands on the edit screen with a `saved` notice (removed dead identical ternary).
- Supplier `default_currency` validation accepts form values after `sanitize_key()` (uppercase EUR/USD/SEK).

### Technical

- Touched: `includes/class-wc-inventory-overview-purchasing-page.php`, `includes/class-wc-inventory-overview-suppliers-list-table.php`, `includes/class-wc-inventory-overview-suppliers.php`.
- Tests: `tests/integration/suppliers/test-suppliers-admin-prg.php`; `tests/includes/test-case.php` `flush_cache()` visibility for modern WP PHPUnit.

### Notes

- Patch release on the M1 baseline. Distinct from M2 / v1.19.0 Purchase Orders work.

## [1.18.0] - 2026-08-04

**Milestone M1 — Suppliers** — first-class supplier entity, Purchasing admin page (Suppliers section), seed migration from historical supplier strings, schema v6.

### Added

- **Supplier entity**: new `wc_io_suppliers` table with name, normalized-name (dedupe key), default currency, configured lead time, contact fields, status (active/archived).
- **Supplier service** (`WC_Inventory_Overview_Suppliers`): full CRUD, `get()`, `get_by_normalized_name()`, `list()`, `count()`, `create()`, `update()`, `archive()`, `reactivate()`. No hard-delete.
- **Supplier normalization**: whitespace collapse + trim + casefold only (no punctuation stripping, no suffix removal, no accent folding).
- **Schema-shape assertion** (`assert_schema_shape()`): generic mechanism checking table existence, column presence, unique index presence. Extends by milestone (e.g. M2 adds v7 assertion). Gating mechanism for new features.
- **Purchasing admin page** (new submenu under WooCommerce, uniform `manage_woocommerce` capability):
  - **Suppliers tab**: list with pagination, search, Active/Archived views; detail/create/edit with all §11.1 fields (name, currency, lead time, email, phone, supplier reference, note); archive/reactivate actions.
  - **Tab structure**: extensible for M2+ (Purchase Orders, Receive Stock tabs).
- **Idempotent seed migration** (`WC_Inventory_Overview_Suppliers_Migration`): distinct historical `supplier_name` strings from batches + movements → normalized → deduplicated supplier rows. Deterministic 3-step tie-break (most-frequent original string, earliest created_at, alphabetical strcmp). Persists report to `wc_io_supplier_seed_migration_report` option.
- **Supplier autocomplete**: dedicated JS + own nonce on Batch Intake's `wc-io-batch-supplier` and Quick Restock's `wc-io-supplier` inputs; "+ create supplier" inline quick-create affordance; AJAX handlers `wc_io_search_suppliers`, `wc_io_quick_create_supplier`.
- **Action hook**: `wc_io_supplier_created` (post-commit, full-row payload).

### Modified

- `DB_VERSION` '5' → '6' (first schema-bumping milestone).
- `includes/class-wc-inventory-overview-install.php`: `create_tables()` adds `wc_io_suppliers` DDL; `activate()`/`maybe_upgrade()` call `assert_schema_shape()` + conditional `Migration::run()`.
- `wc-inventory-overview.php`: require the four new classes (service, migration, list table, page controller).
- `includes/class-wc-inventory-overview-plugin.php`: `init()` instantiates Purchasing_Page; `enqueue_restock_assets()` enqueues supplier-picker.js + localized data.
- `includes/class-wc-inventory-overview-batch-intake-ui.php`: additive picker markup + quick-create modal.
- `tests/includes/test-case.php`: additive `create_supplier()` helper.
- `docs/architecture-audit.md`, `docs/release-runbook.md`, `docs/checklists/deployment-checklist.md`: M1-specific updates per §R6.

### Technical

- New files: `includes/class-wc-inventory-overview-suppliers.php`, `includes/class-wc-inventory-overview-suppliers-migration.php`, `includes/class-wc-inventory-overview-suppliers-list-table.php`, `includes/class-wc-inventory-overview-purchasing-page.php`, `assets/supplier-picker.js`, `docs/admin-guide-suppliers.md`.
- Test files: `tests/unit/suppliers/test-normalization.php`, `tests/integration/suppliers/test-suppliers-crud.php`, `tests/integration/suppliers/test-suppliers-migration.php`, `tests/integration/suppliers/test-suppliers-autocomplete-ajax.php`, `tests/integration/suppliers/test-suppliers-capabilities.php`, `tests/integration/install/test-schema-shape-assertion.php`, `tests/fixtures/suppliers/fixture-migration-*.php`.
- Test infrastructure: new test helpers in `tests/includes/test-case.php`.

### Notes

- **M0 golden suite regression**: Full M0 golden test suite (weighted-average, FX, allocation, movements, batch preview/apply) passes unmodified. Zero behavioral changes to existing costing/FX/allocation logic.
- **Backward compatibility**: Legacy free-text supplier fields in Batch Intake/Quick Restock remain unchanged; no `$_POST` handling modification. Supplier autocomplete is purely additive (zero-named select element).
- **No data loss**: Seed migration is idempotent (run twice = identical result); `wc_io_purchase_batches` and `wc_io_inventory_movements` tables untouched.
- **Purchasing menu** only appears when schema assertion passes and user has `manage_woocommerce` capability.

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
