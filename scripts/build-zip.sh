#!/usr/bin/env bash
# Build deployable ZIP: builds/wc-inventory-overview-{version}.zip
# Archive root folder: wc-inventory-overview/ (WordPress plugin slug).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="wc-inventory-overview"
MAIN="${ROOT}/${SLUG}.php"
OUT_DIR="${ROOT}/builds"
STAGE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wc-io-zip.XXXXXX")"

cleanup() {
	rm -rf "$STAGE_ROOT"
}
trap cleanup EXIT

if [[ ! -f "$MAIN" ]]; then
	echo "error: missing ${MAIN}" >&2
	exit 1
fi

extract_version() {
	local line
	line="$(grep -m1 -iE '^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*' "$MAIN" 2>/dev/null || true)"
	if [[ -z "$line" ]]; then
		echo "0.0.0"
		return
	fi
	echo "$line" | sed -E 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*//I' | tr -d '\r' | sed 's/[[:space:]]*$//'
}

VERSION="$(extract_version)"
DEST="${OUT_DIR}/${SLUG}-${VERSION}.zip"
STAGE="${STAGE_ROOT}/${SLUG}"

mkdir -p "$OUT_DIR" "$STAGE"

rsync -a \
	--exclude='.git' \
	--exclude='.gitignore' \
	--exclude='.DS_Store' \
	--exclude='README.md' \
	--exclude='CHANGELOG.md' \
	--exclude='docs/' \
	--exclude='builds/' \
	--exclude='scripts/' \
	--exclude='bin/' \
	--exclude='tests/' \
	--exclude='node_modules/' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='*.log' \
	--exclude='*.sql' \
	--exclude='*.sql.gz' \
	--exclude='*.dump' \
	"${ROOT}/" "${STAGE}/"

write_zip() {
	local parent="$1"
	local name="$2"
	local zip_path="$3"
	if command -v zip >/dev/null 2>&1; then
		( cd "$parent" && zip -qr "$zip_path" "$name" )
		return
	fi
	if command -v python3 >/dev/null 2>&1; then
		python3 - "$parent" "$name" "$zip_path" <<'PY'
import sys, zipfile
from pathlib import Path
parent, name, out = Path(sys.argv[1]), sys.argv[2], Path(sys.argv[3])
root = parent / name
with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED) as zf:
    for path in root.rglob("*"):
        if path.is_file():
            zf.write(path, path.relative_to(parent).as_posix())
PY
		return
	fi
	echo "error: install zip or python3" >&2
	exit 1
}

write_zip "$STAGE_ROOT" "$SLUG" "$DEST"

echo "Built ${DEST} (version ${VERSION})"
