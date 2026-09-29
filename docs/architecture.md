# Architecture

The full specification is `cpdeploy-development-plan.md` (§6). This page
summarises the rules and the pieces that exist so far.

## Layers

```text
Presentation   Commands/  Menus/  Wizard/        no business logic
     │ calls
Services       Deploy/, Config/, Env/, Runtime/, Git/, Check/, …
     │ uses
Adapters       Support/Shell, Support/Http, Support/Fs, Cpanel/Uapi, GitHub/…
```

- A menu action and its CLI command call the same service method (ARC-03).
- Services never call Laravel Prompts. They get answers from an `Ui\Asker`
  and report progress to an `Ui\Reporter` (ARC-04):

  | Situation | Asker | Reporter |
  |---|---|---|
  | Terminal | `PromptsAsker` | `TaskReporter` |
  | No TTY / `-n` | `NonInteractiveAsker` | `PlainReporter` |
  | Tests | `ScriptedAsker` | `MemoryReporter` |

- Every external program runs through `Support\Shell` (ARC-05).
- Every path comes from `Config\Paths` (ARC-06).
- Objects are wired by hand in `src/Services.php` (ARC-07).
- Every user-facing error is a `CpdeployException` with an `ErrorCode`
  (ARC-09); `Application` renders it in the §13 format.

## Startup

`bin/cpdeploy` → `Application::doRun()`:

1. `umask 022` (FS-06); `NO_COLOR` handling.
2. `--version` prints and exits.
3. Refuse root (CLI-01) unless the hidden `--allow-root` is given.
4. Except for `list` and `help`: create `~/cpdeploy` with its modes, re-apply
   the modes of secrets (LAY-02), load and validate `config.yml`, clean stale
   `tmp/` entries (LAY-03).
5. Install signal handlers (LCK-04), then run the command.
6. `CpdeployException` → §13 message and its exit code. Any other exception →
   exit 1 and `~/cpdeploy/crash.log`.

## Running commands (`Support\Shell`)

- Argument arrays, never a shell, except `pipeline()` (`bash -o pipefail -c`)
  built from `Shell::quote()`d parts (SH-01).
- The environment is rebuilt from scratch: a fixed base plus the run's
  additions; `GIT_DIR` and `GIT_WORK_TREE` are never passed (SH-03).
- Each command starts under `setsid`. A timeout or Ctrl+C sends SIGTERM to the
  whole process group, then SIGKILL after 10 s (SH-05).
- Output is masked, then written to the operation log and streamed to the
  reporter (SH-04, LOG-04).
- `ProcessResult` recognises out-of-memory (SH-06) and full-disk (SH-07)
  failures; `Shell::failure()` turns them into `E_OOM` / `E_DISK`.

## Filesystem safety (`Support\Fs`)

- `writeAtomic()`: temp file with the final mode, fsync, rename (FS-01).
- `swapSymlink()`: new link under a temp name, renamed over the old one;
  never `ln -sfn` (FS-02). `linkRelative()` always writes relative links (LAY-01).
- `deleteTree()` never follows a symlink, only deletes inside `tmp/`, `tools/`
  or a site's `releases/`, refuses the live release, and refuses any path
  reached through a symlinked folder (FS-03).

## Sites and releases

- `Config/SiteRegistry` loads `site.yml`: schema migration, then §8.2 defaults
  ← the type's preset (`Config/Presets`, `resources/presets/*.yml`) ← the
  file, then validation (`Schema/SiteSchema`, VAL-01…12, SEC-11). The result is
  an immutable `SiteConfig`; `with()` + `save()` change it.
- `Deploy/ReleaseManager` creates `releases/<id>` folders, reads and writes
  `.release.json` (`Release`), finds the live release through `current`, and
  prunes (PR-01) through `Fs::deleteTree()`.
- `Deploy/StateFile` is `.deploy-state.json`, written before every step that can
  touch the live site (INV-08).

## The deploy engine (`Deploy/Deployer`)

```text
deploy(site, DeployFlags, Asker, Reporter)
 ├─ lock (LCK-01) · Recovery::beforeOperation (REC-01) · open the log
 ├─ Phase A  nothing live changes
 │    site.yml → domain IP → fetch/clone mirror → resolve ref → ChangeAnalyzer
 │    → same commit / rewind guards → runtimes (PhpService, ComposerInstaller,
 │    NodeResolver/Installer) → Preflight (incl. REL-06 resync, PRE-17/18 drift)
 │    → PlanBuilder (questions) → DB check → confirm
 ├─ Phase B  Builder: Steps/Export → LinkShared → DocrootFiles → Composer
 │           → FrontendBuild → StorageLink → Optimize → custom → B9 migration
 │           check → B10 manifest + marker + ready  (only the new release changes)
 ├─ Phase C  GoLive: G1 down(L) → G2 migrate → G3 seed → G4 MultiPHP ↑
 │           → G5 switch current → G6 docroot → G7 MultiPHP ↓ → G8 up(N)
 │           (one Signals::critical() section) → G9 → G10 after-activate → G11 health
 ├─ HC-03    health failed: ask / rollback / keep (NI-04) → Rollback::run() to L
 └─ Phase D  Finisher: prune, logs, tmp, history.jsonl; state file removed
```

- Every run shares one `DeployContext` (site, commit, live and new release,
  runtimes, plan, reporter). Steps implement `Steps/Step` and are run by
  `StepRunner`, which reports start/succeed/fail and records durations.
- Commands in the release run with a per-run shim folder first on PATH
  (`Runtime/Shims`: `php` → site PHP, `composer` → site PHP + phar), so every
  `php` and `@php` uses the site's PHP (BLD-01).
- A failure in Phase B marks the release failed and leaves `current` alone. In
  Phase C each step undoes what the earlier ones did (bring L back up, revert the
  MultiPHP change, put `current` back) before it reports.
- `Deploy/SiteStatus` gives `status`, `releases` (and later the main menu) the
  same read-only view of sites and releases.
- `Deploy/ReleaseManifest` writes `.release-manifest` at B10 and finds files
  changed on the server at the next preflight (DOC-06). `Support/LineDiff`
  shows the `.htaccess` drift (DOC-05) without an external `diff`.

## Rollback (`Deploy/Rollback`)

```text
rollback(site, id | --previous | picker, …)       the `rollback` command
 ├─ lock · Recovery::beforeOperation · target (RB-02) · log
 ├─ prepare()  target PHP from .release.json (RB-03), domain PHP, risks (RB-04)
 ├─ confirm (RB-05)
 └─ run()      state file (operation rollback):
               relink shared + docroot files, handler block for the target PHP
               → optimize (target PHP; a failure changes nothing, exit 8)
               → up(target) → [MultiPHP ↑] → switch current → [MultiPHP ↓]
               → health check (switch back offered on a terminal) → history
```

A deploy whose health check fails calls `prepare()` + `run()` itself, since it
already holds the lock; it writes the deploy's history entry, then the
rollback's.

## Recovery (`Deploy/Recovery`)

A state file with the lock free is an interrupted operation (REC-01).
`beforeOperation()` runs at the start of deploy and rollback (and `releases`
changes refuse to run): it recovers with `--recover` (deploy), `--yes`
(rollback), or when the user agrees on a terminal; otherwise E_INTERRUPTED
(exit 11). `recoverLocked()` acts on the recorded phase (§11.9) — every action is
idempotent, and the statuses are always re-derived from where `current` points —
then writes its own log and a `recover` history entry (REC-03).
