#!/usr/bin/env bash
# cpdeploy installer / uninstaller (§15.3). Run as the cPanel user, never root.
#
#   bash install.sh [./cpdeploy.phar] [--sha256=<hex>]   install a local phar
#   bash install.sh --download [vX.Y.Z]                  download from GitHub Releases
#   bash install.sh --uninstall                          remove the tool (sites keep running)
set -euo pipefail

# GitHub repository that publishes releases (update.repo, §8.1).
CPD_REPO="${CPDEPLOY_REPO:-jay-brainbean/cpdeploy}"
# Filled in by scripts/build.sh for release assets: the launcher, base64-encoded.
EMBEDDED_LAUNCHER_B64=""

CPD_ROOT="$HOME/cpdeploy"
CPD_APP="$CPD_ROOT/app"
CPD_BIN="$HOME/bin"
PATH_MARKER="# added by cpdeploy"
PATH_LINE="export PATH=\"\$HOME/bin:\$PATH\"  $PATH_MARKER"

say() { printf '%s\n' "$*"; }
fail() {
    printf 'cpdeploy install: %s\n' "$1" >&2
    [ -n "${2:-}" ] && printf '  Fix: %s\n' "$2" >&2
    exit "${3:-3}"
}

# --- arguments -----------------------------------------------------------------
PHAR=""
SHA256=""
DOWNLOAD=0
VERSION=""
UNINSTALL=0
while [ $# -gt 0 ]; do
    case "$1" in
        --sha256=*) SHA256="${1#--sha256=}" ;;
        --download) DOWNLOAD=1
            if [ $# -gt 1 ] && [[ "$2" == v* ]]; then VERSION="$2"; shift; fi ;;
        --uninstall) UNINSTALL=1 ;;
        -h|--help)
            sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        -*) fail "unknown option $1" "bash install.sh --help" 2 ;;
        *) PHAR="$1" ;;
    esac
    shift
done

# --- 1. refuse root, check programs ----------------------------------------------
if [ "$(id -u)" = "0" ]; then
    fail "run the installer as the cPanel user, not root" "su - <user> -s /bin/bash, then run it again" 2
fi

if [ "$UNINSTALL" = "1" ]; then
    rm -f "$CPD_BIN/cpdeploy"
    rm -rf "$CPD_APP"
    for rc in "$HOME/.bashrc" "$HOME/.bash_profile"; do
        if [ -f "$rc" ] && grep -qF "$PATH_MARKER" "$rc"; then
            tmp="$(mktemp "$rc.cpd-tmp-XXXXXX")"
            grep -vF "$PATH_MARKER" "$rc" > "$tmp" || true
            cat "$tmp" > "$rc"
            rm -f "$tmp"
        fi
    done
    say "cpdeploy was removed."
    say "Your sites keep running. Their files are in ~/cpdeploy_sites and their settings in ~/cpdeploy/sites; remove them yourself if you're sure."
    exit 0
fi

command -v sha256sum >/dev/null 2>&1 || fail "sha256sum was not found" "ask your host to make coreutils available"
if [ "$DOWNLOAD" = "1" ]; then
    command -v curl >/dev/null 2>&1 || fail "curl was not found (needed for --download)" "download cpdeploy.phar yourself and pass its path"
fi

# --- 2. find the Tool PHP (same rules as the launcher, LCH-01…03) ------------------
php_ok() {
    if [ -z "$1" ] || [ ! -x "$1" ]; then return 1; fi
    # shellcheck disable=SC2086
    "$1" ${CPDEPLOY_PHP_ARGS:-} -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("phar") && extension_loaded("mbstring") ? 0 : 1);' >/dev/null 2>&1
}
php_candidates() {
    for p in /opt/cpanel/ea-php*/root/usr/bin/php; do if [ -x "$p" ]; then echo "$p"; fi; done | sort -r
    for p in /opt/alt/php*/usr/bin/php; do if [ -x "$p" ]; then echo "$p"; fi; done | sort -r
    command -v php 2>/dev/null || true
}
TOOL_PHP=""
if [ -n "${CPDEPLOY_PHP:-}" ]; then
    php_ok "$CPDEPLOY_PHP" || fail "CPDEPLOY_PHP=$CPDEPLOY_PHP is not PHP 8.1 or newer with phar and mbstring"
    TOOL_PHP="$CPDEPLOY_PHP"
else
    while IFS= read -r candidate; do
        if php_ok "$candidate"; then TOOL_PHP="$candidate"; break; fi
    done < <(php_candidates)
fi
if [ -z "$TOOL_PHP" ]; then
    installed=""
    while IFS= read -r p; do
        v="$("$p" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
        [ -n "$v" ] && installed="$installed $v ($p)"
    done < <(php_candidates)
    fail "cpdeploy needs PHP 8.1 or newer with the phar and mbstring extensions. Installed:${installed:- none}" \
        "Ask your host to install ea-php82 (or newer) — it doesn't need to be your sites' version."
fi
# shellcheck disable=SC2016,SC2086
disabled="$("$TOOL_PHP" ${CPDEPLOY_PHP_ARGS:-} -r '$d = array_map("trim", explode(",", (string) ini_get("disable_functions"))); foreach (["proc_open", "exec", "shell_exec"] as $f) { if (in_array($f, $d, true)) { echo $f, " "; } }' 2>/dev/null || true)"
if [ -n "$disabled" ]; then
    fail "cpdeploy needs proc_open, exec and shell_exec, which are disabled for $TOOL_PHP ($disabled)" \
        "In WHM → MultiPHP INI Editor, remove them from disable_functions for this PHP version, or set CPDEPLOY_PHP_ARGS=\"-d disable_functions=\" if your host allows it."
fi
say "Tool PHP: $TOOL_PHP ($("$TOOL_PHP" -r 'echo PHP_VERSION;'))"

# --- 3. get and verify the phar ---------------------------------------------------
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cpd-install-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

if [ "$DOWNLOAD" = "1" ]; then
    if [ -n "$VERSION" ]; then
        base="https://github.com/$CPD_REPO/releases/download/$VERSION"
    else
        base="https://github.com/$CPD_REPO/releases/latest/download"
    fi
    say "Downloading cpdeploy ${VERSION:-(latest)} from github.com/$CPD_REPO…"
    curl -fsSL --connect-timeout 10 --max-time 300 -o "$WORK/cpdeploy.phar" "$base/cpdeploy.phar" \
        || fail "couldn't download cpdeploy.phar from $base" "check the network, or download it yourself and pass its path"
    curl -fsSL --connect-timeout 10 --max-time 60 -o "$WORK/cpdeploy.phar.sha256" "$base/cpdeploy.phar.sha256" \
        || fail "couldn't download cpdeploy.phar.sha256 from $base" "check the network, or download it yourself and pass its path"
    SHA256="$(awk '{print $1; exit}' "$WORK/cpdeploy.phar.sha256")"
    PHAR="$WORK/cpdeploy.phar"
else
    if [ -z "$PHAR" ]; then
        here="$(cd "$(dirname "$0")" && pwd)"
        for guess in "./cpdeploy.phar" "$here/cpdeploy.phar"; do
            if [ -f "$guess" ]; then PHAR="$guess"; break; fi
        done
    fi
    if [ -z "$PHAR" ] || [ ! -f "$PHAR" ]; then
        fail "no cpdeploy.phar given" "bash install.sh ./cpdeploy.phar, or bash install.sh --download" 2
    fi
    if [ -z "$SHA256" ] && [ -f "$PHAR.sha256" ]; then
        SHA256="$(awk '{print $1; exit}' "$PHAR.sha256")"
    fi
    cp "$PHAR" "$WORK/cpdeploy.phar"
    PHAR="$WORK/cpdeploy.phar"
fi

if [ -n "$SHA256" ]; then
    actual="$(sha256sum "$PHAR" | awk '{print $1}')"
    if [ "$actual" != "$SHA256" ]; then
        fail "cpdeploy.phar failed its checksum — not installed" "download it again; if it repeats, check where it comes from"
    fi
    say "Checksum OK."
else
    say "No checksum given: skipping verification (pass --sha256=<hex> to verify)."
fi
# shellcheck disable=SC2086
if ! "$TOOL_PHP" ${CPDEPLOY_PHP_ARGS:-} "$PHAR" --version >/dev/null 2>"$WORK/version.err"; then
    fail "the phar doesn't run with $TOOL_PHP: $(head -n 3 "$WORK/version.err")" "make sure you downloaded cpdeploy.phar completely"
fi

# --- 4. put the phar in place atomically ------------------------------------------
umask 022
mkdir -p "$CPD_APP"
chmod 711 "$CPD_ROOT"
chmod 755 "$CPD_APP"
if [ -f "$CPD_APP/cpdeploy.phar" ]; then
    cp -p "$CPD_APP/cpdeploy.phar" "$CPD_APP/cpdeploy.phar.prev"
fi
cp "$PHAR" "$CPD_APP/cpdeploy.phar.cpd-tmp"
chmod 755 "$CPD_APP/cpdeploy.phar.cpd-tmp"
mv -f "$CPD_APP/cpdeploy.phar.cpd-tmp" "$CPD_APP/cpdeploy.phar"
printf '%s\n' "$TOOL_PHP" > "$CPD_APP/.php-path"
chmod 600 "$CPD_APP/.php-path"

# --- 5. install the launcher ------------------------------------------------------
mkdir -p "$CPD_BIN"
if [ -n "$EMBEDDED_LAUNCHER_B64" ]; then
    printf '%s' "$EMBEDDED_LAUNCHER_B64" | base64 -d > "$CPD_BIN/cpdeploy.cpd-tmp"
else
    launcher="$(cd "$(dirname "$0")" && pwd)/launcher.sh"
    [ -f "$launcher" ] || fail "launcher.sh was not found next to install.sh" "use the install.sh from a release, or run it from the source's scripts/ folder"
    cp "$launcher" "$CPD_BIN/cpdeploy.cpd-tmp"
fi
chmod 755 "$CPD_BIN/cpdeploy.cpd-tmp"
mv -f "$CPD_BIN/cpdeploy.cpd-tmp" "$CPD_BIN/cpdeploy"

# --- 6. PATH ----------------------------------------------------------------------
case ":$PATH:" in
    *":$HOME/bin:"*) ;;
    *)
        for rc in "$HOME/.bashrc" "$HOME/.bash_profile"; do
            if ! { [ -f "$rc" ] && grep -qF "$PATH_MARKER" "$rc"; }; then
                printf '\n%s\n' "$PATH_LINE" >> "$rc"
            fi
        done
        say "Added ~/bin to your PATH. Open a new terminal, or run: source ~/.bashrc"
        ;;
esac

# --- 7. check and next steps --------------------------------------------------------
say ""
say "cpdeploy $("$CPD_BIN/cpdeploy" --version | awk '{print $2}') is installed."
say ""
"$CPD_BIN/cpdeploy" check || true
say ""
say "Next: run  cpdeploy"
