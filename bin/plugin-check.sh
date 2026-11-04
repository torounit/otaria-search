#!/usr/bin/env bash
#
# Runs Plugin Check in the development environment. It does not read .distignore
# and sees the repository, so what .distignore keeps out of the release is
# excluded here. build/ is excluded as in CI: @wordpress/build generates it.
#
# usage: bin/plugin-check.sh [<wp plugin check options>]

set -e

cd "$(dirname "$0")/.."

directories=build
files=
while read -r entry; do
	entry=${entry#/}
	if [ -d "$entry" ]; then
		directories+=",$entry"
	else
		files+=",$entry"
	fi
done <.distignore

npx wp-env run cli wp plugin check otaria-search \
	--exclude-directories="$directories" \
	--exclude-files="${files#,}" \
	"$@"
