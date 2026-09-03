#!/usr/bin/env bash
# Package the plugin into an installable zip next to this script.
set -euo pipefail

cd "$(dirname "$0")"

version="$(grep -m1 '^ \* Version:' gohighlevel-integration/gohighlevel-integration.php | awk '{print $3}')"
output="gohighlevel-integration-${version}.zip"

rm -f "$output"
zip -rq "$output" gohighlevel-integration \
	-x '*.DS_Store' '*/node_modules/*' '*/.git/*'

echo "Wrote $output"
