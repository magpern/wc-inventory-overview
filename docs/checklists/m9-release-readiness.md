# M9 Release Readiness

**Point-in-time status record for Milestone M9 (Supplier Observed Lead-Time Statistics, v1.26.0).** This document records *current status* only — it is not the implementation specification and not the release notes. See [`docs/milestones/m9-implementation-plan.md`](../milestones/m9-implementation-plan.md) (immutable implementation specification) and [`docs/GITHUB_RELEASE_NOTES_1.26.0.md`](../GITHUB_RELEASE_NOTES_1.26.0.md) (pending release notes) for those.

## Status

- [x] **Implementation complete.** All work packages (WP1–WP6) delivered on `feature/m9-supplier-observed-lead-time`: `Supplier_Lead_Time_Service` (sole-owner, read-only, no N+1), architecture guard, admin UI panel, unit + integration tests (including the insertion-order-independence invariant), performance tests, and documentation updates.
- [x] **Independent audit complete and accepted.** A full independent audit re-verified repository state, architecture, SQL correctness, performance, UI, tests, and documentation directly against source and live test execution — not against prior implementation summaries.
- [x] **Audit remediation complete.** The audit's one finding — a false "tagged and published" claim for v1.26.0 in `CLAUDE.md`'s Release note — was corrected and committed.
- [x] **Release intentionally deferred.** Tagging, GitHub Release publication, and deployment are deliberately not performed at this time; v1.26.0 will ship as part of a future bundled release. This is a decision, not a blocker — nothing about M9 remains outstanding.
- [x] **Repository frozen in this state.** Working tree clean; branch not pushed, not merged, not tagged.
- [x] **Repository ready for M10.** No open M9 work remains; the next milestone may be planned and branched independently of when v1.26.0 is eventually tagged.

## Verified facts (at time of this record)

| Fact | Value |
|---|---|
| Branch | `feature/m9-supplier-observed-lead-time` |
| Plugin version | `1.26.0` (header + `WC_INVENTORY_OVERVIEW_VERSION` constant, consistent) |
| `DB_VERSION` | `10` (unchanged — no schema change in M9) |
| Working tree | Clean |
| Pushed to remote | No |
| Merged to `main` | No |
| Tagged | No (`v1.26.0` does not exist; latest tag is `v1.25.0`) |
| GitHub Release published | No |
| Deployed | No |

## Not part of this document

This record does not restate the implementation design (see the implementation plan) or the release content (see the release notes). It exists solely so a future reader can answer "is M9 done, audited, and safe to build on top of?" without needing to re-derive that from commit history — the answer is yes; only the release *event* itself is deferred.
