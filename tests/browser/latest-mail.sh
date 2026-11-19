#!/usr/bin/env bash
# The newest e-mail a site sent to an address, and the links and numbers in it.
# With Camaleaunmail active the site's own e-mail log is read (run inside the site's folder, or pass its folder as the
# second argument); else Studio's .eml files (~/.studio/email/<site-id>/) are.
#
#   tests/browser/latest-mail.sh e2e.ana@example.test [~/Studio/evcert-camptix]
set -euo pipefail

to="${1:?e-mail address}"
site="${2:-}"

if [ -n "$site" ] && (cd "$site" && studio wp plugin is-active camaleaunmail >/dev/null 2>&1); then
	(cd "$site" && studio wp eval 'global $wpdb; $t = $wpdb->prefix . "camaleaunmail_logs"; echo wp_json_encode( $wpdb->get_row( $wpdb->prepare( "SELECT subject, message FROM $t WHERE to_email = %s ORDER BY id DESC LIMIT 1", $argv[1] ?? "'"$to"'" ), ARRAY_A ) );' 2>/dev/null | grep '^{') | python3 -c '
import json, re, sys
row = json.load(sys.stdin)
if not row:
    sys.exit("no mail")
print("#", row.get("subject", ""))
body = str(row.get("message", ""))
print("\n".join(sorted(set(re.findall(r"https?://[^ \"<>)\\\\]+", body)))))
print("numbers:", ", ".join(sorted(set(re.findall(r"\b\d{4,6}\b", body)))))
' && exit 0
fi

for file in $(ls -t ~/.studio/email/*/*.eml 2>/dev/null); do
	if grep -qiE "^To:.*${to}" "$file"; then
		echo "# $file"
		grep -iE "^(Subject|To):" "$file"
		# Quoted-printable soft line breaks and escapes, then the links.
		sed -e ':a' -e 'N' -e '$!ba' -e 's/=\r\?\n//g' -e 's/=3D/=/g' "$file" | grep -oE 'https?://[^ "<>)]+' | sort -u
		exit 0
	fi
done
echo "no mail to $to" >&2
exit 1
