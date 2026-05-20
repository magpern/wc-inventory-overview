# Changelog — WC Inventory Overview

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
