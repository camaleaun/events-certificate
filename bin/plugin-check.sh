#!/usr/bin/env bash
# Plugin Check on what ships: everything .distignore leaves out of the zip is
# left out of the check too, so the two lists can't drift apart.
#
#   bin/plugin-check.sh                       with `wp`
#   bin/plugin-check.sh studio wp             on WordPress Studio (from the site root)
#   bin/plugin-check.sh npx wp-env run cli wp in CI
#
# Fails unless Plugin Check reports "No errors found" (or nothing, when it ignores a code).
set -euo pipefail

plugin="$(cd "$(dirname "$0")/.." && pwd)"
dirs=()
files=()
while IFS= read -r line || [ -n "$line" ]; do
	case "$line" in '' | '#'* | *'*'*) continue ;; esac
	name="${line#/}"
	if [ -d "$plugin/$name" ]; then
		dirs+=("$name")
	elif [ -e "$plugin/$name" ]; then
		files+=("$name")
	fi
done < "$plugin/.distignore"

# Third-party libraries ship (with their licenses) but are not our code to check: docs/decisions/0003.
dirs+=("vendor-prefixed")

join() {
	local IFS=,
	echo "$*"
}

# trunk's dev version (1.1.0-dev) differs from readme.txt's Stable tag (the
# last final) on purpose: the only error that's expected then.
ignore=()
case "$(sed -n 's/^ \* Version: *//p' "$plugin/events-certificate.php")" in
	*-*) ignore=(--ignore-codes=stable_tag_mismatch) ;;
esac

cmd=("$@")
[ ${#cmd[@]} -eq 0 ] && cmd=(wp)
out="$("${cmd[@]}" plugin check events-certificate \
	--exclude-directories="$(join ${dirs[@]+"${dirs[@]}"})" \
	--exclude-files="$(join ${files[@]+"${files[@]}"})" \
	${ignore[@]+"${ignore[@]}"} 2>&1)" || true
echo "$out"
# With --ignore-codes, a clean run prints no results and no "No errors found".
if [ ${#ignore[@]} -gt 0 ] && ! grep -qE '^(FILE: |Error: )' <<< "$out"; then
	echo "No errors found (ignored: ${ignore[*]#--ignore-codes=})."
	exit 0
fi
grep -q 'No errors found' <<< "$out"
