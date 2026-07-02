#!/usr/bin/env bash
#
# Build a distributable plugin zip from bunnify-frontend/, applying the
# exclusion rules in bunnify-frontend/.distignore. Output: dist/bunnify-frontend.zip
# whose archive root is a single `bunnify-frontend/` folder (as WordPress expects).
#
set -euo pipefail

SLUG="bunnify-frontend"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/$SLUG"
BUILD="$ROOT/build"
DIST="$ROOT/dist"
STAGE="$BUILD/$SLUG"

if [[ ! -d "$SRC" ]]; then
	echo "error: plugin source not found at $SRC" >&2
	exit 1
fi

rm -rf "$BUILD" "$DIST"
mkdir -p "$STAGE" "$DIST"

# Build rsync excludes from .distignore (one pattern per non-comment line).
rsync_args=( -a --delete )
if [[ -f "$SRC/.distignore" ]]; then
	while IFS= read -r line; do
		[[ -z "$line" || "$line" == \#* ]] && continue
		rsync_args+=( --exclude "$line" )
	done < "$SRC/.distignore"
fi

rsync "${rsync_args[@]}" "$SRC/" "$STAGE/"

# Guard the staged tree: hard-fail if dev/packaging cruft leaks into the
# distributable, or if a runtime essential is missing.
for banned in build-tools composer.json composer.lock vendor node_modules; do
	if [[ -e "$STAGE/$banned" ]]; then
		echo "error: '$banned' must not ship in the distributable" >&2
		exit 1
	fi
done
for required in bunnify-frontend.php autoload.php uninstall.php readme.txt LICENSE src; do
	if [[ ! -e "$STAGE/$required" ]]; then
		echo "error: required file missing from stage: $required" >&2
		exit 1
	fi
done

# Zip from the build dir so the archive is rooted at the plugin slug folder.
( cd "$BUILD" && zip -rq "$DIST/$SLUG.zip" "$SLUG" )

size="$(du -h "$DIST/$SLUG.zip" | cut -f1)"
echo "Built $DIST/$SLUG.zip ($size)"
