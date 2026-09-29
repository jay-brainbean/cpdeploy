#!/usr/bin/env bash
# shellcheck disable=SC2016,SC2012  # PHP snippets are single-quoted on purpose
# cpdeploy fixture capture — READ-ONLY. Collects what cpdeploy needs to know
# about a cPanel server, for test fixtures (plan §17 M1, Appendix B).
#
# Run as the cPanel user (not root), in SSH or cPanel → Terminal:
#     bash capture-fixtures.sh              # values anonymised (default)
#     bash capture-fixtures.sh --raw        # keep real domains, IPs and user name
#
# It only reads: uapi "get/list" calls, PHP/Node version probes, program
# versions, and outbound TCP connection tests. It creates nothing on the account
# except the final archive in your home folder (mode 600), and changes no setting.
# No passwords, tokens or .env contents are collected.
set -uo pipefail

if [ "$(id -u)" = "0" ]; then
    echo "Run this as the cPanel user, not root." >&2
    exit 2
fi

RAW=0
[ "${1:-}" = "--raw" ] && RAW=1

STAMP="$(date -u +%Y%m%d-%H%M%S)"
OUT="$(mktemp -d "${TMPDIR:-/tmp}/cpd-capture-XXXXXX")"
chmod 700 "$OUT"
trap 'rm -rf "$OUT"' EXIT
ARCHIVE="$HOME/cpdeploy-capture-$STAMP.tar.gz"

run() { # run <file> <command...> : stdout+stderr and exit code into <file>
    local file="$OUT/$1"; shift
    mkdir -p "$(dirname "$file")"
    { "$@"; echo "[exit $?]"; } > "$file" 2>&1
}

echo "Collecting (read-only)…"

# --- 1. cPanel UAPI (CP-04; resolves every "(verify)" field) ----------------------
if command -v uapi >/dev/null 2>&1; then
    run uapi/which.txt command -v uapi
    run uapi/DomainInfo/domains_data.json            uapi --output=json DomainInfo domains_data format=hash
    run uapi/DomainInfo/list_domains.json            uapi --output=json DomainInfo list_domains
    run uapi/LangPHP/php_get_installed_versions.json uapi --output=json LangPHP php_get_installed_versions
    run uapi/LangPHP/php_get_vhost_versions.json     uapi --output=json LangPHP php_get_vhost_versions
    run uapi/LangPHP/php_get_system_default_version.json uapi --output=json LangPHP php_get_system_default_version
    run uapi/Mysql/get_restrictions.json             uapi --output=json Mysql get_restrictions
    run uapi/Mysql/list_databases.json               uapi --output=json Mysql list_databases
    run uapi/Mysql/list_users.json                   uapi --output=json Mysql list_users
    run uapi/Quota/get_quota_info.json               uapi --output=json Quota get_quota_info
    # An error response, to capture the failure format (CP-01).
    run uapi/errors/unknown_function.json            uapi --output=json LangPHP no_such_function_cpdeploy
else
    echo "uapi not found" > "$OUT/uapi-missing.txt"
fi

# --- 2. PHP binaries (PHP-01/02/03) ------------------------------------------------
php_probe() { # php_probe <binary> <name>
    local bin="$1" name="$2"
    run "php/$name/version.txt" "$bin" -r 'echo PHP_VERSION,"|",PHP_SAPI,"|",PHP_BINARY,"\n";'
    run "php/$name/modules.txt" "$bin" -m
    run "php/$name/ini.txt" "$bin" -r 'foreach (["disable_functions","memory_limit","realpath_cache_ttl","open_basedir"] as $k) echo $k,"=",ini_get($k),"\n";'
}
for bin in /opt/cpanel/ea-php*/root/usr/bin/php; do
    [ -x "$bin" ] || continue
    php_probe "$bin" "$(echo "$bin" | sed -E 's#/opt/cpanel/(ea-php[0-9]+)/.*#\1#')"
done
for bin in /opt/alt/php*/usr/bin/php; do
    [ -x "$bin" ] || continue
    php_probe "$bin" "alt-$(echo "$bin" | sed -E 's#/opt/alt/(php[0-9]+)/.*#\1#')"
done
for bin in /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null)"; do
    if [ -z "$bin" ] || [ ! -x "$bin" ]; then continue; fi
    php_probe "$bin" "path-$(echo "$bin" | tr '/' '_')"
done
# CloudLinux PHP Selector reflects the domain's choice when run from its docroot (PHP-03 step 2).
if [ -d "$HOME/public_html" ] && [ -x /usr/local/bin/php ]; then
    run php/selector-in-public_html.txt bash -c "cd \"\$HOME/public_html\" && /usr/local/bin/php -r 'echo PHP_BINARY,\"|\",PHP_VERSION,\"\n\";'"
fi

# --- 3. cPanel PHP handler block in .htaccess (DOC-04) — handler lines only ----------
mkdir -p "$OUT/htaccess"
for ht in "$HOME"/public_html/.htaccess "$HOME"/*/.htaccess; do
    [ -f "$ht" ] || continue
    name="$(echo "${ht#"$HOME"/}" | tr '/' '_')"
    awk '/# php -- BEGIN cPanel-generated handler/,/# php -- END cPanel-generated handler/' "$ht" > "$OUT/htaccess/$name.handler.txt"
done
if [ -f "$HOME/public_html/.user.ini" ]; then
    echo "public_html/.user.ini exists ($(wc -l < "$HOME/public_html/.user.ini") lines)" > "$OUT/htaccess/user-ini.txt"
fi

# --- 4. CloudLinux / CageFS --------------------------------------------------------
run system/os-release.txt cat /etc/os-release
run system/cloudlinux-release.txt cat /etc/cloudlinux-release
run system/cagefs.txt bash -c 'ls -d /var/cagefs 2>&1; test -e /bin/cagefs_enter && echo "cagefs_enter present"; ls /opt/alt 2>&1 | head -50'
run system/cpanel-version.txt cat /usr/local/cpanel/version

# --- 5. Node candidates (NODE-01) and glibc -------------------------------------------
run node/candidates.txt bash -c '
    for n in /opt/cpanel/ea-nodejs*/bin/node /opt/alt/alt-nodejs*/root/usr/bin/node "$HOME"/.nvm/versions/node/*/bin/node "$(command -v node 2>/dev/null)"; do
        [ -n "$n" ] && [ -x "$n" ] && echo "$n => $("$n" --version 2>&1)"
    done; true'
run node/npm.txt bash -c 'command -v npm && npm --version; command -v pnpm; command -v yarn; true'
run system/glibc.txt ldd --version
run system/arch.txt uname -m

# --- 6. Programs, shell and account (§5.1, `check`) ---------------------------------------
run system/programs.txt bash -c '
    for p in git ssh ssh-keygen tar gzip curl stty du cp rm less setsid flock base64 sha256sum; do
        printf "%-11s %s\n" "$p" "$(command -v "$p" || echo MISSING)"
    done
    git --version; ssh -V 2>&1; curl --version | head -1; cp --version | head -1; tar --version | head -1'
run system/account.txt bash -c 'id; echo "shell=$SHELL"; echo "umask=$(umask)"; echo "LANG=${LANG:-} LC_ALL=${LC_ALL:-}"; ls -ld "$HOME" "$HOME/public_html" "$HOME/.ssh" 2>&1; ls -la "$HOME" | head -60'
run system/limits.txt bash -c 'ulimit -a; cat /proc/self/cgroup 2>/dev/null | head -5'

# --- 7. Network (§5.1) ------------------------------------------------------------------
tcp() { timeout 6 bash -c "exec 3<>/dev/tcp/$1/$2" 2>&1 && echo "$1:$2 OK" || echo "$1:$2 FAILED"; }
{
    tcp github.com 22; tcp ssh.github.com 443; tcp api.github.com 443
    tcp getcomposer.org 443; tcp nodejs.org 443
} > "$OUT/network.txt" 2>&1
run network-https.txt bash -c 'for u in https://api.github.com/meta https://getcomposer.org/versions https://nodejs.org/dist/index.json https://getcomposer.org/download/latest-2.x/composer.phar.sha256 https://getcomposer.org/download/latest-2.2.x/composer.phar.sha256; do printf "%s -> " "$u"; curl -sS -o /dev/null -w "%{http_code}\n" --max-time 20 "$u" 2>&1; done'

# --- anonymise -------------------------------------------------------------------------------
if [ "$RAW" = "0" ]; then
    PHPBIN="$(command -v php || ls /opt/cpanel/ea-php*/root/usr/bin/php 2>/dev/null | tail -1)"
    if [ -n "$PHPBIN" ]; then
        "$PHPBIN" -r '
            $dir = $argv[1]; $user = $argv[2]; $home = $argv[3];
            $map = [$home => "/home/cpuser", $user => "cpuser"];
            $raw = (string) @file_get_contents("$dir/uapi/DomainInfo/domains_data.json");
            $dd = json_decode(preg_replace("/\\n?\\[exit \\d+\\]\\s*$/", "", $raw), true);
            $data = $dd["result"]["data"] ?? [];
            $domains = []; $ips = [];
            foreach (["main_domain"] as $k) if (isset($data[$k])) { $domains[] = $data[$k]["domain"] ?? null; $ips[] = $data[$k]["ip"] ?? null; }
            foreach (["addon_domains","sub_domains","parked_domains"] as $k) foreach (($data[$k] ?? []) as $d) { $domains[] = $d["domain"] ?? null; $domains[] = $d["servername"] ?? null; $ips[] = $d["ip"] ?? null; }
            $domains = array_values(array_unique(array_filter($domains)));
            usort($domains, fn($a, $b) => strlen($b) <=> strlen($a));
            foreach ($domains as $i => $d) $map[$d] = "site" . ($i + 1) . ".example.test";
            foreach (array_values(array_unique(array_filter($ips))) as $i => $ip) $map[$ip] = "192.0.2." . ($i + 10);
            // MySQL database and user names often carry project or client names.
            $prefix = $user . "_"; $n = 0; $names = [];
            foreach (["list_databases" => "database", "list_users" => "user"] as $fn => $key) {
                $raw = (string) @file_get_contents("$dir/uapi/Mysql/$fn.json");
                $j = json_decode(preg_replace("/\\n?\\[exit \\d+\\]\\s*$/", "", $raw), true);
                foreach (($j["result"]["data"] ?? []) as $row) {
                    foreach (array_merge([$row[$key] ?? null], $row["users"] ?? [], $row["databases"] ?? []) as $name) {
                        if (is_string($name) && $name !== "" && !isset($names[$name])) {
                            $names[$name] = str_starts_with($name, $prefix) ? "cpuser_db" . (++$n) : "db" . (++$n);
                        }
                    }
                    if (isset($row["shortuser"], $row["user"]) && isset($names[$row["user"]])) {
                        $names[$row["shortuser"]] = substr($names[$row["user"]], strlen("cpuser_"));
                    }
                }
            }
            foreach ($names as $from => $to) { $map["\"" . $from . "\""] = "\"" . $to . "\""; }
            $host = gethostname(); if ($host) $map[$host] = "server.example.test";
            uksort($map, fn($a, $b) => strlen($b) <=> strlen($a));
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { $t = file_get_contents($f); file_put_contents($f, strtr($t, $map)); }
            echo "Anonymised ", count($map), " values (user, home, domains, IPs, hostname).\n";
        ' "$OUT" "$(id -un)" "$HOME"
        echo "anonymised" > "$OUT/ANONYMISED"
    else
        echo "No PHP found to anonymise with; archive contains raw values." >&2
    fi
fi

date -u > "$OUT/captured-at.txt"
( umask 077; tar -czf "$ARCHIVE" -C "$OUT" . )
chmod 600 "$ARCHIVE"
echo
echo "Done: $ARCHIVE"
echo "Look through it if you like (tar -tzf \"$ARCHIVE\"), then send it back."
