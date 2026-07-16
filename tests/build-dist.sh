#!/bin/sh
# Deterministic distributable builder honoring .distignore.
#
# Mirrors `wp dist-archive .` exclusions for environments without WP-CLI.
# Usage: tests/build-dist.sh <output-dir>
# Produces <output-dir>/social-preview-designer/ containing only shippable files.
set -eu

SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:?output dir required}"
SLUG="social-preview-designer"
DEST="$OUT/$SLUG"

rm -rf "$DEST"
mkdir -p "$DEST"

# Build an rsync exclude list from .distignore (skip blanks/comments).
EXCLUDES=""
while IFS= read -r line || [ -n "$line" ]; do
	case "$line" in
		''|\#*) continue ;;
	esac
	EXCLUDES="$EXCLUDES --exclude=$line"
done < "$SRC/.distignore"

# shellcheck disable=SC2086
rsync -a $EXCLUDES --exclude='node_modules' --exclude='vendor' "$SRC/" "$DEST/"

echo "$DEST"
