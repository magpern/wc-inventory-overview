# WC Inventory Overview 1.18.1

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## What changed

**M1 hotfix** (schema remains **v6**):

- Fix supplier Purchasing admin PRG: save / archive / reactivate now `wp_safe_redirect()` + `exit` instead of `wp_safe_remote_post()` (blank `admin-post.php`).
- Nonce-safe Archive / Reactivate list row actions.
- Render Active / Archived / All supplier list views.
- Accept uppercase currencies after `sanitize_key()` so the create/edit form can save EUR/USD/SEK.

No schema bump. No intentional changes to costing, FX, batches, or movements.

## Install / upgrade

1. Download **`wc-inventory-overview-1.18.1.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or replace the plugin folder from the ZIP.

## Rollback

Restore the **1.18.0** plugin folder/ZIP. No DB migration in this patch.

Changelog: [CHANGELOG.md](../CHANGELOG.md)
