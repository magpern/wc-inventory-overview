#!/usr/bin/env bash
#
# Build production release ZIP: builds/wc-inventory-overview-{version}.zip
#
set -euo pipefail

readonly PLUGIN_SLUG="wc-inventory-overview"
readonly REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly VERIFY_ZIP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/verify-release-zip.py"
readonly MAIN_FILE="${REPO_ROOT}/${PLUGIN_SLUG}.php"
readonly OUT_DIR="${REPO_ROOT}/builds"
readonly STAGE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wc-io-zip.XXXXXX")"

cleanup() {
	rm -rf "${STAGE_ROOT}"
}
trap cleanup EXIT

[[ -f "${MAIN_FILE}" ]] || {
	echo "ERROR: Missing ${MAIN_FILE}" >&2
	exit 1
}

HEADER_VERSION="$(
	grep -E '^\s*\*\s*Version:\s*' "${MAIN_FILE}" \
		| head -n 1 \
		| sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//'
)"

VERSION_CONST="$(
	grep -E "define\s*\(\s*'WC_INVENTORY_OVERVIEW_VERSION'" "${MAIN_FILE}" \
		| head -n 1 \
		| sed -E "s/.*'([^']+)'.*/\1/"
)"

[[ "${HEADER_VERSION}" == "${VERSION_CONST}" ]] || {
	echo "ERROR: Version mismatch header=${HEADER_VERSION} const=${VERSION_CONST}" >&2
	exit 1
}

readonly VERSION="${VERSION_CONST}"
readonly ZIP_PATH="${OUT_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
readonly PACKAGE_DIR="${STAGE_ROOT}/${PLUGIN_SLUG}"

echo "==> WC Inventory Overview: build production ZIP"
echo "    Version: ${VERSION}"
echo "    Output:  ${ZIP_PATH}"

mkdir -p "${OUT_DIR}" "${PACKAGE_DIR}"

tar -C "${REPO_ROOT}" \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='vendor' \
	--exclude='node_modules' \
	--exclude='scripts' \
	--exclude='tests' \
	--exclude='docs' \
	--exclude='build' \
	--exclude='builds' \
	--exclude='cli' \
	--exclude='bin' \
	--exclude='.phpcs-cache' \
	--exclude='.phpunit.result.cache' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='*.log' \
	--exclude='*.sql' \
	--exclude='*.sql.gz' \
	--exclude='*.dump' \
	--exclude='*.sqlite' \
	--exclude='.write-test' \
	--exclude='.DS_Store' \
	--exclude='README.md' \
	--exclude='.gitignore' \
	-cf - . \
	| tar -C "${PACKAGE_DIR}" -xf -

rm -f "${ZIP_PATH}"
if command -v zip >/dev/null 2>&1; then
	( cd "${STAGE_ROOT}" && zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}" )
else
	python3 - "${STAGE_ROOT}" "${ZIP_PATH}" "${PLUGIN_SLUG}" <<'PY'
import os
import sys
import zipfile
from pathlib import Path

staging_dir = Path(sys.argv[1])
zip_path = Path(sys.argv[2])
plugin_slug = sys.argv[3]
root = staging_dir / plugin_slug

with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
    for dirpath, _dirnames, filenames in os.walk(root):
        for name in filenames:
            full = Path(dirpath) / name
            zf.write(full, full.relative_to(staging_dir).as_posix())
PY
fi

python3 "${VERIFY_ZIP}" "${ZIP_PATH}" "${VERSION}"
echo "==> Build complete: ${ZIP_PATH}"
