#!/usr/bin/env bash
# build-release-zip.sh — build a pre-resolved VENDORED release ZIP for a TigerCore version.
#
# This is the artifact the no-shell updater (Tiger_Update_Core) atomically swaps in: a complete,
# Composer-resolved vendor/ tree (tiger-core + TigerZF + all deps + the optimized autoloader). The
# whole point — dependency resolution happens HERE, once, in CI; the shared host only downloads and
# unpacks. Same "resolve off-box" thesis as tiger-vendor-bundles. See DEPENDENCIES.md + UPDATING.md.
#
# Usage (CI, on a tiger-core release):
#   VERSION=0.6.0-beta SOURCE_PATH="$(pwd)" ./bin/build-release-zip.sh
#     -> dist/tiger-core-vendored-<version>.zip  (+ .sha256)
#
# SOURCE_PATH (a tiger-core checkout) builds from the exact release commit via a Composer path repo —
# no Packagist-indexing race. Omit it to pull `webtigers/tiger-core:$VERSION` from Packagist instead.
set -euo pipefail

VERSION="${VERSION:?set VERSION (e.g. 0.6.0-beta)}"
VERSION="${VERSION#v}"
OUT="${OUT:-dist}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

for t in composer zip; do command -v "$t" >/dev/null || { echo "!! '$t' required"; exit 1; }; done

if [ -n "${SOURCE_PATH:-}" ]; then
    SRC="$(cd "$SOURCE_PATH" && pwd)"
    # minimum-stability=dev so the local checkout (a path package Composer may label dev-<branch>
    # when HEAD isn't exactly a tag) resolves; prefer-stable keeps the DEPS on their stable releases.
    # The vendored files carry the real version regardless (Version.php), which is what the ZIP ships.
    cat > "$WORK/composer.json" <<JSON
{
  "repositories": [{ "type": "path", "url": "${SRC}", "options": { "symlink": false } }],
  "require": { "webtigers/tiger-core": "*" },
  "minimum-stability": "dev", "prefer-stable": true,
  "config": { "optimize-autoloader": true }
}
JSON
else
    cat > "$WORK/composer.json" <<JSON
{
  "require": { "webtigers/tiger-core": "${VERSION}" },
  "minimum-stability": "beta", "prefer-stable": true,
  "config": { "optimize-autoloader": true }
}
JSON
fi

echo "Resolving vendor/ for TigerCore ${VERSION}…"
( cd "$WORK" && composer install --no-dev --no-interaction --optimize-autoloader --no-progress )

# Sanity: the resolved tree must carry the expected version.
VER_FILE="$WORK/vendor/webtigers/tiger-core/library/Tiger/Version.php"
[ -f "$VER_FILE" ] || { echo "!! resolved vendor/ has no tiger-core"; exit 1; }
GOT="$(grep -oE "VERSION\s*=\s*'[^']+'" "$VER_FILE" | grep -oE "'[^']+'" | tr -d "'")"
echo "  resolved tiger-core: ${GOT}"

# Strip VCS metadata: a package's DIST never has .git — it only appears on a source install (a
# just-tagged version whose dist zipball isn't ready). Removing it matches the dist + trims the bundle.
find "$WORK/vendor" -type d -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true

mkdir -p "$OUT"; ABS_OUT="$(cd "$OUT" && pwd)"
ZIP="${ABS_OUT}/tiger-core-vendored-${VERSION}.zip"
rm -f "$ZIP" "$ZIP.sha256"
( cd "$WORK" && zip -qr "$ZIP" vendor )   # ZIP root contains vendor/ — Tiger_Update_Core::_locateVendor finds it

sha256() { if command -v sha256sum >/dev/null; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
sha256 "$ZIP" > "$ZIP.sha256"

echo "Built $(basename "$ZIP") ($(du -h "$ZIP" | cut -f1))  sha256=$(cat "$ZIP.sha256")"
