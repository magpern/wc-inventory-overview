# Deployment Checklist

**Template produced by M0 release rehearsal. Used by every release.**

Verification steps to execute after deploying a new version to the dev VPS (dev.biopentra.eu).

## Pre-deployment

- [ ] Verify git tag exists and points to the intended commit.
- [ ] Backup the database (via the existing backup routine in `/opt/biopentra/backups/`).
- [ ] Note the start time of the deployment for log review later.

## Deployment

- [ ] SSH to the dev VPS as the deploying user.
- [ ] Navigate to `/opt/biopentra/dev/wc-inventory-overview`.
- [ ] Fetch and checkout the release tag:
  ```bash
  git fetch origin
  git checkout <tag>  # e.g., v1.18.0
  ```

- [ ] From the `/opt/biopentra` directory, validate Docker Compose configuration:
  ```bash
  docker compose config > /dev/null && echo "Config OK"
  ```

- [ ] Deploy the updated plugin container:
  ```bash
  docker compose up -d
  ```

- [ ] Verify all containers are running and healthy:
  ```bash
  docker compose ps
  ```
  (All services should show `Status: Up`.)

## Post-deployment validation

- [ ] Check logs for errors in the last few minutes:
  ```bash
  docker compose logs --tail=50 wordpress
  ```

- [ ] Verify the active public listeners are exactly 2222 (SSH), 80 (HTTP), 443 (HTTPS):
  ```bash
  ss -tuln | grep LISTEN | grep -E ':(2222|80|443)$'
  ```
  (Should show exactly 3 listeners; others suggest misconfiguration.)

- [ ] Test HTTP→HTTPS redirect and certificate validity:
  ```bash
  curl -sI https://dev.biopentra.eu
  ```
  (Should return 200 or 301/302 to HTTPS; certificate should be valid.)

- [ ] Verify the WordPress site is accessible and responds normally:
  ```bash
  curl -sI https://dev.biopentra.eu | head -5
  ```

- [ ] Check WordPress error log for PHP errors (if log file exists):
  ```bash
  # Inside the container:
  docker compose exec wordpress tail -20 /var/www/html/wp-content/debug.log
  ```

## Rollback preparation

- [ ] Save the deployment timestamp and git tag for reference.
- [ ] Note any anomalies or warnings for incident documentation (if any).
- [ ] If any issue is detected, proceed to the [Rollback Checklist](rollback-checklist.md) immediately.

## Post-deployment confirmation

- [ ] The site is accessible and responsive.
- [ ] No new errors in logs (or only expected info-level messages).
- [ ] Public listeners are correct (2222/80/443 only).
- [ ] Deployment time noted for future reference.

---

**If all checks pass**, proceed to the [Validation Checklist](validation-checklist.md) for functional verification.

**If any check fails**, do **NOT** proceed further. Follow the [Rollback Checklist](rollback-checklist.md) to revert.
