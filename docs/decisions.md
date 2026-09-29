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
