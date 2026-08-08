# WC Inventory Overview

Purchasing, receiving, inventory-position, and storefront expected-delivery platform for WooCommerce (HPOS-compatible).

**Version:** 1.24.0 (see plugin header)  
**Releases:** https://github.com/magpern/wc-inventory-overview/releases (tag `v*`)  
**Requires:** WordPress 6.0+, PHP 7.4+, WooCommerce with HPOS supported
**Platform status:** Milestones M0–M7 complete (schema `DB_VERSION` 10) — see [`docs/ARCHITECTURE_BASELINE_v1.24.0.md`](docs/ARCHITECTURE_BASELINE_v1.24.0.md).

## Features

- Inventory overview with inline stock editing, filters, and CSV export
- Quick restock and average-cost adjustments
- Inventory movement ledger
- Order profit and product profitability reporting
- Exchange rate history and multi-currency purchasing
- Dashboard charts (Chart.js bundled in `assets/vendor/`)
- **Suppliers (M1):** WooCommerce → **Purchasing** submenu with first-class supplier entities (schema v6)
- **Purchase Orders (M2):** four-state PO lifecycle, PO numbering, expected dates/confidence, append-only event log (schema v7)
- **Inventory Position (M3):** read-only, derived `{On Hand, Incoming, Position}` with per-supply drill-down (no schema change)
- **Goods Receipts (M4):** the sole stock/cost mutation point — "Receive Stock" tab, Quick Receive Without PO (schema v8)
- **PO Receiving (M5):** receive directly against a Purchase Order; `qty_received` tracking with reconciliation CLI (schema v9)
- **Migration & Retirement (M6):** legacy Batch Intake history migrated into Goods Receipts; Batch Intake retired (schema v10)
- **Storefront Expected Delivery (M7):** customer-facing "Expected back around …" / "Expected during week …" / "Expected soon" text via a versioned public API (no schema change)

## Installation

1. Build or download `builds/wc-inventory-overview-{version}.zip`
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate **WC Inventory Overview**
4. Open **WooCommerce → Inventory & Profit** or **WooCommerce → Purchasing**

Or deploy by copying the plugin folder to `wp-content/plugins/wc-inventory-overview/`.

## Build ZIP

```bash
./scripts/build-zip.sh
# Output: builds/wc-inventory-overview-{version}.zip
```

The archive contains a single top-level folder `wc-inventory-overview/` suitable for WordPress uploads.

## Development

```bash
# Syntax check (Docker)
docker run --rm -v "$(pwd):/app" -w /app php:8.2-cli \
  sh -c 'for f in $(find . -name "*.php" -not -path "./docs/*"); do php -l "$f"; done'

# Optional WP-CLI maintenance script (run inside WooCommerce stack)
# See cli/set-low-stock-threshold.php
```

Automated tests: see [docs/testing.md](docs/testing.md) (PHPUnit, PHPCS, Docker-based integration).

## Documentation

| Doc | Purpose |
|-----|---------|
| [docs/ARCHITECTURE_BASELINE_v1.24.0.md](docs/ARCHITECTURE_BASELINE_v1.24.0.md) | Frozen post-M7 architecture baseline (start here for any new milestone) |
| [docs/OWNERSHIP.md](docs/OWNERSHIP.md) | Inbound vs outbound domain ownership |
| [docs/adr/](docs/adr/) | Architecture decision records |
| [docs/testing.md](docs/testing.md) | Test strategy and golden fixtures |
| [docs/architecture-audit.md](docs/architecture-audit.md) | Code structure and data model |
| [docs/security-review.md](docs/security-review.md) | Security posture and findings |
| [docs/deployment-checklist.md](docs/deployment-checklist.md) | Production deploy steps |
| [docs/rollback-plan.md](docs/rollback-plan.md) | Rollback and data considerations |

## Repository

**Canonical Git:** `git@github.com:magpern/wc-inventory-overview.git` — production ZIPs and GitHub Actions releases live here.

Development copy also exists under `biopentra-custom-plugins/plugins/wc-inventory-overview/` (rsync mirror; **do not** tag `wc-inventory-overview-v*` on the monorepo).

## License

GPL-2.0-or-later (WordPress plugin).
