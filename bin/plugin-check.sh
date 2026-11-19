#!/usr/bin/env bash
#
# Runs Plugin Check in the development environment on the repository, with the
# exclusions in .pcpignore, so it scans what ships.
#
# usage: bin/plugin-check.sh [<wp plugin check options>]

set -e

cd "$(dirname "$0")/.."

npx @wordpress/env run cli wp plugin check otaria-search --use-pcpignore "$@"
