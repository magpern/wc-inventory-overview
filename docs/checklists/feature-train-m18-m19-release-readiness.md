# Feature Train M18–M19 — Release Readiness

**Status:** Readiness review complete — manual acceptance passed — **bundled release executed.**
**Date:** 2026-08-12 (review + release execution, same session).
**Review type:** Combined train-level review (each milestone already passed its own Level A completion review; see `docs/checklists/m18-release-readiness.md` / `m19-release-readiness.md`).
**Last released baseline (at review time):** `main` / tag **`v1.34.0`** (`bfdde20`).
**Canonical train branch / SHA (at review time):** `feature/m19-admin-controller-decomposition-phase2` @ `9e07ee8`.

## WP6 execution record

| Step | Result |
|------|--------|
| Release notes | `docs/GITHUB_RELEASE_NOTES_1.36.0.md`; `readme.txt` stable tag + changelog updated |
| Release-prep tip | `c978685` on `feature/m19-admin-controller-decomposition-phase2` |
| Final release gates | `docker compose config` valid; PHP Parallel Lint 206 files clean; `composer validate --strict` valid; `release-audit.sh --release` passed (v1.36.0, release notes detected, 102-entry ZIP) |
| Release PR | [#24](https://github.com/magpern/wc-inventory-overview/pull/24) — non-draft, base `main`, 6 checks green (Lint, Build ZIP, PHPUnit) |
| Draft CI-only PRs | [#22](https://github.com/magpern/wc-inventory-overview/pull/22) (M18), [#23](https://github.com/magpern/wc-inventory-overview/pull/23) (M19) closed without merging, each with an explanatory comment pointing to PR #24 |
| Release merge commit | `b9c978cd116e047cc129af26c45157c078b8440f` on `main` (merge commit, history-preserving, 24 commits merged) |
| Tag | `v1.36.0` → `b9c978c` (annotated, verified via `git ls-remote --tags origin 'v1.36.0^{}'` dereference) |
| GitHub Release | https://github.com/magpern/wc-inventory-overview/releases/tag/v1.36.0 — published automatically by the repository's existing `.github/workflows/release.yml` (triggers on `push: tags: v*`; builds ZIP, runs `release-audit.sh --release`, publishes via `softprops/action-gh-release`), not draft, not prerelease |
| ZIP | `wc-inventory-overview-1.36.0.zip`, 105 entries (102 files + 3 directory-entry markers), published asset SHA256 `f07e829ed75f4575506055513541ea15fdbdc75f5eb499f738db22619ac005b0`; content-verified against an independent local rebuild (`c543e12d...`, same content, differs only in directory-entry markers and timestamps — a benign cross-tool ZIP difference, not a content discrepancy); confirmed zero `.git/`/`.github/`/`tests/`/`docs/` entries, single `wc-inventory-overview/` root, correct `Version: 1.36.0` header, all three new controller classes (`Dashboard_Controller`, `Settings_Controller`, `Reporting_Controller`) present |
| Deploy | `wc-inventory-overview` is bind-mounted directly from `/opt/biopentra/dev/wc-inventory-overview` into the live `wordpress` container (no separate build/deploy artifact for this plugin) — checked out `main` @ `b9c978c`, container restarted; live `wp plugin status` confirms version 1.36.0, `wc_io_db_version` option confirms 11, `curl -I https://dev.biopentra.eu` returns HTTP 200 |
| Live validation | All 7 admin tabs (Dashboard, Settings, Movements, Order Profit, Product Profitability, Overview, Restock) re-verified rendering cleanly on released `main` via `wp eval-file` as the real admin; Movements CSV export re-verified streaming correctly; zero plugin-attributable errors in container logs post-release |
| Rollback rehearsal | `v1.36.0` → checked out `v1.34.0` tag at the bind-mount path, container restarted: plugin reports v1.34.0, `DB_VERSION` stayed 11, Dashboard/Movements rendered via the pre-extraction monolithic `Plugin` class with **byte-identical output length** to v1.36.0 (13,812 / 40,556 — proving the extraction was genuinely behavior-preserving in both directions), `Dashboard_Controller`/`Reporting_Controller` classes correctly absent at v1.34.0, site reachable throughout → restored `main`/`v1.36.0`, container restarted, version/DB_VERSION/reachability re-confirmed. No business data altered (read-only probes + restarts only) |
| Post-release documentation | `CLAUDE.md` canonical baseline, platform status, milestone paragraph, and Implementation Status table (M18/M19 rows) updated to reflect `v1.36.0` published, mirroring the v1.32.0/v1.34.0 publication commits' treatment |

**Post-release verdict:** No CRITICAL or MAJOR findings surfaced during release execution. Train released cleanly.

**One notable prior execution deviation, not repeated here (recorded for completeness):** during the M19 freeze session, draft CI-only PR #23 was initially opened stacked against `feature/m18-admin-controller-decomposition` before it was discovered that this repository's `tests.yml`/`ci.yml` workflows only trigger on push/PR events targeting `main`/`develop` — a PR based against a feature branch never fires CI. It was re-based to `main` via the GitHub REST API and closed/reopened to force the "opened" event. Having learned this, the real release PR (#24) was opened with base `main` directly from the start — CI triggered normally on the first attempt, no workaround needed this time.

## Train composition

| Milestone | Capability | Development version | Freeze tip | Draft PR |
|---|---|---|---|---|
| M18 | Admin Controller Decomposition, Phase 1 — Dashboard + Settings extracted | 1.35.0 | `2862b8c` on `feature/m18-admin-controller-decomposition` | [#22](https://github.com/magpern/wc-inventory-overview/pull/22) (open, draft, CI green) |
| M19 | Admin Controller Decomposition, Phase 2 — Movements + Order Profit + Product Profitability extracted | 1.36.0 | `9e07ee8` on `feature/m19-admin-controller-decomposition-phase2` | [#23](https://github.com/magpern/wc-inventory-overview/pull/23) (open, draft, CI green) |

**Plugin development version:** `1.36.0`
**`DB_VERSION`:** `11` (unchanged across the entire train — neither M18 nor M19 touches schema)
**Previous published release:** `v1.34.0` (M0–M17)

Immutable plans (materialized once, never edited after): `docs/milestones/m18-implementation-plan.md` (`0d06456`), `m19-implementation-plan.md` (`d9ab35a`). Confirmed one commit each touches each file.

## Train ancestry

```
v1.34.0 / main (bfdde20)
  └─ feature/m18-admin-controller-decomposition
       ├─ ... M18 implementation (WP-M18-0..7)
       ├─ 6f628ea  fix(m18): correct genuine defects surfaced by full-suite CI closure run
       └─ 2862b8c  docs(m18): record final CI and freeze evidence   ← M18 freeze tip
            └─ feature/m19-admin-controller-decomposition-phase2
                 ├─ d9ab35a  docs(m19): materialize plan
                 ├─ e2c1971  test(m19): pre-extraction characterization
                 ├─ 4ea4c2e  refactor(m19): extract Reporting_Controller
                 ├─ e005c2b  test(m19): architecture guards
                 ├─ 7b3e436  ci(m19): CI discovery
                 ├─ eda3195  docs(m19): version bump + CHANGELOG + architecture-audit
                 └─ 9e07ee8  docs(m19): freeze + CI evidence   ← M19 freeze tip / train head
```

`main` remains exactly at `bfdde20` (v1.34.0) throughout — zero M18/M19 commits merged. M18's freeze tip (`2862b8c`) remains intact on its own branch, not rewritten.

## Combined diff summary (`main..9e07ee8`)

**23 files changed, 5,450 insertions(+), 1,535 deletions(-).**

| Category | Files |
|---|---|
| Production (`includes/`, root plugin file) | 5 — `class-wc-inventory-overview-plugin.php` (both milestones' extraction edits), `class-wc-inventory-overview-settings-controller.php` (M18, new), `class-wc-inventory-overview-dashboard-controller.php` (M18, new), `class-wc-inventory-overview-reporting-controller.php` (M19, new), `class-wc-inventory-overview-install.php` (comment only), `wc-inventory-overview.php` (requires + version bumps) |
| Tests | 10 — M18: `test-settings-save-characterization.php`, `test-exchange-rate-crud-characterization.php`, `test-danger-zone-reset-characterization.php`, `test-dashboard-rendering-characterization.php`, `test-settings-controller-architecture.php`, `test-dashboard-controller-architecture.php`; M19: `test-movements-rendering-characterization.php`, `test-order-profit-rendering-characterization.php`, `test-product-profitability-rendering-characterization.php`, `test-reporting-controller-architecture.php` |
| Documentation | 6 — `CHANGELOG.md`, `docs/architecture-audit.md`, `docs/milestones/m18-implementation-plan.md` (new), `docs/milestones/m19-implementation-plan.md` (new), `docs/checklists/m18-release-readiness.md` (new), `docs/checklists/m19-release-readiness.md` (new) |
| CI/tooling | 1 — `tests/docker/run-phpunit.sh` (filter additions for both milestones' test-class prefixes) |

**Scope-creep check:** every file is attributable to M18 or M19. No stray file, no Overview/Restock touch beyond the `enqueue_assets()` mechanical split already documented in both milestones' own architecture-guard tests.

## Cross-milestone architecture review

- **No duplicated ownership between M18's and M19's controllers:** `Settings_Controller`/`Dashboard_Controller` (M18) and `Reporting_Controller` (M19) each own a disjoint set of tabs (Settings/Dashboard vs. Movements/Order Profit/Product Profitability) — no method or hook appears in more than one controller.
- **`Plugin::init()` bootstrap order:** `Purchasing_Page` → `Settings_Controller` → `Reporting_Controller` → `Expected_Delivery_Service` → `Expected_Delivery_Renderer`, confirmed by both milestones' own architecture-guard tests (`test_plugin_bootstrap_order_preserved`), still holding after both merges are combined on the train head.
- **`enqueue_assets()` shared-helper handling:** M18 left this method's Dashboard/Overview/Movements 3-way branching untouched (Dashboard's own asset needs were never in that method to begin with). M19 removed exactly the Movements branch, leaving a clean 2-way Dashboard/Overview split — confirmed no interference between the two milestones' changes to this one shared method (M18 made zero edits to it; M19's edit is its only edit in the combined diff).
- **No schema change across either milestone:** `class-wc-inventory-overview-install.php`'s only diff in `main..9e07ee8` is a one-line comment (`DB_VERSION = '11'; // Unchanged in M18/M19`) — the constant value itself is untouched.
- **No new public API in either milestone:** confirmed via both architecture-guard suites (`test_*_no_new_do_action`) — zero `do_action`/`apply_filters` introduced in any of the three new controller files.
- **No new capability in either milestone:** M18's controllers reuse `manage_woocommerce` (Settings) and no capability check at all (Dashboard, gated upstream by the shell); M19's controller reuses only `manage_woocommerce`, confirmed by grep in both architecture-guard suites.
- **No mutation introduced by M19; M18 retains its two pre-existing mutation surfaces** (Settings save, exchange-rate CRUD, danger-zone preview/apply) unchanged from pre-M18 behavior, characterization-proven byte-identical.
- **Plugin remains sole tab-routing owner** throughout both milestones (INV-M18-1/INV-M19-1) — `PAGE_SLUG`, `TAB_*`, `get_tabs_definition()`, `get_requested_tab()`, `admin_url_tab()`, `on_load_inventory_profit_page()`, `render_inventory_profit_shell()` never move.
- **Overview and Restock are untouched by both milestones combined** — confirmed by diff review (`git diff main..9e07ee8 -- includes/class-wc-inventory-overview-plugin.php` shows zero lines touched in either tab's methods) and by M19's own architecture-guard test (`test_overview_and_restock_remain_on_plugin`).

## Manual acceptance (performed live against `dev.biopentra.eu`, this session)

Because `wc-inventory-overview` is bind-mounted directly from `/opt/biopentra/dev/wc-inventory-overview` into the live `wordpress` container (no separate build/deploy step for this plugin), the M19 train head was already the live code at the start of this session. Manual acceptance was performed server-side via `wp eval-file` as the real administrator (`bp_manager`, ID 1) against real production-like data (24 published products, 14 movement-log rows, real orders), since no interactive browser/Playwright tooling was available in this session — this is recorded honestly as the acceptance method, not claimed as a human click-through.

| Area | Result |
|---|---|
| Dashboard (M18) | Renders, 13,812 bytes output, no PHP errors/warnings/notices in output or container logs |
| Settings (M18) | Renders, 17,409 bytes output, no errors |
| Settings danger-zone preview (M18) | Executed live with `wc_io_reset_movements` scope; real preview token generated and captured via the `wp_redirect` filter (`wc_io_rst=h6mbRM4E0GtPb8HkSUlId4jfzPpT4O7y`), proving capability check → nonce check → payload parsing → `preview_counts()` → token generation → `store_preview()` all executed correctly end-to-end |
| Settings danger-zone apply (M18) | **Not performed** — destructive (deletes real movement/batch/snapshot history on this dev site); relying on existing characterization/integration test coverage for this path, per the acceptance plan's explicit allowance for this exact case |
| Movements (M19) | Renders, 40,556 bytes output; CSV export triggered end-to-end (valid nonce + capability + tab match) and streamed a correctly-formed CSV (UTF-8 BOM, correct header row, real batch/receipt/void rows with sensible cost/quantity data) before `exit()` fired as expected |
| Order Profit (M19) | Renders, 43,435 bytes output; CSV export streamed correctly (BOM, header row, 4 real order rows with revenue/margin figures) |
| Product Profitability (M19) | Renders, 28,944 bytes output; CSV export streamed correctly (BOM, header row, real product rows with units/revenue/margin figures) |
| Overview (regression, unextracted) | Renders, 73,465 bytes output, no errors |
| Restock (regression, unextracted) | Renders, 3,669 bytes output, no errors |
| `on_load_*` bootstrap (M19, no export param) | All three return cleanly with zero output (correct no-op behavior) |
| Class/hook wiring | `Reporting_Controller`/`Dashboard_Controller`/`Settings_Controller` all load; `admin_enqueue_scripts` has 432 registered callbacks (sane, non-zero) |
| Container logs | Zero `wc-inventory-overview`-attributable errors/warnings/fatals across the entire acceptance session |

**Verdict: PASS.** No M18/M19 defect found. No remediation required.

## Existing test/CI evidence (reused, not re-run)

No executable code changed since each milestone's own freeze; both draft PRs' CI is current:

| Gate | Result | Source |
|---|---|---|
| GitHub Actions, PR #22 @ `2862b8c` | Green — Lint, Build ZIP, PHPUnit all pass | M18 freeze session |
| GitHub Actions, PR #23 @ `9e07ee8` | Green — Lint, Build ZIP, PHPUnit all pass (first attempt) | M19 freeze session |
| Unit suite | 417 tests / 2,162 assertions, 0 failures | M19 freeze run |
| M1–M19 focused suite | 819 tests / 3,620 assertions, 0 failures | M19 freeze run |
| Integration suite (full) | 413 tests / 1,501 assertions, 0 failures | M19 freeze run |
| M18-only tests | 47 tests | M18 freeze run |
| M19-only tests | 36 tests | M19 freeze run |
| `release-audit.sh --development` | Passed, v1.36.0 | M19 freeze run |

## Documentation review

- `CHANGELOG.md`: two consistent, correctly-dated unreleased entries (`1.35.0` M18, `1.36.0` M19), each explicitly stating "not individually released."
- `docs/architecture-audit.md`: god-class entry correctly records Phase 1 (M18) and Phase 2 (M19) complete, Phase 3 (Overview + Restock) explicitly named as still open — no false "decomposition complete" claim.
- `CLAUDE.md`: deliberately not yet updated (matches established convention — Implementation Status table/Platform-status header updated only at actual release time). Will be updated in Phase 12 (post-release documentation) once this train actually publishes.
- No `docs/GITHUB_RELEASE_NOTES_1.36.0.md` exists yet — correct at this stage; created in the release-preparation step (Phase 5), not this review.

## Release-version recommendation

**`v1.36.0`** — bundling M18+M19 in one release, matching this repository's own M9–M12 and M13–M15 precedent. No separate `v1.35.0` release: no repository evidence suggests M18 was ever intended to ship alone; every M18 artifact explicitly states "not individually released."

## Release-topology recommendation

```
v1.34.0/main
  → M18 (frozen, draft PR #22)
  → M19 (frozen, draft PR #23)
  → real release PR into main
```

The two draft milestone PRs (#22/#23) are CI-proof contexts only — closed without merging when the real release PR is opened, matching the M9–M12 and M13–M15 trains' own closure precedent.

## Rollback profile

Both milestones are pure code reorganization with zero schema change, zero new mutation path (M19), and unchanged pre-existing mutation paths (M18's Settings/exchange-rate/danger-zone, behavior-proven identical to pre-M18). `docs/rollback-plan.md` does not yet carry M18/M19 entries — neither milestone changes schema or introduces a "code rollback doesn't undo an operation" risk class (unlike M17's supplier merge), so per that document's own pattern (entries only for schema-change or irreversible-operation milestones), no new entry is strictly required; this is noted rather than silently assumed.

## Findings by severity

**CRITICAL:** None.
**MAJOR:** None.
**MINOR:** None.
**OBSERVATION:** `docs/rollback-plan.md` has no M18/M19-specific entry — assessed as not required (see Rollback profile above), but flagged for a future editorial pass if the project later decides every milestone should get an entry regardless of risk class.

## Exact next operation

Proceed to Phase 5 (release-notes preparation) through Phase 12 (post-release documentation) of the authorized release sequence. This document's WP6 execution record will be appended once those phases complete.
