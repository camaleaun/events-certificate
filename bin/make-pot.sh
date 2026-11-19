#!/usr/bin/env bash
# Regenerates languages/events-certificates.pot: the PHP, and the strings of the
# built scripts (build/, build-blocks/, build-signer/: run `npm run build` first,
# the editor's texts are in them). Sources and vendor code are left out.
#
#   bin/make-pot.sh                    with `wp`
#   bin/make-pot.sh studio wp          on WordPress Studio (from the site root)
#
# The pt_BR files are not in the repository (translate.wordpress.org has them);
# to try the Portuguese tests, merge the new template into the site's copy:
#   msgmerge --update --no-fuzzy-matching wp-content/languages/plugins/events-certificates-pt_BR.po languages/events-certificates.pot
#   (translate the new strings, then msgfmt -o ...pt_BR.mo ...pt_BR.po and `wp i18n make-php wp-content/languages/plugins`)
set -euo pipefail

plugin="$(cd "$(dirname "$0")/.." && pwd)"
wp=("${@:-wp}")

"${wp[@]}" i18n make-pot "$plugin" "$plugin/languages/events-certificates.pot" \
	--domain=events-certificates \
	--include=includes,events-certificates.php,blocks,build,build-blocks,build-signer
