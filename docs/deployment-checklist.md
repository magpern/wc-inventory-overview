# Deployment checklist — WC Inventory Overview

Use when deploying from `magpern/wc-inventory-overview` to production (`wp-content/plugins/wc-inventory-overview/`).

**Do not** deploy untested ZIPs during active trading without a maintenance window.

---

## Pre-deploy

- [ ] Confirm target version in `wc-inventory-overview.php` header matches git tag / CHANGELOG
- [ ] Run `./scripts/build-zip.sh` from clean `main` checkout
- [ ] Run PHP lint (see README) — all files pass
- [ ] Verify ZIP: single root folder `wc-inventory-overview/`, no `.env`, `docs/`, or `scripts/` inside
- [ ] **Database backup:** `./wp db export` (or host backup)
- [ ] Note current active version: `./wp plugin get wc-inventory-overview --field=version`
- [ ] Staging smoke test (if available): activate ZIP, open each hub tab, save settings, preview batch (no apply)

---

## Deploy

Choose one:

### A. ZIP upload (WP Admin)

1. Plugins → Add New → Upload Plugin → `builds/wc-inventory-overview-{version}.zip`
2. Replace existing installation when prompted
3. Activate if needed

### B. Filesync (rsync)

```bash
rsync -a --delete \
  /path/to/wc-inventory-overview-repo/ \
  /home/magpern/woocommerce/wp-content/plugins/wc-inventory-overview/ \
  --exclude='.git' --exclude='docs' --exclude='scripts' --exclude='builds' \
  --exclude='README.md' --exclude='CHANGELOG.md' --exclude='.gitignore'
```

### Post-deploy commands

```bash
cd /home/magpern/woocommerce
./wp plugin list --name=wc-inventory-overview
./wp cache flush
```

Activation runs `WC_Inventory_Overview_Install::maybe_upgrade()` if `wc_io_db_version` lags.

---

## Post-deploy verification

- [ ] `./wp plugin is-active wc-inventory-overview`
- [ ] WooCommerce → **Inventory & Profit** loads (dashboard + overview tabs)
- [ ] Restock tab loads for `manage_woocommerce` user
- [ ] Spot-check movements list (no PHP errors in `docker compose logs wordpress`)
- [ ] Optional: inline stock save on one test SKU (staging first)
- [ ] Confirm HPOS still enabled if site uses custom order tables

---

## Rollback pointer

See [rollback-plan.md](rollback-plan.md). Keep previous ZIP in `builds/` or GitHub Release.

---

## Monorepo note

Until `biopentra-custom-plugins` stops tracking this plugin, treat **this repo as source of truth** for WC Inventory Overview and sync or remove the monorepo copy on a planned date to avoid drift.
