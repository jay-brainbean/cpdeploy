#!/usr/bin/env bash
# Builds dist/cpdeploy.phar, dist/cpdeploy.phar.sha256 and dist/install.sh (§15.1).
#
#   scripts/build.sh            build from the committed tree (HEAD)
#   scripts/build.sh --dirty    build from the working tree, uncommitted changes included
#
# Needs PHP >= 8.2, Composer 2 and git. Box is pinned (BLD-10): if `box` on PATH
# isn't that version, the pinned box.phar is downloaded into build/ and verified.
set -euo pipefail

BOX_VERSION="4.7.0"
BOX_SHA256="3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
DIRTY=0
[ "${1:-}" = "--dirty" ] && DIRTY=1

die() { printf 'build: %s\n' "$*" >&2; exit 1; }

# --- 1. requirements --------------------------------------------------------------
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP 8.2 or newer is required to build (Box 4)"
composer --version 2>/dev/null | grep -q 'Composer version 2' || die "Composer 2 is required"
command -v git >/dev/null || die "git is required"
cd "$ROOT"
if [ "$DIRTY" = "0" ] && [ -n "$(git status --porcelain)" ]; then
    die "the git tree has uncommitted changes (commit them, or use --dirty)"
fi

VERSION="$(git describe --tags --always --dirty 2>/dev/null || echo dev)"
VERSION="${VERSION#v}"
case "$VERSION" in *-dirty) ;; *) [ "$DIRTY" = "1" ] && [ -n "$(git status --porcelain)" ] && VERSION="$VERSION-dirty" ;; esac

BOX="$(command -v box || true)"
if [ -z "$BOX" ] || ! "$BOX" --version 2>/dev/null | grep -q "Box version $BOX_VERSION"; then
    BOX="$ROOT/build/tools/box-$BOX_VERSION.phar"
    if [ ! -f "$BOX" ]; then
        mkdir -p "$ROOT/build/tools"
        curl -fsSL -o "$BOX.tmp" "https://github.com/box-project/box/releases/download/$BOX_VERSION/box.phar"
        mv "$BOX.tmp" "$BOX"
    fi
    echo "$BOX_SHA256  $BOX" | sha256sum -c --quiet - || { rm -f "$BOX"; die "box.phar failed its checksum"; }
    BOX="php -d phar.readonly=0 $BOX"
else
    BOX="php -d phar.readonly=0 $BOX"
fi

# --- 2. copy the project into a temp dir and install production dependencies -------
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cpd-build-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
if [ "$DIRTY" = "1" ]; then
    # Tracked and untracked files, minus ones deleted in the working tree.
    # shellcheck disable=SC2016
    git ls-files -z --cached --others --exclude-standard \
        | php -r 'foreach (explode("\0", stream_get_contents(STDIN)) as $f) { if ($f !== "" && file_exists($f)) { echo $f, "\0"; } }' \
        | tar --null -T - -cf - | tar -xf - -C "$WORK"
else
    git archive --format=tar HEAD | tar -xf - -C "$WORK"
fi
(cd "$WORK" && composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet)

# The version replaces @package_version@ in src/Version.php.
# shellcheck disable=SC2016
php -r '
    $f = $argv[1] . "/box.json";
    $c = json_decode(file_get_contents($f), true);
    $c["replacements"] = ["package_version" => $argv[2]];
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$WORK" "$VERSION"

# --- 3. compile ---------------------------------------------------------------------
(cd "$WORK" && $BOX compile --no-interaction --quiet)

# --- 4. outputs + checksum ------------------------------------------------------------
mkdir -p "$DIST"
cp "$WORK/dist/cpdeploy.phar" "$DIST/cpdeploy.phar"
(cd "$DIST" && sha256sum cpdeploy.phar > cpdeploy.phar.sha256)

# install.sh with the launcher embedded, so a release needs only three assets.
LAUNCHER_B64="$(base64 -w0 < "$ROOT/scripts/launcher.sh")"
sed "s|^EMBEDDED_LAUNCHER_B64=\"\"\$|EMBEDDED_LAUNCHER_B64=\"$LAUNCHER_B64\"|" "$ROOT/scripts/install.sh" > "$DIST/install.sh"
grep -q "^EMBEDDED_LAUNCHER_B64=\"$LAUNCHER_B64\"" "$DIST/install.sh" || die "couldn't embed the launcher in install.sh"
chmod 755 "$DIST/install.sh"

# --- 5. smoke test ----------------------------------------------------------------------
printed="$(php "$DIST/cpdeploy.phar" --version)"
case "$printed" in
    "cpdeploy $VERSION "*) ;;
    *) die "smoke test failed: --version printed: $printed" ;;
esac

echo "Built $DIST/cpdeploy.phar ($VERSION)"
cat "$DIST/cpdeploy.phar.sha256"
