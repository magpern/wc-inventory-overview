#!/usr/bin/env bash
#
# Release validation for wc-inventory-overview standalone repo.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERIFY_ZIP="${REPO_ROOT}/scripts/lib/verify-release-zip.py"
MAIN_FILE="${REPO_ROOT}/wc-inventory-overview.php"

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

echo "==> WC Inventory Overview: release audit"

[[ -f "${MAIN_FILE}" ]] || fail "Missing main plugin file"

VERSION_CONST="$(grep -E "define\s*\(\s*'WC_INVENTORY_OVERVIEW_VERSION'" "${MAIN_FILE}" | head -n1 | sed -E "s/.*'([^']+)'.*/\1/")"
HEADER_VERSION="$(grep -E '^\s*\*\s*Version:\s*' "${MAIN_FILE}" | head -n1 | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//')"
[[ "${VERSION_CONST}" == "${HEADER_VERSION}" ]] || fail "Version mismatch"
echo "    Version: ${VERSION_CONST}"

[[ -f "${REPO_ROOT}/includes/class-github-updater.php" ]] || fail "Missing github updater"
[[ -f "${REPO_ROOT}/docs/GITHUB_RELEASE_NOTES_${VERSION_CONST}.md" ]] || fail "Missing release notes"
[[ -f "${REPO_ROOT}/.github/workflows/release.yml" ]] || fail "Missing release workflow"

ZIP_PATH="${REPO_ROOT}/builds/wc-inventory-overview-${VERSION_CONST}.zip"
if [[ ! -f "${ZIP_PATH}" ]]; then
	bash "${REPO_ROOT}/scripts/build-zip.sh"
fi

python3 "${VERIFY_ZIP}" "${ZIP_PATH}" "${VERSION_CONST}"
echo "==> Release audit passed (${VERSION_CONST})"
