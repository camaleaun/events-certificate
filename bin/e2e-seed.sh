#!/usr/bin/env bash
# Seeds the Studio site of a browser scenario (tests/browser/*.md).
#
#   bin/e2e-seed.sh none|camptix|gatherpress|both [args of the seed]
#
# Sites (create them with `studio site create`, install the zip of bin/package.sh and the integrations):
#   none        ~/Studio/evcert-clean     (events-certificates)
#   camptix     ~/Studio/evcert-camptix   (+ camptix, wc-post-types, mu-plugins/wordcamp-shims.php)
#   gatherpress ~/Studio/evcert-gp        (+ gatherpress)
#   both        ~/Studio/wp-events-certificates (the dev site, with both active)
set -euo pipefail

scenario="${1:?scenario: none|camptix|gatherpress|both}"
shift || true
plugin="$(cd "$(dirname "$0")/.." && pwd)"
case "$scenario" in
	none) site=~/Studio/evcert-clean; seed=none ;;
	camptix) site=~/Studio/evcert-camptix; seed=camptix ;;
	gatherpress) site=~/Studio/evcert-gp; seed=gatherpress ;;
	both) site=~/Studio/wp-events-certificates; seed=camptix ;;
	*) echo "unknown scenario: $scenario" >&2; exit 1 ;;
esac

cd "$site"
studio wp eval-file "$plugin/tests/browser/seed-$seed.php" "$@"
if [ "$scenario" = both ]; then
	studio wp eval-file "$plugin/tests/browser/seed-gatherpress.php"
fi
