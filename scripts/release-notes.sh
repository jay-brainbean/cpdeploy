#!/usr/bin/env bash
# Prints the CHANGELOG.md section of one version, for the GitHub release notes (§15.4).
#
#   scripts/release-notes.sh 1.0.0-rc.1
#
# The section starts at a heading "## 1.0.0-rc.1" or "## [1.0.0-rc.1]" (anything may
# follow, such as a date) and ends at the next "## " heading. Without one, a pointer
# to CHANGELOG.md is printed instead.
set -euo pipefail

version="${1:?usage: release-notes.sh <version>}"
root="$(cd "$(dirname "$0")/.." && pwd)"

notes="$(awk -v v="$version" '
    /^## / {
        if (found) { exit }
        heading = $0
        sub(/^## +\[?/, "", heading)
        sub(/[] ].*$/, "", heading)
        if (heading == v) { found = 1; next }
    }
    found { print }
' "$root/CHANGELOG.md")"

if [ -n "${notes//[[:space:]]/}" ]; then
    printf '%s\n' "$notes"
else
    printf 'See CHANGELOG.md for the changes in %s.\n' "$version"
fi
