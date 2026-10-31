#!/usr/bin/env bash
# Builds the install package: dist/board-<version>.zip
#
#   ./tools/build-zip.sh            # version read from plugin.php
#   ./tools/build-zip.sh 0.1.1      # explicit version (must match plugin.php)
#
# The archive contains exactly the production files, nested in a `board/`
# folder so it can be unzipped straight into `include/plugins/`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MANIFEST_VERSION="$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" plugin.php | head -1 | sed -E "s/.*'([^']+)'\$/\1/")"
CONSTANT_VERSION="$(grep -oE "const VERSION = '[^']+'" class.BoardPlugin.php | sed -E "s/.*'([^']+)'\$/\1/")"
VERSION="${1:-$MANIFEST_VERSION}"

if [ "$VERSION" != "$MANIFEST_VERSION" ] || [ "$VERSION" != "$CONSTANT_VERSION" ]; then
  echo "Version mismatch: requested '$VERSION', plugin.php '$MANIFEST_VERSION', BoardPlugin::VERSION '$CONSTANT_VERSION'." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/board/src" "$STAGE/board/assets"
cp plugin.php config.php class.BoardPlugin.php LICENSE "$STAGE/board/"
cp src/*.php "$STAGE/board/src/"
cp assets/board.css assets/board.js "$STAGE/board/assets/"

# Reject anything that must never ship.
if grep -rniE "(passw(or)?d|secret|token)[[:space:]]*=[[:space:]]*['\"][A-Za-z0-9]{8,}" "$STAGE/board"; then
  echo "Refusing to build: something that looks like a literal secret is in the staged files." >&2
  exit 1
fi
if grep -rnE "^<<<<<<<|^>>>>>>>" "$STAGE/board"; then
  echo "Refusing to build: merge conflict markers." >&2
  exit 1
fi

mkdir -p dist
rm -f "dist/board-${VERSION}.zip"
# Reproducible archive: every file gets the commit date, entries are sorted,
# no extra fields. Building the same commit twice yields the same bytes.
EPOCH="$(git log -1 --format=%ct 2>/dev/null || true)"
if [ -z "$EPOCH" ]; then
  EPOCH="$(date +%s)"
  echo "Note: not a git checkout, using the current time for file dates." >&2
fi
find "$STAGE" -exec touch -d "@$EPOCH" {} +
( cd "$STAGE" && find board -type f | LC_ALL=C sort | TZ=UTC zip -X -q "board-${VERSION}.zip" -@ )
mv "$STAGE/board-${VERSION}.zip" "dist/"

echo "Built dist/board-${VERSION}.zip"
unzip -l "dist/board-${VERSION}.zip"
