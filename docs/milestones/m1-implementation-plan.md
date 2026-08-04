# Milestone M1 Implementation Plan — Suppliers (schema v6)

**Status: Complete — released as v1.18.0 on `main` (GitHub tag `v1.18.0` pending).**

**This file is the canonical M1 specification.** Architecture context for purchasing and incoming inventory: [`CLAUDE.md`](../../CLAUDE.md) Part I §1–§5 (decisions, invariants, entity model).

## Summary

Milestone M1 introduces the Supplier entity as the first first-class reference data, with an idempotent seed migration from historical supplier strings, a schema v6 database, a new Purchasing admin page shell, supplier autocomplete for the existing forms, and the schema-shape assertion mechanism required for all future schema-bumping milestones.

**Key constraints:**
- No Purchase Orders (M2+)
- No supplier_id backfills (M6)
- No hard-delete capability
- No modifications to existing batch or movement schemas
- All new features optional until schema assertion passes
- All autocomplete integration purely additive to existing forms

## Implementation Sequence (M1.21)

1. **CLAUDE.md + M1 plan** — already completed (CLAUDE.md root file created with Parts I–III + status index; M1 plan saved here)
2. **Database schema + assertion** — implement M1.5 + M1.16 (DB_VERSION '5'→'6', new `wc_io_suppliers` table, `assert_schema_shape()` method)
3. **Supplier service** — implement M1.7 (`WC_Inventory_Overview_Suppliers` class with full CRUD, normalization, validation)
4. **Migration** — implement M1.15 (`WC_Inventory_Overview_Suppliers_Migration` with deterministic tie-break seed algorithm)
5. **Purchasing page + list/detail** — implement M1.9 (`WC_Inventory_Overview_Purchasing_Page` controller, `WC_Inventory_Overview_Suppliers_List_Table`, detail form with archive/reactivate)
6. **Autocomplete + inline create** — implement M1.9 remaining (JS picker, AJAX handlers, form integration)
7. **Tests** — implement M1.14 (unit normalization, integration CRUD/migration/autocomplete/capabilities, schema assertion)
8. **Documentation** — implement M1.17 (architect-audit updates, admin guide, changelog, release-runbook additions)
9. **Quality gates** — execute M1.19 (PHPUnit, PHPCS, migration dry-run, regression verification)

## Files to Create

Core implementation:
- `includes/class-wc-inventory-overview-suppliers.php`
- `includes/class-wc-inventory-overview-suppliers-migration.php`
- `includes/class-wc-inventory-overview-suppliers-list-table.php`
- `includes/class-wc-inventory-overview-purchasing-page.php`
- `assets/supplier-picker.js`

Tests (M1.14):
- `tests/unit/suppliers/test-normalization.php`
- `tests/integration/suppliers/test-suppliers-crud.php`
- `tests/integration/suppliers/test-suppliers-migration.php`
- `tests/integration/suppliers/test-suppliers-autocomplete-ajax.php`
- `tests/integration/suppliers/test-suppliers-capabilities.php`
- `tests/integration/install/test-schema-shape-assertion.php`
- `tests/fixtures/suppliers/fixture-migration-*.php` (5 fixtures for tie-break scenarios)

Documentation:
- `docs/milestones/m1-implementation-plan.md` (this file)
- `docs/admin-guide-suppliers.md` (new)

## Files to Modify

- `includes/class-wc-inventory-overview-install.php` — DB_VERSION bump, `$suppliers` table DDL, `assert_schema_shape()` method, activate()/maybe_upgrade() orchestration
- `wc-inventory-overview.php` — add `require_once` for four new classes (grouping by responsibility)
- `includes/class-wc-inventory-overview-plugin.php` — `init()` line to bootstrap Purchasing_Page, enqueue supplier-picker.js in enqueue_restock_assets()
- `includes/class-wc-inventory-overview-batch-intake-ui.php` — additive picker markup next to existing `wc-io-batch-supplier` input
- `tests/includes/test-case.php` — additive `create_supplier()` helper
- `docs/architecture-audit.md` — DB tables table (add wc_io_suppliers row, bump v6), service/class map, AJAX actions, admin_post actions tables
- `CHANGELOG.md` — new `## [1.18.0]` entry (per Delivery Roadmap v1.0 M1 target release)
- `docs/release-runbook.md` — M1-specific section documenting seed-migration dry-run and schema-assertion checklist
- `docs/checklists/deployment-checklist.md` — new "Schema-bumping releases only" section with Q4/Q5 checks

## Key Technical Decisions (from M1 plan)

1. **DB schema**: `char(3)` for `default_currency` (literal §11.1 per spec, not `varchar(3)` matching other currency columns)
2. **DB schema**: `updated_at` never uses `ON UPDATE CURRENT_TIMESTAMP`; always explicitly set by PHP
3. **Service asymmetry**: `get()` returns `WP_Error` on miss; `get_by_normalized_name()` returns `null` (semantic correctness for hot paths)
4. **Normalization**: no punct stripping, no suffix stripping, no accent folding; only whitespace collapse + trim + casefold
5. **Migration tie-break**: (1) most-frequent original string; (2) earliest created_at; (3) alphabetical strcmp
6. **Schema gating**: Purchasing menu NOT registered if assertion fails; DB-version bump proceeds regardless
7. **New controller class**: `Purchasing_Page` is separate from `Plugin`, following audit's recommended godclass split
8. **Autocomplete mechanism**: dedicated JS + own nonce, not reliant on unverified WooCommerce core internals
9. **Legacy form integration**: purely additive pickup element with no `name` attribute; existing `$_POST` handling 100% untouched

## Golden Fixture Governance (M1.14)

The M0 golden test suite must remain completely unmodified. All M1 tests must pass this regression gate. Any failing golden test indicates an unintended behavioral change, never a fixture to be "fixed" by editing its expected value.

## Quality Gate Checklist (M1.19)

- [ ] Q1: Full PHPUnit suite green (M0 golden suite + M1 new tests)
- [ ] Q2: PHPCS clean (new files zero-violation, no baseline growth)
- [ ] Q3: `docker compose config` + deploy validation habit
- [ ] Q4: Schema-shape assertion passes on production-copy upgrade
- [ ] Q5: Seed migration verified on production copy (dry-run report reviewed)
- [ ] Q6: No data loss (batch/movement schemas byte-identical pre/post)
- [ ] Q7: Rollback tested (downgrade to pre-M1 build)
- [ ] Q8: Performance within budget, docs complete, manual QA walked through

## Definition of Done (M1.20)

- [ ] `wc_io_suppliers` table exists with all 12 columns + unique/status indexes
- [ ] DB_VERSION is '6'
- [ ] `assert_schema_shape()` returns true on correct schema, WP_Error with specifics on broken schema
- [ ] Purchasing submenu appears only when assertion passed, only for `manage_woocommerce` users
- [ ] Suppliers list/detail CRUD works end-to-end (create/read/update/archive/reactivate)
- [ ] No hard-delete capability exposed anywhere
- [ ] Batch Intake and Quick Restock gain additive picker + inline create (existing POST fields untouched)
- [ ] Seed migration is idempotent (run twice = identical result)
- [ ] Seed migration persists report to `wc_io_supplier_seed_migration_report` option
- [ ] `wc_io_supplier_created` action fires exactly once per create, post-commit, full-row payload
- [ ] All M1.14 tests pass (unit normalization, integration CRUD/migration/ajax/capabilities, schema assertion)
- [ ] M0 golden suite passes unmodified (zero fixture changes)
- [ ] PHPCS + PHP lint clean on new files
- [ ] Documentation complete (M1.17 a-f)
- [ ] All Q1–Q8 quality gates satisfied

## Next Steps

1. Implement the database schema and schema-shape assertion (M1.5 + M1.16)
2. Implement the Supplier service with validation and normalization (M1.7)
3. Implement the migration with the exact tie-break algorithm (M1.15)
4. Implement the Purchasing page controller and admin UI (M1.9)
5. Add tests for each component in parallel (M1.14)
6. Run Q1–Q8 gates and document results
7. Create logical commits and prepare for code review

---

**Note:** Future milestone implementation plans (M2+) will be added under `docs/milestones/` when each milestone is approved for planning or implementation.
