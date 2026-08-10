# Standard Milestone Lifecycle (v2)

**Status: adopted, effective M10 onward.** Governs sequencing, packaging, verification, and release safety for every milestone in this repository unless a specific milestone's own plan deliberately deviates and says so explicitly. Complements — does not replace — `docs/ARCHITECTURE_BASELINE_v1.24.0.md`'s architectural governance rules (§12) and CLAUDE.md Part II's original Delivery Roadmap, which this lifecycle supersedes for process/sequencing purposes from M10 forward.

**Why this exists:** M9 (Supplier Observed Lead-Time Statistics) was implemented, independently audited, and its one audit finding remediated, but the repository had no standing convention for what happens *after* remediation and *before* the next release — the "freeze" step was invented ad hoc for M9. This document makes that step, and the whole lifecycle around it, a permanent, named convention so it doesn't need to be re-derived per milestone.

## WP0 — Planning

1. Draft the milestone implementation plan.
2. Independent plan review.
3. Revise if necessary.
4. User approval.
5. Materialize the approved plan into `docs/milestones/mXX-implementation-plan.md` **before implementation begins**.

From this point forward the plan file is **immutable** (see Rule 1 below).

## WP1 — Implementation

- Implement the entire milestone.
- Stop only for genuine blockers — no silent scope compensation.
- No scope expansion beyond the approved plan.
- Commit logical work packages (one commit per WP, matching the plan's own WP breakdown).

## WP2 — Independent Audit

A **fresh** Claude instance (not the implementer, no shared context) performs the audit. It must:

- Distrust the implementation report — verify claims against the repository itself.
- Inspect the repository (git state, version consistency).
- Inspect architecture (sole-ownership, no unintended writes/coupling).
- Inspect tests (rerun them; confirm they test what they claim to).
- Inspect documentation (accuracy against actual code and actual repo state).
- Inspect release artifacts (ZIP contents, release notes, changelog).
- Classify every finding: **Critical / Major / Minor / Observations.**

## WP3 — Remediation

Fix only the audit findings.

- No feature work.
- No refactoring.
- No "while I'm here."

Finish with: clean working tree, updated documentation, an implementation/remediation report.

## WP4 — Freeze

Create `docs/checklists/mXX-release-readiness.md` recording:

- Implementation complete.
- Audit complete.
- Remediation complete.
- Repository frozen.
- Current release status.
- References to `docs/milestones/mXX-implementation-plan.md` and `docs/GITHUB_RELEASE_NOTES_mXX.md` (or the version they correspond to) — this document records *status*, it never duplicates the spec or the release notes.

**Never modify the implementation plan at this step.**

## WP5 — Continue Development

Unless this milestone changed architecture (see Release Triggers below), **stop here** and begin the next milestone's WP0. No release, no deployment, no tag, no GitHub Release yet.

**Current published baseline:** `main` / **`v1.29.0`** (M9–M12 feature train released). Historical train tip branch: `feature/m12-supplier-list-performance`. See `docs/checklists/feature-train-development-head.md` and `docs/checklists/feature-train-m9-m12-release-readiness.md`.

## WP6 — Feature Train Release

After several milestones have accumulated (for example M9–M11), run **one** release workflow covering all of them together: push, PR, CI, merge, tag, GitHub Release, deployment, operational validation.

---

## Permanent Repository Rules

**Rule 1 — `docs/milestones/` contains implementation specifications. Immutable. Never reused, never overwritten, never repurposed for a different task** (e.g. a freeze/readiness record) once a plan has been approved, materialized, and used for implementation.

**Rule 2 — `docs/checklists/` contains operational state. Mutable.** Freeze records, readiness records, deployment checklists, validation checklists.

**Rule 3 — `docs/GITHUB_RELEASE_NOTES_*.md` files are release artifacts** (pending or published), not implementation documentation. They are required at **WP6 release time** for the version being tagged — not at every intermediate feature-train development version. `scripts/release-audit.sh` encodes this:

- `scripts/release-audit.sh --development` — CI / feature-train validation (ZIP + version consistency; release notes optional).
- `scripts/release-audit.sh --release` — tagging / GitHub Release gate (release notes for the tagged version **required**).

**Rule 4 — `CHANGELOG.md` records development history.** It is acceptable for it to contain unreleased work.

**Rule 5 — Never mark something released, tagged, deployed, or published until it actually has been.** This is the exact class of error M9's audit caught (a premature "tagged and published" claim in `CLAUDE.md` while the branch was still unmerged, unpushed, and untagged) — treat it as a standing hazard to check for at every WP4 freeze, not a one-off fix.

## Release Triggers

Release **immediately** (skip the feature-train wait) only if a milestone introduces one of:

- Schema change
- Migration
- Public API change
- Ownership-boundary change
- Storefront behavior change
- Security fix
- Breaking change

Everything else joins the current feature train and waits for the next batched WP6 release.
