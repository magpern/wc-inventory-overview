# WC Inventory Overview

Operational inventory, costing, and profitability dashboard for WooCommerce (HPOS-compatible).

**Version:** 1.17.2 (see plugin header)  
**Releases:** https://github.com/magpern/wc-inventory-overview/releases (tag `v*`)  
**Requires:** WordPress 6.0+, PHP 7.4+, WooCommerce with HPOS supported

## Features

- Inventory overview with inline stock editing, filters, and CSV export
- Purchase batches, quick restock, and average-cost adjustments
- Inventory movement ledger
- Order profit and product profitability reporting
- Exchange rate history and multi-currency batch intake
- Dashboard charts (Chart.js bundled in `assets/vendor/`)

## Installation

1. Build or download `builds/wc-inventory-overview-{version}.zip`
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate **WC Inventory Overview**
4. Open **WooCommerce → Inventory & Profit**

Or deploy by copying the plugin folder to `wp-content/plugins/wc-inventory-overview/`.

## Build ZIP

```bash
./scripts/build-zip.sh
# Output: builds/wc-inventory-overview-1.17.0.zip
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

## Documentation

| Doc | Purpose |
|-----|---------|
| [docs/architecture-audit.md](docs/architecture-audit.md) | Code structure and data model |
| [docs/security-review.md](docs/security-review.md) | Security posture and findings |
| [docs/deployment-checklist.md](docs/deployment-checklist.md) | Production deploy steps |
| [docs/rollback-plan.md](docs/rollback-plan.md) | Rollback and data considerations |

## Repository

**Canonical Git:** `git@github.com:magpern/wc-inventory-overview.git` — production ZIPs and GitHub Actions releases live here.

Development copy also exists under `biopentra-custom-plugins/plugins/wc-inventory-overview/` (rsync mirror; **do not** tag `wc-inventory-overview-v*` on the monorepo).

## License

GPL-2.0-or-later (WordPress plugin).
