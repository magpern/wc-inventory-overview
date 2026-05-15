# Rollback plan — WC Inventory Overview

---

## When to roll back

- PHP fatal errors on admin inventory pages after deploy
- Incorrect costing/movements after a bad release (prefer restore + code fix)
- Failed DB upgrade (`wc_io_db_version` mismatch symptoms)

---

## Plugin-only rollback (preferred)

1. **Deactivate** (optional, if site unstable):
   ```bash
   ./wp plugin deactivate wc-inventory-overview
   ```

2. **Install previous ZIP** from `builds/wc-inventory-overview-{previous}.zip` or GitHub Release.

3. **Activate:**
   ```bash
   ./wp plugin activate wc-inventory-overview
   ./wp cache flush
   ```

4. Verify admin hub and one report tab.

**Data:** Older plugin versions generally read the same custom tables. Downgrading across `DB_VERSION` bumps may leave schema newer than code expects — avoid downgrading across major DB version changes without DBA review.

---

## Database considerations

| Data | Rollback impact |
|------|-----------------|
| Custom tables `wc_io_*` | Retained; not removed on deactivate |
| Options `wc_io_*` | Retained |
| Order line snapshot meta | Retained on order items |
| Movement / batch history | Retained |

Plugin deactivation does **not** drop tables. Uninstall hook (if added later) should be documented separately.

---

## Full restore (catastrophic)

If deploy corrupted data or wrong danger-zone reset was applied:

1. Stop writes (maintenance mode if needed)
2. Restore MariaDB from pre-deploy dump
3. Restore `wp-content/plugins/wc-inventory-overview/` from known-good ZIP
4. `./wp cache flush`
5. Reconcile WooCommerce stock with physical inventory if movements were lost

---

## Danger zone mistake

If **Settings → Danger zone** delete was applied in error:

- Restore DB backup (only reliable recovery)
- Plugin cannot reconstruct deleted movement/batch rows from WC core alone

---

## Prevention

- Always export DB before deploy and before danger-zone operations
- Tag releases: `v1.17.0` matching plugin header
- Keep at least two ZIP versions in `builds/` or GitHub Releases
