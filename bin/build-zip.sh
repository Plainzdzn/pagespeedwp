#!/usr/bin/env bash
#
# Baut die installierbare ZIP-Datei des Plugins aus dem letzten Commit.
# Dev-Dateien (Tests, Doku, Design, Tools) fehlen darin, siehe .gitattributes (export-ignore).
#
# Nutzung: bin/build-zip.sh [zielordner]   (Standard: dist/)

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$REPO_DIR/dist}"
VERSION="$(sed -n 's/^ \* Version: *//p' "$REPO_DIR/akuma-webp-umwandler.php" | tr -d '[:space:]')"
TARGET="$OUT_DIR/akuma-webp-umwandler-$VERSION.zip"

if [ -n "$(git -C "$REPO_DIR" status --porcelain -- . ':!dist')" ]; then
	echo "Hinweis: Es gibt nicht committete Änderungen. Die ZIP enthält nur den letzten Commit." >&2
fi

mkdir -p "$OUT_DIR"
git -C "$REPO_DIR" archive --format=zip --prefix=akuma-webp-umwandler/ -o "$TARGET" HEAD
echo "$TARGET"
