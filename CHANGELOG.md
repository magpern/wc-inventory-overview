# Changelog

All notable changes to **WC Inventory Overview** are documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.17.0] - 2026-05-15

### Added

- Initial standalone repository (`magpern/wc-inventory-overview`) split from `biopentra-custom-plugins`.
- Operational docs: architecture audit, security review, deployment checklist, rollback plan.
- `scripts/build-zip.sh` for versioned deploy ZIPs.

### Notes

- Plugin behavior unchanged from monorepo `plugins/wc-inventory-overview/` at version **1.17.0**.
- Custom DB tables: movements, purchase batches/lines/costs, exchange rates.
- HPOS compatibility declared via `FeaturesUtil::declare_compatibility( 'custom_order_tables', … )`.
