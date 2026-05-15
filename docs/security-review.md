# Security review — WC Inventory Overview 1.17.0

**Date:** 2026-05-15  
**Reviewer:** Automated architecture/security pass (standalone repo bootstrap)  
**Production changes:** None (documentation only)

---

## Executive summary

No **critical** vulnerabilities requiring immediate code changes were identified. The plugin is **admin-only**, uses WordPress nonces and WooCommerce capabilities consistently on mutating endpoints, and uses `$wpdb->prepare()` / `esc_like()` for custom SQL. Residual risks are operational (danger-zone data reset) and complexity-related (large SQL builders).

---

## Secrets and credentials

| Check | Result |
|-------|--------|
| API keys / tokens in source | **None found** |
| Hard-coded DB credentials | **None** (uses WordPress `$wpdb`) |
| `.env` in tree | **None** |
| Third-party service keys | **None** |

FX and costing data are stored in custom tables and `wc_io_*` options only.

---

## Direct file access

All PHP files under `includes/`, `cli/`, and main plugin file begin with:

```php
defined( 'ABSPATH' ) || exit;
```

**Pass.**

---

## Capability checks

| Surface | Capability |
|---------|------------|
| Menu / dashboard / overview | `edit_products` |
| Restock, movements, profit tabs, settings, batch/cost AJAX | `manage_woocommerce` |
| Inline stock save | `edit_products` + `current_user_can( 'edit_product', $id )` |
| CSV exports | `manage_woocommerce` |
| Legacy redirects | Matching tab capabilities |

Tab routing validates capability in `get_requested_tab()` and falls back to overview if denied.

**Pass** — aligns with WooCommerce admin roles.

---

## Nonces and CSRF

| Endpoint | Protection |
|----------|------------|
| `admin_post_wc_io_*` | `check_admin_referer()` with action-specific keys |
| AJAX handlers | `check_ajax_referer()` |
| CSV export | `check_admin_referer()` on export query args |
| Delete FX rate | `check_admin_referer( 'wc_io_delete_exchange_rate_' . $id, … )` |
| Danger reset apply | Preview token in transient + apply nonce |

**Pass.**

---

## AJAX permissions

All handlers registered as `wp_ajax_*` only (no `wp_ajax_nopriv_*`).

Each handler checks `current_user_can()` before processing.

**Pass.**

---

## Input handling (`$_GET` / `$_POST` / `$_REQUEST`)

Observed patterns:

- `sanitize_key`, `sanitize_text_field`, `absint`, `wc_stock_amount`, `wc_format_decimal` on inputs
- List table filters: sanitized before query/build
- Legacy redirect copies `$_GET` through `sanitize_key` / `sanitize_text_field` (arrays skipped)
- Danger reset: typed confirmation strings validated in service layer

**Low:** Some list tables read `$_REQUEST` directly for pagination/sort — values are sanitized before SQL/order use.

**No raw superglobals passed to SQL without prepare.**

---

## SQL injection

| Area | Assessment |
|------|------------|
| `Repository::filter_posts_clauses` | Uses `$wpdb->prepare()` for category ID and search `LIKE` with `esc_like()` |
| Movements / profit queries | Dynamic WHERE built with prepared placeholders in reviewed paths |
| Install `dbDelta` | Static schema strings |
| Data reset DELETEs | Scoped with prepared date bounds where applicable |

**Pass** with **medium complexity risk** — future edits to query builders should keep placeholder discipline.

---

## Output escaping

Admin UI uses `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` where appropriate. AJAX JSON responses use `wp_send_json_*`. Batch preview HTML built server-side in service — ensure any user-supplied notes remain escaped in templates (review when changing batch UI).

---

## File operations

| Operation | Context |
|-----------|---------|
| `fopen( 'php://output', 'w' )` | CSV export to browser only |
| No arbitrary file upload/write | **N/A** |
| ZIP build (repo script) | Out of band; not in plugin runtime |

**Pass.**

---

## Danger zone (data reset)

- Requires `manage_woocommerce`
- Two-step preview + apply with transient token (20 min TTL)
- Deletes plugin-owned rows/meta only (documented; does not delete WC orders/stock)

**Operational risk (high impact, authorized users only)** — not an auth bypass.

---

## CLI script

`cli/set-low-stock-threshold.php` runs only under WP-CLI with WooCommerce active. Not web-accessible. Modifies variation meta in bulk — run only by trusted operators.

---

## Dependencies

- Vendored **Chart.js** minified in `assets/vendor/chart.umd.min.js` — pin version in CHANGELOG when upgrading; scan for known CVEs on bump.

---

## Findings summary

| Severity | Finding | Action |
|----------|---------|--------|
| — | No critical issues | Document only |
| Low | God-class maintenance risk | Future refactor (see architecture audit) |
| Low | Broad `edit_products` on inline stock | Acceptable if intentional for shop managers |
| Medium | Complex dynamic SQL in reports | Add tests / code review on changes |
| Medium | Danger zone bulk delete | Restrict role; backup before use |

**No plugin code changes made** per instruction unless critical — none required.

---

## Recommended hardening (next steps)

1. Add GitHub Actions: `php -l`, secret scan (gitleaks), `build-zip.sh` artifact.
2. PHPCS with WordPress.Security ruleset on PRs.
3. Capability audit checklist in release process for any new `admin_post` / AJAX.
4. Document danger-zone procedure in ops runbook (backup DB first).
