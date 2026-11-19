#!/usr/bin/env bash
# Makes the release zip: what git archive keeps (the dev files are export-ignore) plus what the build makes
# and git does not hold (build/, build-signer/, build-blocks/, assets/css, assets/js), without source maps.
#
#   bin/package.sh [output-dir]      default: /tmp/evcert-release
#
# Run it from a clean, committed tree: the zip is made from HEAD.
set -euo pipefail

plugin="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-/tmp/evcert-release}"
slug="events-certificates"
version="$(sed -n 's/^ \* Version: *//p' "$plugin/$slug.php" | head -1)"

cd "$plugin"
npm run build > /dev/null

rm -rf "$out" && mkdir -p "$out"
git archive --format=tar --prefix="$slug/" HEAD | tar -x -C "$out"
for dir in build build-signer build-blocks assets/css assets/js; do
	cp -R "$dir" "$out/$slug/$dir"
done
find "$out" \( -name '*.map' -o -name '.DS_Store' \) -delete

(cd "$out" && zip -qr "$slug-$version.zip" "$slug")
echo "$out/$slug-$version.zip ($(find "$out/$slug" -type f | wc -l | tr -d ' ') files)"
