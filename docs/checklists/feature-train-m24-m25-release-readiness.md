# M24+M25 Feature Train — Combined Release-Readiness Review

**Status:** Reviewed — **APPROVED FOR RELEASE as v1.42.0**  
**Date:** 2026-08-15  
**Canonical train source:** `feature/m25-bulk-draft-po-creation` @ `0c483530fed60c666e224687ae8c353731b29da3`  
**Executable tip (last code change):** `cfd60d73da525ce1fe7e5e3a65b73ab373a1923e`  
**Released baseline:** `main` / `v1.40.0` @ `6965262bb035697c66427b6f907480042a03e5e6`  
**DB_VERSION:** 11 (unchanged)  
**Combined diff vs main:** 58 files changed, ~9456 insertions / ~66 deletions

This review composes, but does not re-litigate, the already-frozen point-in-time specifications and checklists:

- `docs/milestones/m24-implementation-plan.md` (immutable; first commit `8180465`)
- `docs/milestones/m25-implementation-plan.md` (immutable; sole commit `716875f`)
- `docs/checklists/m24-release-readiness.md`
- `docs/checklists/m25-release-readiness.md` (includes WP2 F-01…F-05 closure)

It verifies the two milestones compose correctly into one merchant workflow and finds nothing that invalidates either freeze.

## A. Train ancestry / topology — VERIFIED

```
v1.40.0 / main (6965262)
    └── M24 frozen tip (3c21a69) — draft PR #32, CI-green
        └── M25 frozen tip (0c48353) — draft PR #33, CI-green
            └── combined release → v1.42.0 (this train)
```

`git merge-base --is-ancestor` confirmed for main→M24 and M24→M25.  
`v1.41.0` exists only as M24's unpublished development version string in history — **never tagged**.  
No `v1.42.0` tag/release yet. Neither branch is merged into `main`. No M26 branch/plan/code.

Diff scope is M24 planning + M25 commit + accepted remediations + tests/docs/tooling (incl. M24 bulk-action hooks on `list-table` / `overview-controller`, CI filter updates). No unexplained production owners; no M26.

## B. M24 → M25 ownership — VERIFIED

| Layer | Owner | Mutation? |
|---|---|---|
| Plan rebuild | `Replenishment_Planning_Service::build_plan()` | **No** (read-only) |
| Preference rule | `Supplier_Preference_Resolver::decide()` | **No** (pure) |
| Bulk commit orchestrator | `Replenishment_Commit_Service::commit()` | Orchestrates only |
| Draft PO creation | `PO_Service::create_draft()` | **Sole** PO draft writer used by M25 |

`Purchase_Orders::create_draft()` remains called only from `PO_Service` (existing PO domain). M25 adds no second repository write path. Planning service has zero `$wpdb` writes / `create_draft` calls.

## C. M21 / M22 / M23 / M17 compatibility — VERIFIED

- Needs-reorder classification still flows through Summary → Reorder_Signal_Resolver (M21); M25 does not reimplement it.
- Position remains owned by `Inventory_Position_Service` (no duplicated Position arithmetic in M24/M25 new code).
- Supplier preference precedence is sole-owned by `Supplier_Preference_Resolver` (extracted in M24; M22 prefill delegates).
- M17 eligibility (`Suppliers::is_eligible_for_selection` / active+unmerged) remains authoritative via existing supplier lookups used by preference/history paths.
- No second preferred-supplier algorithm or eligibility predicate introduced by M25.

## D. M24 read-only guarantee — VERIFIED

Planning GET / catalog / scoped plan / unresolved rendering perform no PO/stock writes. M25 adds an EDIT_PO-only form on the same tab; form submission is `admin_post` only. GET/render cannot create POs.

## E. M25 server authority — VERIFIED

POST is authoritative only for selected identity + qty. Server rebuilds plan (`build_plan` once), derives supplier/currency/grouping, maps qty by canonical `item_post_id`, omits header currency so `create_draft()` derives supplier currency. Forged supplier/currency fields have zero effect (tested).

## F. Quantity / identity — VERIFIED

Canonical key: `variation_id > 0 ? variation_id : product_id`. Dedup before locks. Qty mapped by key, not array position (reordered-row test). Unchecked siblings discarded before validation. Selected invalid qty → PRG notice. No new rounding layer beyond existing float `qty_ordered` domain behavior.

## G. Item locking / concurrency — VERIFIED

`Replenishment_Item_Lock`: ascending ids, bounded timeout (5s), returns only acquired locks, commit skips non-acquired as `concurrent_commit_in_progress` (never unlocked processing), `try/finally` release. Dual-connection `IS_FREE_LOCK` Seam B proof present (WP2 F-02 closed). Locks only used on M25 commit path. **No claim** that non-M25 mutations participate.

## H. Conflicting open PO-line semantics — VERIFIED

Blocking statuses exactly: `draft`, `placed`, `partially_received`.  
History in `received` / `cancelled` / `closed_short` alone does not conflict-block. Fresh needs_reorder still governs eligibility. Exact simple (`variation_id=0`) / variation branches.

## I. Duplicate-prevention composition — VERIFIED

1. One-shot `PO_Request_Token` (context `replenishment_commit`)  
2. Item advisory locks across concurrent M25 commits  
3. Open/draft conflict check under lock before grouping  
4. Duplicate POST identities collapse to one canonical entry  

Not claimed: global duplicate detection for deliberate later reorders after conflict statuses clear.

## J. Partial-success semantics — VERIFIED

Seam A: A succeeds, B real `create_draft` fails (`wc_io_po_supplier_inactive`), C succeeds.  
Seam B: A durable after interrupt; B not attempted; locks free to independent connection.

## K. Residual TOCTOU — VERIFIED

`docs/checklists/m25-release-readiness.md` states accurately: M25 locks serialize other M25 commits on the same items; they do **not** serialize unrelated non-M25 paths; residual window bounded by measured commit wall-clock. No global-serialization overclaim.

## L. Operation limits — VERIFIED

`MAX_COMMIT_LINES = 100` (UI + admin + service).  
**MAX_COMMIT_GROUPS NOT REQUIRED** — freeze evidence 100/100 @ **1.31s**, 100 POs, 0 skips/fails.

## M. Performance — VERIFIED (frozen evidence reused)

Executable code unchanged since `cfd60d7`. Freeze matrix authoritative:

100/100 → created=100, POs=100, GET_LOCK=100, RELEASE_LOCK=100, queries=1317, wall≈1.31s.  
No skip-inflation; linear write growth expected; no per-line supplier/history/default lookup in orchestrator.

## N. Security — VERIFIED

Handler order: EDIT_PO → nonce → token → filter selected → validate → service.  
VIEW_PO-only and `edit_products`-only cannot commit. No new capability constant. No GET mutation.

## O. PRG / result isolation — VERIFIED

`wc_io_replen_result_{user_id}_{result_id}`; user id server-derived; result id `/^[0-9a-f]{12}$/`; read-once delete; refresh cannot recreate POs.

## P. Schema / version — VERIFIED

Version **1.42.0**, `DB_VERSION` **11**, install comment-only change. No migration.

## Q. Public API / scope — VERIFIED

Only intended `admin_post_wc_io_replenishment_commit`. No new public filters/hooks for extensibility. No automatic/scheduled purchasing, cost suggestion, expected-date invention, or M26.

## Findings

| Severity | Count | Notes |
|---|---:|---|
| CRITICAL | 0 | — |
| MAJOR | 0 | — |
| MINOR | 0 | — |
| OBSERVATION | 2 | (1) Live bind-mount already serves train HEAD as 1.42.0 on dev — release is git/main/tag publication, not a separate package install path. (2) Docs-only commits after `cfd60d7` do not change executable evidence. |

## Verdict

**APPROVED FOR RELEASE as v1.42.0.**  
Proceed to automated acceptance, release prep, single combined release PR, tag, GitHub Release, deploy verification, rollback rehearsal, and post-release docs. Do **not** publish `v1.41.0`. Close draft PRs #32 and #33 unmerged once the real release PR exists.
