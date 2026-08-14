# M22+M23 Feature Train — Combined Release-Readiness Review

**Status:** Reviewed — **APPROVED FOR RELEASE as v1.40.0**
**Date:** 2026-08-14
**Canonical train source:** `feature/m23-replenishment-defaults` @ `3932107f3f200f526ecedacad0f7b2ec87a8e056`
**Released baseline:** `main`/`v1.38.0` @ `7f300d556911960faa89d05d02fb8889c1a07992`
**Combined diff vs. main:** 38 files changed, 5169 insertions(+), 29 deletions(-)

This review composes, but does not re-litigate, two already-approved, already-frozen point-in-time specifications: `docs/milestones/m22-implementation-plan.md` and `docs/milestones/m23-implementation-plan.md` (both immutable, unmodified since their own WP-0 commits — verified: exactly one commit touches each file in the train's history). Their own per-milestone freeze checklists (`docs/checklists/m22-release-readiness.md`, `docs/checklists/m23-release-readiness.md`) remain the authoritative per-milestone evidence; this document verifies the two milestones compose correctly and finds nothing that invalidates either.

## A. Train ancestry/topology — VERIFIED

```
v1.38.0/main (7f300d55)
    └── M22 frozen tip (1716e323) — verified ancestor of M23 via git merge-base --is-ancestor
        └── M23 frozen tip (3932107) — canonical train head
```

`git merge-base --is-ancestor 7f300d55... 1716e323...` and `git merge-base --is-ancestor 1716e323... 3932107f...` both confirmed true. No missing or unrelated commits: `git diff --name-only main..M23` touches exactly the files each milestone's own plan/checklist documents (`install.php`, `list-table.php`, `plugin.php`, `po-admin.php`, `product-replenishment-admin.php` (new), `purchase-order-lines.php`, `reorder-prefill-service.php` (new in M22, modified in M23), `replenishment-defaults.php` (new), `suppliers.php`, `wc-inventory-overview.php`, plus test/doc files) — no unexplained production file appears in the diff.

## B. Scope coherence — VERIFIED

M21 → M22 → M23 form one coherent, incrementally-value-adding merchant workflow, not three unrelated features bundled by coincidence:

- **M21** classifies an item as `needs_reorder` (position ≤ effective low-stock threshold, net of incoming supply) — read-only, no action surface.
- **M22** turns that classification into an actionable "Create Draft PO" link, prefilling the New PO screen from the item's own committed purchase history — still merchant-reviewed before any PO exists.
- **M23** makes that prefill materially faster for repeat purchasing by letting the merchant pre-configure the two values M22 could only ever infer (supplier) or never supply at all (quantity) — without changing what M22 classifies, without creating a competing PO-creation path, and without removing the merchant's final review step.

Each milestone's own plan explicitly scoped itself against this progression (M22's plan deferred exactly M23's two features as "genuine open design questions"; M23's plan verified via exhaustive grep that neither concept existed before implementing it). No scope collision, no duplicated responsibility.

## C. Mutation ownership — VERIFIED

`grep -rl "Purchase_Orders::create_draft(\|Purchase_Order_Lines::create("  includes/` returns exactly one file: `class-wc-inventory-overview-po-service.php` — the sole caller, unmodified by either milestone. Neither M22 nor M23 introduces a second PO-creation path, a new admin-post handler, or a new AJAX mutation endpoint. M23's own mutation surface (product/variation configuration save) is entirely separate from — and never calls into — PO creation. Configuring a preferred supplier or default quantity cannot, by construction, create or place a PO; the only path to a PO remains explicit merchant submission through the unmodified `handle_save()` → `PO_Service::create_draft()` pipeline.

## D. Supplier semantics — VERIFIED

- M17 eligibility (`Suppliers::is_eligible_for_selection()` — active AND not merged) is the single predicate consulted everywhere in the train; no second eligibility rule exists in either milestone.
- A valid, currently-eligible M23 preference is used directly, and M22's committed-history query is skipped entirely (`test_valid_preferred_supplier_skips_history_query_entirely`).
- No preference configured → M22's original `resolve_supplier_from_history()` body runs byte-for-byte unchanged, including its measured query count (verified equal to the pre-M23 characterization baseline).
- A stale (archived/merged/deleted) preference falls back to that same M22 history algorithm, with an additional distinct notice — never silently discarded, never silently repointed at a merge target (`test_rendering_stale_preference_never_mutates_storage`, `test_preference_pointing_at_merge_source_falls_back_to_history`, `test_deleted_preferred_supplier_falls_back_to_history`).
- Archived/merged/nonexistent suppliers cannot become new selections at save time either (`test_save_archived_supplier_rejected`, `test_save_merged_supplier_rejected`, `test_save_nonexistent_supplier_rejected`), with one narrow, deliberate exception (resubmitting an already-stored now-stale value is a no-op, closing a silent-clobber hazard, not weakening eligibility).

## E. Quantity semantics — VERIFIED

`Replenishment_Defaults::save_default_qty()`/`get_default_qty()` and the `Reorder_Prefill_Service` integration point contain no reference to Position, on-hand, incoming, threshold, sales velocity, or lead time anywhere (grep-verified against both files). The configured value is used verbatim as `qty_ordered` (`(string) $default_qty`). No target-stock/par-level derivation, no forecasting, no EOQ, no safety-stock calculation exists anywhere in the train.

## F. Product/variation semantics — VERIFIED

Item identity is keyed exclusively on the concrete purchasable item's own post id (`$item_id = $variation_id > 0 ? $variation_id : $product_id`, identical to M22's own convention, reused verbatim in the M23 integration). `test_parent_and_variation_meta_are_independent` and `test_two_variations_of_one_parent_are_independent` confirm no inheritance in either direction. `test_variable_parent_defaults_never_consulted` confirms a variable parent's identity is rejected by the unmodified `PO_Product_Validator` before any M23 defaults are ever read, even if a value happens to be stored under the parent's post id via direct API misuse.

## G. M21 compatibility — VERIFIED

`Reorder_Signal_Resolver::resolve()` is untouched by both M22 and M23 (zero-line diff against `main`). `Inventory_Position_Service::get_position()` is likewise untouched. Both milestones only ever call these primitives, never reimplement them (grep-verified: no `position <= threshold`-shaped comparison and no `on_hand + incoming`-shaped arithmetic appears outside the two owning classes).

## H. Query/performance composition — VERIFIED (frozen evidence reused; no reason found to consider it stale)

- List-table (Inventory Overview) row rendering: M22's action link adds zero SQL (derived from the already-loaded product + already-computed `needs_reorder`); M23 touches no code that runs during list-table rendering at all (`list-table.php`'s diff against `main` is M22-only). Both M21's and M22's own list-table query-count regression suites were re-run unmodified during M23's own freeze (WP-M23-5) and stayed green.
- New-PO prefill (`Reorder_Prefill_Service::resolve()`'s `'prefilled'` branch): fixed, bounded, and invariant with respect to historical-supplier count on all three M23 preference branches (unconfigured/valid/stale), proven at 0/1/10/50-supplier scale; the unconfigured branch's measured total matches the pre-M23 M22-alone baseline exactly.
- No N+1 was introduced by composing M21's classification, M22's history lookup, and M23's preference check in sequence — each stage's query cost is independently bounded and none scales with the others' inputs.

## I. Security — VERIFIED

- M22's mutation endpoint (`handle_save()`) is byte-for-byte unmodified by M23 — same nonce, same `Purchasing_Caps::EDIT_PO` gate, same `PO_Product_Validator`/`PO_Validation` re-validation at submit time, regardless of what any prefill (M22-history-derived or M23-preference-derived) suggested.
- M23's own render/save capability parity is intact: both gate on WooCommerce core's `current_user_can( 'edit_product', $id )`, identical check at render and save, confirmed independent of `Purchasing_Caps` (`test_purchasing_caps_filter_has_no_effect_on_this_surface`).
- M23 uses WooCommerce's standard product/variation save lifecycle exactly as designed — no custom nonce, no custom AJAX handler.
- No new public hook/filter, no new capability constant, no new public API surface anywhere in the train (grep-verified against both new M23 files; M22's own architecture guards for the same properties remain green, unmodified).

## J. Data/schema — VERIFIED

`DB_VERSION` is `11` at the train head, unchanged from the released `v1.38.0` baseline (`git diff` against `install.php` shows a comment-only change). Neither milestone's diff touches `create_tables()` or `expected_schema_vN()`. M23's two new values are ordinary WordPress product/variation postmeta (`_wc_io_preferred_supplier_id`, `_wc_io_default_replenishment_qty`) — no custom table, no migration. **No database migration will run on deploy or on release.**

## K. Documentation — VERIFIED (audited and corrected where needed)

- `CHANGELOG.md`: both the `[1.39.0] - Unreleased` (M22) and `[1.40.0] - Unreleased` (M23) entries exist, correctly marked unreleased at the time they were written. This combined review does not retroactively edit them (see §L) — instead, release preparation (§6 of the release brief) adds the actual `v1.40.0` release documentation fresh, describing the bundled outcome, and post-release documentation (§15) will record the actual publication. No document claims `v1.39.0` was ever published as a standalone tag/release.
- `CLAUDE.md`'s Implementation Status table correctly shows both M22 and M23 as `🧊 Frozen — Unreleased` at versions 1.39.0/1.40.0 respectively — accurate at time of writing, to be updated post-release per §15.
- Rollback implications are correctly documented as code-only (no schema migration to reverse) in both milestone plans and will be exercised directly in the rollback rehearsal phase.

## L. Frozen plans — CONFIRMED IMMUTABLE

`docs/milestones/m22-implementation-plan.md` and `docs/milestones/m23-implementation-plan.md` are not modified by this review or by any subsequent release-preparation work. Verified via `git log --oneline -- <path>`: exactly one commit touches each file in the entire train history (its own WP-0 materialization commit).

## Findings

No CRITICAL findings.
No MAJOR findings.

**MINOR:** None requiring code change. The `readme.txt` `Stable tag` staleness (pre-existing since before M20, explicitly flagged and deliberately deferred by both M22 and M23's own plans) remains unresolved — carried forward as an existing, pre-existing, out-of-scope observation, not a train-introduced regression.

**OBSERVATION:** The combined train's total diff (5169 insertions across 38 files) is larger than any single prior milestone, as expected for a two-milestone bundle — consistent with the repo's established train-sizing precedent (M9–M12, M13–M15, M18–M19).

## Verdict

**APPROVED.** No finding blocks release. Proceeding directly to AI-driven combined runtime acceptance and release preparation.
