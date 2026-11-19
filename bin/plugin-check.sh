#!/usr/bin/env bash
# Plugin Check on what ships: everything .distignore leaves out of the zip is
# left out of the check too, so the two lists can't drift apart.
#
#   bin/plugin-check.sh                       with `wp`
#   bin/plugin-check.sh studio wp             on WordPress Studio (from the site root)
#   bin/plugin-check.sh npx wp-env run cli wp in CI
#
# Fails when Plugin Check lists any file or error.
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

# trunk's dev version (0.72-dev) differs from readme.txt's Stable tag (the
# last final) on purpose: the only error that's expected then.
ignore=()
case "$(sed -n 's/^ \* Version: *//p' "$plugin/events-certificates.php")" in
	*-*) ignore=(--ignore-codes=stable_tag_mismatch) ;;
esac

cmd=("$@")
[ ${#cmd[@]} -eq 0 ] && cmd=(wp)
out="$("${cmd[@]}" plugin check events-certificates \
	--exclude-directories="$(join ${dirs[@]+"${dirs[@]}"})" \
	--exclude-files="$(join ${files[@]+"${files[@]}"})" \
	${ignore[@]+"${ignore[@]}"} 2>&1)" || true
echo "$out"
# Clean when Plugin Check lists no file and no error (its words follow the site's language).
if grep -qE '^(FILE: |Error: )' <<< "$out"; then
	exit 1
fi
[ ${#ignore[@]} -gt 0 ] && echo "(ignored: ${ignore[*]#--ignore-codes=})"
exit 0
