# Migration guide: legacy Batch Intake → Goods Receipts

**Audience:** store operators/administrators running `wp wc-io migrate-batches` after upgrading to v1.23.0 (Milestone M6). This is an operational runbook, not a technical design document — see `docs/milestones/m6-implementation-plan.md` and `docs/architecture-audit.md` for the architecture.

---

## What this is

Before v1.23.0, this plugin had **two** ways to receive stock: the original "Batch Intake" feature, and the newer Goods Receipt engine (introduced in v1.21.0/M4). As of v1.23.0, Batch Intake can no longer create new batches — but every batch you created **before** upgrading still exists, unchanged, in its own tables. This tool copies that history into the current Goods Receipt schema so your entire receiving history lives in one place, without changing your current stock levels or costs in any way.

**The one thing to understand before you run anything:** migrating a batch does **not** re-receive it. Your stock and average cost already reflect every batch you ever applied — that happened when you originally clicked "Apply Batch," months or years ago. This tool only writes a historical record in today's format; it never touches current stock or cost. If you run it and check a product's stock/cost before and after, they will be identical.

---

## Before you start

1. **Take a database backup.** This plugin does not automate backups — that's your hosting/server responsibility. Standard practice: `wp db export` or your host's backup tool, before running `--apply`.
2. **Confirm the schema upgrade already happened.** Deploying v1.23.0 alone upgrades your database schema automatically (adds two tracking columns) — it does not migrate any data. Confirm with:
   ```bash
   wp option get wc_io_db_version
   ```
   This should read `10`. If it doesn't, the plugin hasn't finished its normal upgrade routine yet — check the deploy, don't proceed.
3. **Do this on a quiet moment**, not during a busy sales period, even though migration itself is safe and doesn't touch stock — it's still a database write operation.

---

## Step 1 — Preview (dry run)

Always start here. This makes **zero** writes:

```bash
wp wc-io migrate-batches
```

You'll see one line per batch that would be migrated, and a summary count. If it says "0 batches found, nothing to migrate," you either have no legacy batches, or they've already been migrated (see Step 3).

## Step 2 — Migrate

Once the preview looks right:

```bash
wp wc-io migrate-batches --apply
```

Each batch is migrated independently — if you have hundreds of batches, this may take a little while, but a failure partway through never leaves a half-written batch behind (each batch either fully migrates or doesn't; see "What happens if it's interrupted" below). Output looks like:

```
Batch #12 -> Goods Receipt GR-2023-0004 (#57): 3 line(s), 1 cost row(s), 3 movement(s) backfilled.
...
Success: Migrated: 48, Failed: 0.
```

**Useful options:**

- `--limit=<n>` — migrate at most `<n>` batches in this run (useful for a very large history, or to test on a handful first).
- `--batch=<id>` — migrate exactly one batch (useful for troubleshooting a specific failure).

**If "Failed" is not zero:** each failed batch is reported with the specific error. Nothing about a failed batch is written — it's still eligible, and re-running `--apply` will try it again. Common cause: extremely rare receipt-number collisions, which resolve themselves on retry.

## Step 3 — Verify

Confirm the migration produced exactly what it should, with zero writes:

```bash
wp wc-io migrate-batches --verify
```

Reports `Verified: N, Drift found: 0` when everything matches. This is also the tool to run **any time in the future** if you want to double-check migrated history hasn't drifted (e.g. after a database restore, or just for peace of mind) — it's permanent, not a one-time step.

---

## What "successful migration" looks like

- `wp wc-io migrate-batches` (no flags) reports 0 eligible batches remaining.
- `wp wc-io migrate-batches --verify` reports 0 drift.
- In the admin, **Inventory Movements** → your old batches' movement rows now link to a Goods Receipt instead of showing an unlinked note.
- **Goods Receipts** admin list shows your migrated batches, each with a reference like "Migrated from legacy Batch #12", a receipt number in the *year the original batch happened* (not this year), and status "Posted."
- Current stock and cost for every affected product are **exactly what they were before you ran anything.**

---

## Recovering from an interruption

If the migration process is killed, the server restarts, or the connection drops mid-run: nothing is corrupted. Each batch's migration is its own independent database transaction — a batch is either fully migrated (visible as a new Goods Receipt, with its `migrated_receipt_id` set) or not migrated at all (nothing written). Simply run `wp wc-io migrate-batches --apply` again; it automatically picks up exactly where it left off, because it only ever looks at batches that haven't been migrated yet. You will never get duplicate Goods Receipts from re-running this command.

---

## Undoing a mistake

**Undoing one batch's migration** (you migrated something you didn't mean to, or want to try a fresh migration after fixing something):

```bash
wp wc-io migrate-batches --rollback=<batch_id>
```

You'll be asked to confirm. This deletes that batch's migrated Goods Receipt (and its lines/landed-cost rows), restores its inventory movement rows to their pre-migration (unlinked) state, and clears the batch's migration tracking — making it eligible for `--apply` again. **It never touches current stock or cost.** This is not the same as "voiding" a receipt in the admin UI — migrated receipts can't be voided there on purpose, because voiding assumes a live receipt, not a historical record (see `docs/architecture-audit.md`, Milestone M6 section, for why).

**Undoing the whole plugin version** (rolling back to v1.22.0 after some batches are already migrated): this is safe without any special action — see `docs/rollback-plan.md`'s M6 section. Migrated Goods Receipts are simply invisible to v1.22.0 code; nothing needs to be reverted or cleaned up first.

---

## After migration: what changes for me day-to-day?

- **Batch Intake is gone from the admin UI.** Use Goods Receipts (Restock / Cost Adjustment → the receiving flow introduced in v1.21.0) for all new receiving going forward.
- **Your historical batch data is never deleted.** The original tables remain, permanently, as the audit trail behind every migrated receipt.
- **Nothing about Quick Restock or Cost Adjustment changes** — those are unaffected by any of this.

If anything here doesn't match what you see, stop and check `wp wc-io migrate-batches --verify`'s output before proceeding further, and consult `docs/architecture-audit.md`'s Milestone M6 section for the technical detail behind any specific field.
