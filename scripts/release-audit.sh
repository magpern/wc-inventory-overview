#!/usr/bin/env bash
#
# Release / development validation for wc-inventory-overview standalone repo.
#
# Modes (exactly one required):
#   --development  Feature-train / CI validation. Checks version consistency,
#                  updater presence, release workflow presence, and the built
#                  ZIP — but does NOT require per-version GitHub release notes.
#                  Unreleased milestones on a feature train intentionally share
#                  one eventual release-notes artifact at WP6 release time.
#   --release      Actual release preparation (tagging / GitHub Release).
#                  Requires docs/GITHUB_RELEASE_NOTES_{VERSION}.md in addition
#                  to everything --development checks.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERIFY_ZIP="${REPO_ROOT}/scripts/lib/verify-release-zip.py"
MAIN_FILE="${REPO_ROOT}/wc-inventory-overview.php"

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

usage() {
	cat >&2 <<'EOF'
Usage: scripts/release-audit.sh --development|--release

  --development  CI / feature-train validation (no per-version release notes)
  --release      Release preparation (requires GITHUB_RELEASE_NOTES_{VERSION}.md)
EOF
	exit 1
}

MODE=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--development)
			MODE="development"
			shift
			;;
		--release)
			MODE="release"
			shift
			;;
		-h|--help)
			usage
			;;
		*)
			fail "Unknown argument: $1 (use --development or --release)"
			;;
	esac
done

[[ -n "${MODE}" ]] || usage

echo "==> WC Inventory Overview: release audit (${MODE})"

[[ -f "${MAIN_FILE}" ]] || fail "Missing main plugin file"

VERSION_CONST="$(grep -E "define\s*\(\s*'WC_INVENTORY_OVERVIEW_VERSION'" "${MAIN_FILE}" | head -n1 | sed -E "s/.*'([^']+)'.*/\1/")"
HEADER_VERSION="$(grep -E '^\s*\*\s*Version:\s*' "${MAIN_FILE}" | head -n1 | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//')"
[[ "${VERSION_CONST}" == "${HEADER_VERSION}" ]] || fail "Version mismatch"
echo "    Version: ${VERSION_CONST}"

[[ -f "${REPO_ROOT}/includes/class-github-updater.php" ]] || fail "Missing github updater"
[[ -f "${REPO_ROOT}/.github/workflows/release.yml" ]] || fail "Missing release workflow"

NOTES_FILE="${REPO_ROOT}/docs/GITHUB_RELEASE_NOTES_${VERSION_CONST}.md"
if [[ "${MODE}" == "release" ]]; then
	[[ -f "${NOTES_FILE}" ]] || fail "Missing release notes (${NOTES_FILE}). Required for --release / tagging."
	echo "    Release notes: ${NOTES_FILE}"
else
	if [[ -f "${NOTES_FILE}" ]]; then
		echo "    Release notes: present (optional in --development)"
	else
		echo "    Release notes: deferred (feature-train; required only for --release)"
	fi
fi

ZIP_PATH="${REPO_ROOT}/builds/wc-inventory-overview-${VERSION_CONST}.zip"
if [[ ! -f "${ZIP_PATH}" ]]; then
	bash "${REPO_ROOT}/scripts/build-zip.sh"
fi

python3 "${VERIFY_ZIP}" "${ZIP_PATH}" "${VERSION_CONST}"
echo "==> Release audit passed (${MODE}, ${VERSION_CONST})"
