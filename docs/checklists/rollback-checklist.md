# Rollback Checklist

**Template produced by M0 release rehearsal. Used by every release.**

Emergency procedure to revert to a prior release if issues are discovered post-deployment.

## When to rollback

Rollback immediately if:

- The site becomes inaccessible (503, 500, or timeout errors).
- Critical functionality is broken (e.g., admin screens do not load).
- Uncaught PHP errors appear in logs.
- Database queries fail or hang.
- Any symptom suggests the new code is incompatible with the running environment.

**Do NOT wait for a "better time."** Rollback is safe and quick; leaving a broken release in production is not.

## Rollback procedure

### 1. Determine the prior release tag

Identify the immediately previous release tag:

```bash
git tag -l | grep "^v" | sort -V | tail -2
```

(The last line is the current release; the second-to-last is the prior one.)

### 2. Stop the current release (safely)

```bash
# Check what's currently running:
git describe --tags

# If issues require immediate stopping, you can kill the container, but prefer reverting first.
```

### 3. Revert to the prior tag

```bash
cd /opt/biopentra/dev/wc-inventory-overview
git fetch origin
git checkout <prior-tag>  # e.g., v1.17.2
```

### 4. Redeploy

```bash
cd /opt/biopentra
docker compose config > /dev/null && echo "Config OK"
docker compose up -d
```

### 5. Verify rollback success

```bash
docker compose ps
docker compose logs --tail=30 wordpress
curl -sI https://dev.biopentra.eu
ss -tuln | grep LISTEN
```

All outputs should match the deployment checklist expectations.

### 6. Confirm the old release is active

```bash
git describe --tags
```

Should show the prior tag (e.g., `v1.17.2`), not the problematic release.

### 7. Post-rollback checks

Follow the [Validation Checklist](validation-checklist.md) to confirm functionality is restored.

- [ ] Site is accessible and responsive.
- [ ] No new errors in logs (or same errors as before the rollback).
- [ ] Public listeners are correct.
- [ ] WordPress admin interface loads.

## Database rollback

**For M0 and most future releases:** No database schema changes occur, so no rollback is needed beyond reverting the code.

**For milestones with schema changes or migrations:** A pre-deployment database backup (taken before deploy) may be needed. Refer to the milestone's implementation plan for database rollback procedures.

To restore a database backup (if needed):

```bash
# Locate the backup file (usually in /opt/biopentra/backups/).
ls -lh /opt/biopentra/backups/ | tail -5

# Restore via the existing Biopentra backup utilities (refer to /opt/biopentra/docs/ for your deployment's backup process).
```

## After rollback

1. **Do not attempt to redeploy the same tag immediately.** Investigate the root cause first.
2. **File an incident report** documenting what went wrong, when it was noticed, and how it was rolled back.
3. **Pause the release process** until the cause is understood and fixed.
4. **Create a new patch version** fixing the issue, and repeat the deployment and validation checklists.

## See also

- [Deployment Checklist](deployment-checklist.md)
- [Validation Checklist](validation-checklist.md)
