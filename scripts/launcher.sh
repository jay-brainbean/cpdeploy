#!/bin/sh
# cpdeploy launcher, installed as ~/bin/cpdeploy (§15.2).
# POSIX sh with no bashisms, so it also works in cPanel's jailshell.
# Picks a PHP >= 8.1 with phar and mbstring to run the tool; that PHP is
# independent of the PHP versions your sites use.

CPD_APP="$HOME/cpdeploy/app"
CPD_PHAR="$CPD_APP/cpdeploy.phar"
CPD_CACHE="$CPD_APP/.php-path"

# LCH-01 acceptance test for one candidate.
cpd_php_ok() {
    if [ -z "$1" ] || [ ! -x "$1" ]; then return 1; fi
    # shellcheck disable=SC2086,SC2016
    "$1" $CPDEPLOY_PHP_ARGS -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("phar") && extension_loaded("mbstring") ? 0 : 1);' >/dev/null 2>&1
}

# Candidates in LCH-01 order, one per line (highest version first per family).
cpd_candidates() {
    if [ -f "$CPD_CACHE" ]; then
        head -n 1 "$CPD_CACHE"
    fi
    for p in /opt/cpanel/ea-php*/root/usr/bin/php; do if [ -x "$p" ]; then echo "$p"; fi; done | sort -r
    for p in /opt/alt/php*/usr/bin/php; do if [ -x "$p" ]; then echo "$p"; fi; done | sort -r
    command -v php 2>/dev/null
}

# Every PHP found, for the LCH-03 message.
cpd_installed() {
    for p in $(cpd_candidates); do
        # shellcheck disable=SC2016
        v=$("$p" -r 'echo PHP_VERSION;' 2>/dev/null) && printf '%s (%s) ' "$v" "$p"
    done
}

if [ -n "${CPDEPLOY_PHP:-}" ]; then
    if ! cpd_php_ok "$CPDEPLOY_PHP"; then
        echo "cpdeploy: CPDEPLOY_PHP=$CPDEPLOY_PHP is not PHP 8.1 or newer with the phar and mbstring extensions." >&2
        exit 3
    fi
    PHP="$CPDEPLOY_PHP"
else
    PHP=""
    for candidate in $(cpd_candidates); do
        if cpd_php_ok "$candidate"; then
            PHP="$candidate"
            break
        fi
    done
    if [ -z "$PHP" ]; then
        # LCH-03
        echo "cpdeploy needs PHP 8.1 or newer with the phar and mbstring extensions. Installed: $(cpd_installed)" >&2
        echo "Ask your host to install ea-php82 (or newer) — it doesn't need to be your sites' version." >&2
        exit 3
    fi
    if [ -d "$CPD_APP" ]; then
        ( umask 077; printf '%s\n' "$PHP" > "$CPD_CACHE" ) 2>/dev/null
    fi
fi

# LCH-02: the tool runs other programs, so these functions must be allowed.
# (The PHP code is single-quoted on purpose.)
# shellcheck disable=SC2086,SC2016
disabled=$("$PHP" $CPDEPLOY_PHP_ARGS -r '$d = array_map("trim", explode(",", (string) ini_get("disable_functions"))); foreach (["proc_open", "exec", "shell_exec"] as $f) { if (in_array($f, $d, true)) { echo $f, " "; } }' 2>/dev/null)
if [ -n "$disabled" ]; then
    version=$("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null)
    echo "cpdeploy needs proc_open, exec and shell_exec, which are disabled for PHP $version (disable_functions: $disabled)." >&2
    echo "Fix: in WHM → MultiPHP INI Editor, remove them from disable_functions for this PHP version, or run with CPDEPLOY_PHP_ARGS=\"-d disable_functions=\" if your host allows it." >&2
    exit 3
fi

if [ ! -f "$CPD_PHAR" ]; then
    echo "cpdeploy: $CPD_PHAR is missing. Reinstall with install.sh." >&2
    exit 3
fi

# LCH-04
# shellcheck disable=SC2086
exec "$PHP" $CPDEPLOY_PHP_ARGS -d memory_limit=512M -d display_errors=stderr "$CPD_PHAR" "$@"
