# Decision log

Judgement calls made where the plan is silent or ambiguous (plan §0 rule 5), and
anything that deviates from the letter of the plan. Newest last.

| Date | Question | Choice | Reason |
|---|---|---|---|
| 2026-09-29 | `update.repo` default (plan §0.1 input 1) | `jay-brainbean/cpdeploy`, public | Owner's answer. Self-update needs no token while the repo is public |
| 2026-09-29 | ARC-01: use Laravel Prompts' `task()`? | No. `Ui/TaskReporter` implements the same look itself (spinner, last 10 lines dimmed, collapse to `✓ Step 12s`) | `task()` exists in 0.3.24, but it forks a renderer process and replaces the SIGINT handler with `exit()`. That breaks LCK-04 (cancel flag, deferred signals during go-live) |
| 2026-09-29 | How does the spinner animate without pcntl (ARC-02)? | `Shell` calls the reporter's `tick()` about every 100 ms while a command runs | No fork or timer signal needed, so it works with and without pcntl |
| 2026-09-29 | Classes not listed in §6.3 | Added `Support/Signals` (LCK-04 flag and critical sections), `Support/RunOptions` (SH-02 declaration), `Support/Environment` (test-only variable gate, §16.2), `Support/SystemInfo` (PHP/user facts, testable), `Check/CheckResult`, `Check/CheckGroup` | One responsibility per file; each supports a listed requirement |
| 2026-09-29 | Error codes for usage errors and bugs | Added `E_USAGE` (exit 2) and `E_INTERNAL` (exit 1) to the §13 enum | §10.3 defines exit 2 for usage errors and exit 1 for bugs, but §13 has no code for them |
| 2026-09-29 | Where the stack trace of an unexpected error goes (§10.3 "stack trace in the log") | `~/cpdeploy/crash.log` (600), overwritten by the next crash | Not every crash has a site log. `tmp/` is cleaned after 24 h |
| 2026-09-29 | Error output with `--json` | The §13 text goes to stderr; stdout gets `{"schema":1,"error":{code,message,hint,live_affected,exit_code}}` | Keeps NI-05 (stdout holds only JSON) and gives scripts a parseable error |
| 2026-09-29 | LOG-04: mask very short secret values? | Values shorter than 4 characters are not masked | Masking a value such as `web` would replace that word everywhere in logs. Over-masking longer values is accepted |
| 2026-09-29 | SH-03: which inherited variables reach child processes? | Only an allowlist: `USER`, `LOGNAME`, `SHELL`, `TERM`, `COLUMNS`, `LINES`, `TZ`, `TMPDIR`, proxy variables and CA-bundle variables. In test mode also `CPDEPLOY_*` and `CPD_*` | HTTP-01 requires honouring `https_proxy`; hosts with TLS inspection need the CA variables. Everything else is dropped |
| 2026-09-29 | How tests put fake binaries first on PATH | Test-only variable `CPDEPLOY_TEST_PATH` (ignored unless `CPDEPLOY_TESTING=1`) is inserted before `~/bin` in SH-03's PATH | SH-03 fixes PATH, so the harness can't just prepend to the caller's PATH |
| 2026-09-29 | Box `git-version` vs build-time replacement | `build.sh` compiles from a `git archive` copy (no `.git`), so it writes the version into box.json `replacements` | Same effect as `git-version` (`@package_version@` replaced with the tag), and it also works for `--dirty` builds |
| 2026-09-29 | Where install.sh gets the launcher | `build.sh` embeds `scripts/launcher.sh` (base64) into `dist/install.sh`; from a source checkout, install.sh copies `launcher.sh` from its own folder | A release only ships three assets (§15.4); one source of truth for the launcher |
| 2026-09-29 | Box version (BLD-10) | Box 4.7.0, pinned by SHA-256 in `build.sh` | Latest 4.x at the time; downloaded and verified when `box` on PATH is another version |
| 2026-09-29 | `--version` as root | Allowed; handled before the root check (CLI-01) | It prints one line and touches nothing; the installer and build smoke tests call it |
| 2026-09-29 | Commands that don't create `~/cpdeploy` (LAY-02) | `list`, `help`, `completion` and `--version` | Asking for help shouldn't create folders |
| 2026-09-29 | `cpdeploy` with no arguments on a TTY before M5 | Shows the command list | UIG-01's main menu arrives in M5 |
| 2026-09-29 | When is `config.yml` validated? | On every start (bootstrap) | A broken file is reported before an operation starts, not half-way through |
| 2026-09-29 | `composer.json` licence | `proprietary` until the owner chooses one (plan §0.1 input 4, needed by M7) | `composer validate --strict` requires a value |
| 2026-09-29 | SH-05 without pcntl | Commands start under `setsid` only when pcntl is available | `setsid` moves a command out of the terminal's process group. Without pcntl, cpdeploy can't catch Ctrl+C to stop it, so the command would keep running after cpdeploy dies. Without `setsid`, the terminal's SIGINT reaches it directly |
| 2026-09-29 | Ctrl+C inside a Laravel Prompts question | `PromptsAsker` sets `Prompt::cancelUsing()` to restore the terminal and throw `E_CANCELLED` (exit 130) | Prompts' default is `exit(1)`, which skips wizard clean-up (UI-02) and gives the wrong exit code |
| 2026-09-29 | M1: when is a MultiPHP vhost "inherit"? (PHP-03) | Inherited unless `phpversion_source` is `{"domain": "<the vhost itself>"}` | The real server only showed that form (every vhost had its own version). Treating anything else as inherited errs towards checking PHP Selector / the system default, which never changes the domain |
| 2026-09-29 | M1: is `/opt/alt/php*` proof of CloudLinux? | No. CloudLinux = `/etc/cloudlinux-release`; PHP Selector = CloudLinux **and** alt-php binaries | The test server (plain AlmaLinux 8) has `/opt/alt/php*` folders installed by Imunify360 |
| 2026-09-29 | M1: `uapi` failure detection | Only `result.status`; the exit code is ignored and any text before the JSON is skipped | Real `uapi` exits 0 on failure and prints a warning line first |
| 2026-09-29 | M1: MySQL restriction field names | Read `max_database_name_length` / `max_username_length` (real), falling back to the plan's draft names | The draft names in CP-04 were wrong |
| 2026-09-29 | M1: refreshing Composer fails but an older verified copy exists | Use the cached copy and warn | That copy passed its SHA-256 check when downloaded (SEC-06 forbids only unverified copies); a network blip shouldn't stop a deploy |
| 2026-09-29 | NODE-08: pnpm/yarn version when `packageManager` doesn't name one | pnpm → `pnpm@latest` in `tools/pm/pnpm-latest`; yarn v1 → `yarn@1` in `tools/pm/yarn-1` | "The latest major" in the plan; installed once and reused |
| 2026-09-29 | NODE-02: which words mean "newest version"? | `node`, `latest`, `current`, and also `stable` | `stable` is a common nvm alias with the same meaning |
| 2026-09-29 | `check` Network group | TCP connects to all hosts in parallel (5 s timeout); `api.github.com` only when a token file exists; Composer/Node hosts come from `mirrors.*` | Keeps `check` under the 20 s target (§14) and matches what each download actually uses |
| 2026-09-29 | New test-only variables | `CPDEPLOY_NODE_SEARCH_PATHS` (glob patterns), `CPDEPLOY_SYSTEM_ROOT` (fake `/etc`, `/opt`, `/usr/local`), `CPDEPLOY_ARCH`, `CPDEPLOY_GLIBC`, `CPDEPLOY_TCP_OVERRIDE`, `CPDEPLOY_HTTP_NO_BACKOFF` | All ignored unless `CPDEPLOY_TESTING=1` (§16.2) |
| 2026-09-29 | Composer's `latest-2.2.x` channel | Kept as in CMP-01, still unverified: getcomposer.org is unreachable from the development sandbox. `scripts/capture-fixtures.sh` now probes it on the next server | Needs a real check before M3 relies on it |
