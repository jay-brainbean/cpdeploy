# cpdeploy: Development Plan & Specification

**Status:** design approved, ready for implementation
**Plan version:** 1.0 (2026-09-29)
**Product:** `cpdeploy`, a menu-driven command-line tool that deploys private GitHub repositories (Laravel first) to a cPanel account, using releases and an atomic switch.

---

## Contents

- 0. How to use this document (read this first)
- 1. Product summary
- 2. Locked decisions
- 3. Glossary
- 4. Scope
- 5. Requirements and compatibility
- 6. Technology and architecture
- 7. Server layout and subsystem specifications
- 8. Configuration and data formats
- 9. Interactive UI specification
- 10. Command-line interface
- 11. Deploy engine
- 12. Security requirements and invariants
- 13. Error catalogue
- 14. Operational targets and limits
- 15. Installation and distribution
- 16. Testing strategy
- 17. Milestones (build order)
- 18. Definition of done
- Appendix A: Examples
- Appendix B: External interface reference
- Appendix C: Backlog (not in v1)

---

## 0. How to use this document (read this first)

This document is the single source of truth for building cpdeploy. It replaces all earlier chat designs and the earlier `cpanel-git-setup.sh` script. It is written for AI coding agents and human developers.

**Rules for implementers**

1. **Keywords.** **MUST** / **MUST NOT** are hard requirements. **SHOULD** means do it unless there is a documented reason not to. **MAY** is optional.
2. **Requirement IDs.** Requirements carry IDs such as `PRE-12` or `SEC-04`. Reference them in commit messages, and in test docblocks as `@covers-req PRE-12`. Every MUST requirement needs at least one test, or an entry in the manual QA checklist (§16.5).
3. **Build order.** Work milestone by milestone (§17). Do not start a milestone until the acceptance criteria of the previous one pass.
4. **Locked decisions.** The decisions in §2 MUST NOT change without the owner's approval.
5. **When the spec is silent or ambiguous,** choose the behaviour that:
   - (a) never touches the live site,
   - (b) never loses data,
   - (c) asks the user in interactive mode, and fails with a clear message in non-interactive mode.

   Record the choice in `docs/decisions.md` (date, question, choice, reason).
6. **External interfaces.** cPanel UAPI, the GitHub REST API, Laravel artisan output, Composer and nodejs.org are listed in Appendix B. Anything marked **(verify)** was not confirmed when this plan was written. Confirm it on a real cPanel server or in the current official docs before relying on it, and add a test fixture that captures the real output.
7. **Error messages.** Every user-facing error MUST say:
   - (1) what happened,
   - (2) whether the live site was affected,
   - (3) what to do next.

   Use the error catalogue (§13).
8. **Scope.** Do not add features that are not in this document. Put ideas in `docs/backlog.md`.

### 0.1 Inputs needed from the owner

| # | Input | Needed by |
|---|---|---|
| 1 | The GitHub repository that will host cpdeploy itself, and whether it is public or private (sets `update.repo`, §8.1 / §15.5) | M0 |
| 2 | A test cPanel server (AlmaLinux 9 + EasyApache 4, PHP-FPM) with shell access, and a CloudLinux + CageFS server for QA | M1 / M7 |
| 3 | A test Laravel repository on GitHub, and a fine-grained token for it (Administration: read and write) | M2 |
| 4 | License for the tool | M7 |

---

## 1. Product summary

**What it is.** A command-line tool, run over SSH or in cPanel's Terminal as the cPanel user, that:

- connects a private GitHub repository to a domain on the account (deploy key per site, or automatically through an optional GitHub token)
- builds each deploy in its own **release** folder: `composer install`, `npm ci` and `npm run build`, `php artisan storage:link` and `php artisan optimize`, each using **that site's own PHP and Node versions**
- asks up front whether to run `composer install` and migrations, with a smart default based on what changed
- switches the site to the new release **atomically**, using maintenance mode only while migrations run
- rolls back to any kept release in seconds
- manages each site's `.env`, PHP and Node versions, deploy steps and deploy key, and runs Laravel artisan commands

**Who uses it.** A developer or sysadmin with shell access to a cPanel server that hosts **one cPanel account**.

**Core promises.** These are product invariants; see also §12.2.

- **P1:** A deploy never leaves the live site half-updated.
- **P2:** If anything fails before go-live, the live site is untouched.
- **P3:** Nothing is installed system-wide and root is never needed.
- **P4:** Every action can be done from the menus **and** from a plain command with flags.
- **P5:** No secret is ever printed, logged or passed on a command line.

---

## 2. Locked decisions

| # | Decision | Value |
|---|---|---|
| D1 | Language and UI | PHP. The UI uses **Laravel Prompts**, commands use **Symfony Console**, and the tool ships as a **single `.phar`** |
| D2 | When deploys happen | **Manual only.** No webhooks, polling, cron or auto-deploy |
| D3 | Strategy | **Releases + atomic symlink switch** for every project type in v1 |
| D4 | Versions | PHP and Node versions are chosen when a site is **added**, stored in `site.yml`, and changed from *Manage site*. A normal deploy never asks for versions |
| D5 | Deploy questions | `composer install` = **ask each deploy**. Migrations = **ask each deploy**. All questions are asked **before anything runs** |
| D6 | Other Laravel steps | Frontend build: **every deploy**. `storage:link`: **every deploy**. `php artisan optimize`: **every deploy** (not `optimize:clear`). Maintenance mode: **only while migrations run**. `queue:restart`: **off** by default. `db:seed`: **off** by default |
| D7 | Removed features | No background-job automation (scheduler or queue), no notifications |
| D8 | GitHub token | **Optional**, fine-grained. Without it, the user adds the deploy key by hand |
| D9 | Root / WHM | **No root mode.** One cPanel account per server. The tool refuses to run as root |
| D10 | Tool's own PHP | Any installed PHP **≥ 8.1**, independent of the PHP version each site uses |
| D11 | Composer / Node | Downloaded into the account when missing, checksum-verified, cached and shared by all sites |
| D12 | UI language | English only |

### 2.1 Corrections to the earlier chat design (binding)

| Earlier design | Now | Why |
|---|---|---|
| Data under `~/deployments/` | Data under **`~/cpdeploy/`** | The earlier `cpanel-git-setup.sh` script used `~/deployments/`. A separate root avoids collisions and allows a clean import (§10.6) |
| `storage:link` first deploy only | **Every deploy** | Each release has a fresh `public/` folder, so the `public/storage` link must be recreated each time |
| `optimize` after go-live | **Before go-live**, inside the new release | Caches are per release; building them first means the site switches to a ready release |
| Maintenance mode in the new release | **In the live release** | `storage/framework` is per release (§7.3). The live release is what visitors see |
| Skip composer = reuse `vendor/` | Skip = **copy** `vendor/` from the live release, then run `composer dump-autoload` | Regenerates the autoloader and package discovery for the new code |
| Docroot backup next to the docroot | Backup under **`~/cpdeploy/sites/<site>/backups/`** | A backup inside `public_html` could be served over the web, including old `.env` files |
| Tool folders `700` | Folders on the web path are **`711`/`755`** | Apache must traverse the symlinked path to serve static files |

---

## 3. Glossary

| Term | Meaning |
|---|---|
| **Site** | One deployed app: one repo and branch, deployed to one domain. Has a short **site name** (e.g. `shop`) |
| **Release** | One folder containing the app at one commit, fully built. Id: `YYYYMMDD-HHMMSS` in UTC, e.g. `20260929-030512` |
| **Live release** | The release that `current` points to |
| **`current`** | Symlink `~/cpdeploy_sites/<domain>/current → releases/<id>` (LAY-04) |
| **Shared** | `~/cpdeploy_sites/<domain>/shared/`: files that survive every deploy (`.env`, parts of `storage/`, docroot extras) |
| **Docroot** | The folder cPanel serves for the domain (e.g. `~/public_html`, `~/shop.example.com`). After the first go-live it is a symlink to `current/<web_dir>` |
| **Web dir** | The folder inside a release that is served: `public` for Laravel, the build output folder for static sites |
| **Go-live / activation** | Pointing `current` at the new release (atomic) |
| **Site PHP** | The PHP version the site builds and runs with (e.g. ea-php82) |
| **Tool PHP** | The PHP running cpdeploy itself (the newest PHP ≥ 8.1 on the server) |
| **Ask step** | A deploy step whose "when" is `ask`. The user answers before the deploy starts |
| **Preflight** | Checks run before anything changes |
| **State file** | `~/cpdeploy/sites/<site>/.deploy-state.json`. Records the phase of a running operation so an interrupted deploy can be recovered |
| **Tools dir** | `~/cpdeploy/tools/`: downloaded Composer, Node and package managers |

---

## 4. Scope

### 4.1 In scope (v1)

- Project types:
  - **Laravel** (full support; tested on 10, 11, 12, 13; best effort for 8 and 9)
  - **Static / SPA** (Node build, serve the output folder)
  - **Plain PHP** (optional Composer, serve a folder)
  - **Custom** (the user defines the steps)
- GitHub repositories, private or public, over SSH using a per-site deploy key. Port 22 is used, with a fallback to `ssh.github.com:443`.
- Optional GitHub fine-grained token: pick repos from a list, and add or remove deploy keys automatically.
- cPanel integration through `uapi`, run as the cPanel user:
  - domains and document roots
  - MultiPHP version (read and set)
  - MySQL database and user creation
  - disk and inode quota
- CloudLinux: CageFS and PHP Selector (alt-php) detection.
- Runtimes:
  - PHP: ea-php and alt-php
  - Composer: downloaded, versioned
  - Node: ea-nodejs, alt-nodejs, nvm installs, or downloaded official builds
  - Package managers: npm; pnpm and yarn with the limits in §7.9
- Interactive menus, a 10-step add-site wizard, a deploy screen with live output, rollback, recovery from interrupted deploys, and a server check.
- Plain commands with flags and JSON output for `status`, `releases`, `check` and `logs`.
- Distribution as a single phar, an installer, and self-update from GitHub Releases.

### 4.2 Out of scope for v1 (do not build; see `docs/backlog.md`)

- Automatic deploys of any kind (webhooks, polling, cron), and notifications
- Scheduler or queue-worker automation. The tool only **shows** the scheduler cron line (LAR-07, §9.5.8)
- Root mode or multiple cPanel accounts
- Non-GitHub hosts (GitLab, Bitbucket)
- Git submodules and Git LFS: detected and refused with a clear message
- "Copy into folder" strategy. The `strategy` key is reserved
- WordPress preset: specified in the backlog, not built in v1
- HTTPS-with-token git transport: backlog
- Windows, macOS and non-cPanel servers

---

## 5. Requirements and compatibility

### 5.1 Server (where cpdeploy runs)

| Requirement | Detail | Checked by |
|---|---|---|
| cPanel & WHM with **EasyApache 4** | Any cPanel version currently supported by cPanel, on AlmaLinux / Rocky / CloudLinux 8–9 or Ubuntu 22.04 / 24.04 | `check` |
| Shell access for the cPanel user | Normal shell or jailshell; cPanel Terminal or SSH | install.sh |
| **Tool PHP ≥ 8.1** | Any installed version: ea-php81+ or alt-php81+. Does not need to be the default | launcher |
| Tool PHP extensions | `phar`, `mbstring`, `json`, `ctype` (or its Symfony polyfill). Optional: `pcntl` (animated spinners, clean Ctrl+C), `posix` (process-group kill) | Box requirement checker + `check` |
| PHP functions allowed | `proc_open`, `proc_get_status`, `proc_terminate`, `exec`, `shell_exec` (Laravel Prompts runs `stty`) | launcher + `check` |
| Programs on PATH | `git` (≥ 2.3 for `GIT_SSH_COMMAND`; ≥ 2.20 recommended), `ssh`, `ssh-keygen`, `tar`, `gzip`, `curl`, `stty`, `uapi`, `du`, `cp` (GNU, for `cp -a`), `rm` | `check` |
| Network (outbound) | github.com:22 **or** ssh.github.com:443 (always). api.github.com:443 (only with a token). getcomposer.org:443 (Composer downloads). nodejs.org:443 (Node downloads) | `check` |
| glibc | ≥ 2.28 to download official Node ≥ 18 builds. Older systems must use ea-nodejs or alt-nodejs | Node resolver |
| Disk / inodes | Roughly `keep_releases × release size` + build headroom | preflight |

### 5.2 Build machine (where the phar is built)

PHP ≥ 8.2 (required by Box 4), Composer 2, git, and Box (`humbug/box`, installed as a tool rather than a project dependency).

### 5.3 Terminal

- Minimum 80×24.
- A UTF-8 locale is recommended. The tool falls back to ASCII symbols when the locale is not UTF-8 (UI-07).
- Labels MUST fit in 74 characters, which is Laravel Prompts' guidance for 80-column terminals.
- Colour follows `NO_COLOR` and `--no-ansi`.
- cPanel's web Terminal and plain SSH MUST both work.

### 5.4 Apps

- **Laravel:** requires `composer.json` with `laravel/framework`, plus `artisan`.
- **Static:** a `package.json` with a build script, or pre-built files.
- **Plain PHP:** a folder of PHP files, optionally with `composer.json`.
- **Node runtime apps** (Node servers) are **not** supported. Only Node **builds** are.

---

## 6. Technology and architecture

### 6.1 Dependencies (`composer.json`)

| Package | Constraint | Purpose |
|---|---|---|
| `php` | `^8.1` | Tool runtime |
| `ext-mbstring` | `*` | Required by Laravel Prompts |
| `laravel/prompts` | `^0.3.24` (the current release when this plan was written; PHP ^8.1) | Menus, prompts, `task()` output, tables |
| `symfony/console` | `^6.4` | Commands, input and output, signal handling |
| `symfony/process` | `^6.4` | Running git, composer, npm and artisan |
| `symfony/yaml` | `^6.4` | `site.yml`, `config.yml`, presets |
| `composer/semver` | `^3.4` | Version constraints for PHP, Composer and Node |

- **Dev dependencies:** `phpunit/phpunit ^10.5` (supports PHP 8.1), `phpstan/phpstan ^2`, `friendsofphp/php-cs-fixer`.
- **Build tool:** Box 4, installed on the build machine only.
- **`suggest`:** `ext-pcntl`, `ext-posix`.
- **Platform pin.** `config.platform.php` MUST be `"8.1.0"`, so Composer only resolves versions that run on PHP 8.1. Symfony 6.4 LTS is the last line that supports 8.1.

**ARC-01.** Laravel Prompts' `task()` (live scrolling output with a spinner) is used for deploy steps if it exists in the pinned version *(verified 2026-09-29: it exists in 0.3.24 but forks a renderer and replaces the SIGINT handler with `exit()`, so it is not used; see `docs/decisions.md`)*. If it doesn't, implement the same behaviour in `Ui/TaskReporter`: a spinner line with elapsed time, the last 10 output lines dimmed beneath it, and the whole block collapsing to `✓ Step  12s` when the step finishes.

**ARC-02.** Laravel Prompts animates spinners only when `pcntl` is available, and shows a static spinner otherwise. Both MUST work.

### 6.2 Architecture rules

```
 Presentation     Commands/  Menus/  Wizard/           (no business logic)
       │  calls
 Services         Deploy/Deployer, Deploy/Rollback, Deploy/Recovery, Config/SiteRegistry,
                  Env/EnvManager, Runtime/PhpService, Runtime/NodeResolver, Git/DeployKeyService,
                  Database/DatabaseService, Docroot/DocrootManager, Check/ServerCheck
       │  uses
 Adapters         Support/Shell, Support/Http (curl), Git/GitRepository, GitHub/GitHubApi,
                  Cpanel/Uapi, Support/Fs, Laravel/Artisan
```

- **ARC-03.** A menu action and its CLI command MUST call the **same service method**. There is never a second implementation.
- **ARC-04.** Services MUST NOT call Laravel Prompts directly:
  - They receive answers through value objects (`DeployPlan`, `WizardState`) or an `Ui\Asker` interface.
  - They report progress through an `Ui\Reporter` interface.
  - Implementations: `PromptsAsker`/`TaskReporter` (interactive), `NonInteractiveAsker`/`PlainReporter` (no TTY), `ScriptedAsker`/`MemoryReporter` (tests).
- **ARC-05.** All external programs run through `Support/Shell` (§7.17). Nothing else may call `proc_open`, `exec` or backticks.
- **ARC-06.** All paths come from `Config/Paths`. No path strings anywhere else.
- **ARC-07.** Code style:
  - `declare(strict_types=1)` everywhere; classes `final` unless designed for extension; typed properties.
  - No static mutable state. Constructor injection.
  - Objects are wired in `src/Services.php`, a small hand-written factory (no container library).
- **ARC-08.** Quality gates (CI must pass all):
  - PHPStan level 8, zero errors
  - php-cs-fixer (PSR-12 plus the project rules)
  - PHPUnit on PHP 8.1, 8.2, 8.3, 8.4 and 8.5. If PHPUnit 10.5 misbehaves on PHP ≥ 8.4, those CI jobs MAY resolve dev tools without the platform pin; record this in `docs/decisions.md`
- **ARC-09.** Every error thrown to the user is a `CpdeployException` carrying an `ErrorCode` (enum, §13), a message, a hint, and a `liveAffected` flag.

### 6.3 Source tree

This tree supersedes the earlier list from the chat. Each file has one responsibility.

```
cpdeploy/
├── bin/cpdeploy                      Entry point: boots Application; no args → menu (TTY) or help (no TTY)
├── composer.json                     §6.1 (platform php 8.1.0)
├── box.json                          §15.1
├── phpunit.xml  phpstan.neon  .php-cs-fixer.php
├── README.md                         User guide: install, first site, deploy, rollback, troubleshooting
├── CHANGELOG.md
├── docs/
│   ├── architecture.md               Expanded §6.2 with sequence diagrams
│   ├── decisions.md                  Decision log (see §0 rule 5)
│   ├── testing.md                    How to run the fakes and scenario tests
│   └── backlog.md                    Out-of-scope ideas (§4.2)
├── resources/
│   ├── presets/laravel.yml           Default site.yml values per project type (§8.4)
│   ├── presets/static.yml
│   ├── presets/php.yml
│   ├── presets/custom.yml
│   └── github_known_hosts            GitHub's published host keys for github.com and [ssh.github.com]:443 (Appendix B.6)
├── scripts/
│   ├── build.sh                      Build the phar + sha256 (§15.1)
│   ├── install.sh                    Server installer / uninstaller (§15.3)
│   └── launcher.sh                   Installed as ~/bin/cpdeploy; picks the Tool PHP (§15.2)
├── .github/workflows/
│   ├── ci.yml                        Lint, static analysis, tests matrix, real-Laravel scenario job
│   └── release.yml                   On tag v*: test, build, publish phar + sha256 + install.sh
├── src/
│   ├── Application.php               Symfony Application: registers commands, global options, root check, error rendering
│   ├── Services.php                  Hand-written factory wiring all services
│   ├── Version.php                   Version constant (replaced at build time)
│   ├── Commands/
│   │   ├── MenuCommand.php           `cpdeploy` / `cpdeploy menu`: main menu loop
│   │   ├── AddCommand.php            `add`: wizard, or `--from=<file>`
│   │   ├── DeployCommand.php         `deploy <site>`
│   │   ├── RollbackCommand.php       `rollback <site> [release]`
│   │   ├── ReleasesCommand.php       `releases <site> [protect|unprotect|delete <id>]`
│   │   ├── StatusCommand.php         `status [site] [--json]`
│   │   ├── CheckCommand.php          `check [site] [--probe] [--json]`
│   │   ├── PhpCommand.php            `php <site> [version]`
│   │   ├── NodeCommand.php           `node <site> [version|auto|none]`
│   │   ├── EnvCommand.php            `env <site> list|get|set|unset|edit|apply`
│   │   ├── ArtisanCommand.php        `artisan <site> -- <args>`
│   │   ├── MaintenanceCommand.php    `down <site>` / `up <site>`
│   │   ├── LogsCommand.php           `logs <site> [id|--last|--failed] [--tail=N]`
│   │   ├── ConfigCommand.php         `config <site> show|get|set|edit`
│   │   ├── KeyCommand.php            `key <site> show|test|rotate`
│   │   ├── TokenCommand.php          `token set|test|remove`
│   │   ├── RecoverCommand.php        `recover <site>`
│   │   ├── RemoveCommand.php         `remove <site>`
│   │   └── SelfUpdateCommand.php     `self-update [--check|--rollback]`
│   ├── Menus/
│   │   ├── MainMenu.php              Site table + main actions (§9.2)
│   │   ├── DeployScreen.php          Plan → questions → progress → result/failure screen (§9.4)
│   │   ├── ManageSiteMenu.php        §9.5
│   │   ├── RollbackScreen.php
│   │   ├── ReleasesMenu.php
│   │   ├── PhpMenu.php               Change-PHP flow (§9.5.4)
│   │   ├── NodeMenu.php
│   │   ├── StepsMenu.php             Deploy-steps editor
│   │   ├── EnvMenu.php               View / edit / add / apply
│   │   ├── LaravelToolsMenu.php
│   │   ├── KeyMenu.php
│   │   ├── LogsMenu.php
│   │   ├── RemoveSiteFlow.php
│   │   └── SettingsMenu.php
│   ├── Wizard/
│   │   ├── AddSiteWizard.php         Step machine: Next / Back / Cancel, transaction + cleanup (§9.3)
│   │   ├── WizardState.php           All answers; nothing persisted until Review
│   │   ├── WizardTransaction.php     Records side effects (key created, key added to GitHub, DB created) for cleanup on cancel
│   │   ├── LegacyImporter.php        Import from cpanel-git-setup.sh sites in ~/deployments (§10.3)
│   │   └── Steps/
│   │       ├── RepositoryStep.php    1
│   │       ├── AccessStep.php        2 (includes branch pick when no token)
│   │       ├── ProjectTypeStep.php   3
│   │       ├── DomainStep.php        4
│   │       ├── ServingStep.php       5 (release strategy info + web dir)
│   │       ├── PhpStep.php           6
│   │       ├── NodeStep.php          7
│   │       ├── EnvironmentStep.php   8 (.env + database)
│   │       ├── DeployStepsStep.php   9
│   │       └── ReviewStep.php        10
│   ├── Deploy/
│   │   ├── Deployer.php              Orchestrates phases A–D (§11)
│   │   ├── DeployContext.php         Everything one run needs: site, target commit, live release, runtimes, paths, reporter
│   │   ├── DeployPlan.php            Answers: composer yes/no, migrate yes/no, skips, ref, health policy
│   │   ├── PlanBuilder.php           Builds questions and defaults from ChangeAnalyzer + flags (§11.3)
│   │   ├── ChangeAnalyzer.php        Diff live → target: commits, lock changes, migrations, rewinds
│   │   ├── Preflight.php             PRE-xx checks (§11.2)
│   │   ├── GoLive.php                Maintenance / migrate / MultiPHP / docroot / switch sequencing (§11.5)
│   │   ├── HealthChecker.php         §11.6
│   │   ├── Rollback.php              §11.8
│   │   ├── Recovery.php              §11.9
│   │   ├── StateFile.php             Read/write .deploy-state.json atomically
│   │   ├── ReleaseManager.php        Create, list, statuses, protect, prune, delete-safely
│   │   ├── Release.php               One release + its .release.json
│   │   └── Steps/                    One class per build step (§11.4)
│   │       ├── ExportStep.php
│   │       ├── LinkSharedStep.php
│   │       ├── DocrootFilesStep.php
│   │       ├── ComposerStep.php
│   │       ├── FrontendBuildStep.php
│   │       ├── StorageLinkStep.php
│   │       ├── OptimizeStep.php
│   │       ├── SeedStep.php
│   │       ├── CustomCommandStep.php
│   │       └── QueueRestartStep.php
│   ├── Laravel/
│   │   ├── Artisan.php               Run artisan in a release with the right PHP
│   │   ├── MigrationStatus.php       Version-aware pending-migration detection + parsers (§7.11)
│   │   ├── Maintenance.php           down / up / is-down per release
│   │   └── AppKey.php                Generate base64 keys
│   ├── Project/
│   │   ├── ProjectDetector.php       Type + framework version at a commit
│   │   ├── ProjectInfo.php
│   │   ├── ComposerInspector.php     PHP constraint, lock diff, platform check
│   │   └── NodeInspector.php         Version spec, package manager, scripts, build outputs
│   ├── Runtime/
│   │   ├── PhpLocator.php            Installed ea-php / alt-php binaries, versions, extensions
│   │   ├── PhpService.php            Site PHP resolution, domain PHP detection, change flow
│   │   ├── ComposerInstaller.php     Download / verify / cache composer.phar
│   │   ├── NodeLocator.php           Installed Node candidates
│   │   ├── NodeResolver.php          Spec → concrete version (installed or index.json)
│   │   ├── NodeInstaller.php         Download / verify / extract official builds
│   │   ├── PackageManager.php        npm / pnpm / yarn commands and installation
│   │   └── Shims.php                 Per-operation bin dir: php → site PHP, composer wrapper
│   ├── Cpanel/
│   │   ├── Uapi.php                  `uapi --output=json`, URI-encoding, error mapping
│   │   ├── DomainService.php         DomainInfo::domains_data
│   │   ├── MultiPhpService.php       LangPHP get/set vhost versions, installed versions
│   │   ├── MysqlService.php          Restrictions, list, create/delete db & user, grant
│   │   ├── QuotaService.php          Quota::get_quota_info
│   │   └── CloudLinux.php            CloudLinux / PHP Selector detection
│   ├── Git/
│   │   ├── RepoUrl.php               Parse / normalise GitHub URLs
│   │   ├── GitRepository.php         Bare mirror operations (§7.4)
│   │   ├── SshCommand.php            Builds GIT_SSH_COMMAND per site
│   │   ├── HostKeys.php              known_hosts management + fingerprint verification
│   │   ├── Transport.php             Detect / choose port 22 or 443
│   │   └── DeployKeyService.php      Generate, register (API/manual), test, rotate, remove
│   ├── GitHub/
│   │   ├── GitHubApi.php             REST calls through Support/Http (§7.5)
│   │   └── TokenStore.php            ~/cpdeploy/secrets/github-token
│   ├── Config/
│   │   ├── Paths.php                 Every path (§7.1 table)
│   │   ├── GlobalConfig.php          ~/cpdeploy/config.yml
│   │   ├── SiteConfig.php            Typed view of site.yml
│   │   ├── SiteRegistry.php          List / load / save / create / delete sites
│   │   ├── Presets.php               Load resources/presets/*.yml
│   │   └── Schema/
│   │       ├── SiteSchema.php        Validation + defaults + migrations for site.yml (§8.2)
│   │       └── GlobalSchema.php
│   ├── Env/
│   │   ├── EnvFile.php               Lossless .env parser / writer (§7.12)
│   │   ├── EnvManager.php            Backups, validation, apply-to-live
│   │   └── SecretKeys.php            Which keys are masked
│   ├── Database/
│   │   ├── DatabaseService.php       Create (cPanel), existing, SQLite
│   │   └── DbCheck.php               Connection test using the site PHP (§7.13)
│   ├── Docroot/
│   │   ├── DocrootManager.php        Safety checks, first conversion, backups, restore/detach (§7.14)
│   │   ├── HandlerBlock.php          cPanel PHP handler block capture / rewrite / inject
│   │   └── HtaccessDrift.php         Detect live .htaccess changes made outside git
│   ├── Check/
│   │   └── ServerCheck.php           `check` command logic (§9.7)
│   ├── Support/
│   │   ├── Shell.php                 Process runner (§7.17)
│   │   ├── ProcessResult.php
│   │   ├── Http.php                  curl wrapper (§7.15)
│   │   ├── Fs.php                    Atomic write, atomic symlink swap, safe delete, copy, perms (§7.16)
│   │   ├── Masker.php                Secret masking for logs/output (§7.18)
│   │   ├── Lock.php                  flock-based locks (§7.19)
│   │   ├── Clock.php                 Injectable time (tests)
│   │   ├── Log.php                   Per-operation log files + history.jsonl
│   │   └── Errors/
│   │       ├── ErrorCode.php         Enum (§13)
│   │       └── CpdeployException.php
│   └── Ui/
│       ├── Asker.php  PromptsAsker.php  NonInteractiveAsker.php  ScriptedAsker.php
│       ├── Reporter.php  TaskReporter.php  PlainReporter.php  MemoryReporter.php
│       ├── Theme.php                 Symbols (✓ ✗ ⚠ ? ❯) with ASCII fallback, colours
│       ├── Pager.php                 `less -R` if available, else last 200 lines
│       └── Format.php                Durations, sizes, relative times, truncation to 74 chars
└── tests/
    ├── Unit/ Feature/ Scenario/
    ├── Fixtures/                     Repos, uapi JSON, fake php/node, mirrors, artisan stub, GitHub API router
    └── Support/                      Test harness: temp HOME, fake binaries on PATH, local servers
```

---

## 7. Server layout and subsystem specifications

### 7.1 On-server layout and paths

Everything the tool creates lives in the places below. `Config/Paths` is the only class that builds these paths (ARC-06).

```
~/bin/cpdeploy                          launcher script (§15.2)
~/cpdeploy/                        711  tool root  (CPDEPLOY_HOME overrides it, only when CPDEPLOY_TESTING=1)
├── app/cpdeploy.phar              755  the tool
├── app/cpdeploy.phar.prev         755  previous version (self-update --rollback)
├── config.yml                     600  global settings (§8.1)
├── secrets/                       700
│   └── github-token               600  optional token — never stored in config.yml
├── known_hosts                    644  verified GitHub host keys (§7.4)
├── tools/                         711  shared by all sites
│   ├── .lock                           download lock
│   ├── composer/composer-latest-2.x.phar
│   ├── node/index.json                 cached nodejs.org release index (24 h)
│   ├── node/node-v22.20.0-linux-x64/   extracted official Node builds
│   └── pm/pnpm-9.12.0/                 package managers installed with npm
├── tmp/                           700  temporary files; stale entries (>24 h) removed at start
├── removed/                       700  data kept from removed sites (§9.5.14)
└── sites/                         711
    └── shop/                      711  the tool's data about one site (site name)
        ├── site.yml               600  site settings (§8.2), incl. site_dir
        ├── .lock                  600  flock target (§7.19)
        ├── .deploy-state.json     600  exists only during an operation or after an interruption (§8.7)
        ├── repo.git/              700  bare mirror of the GitHub repo
        ├── backups/               700  docroot-YYYYMMDD-HHMMSS/ = the docroot as it was before the first go-live
        ├── logs/                  700  YYYYMMDD-HHMMSS-<action>.log (600), last 50 kept
        └── history.jsonl          600  one JSON line per operation (§8.6)
~/cpdeploy_sites/                  711  the sites themselves (config.yml sites_dir, LAY-04)
└── shop.example.com/              711  one folder per site, named after its domain (site.yml site_dir)
    ├── releases/                  711
    │   └── 20260929-030512/       755  one release (§7.3)
    ├── current -> releases/20260929-030512        relative symlink
    └── shared/                    711  survives every deploy (§7.3)
        ├── .env                   600
        ├── env-backups/           700  last 10 versions of .env (600 each)
        ├── auth.json              600  optional Composer credentials (§7.8)
        ├── php-handler.block      644  captured cPanel PHP handler block (§7.14)
        ├── storage/                    app, logs, framework/cache, framework/sessions (Laravel)
        ├── docroot/.well-known/        AutoSSL/ACME validation files
        ├── docroot/.user.ini           MultiPHP INI Editor settings, if any
        └── database/database.sqlite    SQLite sites only (600)
~/.ssh/cpdeploy_shop               600  deploy key (private);  ~/.ssh/cpdeploy_shop.pub 644
<docroot> -> cpdeploy_sites/shop.example.com/current/<web_dir>     relative symlink, created at first go-live
```

**LAY-01.** All symlinks the tool creates MUST be **relative**. This keeps sites working if the account is restored under a different home path, such as `/home2`. Compute them with `Fs::relativePath()`; never hard-code `../` chains.

**LAY-02.** On first run, the tool creates `~/cpdeploy` with the modes shown. On every start it re-applies the modes of `~/cpdeploy`, `secrets/`, `config.yml` and `secrets/github-token`. It MUST NOT recursively chmod releases or shared data.

**LAY-03.** `tmp/` entries older than 24 hours are deleted at startup, but only entries the tool itself created, identified by the `cpd-` name prefix.

**LAY-04 (two folders per site).** A site's own files (`current`, `releases/`, `shared/`) live in `~/<site_dir>`, by default `~/cpdeploy_sites/<domain>`, like Laravel Forge's per-site folder; the tool's data about it (settings, mirror, logs, history, backups, lock, state) stays in `~/cpdeploy/sites/<site>`.
- `site_dir` is recorded in `site.yml` at creation, relative to the home folder (LAY-01), and never changes (`config set`/`edit` refuse it), so changing the domain or `sites_dir` never moves or orphans files.
- New sites go in `~/<sites_dir>/<domain>` (`config.yml`, default `cpdeploy_sites`; Settings → Defaults for new sites). The folder must not exist yet.
- The site folder MUST NOT be inside any domain's document root, where `.env` and the code could be downloaded: DOC-01 refuses it (wizard and every go-live), and `check` reports the sites folder.
- Sites made by 1.0.0-rc.1/rc.2 (no `site_dir`) are not migrated: loading one explains that it must be removed with rc.2 and added again.

### 7.2 Permissions

The web server reaches files through the docroot symlink, so every folder on the path from `~` to the served files MUST be traversable by "others".

| Path | Mode | Why |
|---|---|---|
| `~` | 711 (cPanel default; tool never changes it) | Apache traversal |
| `~/cpdeploy`, `sites/`, `sites/<site>/`, `~/cpdeploy_sites`, `<site_dir>`, `releases/`, `shared/`, `tools/` | 711 | Traversal without listing |
| Release folders and everything inside | dirs 755, files 644 (umask 022 during build); executable bits from git kept | Apache serves `public/` |
| `shared/storage/**` | dirs 755, files 644 (Laravel defaults) | `storage/app/public` is served through `public/storage` |
| `shared/.env`, `auth.json`, `site.yml`, `config.yml`, token, logs, history, state, backups | 600 / 700 | Secrets |
| `repo.git/` | 700 | Not served |
| `~/.ssh` 700; private key 600; `.pub` 644 | | OpenSSH requirements |

- **PERM-01.** The tool MUST NOT create anything with mode 777 or 666, and MUST NOT make secrets group- or world-readable.
- **PERM-02.** PHP must run as the cPanel user (PHP-FPM, CGI, suPHP, LSAPI; the cPanel default) so it can read `.env` (600) and write `storage/`. `check` reports the handler through `LangPHP` (field `php_fpm`: `1`/`0`, *verified*) and warns if the site uses mod_php (DSO).
- **PERM-03.** All files are owned by the cPanel user. Running as root is refused (§10.1), so ownership mismatches cannot happen. This also satisfies Apache's `SymLinksIfOwnerMatch`: every symlink and its target have the same owner.

### 7.3 Release anatomy: shared vs per release (Laravel)

```
releases/<id>/
├── … all files of the commit (git archive)
├── .env                  → shared/.env                                   (shared file)
├── storage/              real folder
│   ├── app               → shared/storage/app                            (shared)
│   ├── logs              → shared/storage/logs                           (shared)
│   └── framework/        real folder
│       ├── cache         → shared/storage/framework/cache                (shared)
│       ├── sessions      → shared/storage/framework/sessions             (shared)
│       ├── views/        per release (compiled Blade)
│       └── down, maintenance.php   appear here while THIS release is in maintenance mode
├── bootstrap/cache/      per release (config/route/event/package caches)
├── vendor/               per release
├── public/
│   ├── build/            per release (Vite output)
│   ├── storage           → ../storage/app/public   (created by storage:link each deploy)
│   ├── .well-known       → shared/docroot/.well-known   (unless the repo has public/.well-known)
│   ├── .user.ini         → shared/docroot/.user.ini     (only if present in shared)
│   └── .htaccess         from git + cPanel handler block injected at the top (§7.14)
└── .release.json         metadata (§8.5)
```

- **REL-01.** Shared paths come from `site.yml → shared.files` and `shared.dirs`. Laravel default: files `[.env]`; dirs `[storage/app, storage/logs, storage/framework/cache, storage/framework/sessions]`.
- **REL-02.** `storage/framework/views` and `bootstrap/cache` MUST stay per release. Validation rejects them in `shared.dirs`.
  - Sharing compiled views lets a new release's `view:cache` affect the release that is still live, and leaves stale files behind.
  - Cached config contains the release's absolute paths.
- **REL-03 (seeding).** When a shared dir does not exist yet, create it. If the release contains that path from git (for example `storage/app/.gitignore`), copy the release's contents into shared first. For `storage/app`, create `storage/app/public` as well.
- **REL-04 (linking).** For each shared path: delete the release's copy (safe delete, §7.16), create the parent folders, then create a relative symlink to shared. Shared files that don't exist yet are linked anyway, except `.env`, which preflight requires to exist.
- **REL-05 (docroot extras).** `shared.docroot_dirs` (default `[.well-known]`) and `shared.docroot_files` (default `[.user.ini, php.ini]`) are linked **inside the web dir**, but only if the shared copy exists, or for dirs, always. Exception: if the repo itself contains the path, keep the repo's version and log `docroot extra '<path>' provided by the repo — not linked`.
- **REL-06 (docroot extras resync).** At the start of every deploy, for each `docroot_files` entry: if the live release has a **regular file** at that path, cPanel has replaced the link with a real file (e.g. after an INI Editor change). Copy it into shared, since it is newer, and log it.
- **REL-07.** Static and plain-PHP types use the same mechanism. Their defaults: no shared files or dirs, except `docroot_dirs: [.well-known]`.

### 7.4 Git, SSH keys and GitHub host keys

- **GIT-01 (remote URL).**
  - `ssh22` → `git@github.com:OWNER/REPO.git`
  - `ssh443` → `ssh://git@ssh.github.com:443/OWNER/REPO.git`
  - Tests can override the URL with `CPDEPLOY_GIT_URL_OVERRIDE` (e.g. `file:///…`).
- **GIT-02 (SSH command).** Every git network call sets, only for that process:
  `GIT_SSH_COMMAND=ssh -F /dev/null -i <key> -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=<~/cpdeploy/known_hosts> -o GlobalKnownHostsFile=/dev/null -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=4`
  - `-F /dev/null` isolates cpdeploy from the user's `~/.ssh/config`.
  - The tool never edits `~/.ssh/config` or `~/.ssh/known_hosts`.
- **GIT-03 (host keys).** `~/cpdeploy/known_hosts` is written from `resources/github_known_hosts` (Appendix B.6) for `github.com` and `[ssh.github.com]:443`.
  - A unit test MUST compute the SHA256 fingerprints of the embedded keys and compare them with the published values.
  - If GitHub rotates its keys, ssh fails with "Host key verification failed" → `E_GIT_HOSTKEY`, with two fixes: `cpdeploy self-update`, or `cpdeploy check --refresh-host-keys`. The latter fetches `https://api.github.com/meta` (`ssh_keys`) over HTTPS, shows the SHA256 fingerprints, and asks for confirmation before replacing the file.
- **GIT-04 (transport).**
  - When a site is created: TCP-connect to `github.com:22` (5 s timeout). If that fails, try `ssh.github.com:443`. If both fail → `E_GIT_NET`.
  - Store the result in `repo.transport`.
  - If a later fetch fails with a connection error (not an auth error), re-detect once, retry, and save the new transport if it changed.
- **GIT-05 (keys).** `ssh-keygen -q -t ed25519 -N "" -C "cpdeploy:<site>@<hostname>" -f ~/.ssh/cpdeploy_<site>`.
  - Existing key files are never overwritten except by rotation (GIT-18).
  - If a key file already exists when creating a site with that name, ask whether to reuse it or to rotate.
- **GIT-06 (access test and error classes).** `git ls-remote --heads <url>`:

  | stderr contains | Error |
  |---|---|
  | `Permission denied (publickey)`, `Repository not found`, `Could not read from remote repository` | `E_GIT_AUTH` |
  | `Host key verification failed` | `E_GIT_HOSTKEY` |
  | `Connection timed out`, `Connection refused`, `Could not resolve hostname`, `Network is unreachable` | `E_GIT_NET` |
  | anything else | `E_GIT` with stderr shown |

- **GIT-07 (mirror).**
  - `git clone --bare --quiet <url> repo.git`
  - then `git -C repo.git config remote.origin.fetch "+refs/heads/*:refs/heads/*"`
  - then `git -C repo.git config core.logAllRefUpdates false`
- **GIT-08 (fetch).** `git -C repo.git fetch --prune --tags --quiet origin` (timeout `timeouts.git`).
- **GIT-09 (ref resolution).** Order:
  1. exact branch `refs/heads/<ref>`
  2. tag `refs/tags/<ref>^{commit}`
  3. commit SHA of 7–40 hex characters via `rev-parse --verify <ref>^{commit}`

  Not found → `E_REF_NOT_FOUND`. If the configured branch is gone from the remote → `E_BRANCH_GONE`, with the hint to change the branch in *Manage site → Branch*.
- **GIT-10.** Read a file at a commit: `git show <sha>:<path>`. A missing file returns `null`, not an error.
- **GIT-11.**
  - Commit info: `git log -1 --format=%H%x1f%h%x1f%an%x1f%ae%x1f%at%x1f%s <sha>`.
  - Range: `git log --format=… <from>..<to> -n 50`.
- **GIT-12.** Rewind detection: `git merge-base --is-ancestor <live> <target>` (exit 1 = not an ancestor).
- **GIT-13 (export).** Run through `bash -o pipefail -c` with every argument shell-quoted:
  `git -C <repo.git> archive --format=tar <sha> | tar -xf - -C <release>`
  - Afterwards, verify the release contains at least one file.
  - Files marked `export-ignore` in `.gitattributes` are not deployed. The user guide MUST document this.
- **GIT-14 (unsupported).** At preflight, on the target commit:
  - `.gitmodules` exists → `E_SUBMODULES`
  - `.gitattributes` contains `filter=lfs` → `E_LFS`
- **GIT-15 (default branch).** `git ls-remote --symref <url> HEAD` → parse `ref: refs/heads/<name>`.
- **GIT-16 (repair).** Fetch output containing `bad object`, `corrupt`, `does not appear to be a git repository`, `inflate: data stream error`, `failed to read delta`, `unable to read` or `packfile` (the last four seen with a real damaged pack) → offer re-clone:
  1. rename `repo.git` to `repo.git.broken-<ts>`
  2. clone fresh
  3. delete the broken copy on success; restore it on failure
- **GIT-17 (registering the key).**
  - **With a token:** `POST /repos/{o}/{r}/keys` with `{title: "cpdeploy · <site> · <user>@<host>", key, read_only: true}`, and store the returned `id` in `repo.deploy_key_id`.
    - On 422 (key already in use), generate a new key pair and retry once.
    - On 403/404 → `E_TOKEN_PERMS`, then fall back to the manual flow.
  - **Without a token (manual flow):** show:
    - the public key
    - `https://github.com/OWNER/REPO/settings/keys/new`
    - "Allow write access: leave unchecked"

    Then loop on *Check again / Show key again / Cancel*, checking with GIT-06.
  - Never use or request write access.
- **GIT-18 (rotation).**
  1. Generate `~/.ssh/cpdeploy_<site>.new`.
  2. Register it (API or manual) and test it with GIT-06 using the new key.
  3. Atomically rename the new files over the old ones.
  4. Delete the old GitHub key (API: by the stored id; manual: tell the user which key title to delete).
  5. Update `deploy_key_id`.

  If any step fails, the old key stays in place.
- **GIT-19 (removal).** When a site is removed with a token, `DELETE /repos/{o}/{r}/keys/{id}`. A 404 is not an error. Without a token, show the manual instruction.

### 7.5 GitHub REST API (optional token)

- **GH-01.** Base URL `https://api.github.com` (env `CPDEPLOY_GITHUB_API` for tests). Headers:
  - `Accept: application/vnd.github+json`
  - `X-GitHub-Api-Version: 2022-11-28`
  - `User-Agent: cpdeploy/<version>`
  - `Authorization: Bearer <token>`

  The Authorization header is passed to curl through a config file on **stdin** (`curl --config -`), never in argv (SEC-03).
- **GH-02. Endpoints used.** Permission names are from GitHub's docs.

  | Call | Use | Fine-grained permission |
  |---|---|---|
  | `GET /user` | Validate the token, show the login, read the `github-authentication-token-expiration` response header | none beyond the token (verify) |
  | `GET /user/repos?per_page=100&sort=updated` (follow `Link: rel="next"`) | Repo picker | Metadata: read |
  | `GET /repos/{o}/{r}` | `default_branch`, `private`, existence | Metadata: read |
  | `GET /repos/{o}/{r}/branches?per_page=100` | Branch picker | Metadata: read |
  | `GET /repos/{o}/{r}/keys` | Check that a key id still exists | Administration: read |
  | `POST /repos/{o}/{r}/keys` | Add a deploy key (201) | Administration: read and write |
  | `DELETE /repos/{o}/{r}/keys/{id}` | Remove a deploy key (204) | Administration: read and write |
  | `GET /meta` (no auth) | `ssh_keys` for the host-key refresh (GIT-03) | none |

- **GH-03 (token guidance shown in Settings).** "Create a fine-grained token at github.com → Settings → Developer settings → Fine-grained tokens:
  - Repository access: only the repos you deploy
  - Permissions: **Administration: Read and write** (to add and remove deploy keys); Metadata: Read is included automatically
  - Expiration: your choice; cpdeploy warns 14 days before it expires."

  Classic tokens (`ghp_…`) are accepted with a warning that they grant far more access than needed.
- **GH-04 (errors).**

  | Response | Error |
  |---|---|
  | 401 | `E_TOKEN_INVALID` |
  | 403 with `x-ratelimit-remaining: 0` | `E_GITHUB_RATE` (show the reset time) |
  | 403/404 on `/keys` | `E_TOKEN_PERMS` |
  | 5xx or timeout | `E_GITHUB_DOWN` |

  Every API failure in the wizard offers the manual key flow as the fallback.
- **GH-05 (expiry).** If the token expires in under 14 days, show a banner in the main menu and a warning in `check`.

### 7.6 cPanel UAPI

- **CP-01 (invocation).** `uapi --output=json <Module> <function> key=value …` through Shell.
  - **Every value MUST be URI-encoded** (`rawurlencode`), as cPanel requires for the UAPI command line.
  - Parse the JSON. `result.status` 1 = success, 0 = failure. Collect `result.errors` and `result.warnings`. Data is in `result.data`.
  - Timeout 60 s. A failure → `E_UAPI` ("cPanel refused <Module>::<function>: <errors>").
- **CP-02.** `uapi` not on PATH → `E_NOT_CPANEL`. `check` still runs and reports it.
- **CP-03.** `CPDEPLOY_UAPI_BIN` overrides the binary (tests).
- **CP-04. Functions used.** Verified 2026-09-29 against cPanel 11.138 on AlmaLinux 8.10; fixtures in `tests/Fixtures/uapi`. Facts found on the real server:
  - `uapi` **exits 0 even when a call fails**, and may print a `warn [uapi] …` line before the JSON. Only `result.status` tells success from failure.
  - `phpversion_source` is an object: `{"domain": "<vhost>"}` when the vhost has its own version. The inherit form was not seen on that server; anything else is treated as inherited.

| Module::function | Args | Fields used |
|---|---|---|
| `DomainInfo::domains_data` | `format=hash` | `main_domain{domain, documentroot, ip, homedir, type, scriptalias?}`, `addon_domains[]`, `sub_domains[]`, `parked_domains[]` (parked are listed but never selectable) |
| `LangPHP::php_get_installed_versions` | none | `versions[]` e.g. `ea-php82` |
| `LangPHP::php_get_vhost_versions` | none | per vhost: `vhost`, `version`, `documentroot`, `php_fpm` (1/0), `main_domain` (1/0), `phpversion_source` (inherit detection), also `account`, `homedir`, `php_fpm_pool_parms` |
| `LangPHP::php_get_system_default_version` | none | `version` |
| `LangPHP::php_set_vhost_versions` | `vhost=<domain>`, `version=ea-phpNN` | success, changed vhosts |
| `Mysql::get_restrictions` | none | `prefix` (e.g. `user_`; may be absent when prefixing is off), `max_database_name_length`, `max_username_length` (real names; the draft names `database_name_length_limit` / `database_user_name_length_limit` are also accepted) |
| `Mysql::list_databases` | none | `database`, `users[]` |
| `Mysql::list_users` | none | `user`, `databases[]` |
| `Mysql::create_database` | `name` | |
| `Mysql::create_user` | `name`, `password` | see SEC-07 |
| `Mysql::set_privileges_on_database` | `user`, `database`, `privileges=ALL PRIVILEGES` (encoded) | |
| `Mysql::delete_database` / `delete_user` | `name` | remove-site only |
| `Quota::get_quota_info` | none | `megabytes_used`, `megabyte_limit` (0 = unlimited), `megabytes_remain`, `inodes_used`, `inode_limit` (0 = unlimited), `inodes_remain`. Values mix numbers and numeric strings (`"0.00"`) |

- **CP-05 (MultiPHP facts to respect).**
  - Setting a PHP version fails if the vhost's docroot has no `.htaccess`. The tool MUST make sure the live web dir has an `.htaccess` (creating an empty one if needed) before calling `php_set_vhost_versions`.
  - On CloudLinux, MultiPHP takes priority over PHP Selector. PHP Selector only applies when the domain is set to **inherit**.

### 7.7 PHP runtimes

- **PHP-01 (installed binaries).** Scan:
  - `/opt/cpanel/ea-phpNN/root/usr/bin/php` → family `ea`
  - `/opt/alt/phpNN/usr/bin/php` → family `alt`

  Cross-check with `LangPHP::php_get_installed_versions`. For each binary run `-r 'echo PHP_VERSION,"|",PHP_SAPI;'` and keep only CLI binaries. Cache results in memory per run.
  - Tests override the scan roots with `CPDEPLOY_PHP_SEARCH_PATHS`.
- **PHP-02 (extensions).** `<php> -m`, lower-cased, ignoring `[PHP Modules]` and `[Zend Modules]` headers; map `zend opcache` → `opcache`.
- **PHP-03 (domain's current PHP).**
  1. `php_get_vhost_versions` entry for the domain. If `version` is `ea-phpNN` and the source is not inherit → that is the version.
  2. If the domain **inherits** and CloudLinux PHP Selector is active (`/etc/cloudlinux-release` exists and `/opt/alt/php*` exists):
     - run `/usr/local/bin/php -r 'echo PHP_BINARY,"|",PHP_VERSION;'` with the docroot as the working directory
     - CloudLinux's CLI reflects the Selector choice
     - an `/opt/alt/phpNN/` path means family `alt`
  3. Otherwise, inherit = `php_get_system_default_version`.
  4. **Ground truth (optional):** the HTTP probe (§7.15) returns the version actually served.
- **PHP-04 (site setting).** `site.yml` stores `php.version` (`"8.2"`) and `php.family` (`ea`/`alt`). The binary path is resolved on every run. If it is missing → `E_PHP_MISSING`, listing the installed versions.
- **PHP-05 (compatibility).** A version is compatible with a commit when `composer check-platform-reqs --lock --no-dev --format=json` succeeds when run with that PHP against the commit's `composer.json` + `composer.lock`, exported to a temp dir. This checks the PHP version and every required extension from the lock file.
  - Parse the JSON loosely: any entry whose status is not success is a problem. If the JSON can't be parsed, fall back to the exit code plus the text output **(verify format)**.
  - Show missing extensions by name.
  - Without `composer.lock`, compare `require.php` from `composer.json` using `composer/semver`, and check `ext-*` keys against PHP-02.
- **PHP-06 (changing version, MultiPHP sync).** If `php.sync_multiphp` is true, the tool sets the domain's MultiPHP version **at go-live**, not at the moment the setting changes. Ordering is in §11.5.
  - When the domain is on CloudLinux PHP Selector (inherit + alt), offer:
    - (a) "Switch this domain to MultiPHP ea-phpNN (PHP Selector extensions will no longer apply)"
    - (b) "Keep PHP Selector; I'll change it in cPanel → Select PHP Version"

    With (b), the tool builds with `/opt/alt/phpNN` and never calls `php_set_vhost_versions`. After deploy it verifies with the probe and warns if the versions differ.
- **PHP-07 (per-release PHP).** Commands that run inside an **existing** release (down/up on the live release, optimize during rollback, artisan tools) use the PHP recorded in that release's `.release.json`, if that binary still exists. Otherwise they use the site PHP and log a warning.

### 7.8 Composer

- **CMP-01 (download).** Channel mapping from `composer.version`:

  | `composer.version` | Channel |
  |---|---|
  | `2` | `latest-2.x` |
  | `stable` | `latest-stable` |
  | `2.2` / `lts` | `latest-2.2.x` (verify it exists; for PHP < 7.2.5) |
  | exact `2.8.4` | `2.8.4` |

  - Download `<mirror>/<channel>/composer.phar` and `<mirror>/<channel>/composer.phar.sha256`. The checksum file's first whitespace-separated field is the hex hash.
  - Verify SHA-256 before use. On a mismatch → `E_CHECKSUM`; delete the download.
  - Cache at `tools/composer/composer-<channel>.phar`. Channels (`latest-*`) refresh when older than 30 days. Exact versions never refresh.
  - The download runs under the `tools/.lock` flock.
  - Mirror: `config.yml → mirrors.composer`, default `https://getcomposer.org/download`.
- **CMP-02 (invocation).** Always `<site-php> <composer.phar> …`, through a per-operation shim dir (`Runtime/Shims`) placed first on `PATH`, containing:
  - `php` → symlink to the site PHP binary, so Composer scripts using `php` or `@php` get the right version
  - `composer` → `#!/bin/sh` + `exec "<site-php>" "<phar>" "$@"`
- **CMP-03 (environment).**
  - `COMPOSER_NO_INTERACTION=1`, `COMPOSER_MEMORY_LIMIT=-1`
  - `COMPOSER_AUTH=<contents of shared/auth.json>` if that file exists and is valid JSON
  - Do **not** override `COMPOSER_HOME` or `COMPOSER_CACHE_DIR`, so the user's global auth and cache keep working
- **CMP-04 (install).** `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress`, overridable through `composer.install_flags`.
  - Missing `composer.lock` → `E_NO_LOCK`, unless `composer.allow_no_lock: true`, in which case warn "dependencies will be resolved fresh".
- **CMP-05 (skip = reuse).** When the user skips `composer install`:
  1. `cp -a <live>/vendor <new>/vendor`. A full copy, **never hardlinks**: autoload files are rewritten in place, and some packages write caches inside `vendor/`, so hardlinks would corrupt older releases.
  2. Run `composer dump-autoload --optimize --no-dev --no-interaction`. This regenerates the classmap for the new code and runs `post-autoload-dump` scripts such as `package:discover`.
  3. If the live release has no `vendor/`, skipping is impossible: install instead and log why.
- **CMP-06 (private packages).** `shared/auth.json` holds Composer credentials, e.g. `{"github-oauth": {"github.com": "<token>"}}` or `http-basic` entries. It is edited from *Manage site → Composer credentials* (validated JSON, mode 600) and never written into a release.

### 7.9 Node and package managers

- **NODE-01 (version spec sources, first match wins).**
  1. `site.yml node.version`, if not `auto`
  2. `.nvmrc`
  3. `.node-version`
  4. `package.json` `volta.node`
  5. `package.json` `engines.node`
  6. none

  Files are read from the **target commit** (GIT-10).
- **NODE-02 (spec forms).**
  - exact `20.11.1`; major `20`; `20.11`; semver ranges (`>=18.17 <21`, `^20`, `18.x || 20.x`), matched with `composer/semver`
  - `lts/*` and `lts/<codename>`, resolved with `index.json`'s `lts` field
  - `node`, `latest`, `current` → newest version
  - A leading `v` is ignored
  - Anything unparseable → `E_NODE_SPEC`, showing the value and its source file
- **NODE-03 (installed candidates).** Bin dirs, versions from `node -v`:
  - `/opt/cpanel/ea-nodejsNN/bin`
  - `/opt/alt/alt-nodejsNN/root/usr/bin`
  - `~/.nvm/versions/node/v*/bin`
  - `tools/node/node-v*-linux-*/bin`
  - Tests use `CPDEPLOY_NODE_SEARCH_PATHS`.
- **NODE-04 (resolution).** Pick the highest installed version satisfying the spec. If none, fetch `<mirror>/index.json` (cached 24 h), choose the highest version satisfying the spec whose `files` include `linux-x64` (or `linux-arm64`), and download it. No spec at all → newest installed Node. If nothing is installed either and the build needs Node → `E_NODE_NONE` ("Set a Node version (Manage site → Node version) or add .nvmrc").
- **NODE-05 (download).**
  1. Download `<mirror>/v<ver>/SHASUMS256.txt` and `node-v<ver>-linux-<arch>.tar.gz`.
  2. Verify the SHA-256 against the matching line.
  3. Extract into `tools/node/`.
  4. Run `node -v` to confirm.

  Mirror: `mirrors.node`, default `https://nodejs.org/dist`. Architecture: `x86_64` → `x64`, `aarch64` → `arm64`; anything else → `E_NODE_ARCH`.
- **NODE-06 (glibc guard).** Read the glibc version from `getconf GNU_LIBC_VERSION`, falling back to `ldd --version`. Official builds of Node ≥ 18 need glibc ≥ 2.28. On older systems → `E_GLIBC_OLD`, with the hint to install ea-nodejs or alt-nodejs, or to build elsewhere.
- **NODE-07 (package manager detection).**
  1. `package.json` `packageManager` (e.g. `pnpm@9.12.0`)
  2. otherwise by lockfile: `package-lock.json` → npm; `pnpm-lock.yaml` → pnpm; `yarn.lock` → yarn (v1 unless `.yarnrc.yml` exists); `bun.lock` or `bun.lockb` → `E_PM_UNSUPPORTED`
  3. otherwise npm
- **NODE-08 (package manager availability).**
  - npm comes with Node.
  - pnpm and yarn v1: install `npm install --prefix tools/pm/<pm>-<ver> <pm>@<ver>`, using the version from `packageManager` or else the latest major, and put its `node_modules/.bin` on PATH. Corepack is not used: it is not shipped with Node 25+.
  - Yarn Berry is supported only when `.yarnrc.yml` has `yarnPath`; run `node <yarnPath> …`. Otherwise → `E_PM_UNSUPPORTED`.
- **NODE-09 (commands).**

  | Manager | Install | Build |
  |---|---|---|
  | npm | `npm ci --no-audit --no-fund` (no lockfile: `npm install --no-audit --no-fund` + warning) | `npm run <script>` |
  | pnpm | `pnpm install --frozen-lockfile` | `pnpm run <script>` |
  | yarn v1 | `yarn install --frozen-lockfile --non-interactive` | `yarn run <script>` |
  | yarn berry | `yarn install --immutable` | `yarn run <script>` |

- **NODE-10 (environment).**
  - Node's bin dir goes first on PATH.
  - `NODE_OPTIONS=--max-old-space-size=<n>` if `node.max_old_space_mb` is set.
  - Do **not** set `NODE_ENV`: the build needs devDependencies.
  - Do **not** set `CI`: some tools turn warnings into errors under CI.
- **NODE-11 (outputs).** After the build, every path in `node.expect_files` MUST exist. Laravel + Vite default: `public/build/manifest.json` **or** `public/build/.vite/manifest.json`. Laravel Mix: `public/mix-manifest.json`. Static: the web dir, non-empty. Missing → `E_BUILD_OUTPUT`.
- **NODE-12.** If `node.remove_node_modules` (default true), delete `node_modules` after a successful build, using safe delete.
- **NODE-13 (skip build = reuse).** Used by *Deploy with changes → skip build*. Copy the `node.build_outputs` paths (Laravel default `[public/build]`) from the live release. If they're missing in the live release, the build can't be skipped: the build runs.

### 7.10 Project detection and change analysis

- **PRJ-01 (type detection at a commit).**
  - `composer.json` requires `laravel/framework` **and** `artisan` exists → `laravel`, with the version from `composer.lock`
  - else `package.json` has `scripts.build` → `static`
  - else any `*.php` at the root or in `public/` → `php`
  - else `custom`

  The user can override the result.
- **PRJ-02 (web dir defaults).** laravel `public`. static: `dist`, or `build` if `vite.config.*` sets `outDir: 'build'` (best effort; the user confirms). php: `public` if it exists, else the release root (`""`).
- **CHG-01 (ChangeAnalyzer inputs).** Live release commit L (may be null), target commit T.
- **CHG-02 (outputs).**

  | Output | How |
  |---|---|
  | `commits` | up to 50 commits in `L..T` (count + list) |
  | `isRewind` | T not a descendant of L (GIT-12); counts both ways |
  | `sameCommit` | T == L |
  | `composerChanged` | `git diff --name-only L T -- composer.json composer.lock` non-empty |
  | `lockDiff` | compare `packages` name→version from both locks: added / removed / updated lists |
  | `migrationsAdded` / `Modified` / `Deleted` | `git diff --name-status L T -- database/migrations` |
  | `nodeChanged` | `package.json`, lockfiles, `.nvmrc` changed |
  | `phpRequirementChanged` | `require.php` differs |
  | `htaccessChanged` | `<web_dir>/.htaccess` differs |

  With no L (first deploy), everything counts as new.

### 7.11 Laravel integration

- **LAR-01 (artisan runner).** `<php> artisan <command> --no-interaction`, with the working directory set to the release, through Shell with the site's shims on PATH. Add `--ansi` when output is shown live, and `--no-ansi` when it is parsed.
- **LAR-02 (Laravel version).** From `composer.lock` (`laravel/framework`), stored in `.release.json`.
- **LAR-03 (pending migrations, version-aware).** Returns `{known: bool, pending: string[]}`:
  - **Laravel ≥ 11:** `migrate:status --pending=3 --no-ansi`. Exit 3 = pending, listed in the output. Exit 0 = none.
  - **Laravel 9–10:** `migrate:status --no-ansi`. Parse lines matching `^\s*(\S+)\s+\.{2,}.*\bPending\b`.
  - **Laravel 8:** parse table rows `| No | <name> |`.
  - Output contains `Migration table not found` → all migration files in `database/migrations` are pending (fresh database).
  - Any other failure or parse miss → `known=false`, with the raw output logged.

  Fixtures for each version's output are REQUIRED.
- **LAR-04.** Migrate: `migrate --force`. Seed: `db:seed --force`. Storage link: `storage:link` (a message containing `already exists` counts as success). Optimize: `optimize`. Queue restart: `queue:restart`.
- **LAR-05 (maintenance).**
  - Down: `down <maintenance.options>`, default `--retry=60`, plus `--secret=<value>` if `maintenance.secret` is set. It runs on the **live** release with that release's PHP (PHP-07).
  - Up: `up`. Any exit code is only a warning, because "already up" is fine.
  - `Maintenance::isDown(release)` is true when `storage/framework/down` exists in that release.
- **LAR-06 (APP_KEY).** Generated by the tool: `'base64:' . base64_encode(random_bytes(32))` (AES-256-CBC, Laravel's default). Never overwrites an existing non-empty `APP_KEY` without explicit confirmation.
- **LAR-07 (scheduler helper).** *Laravel tools → Show scheduler cron line* prints the following and does nothing else (D7):
  `* * * * * <site-php> <abs path>/cpdeploy_sites/<domain>/current/artisan schedule:run >> /dev/null 2>&1`
  It uses `current`, so it follows every release. Also shown: "Add it in cPanel → Cron Jobs".
- **LAR-08 (dangerous commands).** *Laravel tools → Run artisan command* asks the user to **type the site name** before running any of these: `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `migrate:rollback`, `db:wipe`, `db:seed`, `key:generate`.

### 7.12 `.env` handling

- **ENV-01 (lossless parser).** `EnvFile` keeps every line (blank lines, comments, order, `export ` prefixes, original quoting) and re-serialises unchanged lines byte for byte. Supported values:
  - unquoted, with an optional ` #comment`
  - single-quoted (literal)
  - double-quoted, with `\\`, `\"` and `\n` escapes and **multi-line** values
- **ENV-02 (keys).** Must match `^[A-Za-z_][A-Za-z0-9_.]*$`. Duplicate keys produce a warning that names the line numbers.
- **ENV-03 (set).**
  1. If an active line exists, replace its value only.
  2. Else, if a commented assignment `# KEY=…` exists, replace that line with an active one. Laravel 11+ `.env.example` ships `DB_HOST` and similar keys commented out.
  3. Else, insert after the last key with the same prefix before the first `_`, or append at the end.
- **ENV-04 (quoting on write).**
  - `^[A-Za-z0-9_./:@+,-]*$` → unquoted
  - contains `$` and no `'` → single-quoted (prevents `${VAR}` interpolation)
  - else double-quoted with escapes
  - contains both `$` and `'` → refuse with an explanation
- **ENV-05 (secrets).** Keys matching `/(PASS|PASSWORD|SECRET|TOKEN|KEY|PRIVATE|CREDENTIAL|AUTH|DSN)/i` are shown as `••••••` unless the user picks *Reveal*.
- **ENV-06 (saving).**
  1. Parse check.
  2. Back up the current file to `shared/env-backups/.env.<UTC-ts>` (mode 600, keep 10).
  3. Write atomically (temp file + rename, mode 600).
- **ENV-07 (editor).** `edit` copies `.env` to `tmp/cpd-env-<rand>` (600) and opens `$VISUAL`, then `$EDITOR`, then `nano`, then `vi`. On save it validates; invalid input offers *Edit again / Discard*. The temp file is deleted in every case.
- **ENV-08 (apply to live).** After any change (Laravel sites), ask: "Apply to the live site now? (runs `php artisan optimize` on the live release)". Default Yes. Needed because config is cached per release.
- **ENV-09 (new `.env`).** Created in the wizard (§9.3 step 8) from the target commit's `.env.example`, falling back to a minimal Laravel template. Sets:
  - `APP_ENV=production`, `APP_DEBUG=false`
  - `APP_URL=https://<domain>`
  - `APP_KEY` (LAR-06)
  - the database keys (§7.13)

### 7.13 Database

- **DB-01 (create through cPanel).**
  1. Call `Mysql::get_restrictions`.
  2. Name = `<prefix><sanitised site name>` (lowercase, `[a-z0-9_]`), truncated to `database_name_length_limit`. User = the same rule with `database_user_name_length_limit`. Make both unique against `list_databases` and `list_users` by appending `_2`, `_3`, ….
  3. Password: 32 characters from `[A-Za-z0-9]`, with at least one character of each class. If cPanel rejects it as weak, retry once with 48 characters.
  4. `create_database` → `create_user` → `set_privileges_on_database` (ALL PRIVILEGES).
  5. Record `database.created_by_cpdeploy: true`, plus the name and user (never the password) in `site.yml`.
  6. `.env`: `DB_CONNECTION=mysql`, `DB_HOST=localhost`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
  7. Register every created object in the WizardTransaction, so a cancel deletes it again.
- **DB-02 (existing database).** Pick the database (`list_databases`) and the user (`list_users`, filtered to users with access to that database), then enter the password (hidden). Test with DB-04 before continuing.
- **DB-03 (SQLite).** `DB_CONNECTION=sqlite`, `DB_DATABASE=<absolute path>/shared/database/database.sqlite` (created empty, 600). Other `DB_*` keys are commented out.
- **DB-04 (connection check).**
  - Run the **site PHP** with a small inline PDO script (`-r`). Credentials are passed through environment variables `CPD_DB_DRIVER`, `CPD_DB_HOST`, `CPD_DB_PORT`, `CPD_DB_NAME`, `CPD_DB_USER` and `CPD_DB_PASS`, never in argv. Connect timeout 5 s.
  - The script prints `OK` or the PDO error message.
  - Supports mysql/mariadb, pgsql and sqlite. The PDO driver must exist in the site PHP (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`); a missing driver → `E_DB_DRIVER`.
- **DB-05 (remove).** Only when `created_by_cpdeploy` is true, and only after the user types the database name: `delete_database`, then `delete_user`.

### 7.14 Docroot management

- **DOC-01 (safety checks).** Run before the first go-live and in preflight. Each one blocks with `E_DOCROOT_UNSAFE` and names the problem.
  - (a) The docroot is inside `$HOME` and is not `$HOME` itself.
  - (b) The docroot is not inside `~/cpdeploy`, and `~/cpdeploy` is not inside the docroot.
  - (c) The **real path of the docroot's parent** is not inside any managed site's folder. Example: an addon docroot `public_html/shop.example.com` where `public_html` is already another site's symlink. Message: "This domain's folder lives inside another managed site. Change its document root in cPanel → Domains to a folder outside public_html, e.g. ~/shop.example.com".
  - (d) **No other domain's document root is inside this docroot** (from `domains_data`). Converting would move those sites' files and take them offline. Message: "public_html contains the folders of other domains: blog.example.com, … Converting it would take those sites offline. Move those domains' document roots outside public_html first (cPanel → Domains), or use a different domain for this site."
  - (e) No other managed site uses the same docroot.
  - (f) If the docroot is already a symlink, it must point inside **this site's** folder (normally `current/<web_dir>`). Any other symlink → block; the tool never rewrites a symlink it did not create.
  - (g) The docroot in `site.yml` must still equal the domain's document root reported by `DomainInfo`, and the domain must still exist. Otherwise → block: "cPanel now serves <domain> from <new path>, but cpdeploy manages <old path>. Run: cpdeploy config <site> set domain.docroot <new path>". Changing `domain.docroot` resets `converted_at`, so the new path is converted at the next go-live.
- **DOC-02 (first conversion, at the first go-live, §11.5).**
  1. If the docroot doesn't exist → create the relative symlink. Done.
  2. If it is a directory:
     - Capture the cPanel handler block from its `.htaccess` into `shared/php-handler.block`, if present (DOC-04).
     - Copy `.well-known/` into `shared/docroot/.well-known/`, and `.user.ini` / `php.ini` into `shared/docroot/`, if present.
     - If the old `.htaccess` has other rules beyond the handler block, log them and show a notice: "Your old .htaccess had extra rules (shown in the log). They are not carried over — add them to public/.htaccess in your repo if you need them."
     - Rename the docroot to `backups/docroot-<ts>`. Same filesystem, so this is atomic. If the rename fails with EXDEV (different filesystem) → `E_DOCROOT_MOVE`: nothing is copied, nothing changes.
     - Create the relative symlink at the docroot path.
     - Record `domain.converted_at` and `domain.backup` in `site.yml`.
  3. The gap between the rename and the symlink is microseconds. Accepted.
  4. `cgi-bin/` inside an old `public_html` goes into the backup. The notice says so. Users who need CGI can add `cgi-bin` to `shared.docroot_dirs`.
- **DOC-03 (the web dir needs `.htaccess`).** Before any `php_set_vhost_versions` call, the folder the docroot currently serves must contain an `.htaccess` (CP-05): `current/<web_dir>/`, or before the first go-live, the docroot folder itself. Create an empty one if needed.
- **DOC-08 (changed web dir).** If the docroot is already this site's symlink but points to a different path than `current/<web_dir>` (the user changed `web_dir`), G6 re-points it atomically, straight after the switch (FS-02 applied to the docroot link).
- **DOC-04 (PHP handler block).** cPanel writes a block like this into the docroot's `.htaccess`:

  ```
  # php -- BEGIN cPanel-generated handler, do not edit
  # Set the “ea-php82” package as the default “PHP” programming language.
  <IfModule mime_module>
    AddHandler application/x-httpd-ea-php82 .php .php8 .phtml
  </IfModule>
  # php -- END cPanel-generated handler, do not edit
  ```

  The docroot now points into a release, so each new release's `.htaccess` comes from git and lacks this block. Without it, the site could fall back to the server's default PHP. Rules:
  - **Capture:** read the block between the BEGIN and END marker lines (exact text) from the folder the docroot currently serves (`current/<web_dir>/.htaccess`, or before the first go-live, the docroot folder's `.htaccess`):
    - at the start of every deploy (PRE-21), so changes made in cPanel's MultiPHP Manager are kept
    - after every MultiPHP change the tool makes
    - from the old docroot at first conversion

    Save it to `shared/php-handler.block`.
  - **Rewrite** for the site PHP when it differs: replace the package token `(ea|alt)-php\d\d` with the site's family and version, and the `.phpN` extension with the PHP major version. LiteSpeed-style suffixes (`___lsphp`) are preserved.
  - **Inject** into each new release's `<web_dir>/.htaccess`: remove any existing cPanel block, then prepend the block plus one blank line. Create the file if it's missing.
  - If no block was ever captured (some PHP-FPM setups), inject nothing.
- **DOC-05 (`.htaccess` drift warning).** At deploy preflight, compare the live `<web_dir>/.htaccess` (handler block removed) with `git show <live-commit>:<web_dir>/.htaccess` (handler block removed). If they differ, cPanel features such as Redirects, Hotlink Protection or Directory Privacy, or a manual edit, changed the live file. Show a diff and ask:
  - *Continue (these changes will be lost)*
  - *Show diff*
  - *Cancel (commit them to the repo first)*

  Non-interactive: continue with a warning in the log.
- **DOC-06 (live-files drift, warning only).** At build time, write `.release-manifest` (path + SHA-1 of every file except `vendor/`, `node_modules/`, `storage/`, `bootstrap/cache/` and `public/build/`). At the next deploy's preflight, compare it with the live release. List up to 20 changed or added files as a warning: "Files changed directly on the server since the last deploy (they will not be in the new release)". The check SHOULD finish in under 5 s for 20k files. If it would take longer, skip it and say so.
- **DOC-07 (restore and detach, used by *Remove site*).**
  - **Restore original:** replace the docroot symlink with the folder from `backups/docroot-<ts>` (a rename).
  - **Detach:** copy the live release into `~/<site>-app/` (real files, with `.env` and `storage/` copied in from shared instead of linked), then point the docroot at `~/<site>-app/<web_dir>` as a relative symlink. The site keeps running without cpdeploy.
  - **Empty:** replace the symlink with an empty folder containing only the shared `.well-known`.

### 7.15 HTTP client, probe and health check

- **HTTP-01.** All HTTP traffic (GitHub API, downloads, health checks) goes through `Support/Http`, which runs the `curl` binary:
  - `--fail-with-body` is not used; the status code is read with `-w '%{http_code}'` and headers with `-D <tmpfile>`
  - `--location --max-redirs 5`
  - `--connect-timeout 10` and `--max-time <per call>`
  - secret headers passed with `--config -` on stdin

  Honour `https_proxy`/`HTTPS_PROXY` if set (curl does this natively).
- **HTTP-02 (retries).** GETs are retried twice on network errors or 5xx, with backoff 1 s then 3 s. Other methods are never retried automatically.
- **HTTP-03 (resolving the domain to this server).** Requests to the site's own domain use `--resolve <domain>:443:<ip>` and `--resolve <domain>:80:<ip>`, with the IP from `domains_data`. This tests this server even if DNS points elsewhere (e.g. Cloudflare, or DNS not moved yet).
- **HTTP-04 (served-PHP probe, used by `check --probe` and after a PHP change).**
  1. Write `current/<web_dir>/.cpd-probe-<32 hex>.php` containing `<?php echo PHP_VERSION;`, mode 644.
  2. GET `https://<domain>/<file>`, falling back to `http://` if HTTPS fails to connect.
  3. Delete the file in a `finally` block, always.
  4. Parse the version. Anything else → "unknown (the site did not return a version; it may be behind authentication or a firewall)".
- **HTTP-05 (health check).** §11.6.

### 7.16 Filesystem safety

- **FS-01 (atomic write).** Write `<file>.cpd-tmp-<rand>` with the final mode, `fflush` + `fsync` when available, then `rename()` over the target.
- **FS-02 (atomic symlink swap).** Create `current.cpd-tmp-<rand>` → target, then `rename()` it over `current`. Never use `ln -sfn`, which unlinks first.
- **FS-03 (safe delete). CRITICAL.** Deleting a release or any tree MUST NEVER follow symlinks. Releases contain links into `shared/`; following them would delete `.env` and uploads.
  - Use `rm -rf -- <path>`: GNU rm does not follow symlinks inside the tree.
  - Or use an iterator that `unlink()`s symlinks without descending.
  - Before any recursive delete, assert that the path is inside a site's `<site_dir>/releases/` (LAY-04), `~/cpdeploy/tmp/` or `~/cpdeploy/tools/`, has no symlinked folder above it, and is not `current`'s target. The only exception is DOC-07's "empty", which replaces a symlink, never a tree.
  - Removing a site deletes its site folder only when it is inside the home folder, outside `~/cpdeploy`, not a symlink, and holds nothing but `current`, `releases/` and `shared/`.
  - A unit test MUST build a release containing a symlink to a sentinel folder, delete the release, and assert the sentinel still exists.
- **FS-04 (copy).** `cp -a` for `vendor/` and build outputs; symlinks are preserved as symlinks.
- **FS-05 (disk usage).** `du -sk`. Disk usage appears in *Site info* and the releases list, calculated lazily with a spinner.
- **FS-06 (umask).** The process umask is `022` at start, and new secret files are created directly with mode `0600`.

### 7.17 Process runner (`Support/Shell`)

- **SH-01.** Everything runs with a **command array** (no shell) unless a pipeline is required (GIT-13), in which case it uses `bash -o pipefail -c '<script>'` built only from shell-quoted arguments.
- **SH-02.** Every run declares:
  - working directory
  - environment (a base plus additions)
  - timeout (from `config.yml → timeouts`)
  - output mode: `stream` (to the Reporter), `capture`, or `tty` (interactive passthrough, e.g. `tinker`, the editor)
- **SH-03 (base environment).**
  - `HOME`
  - `PATH` = shim dir + Node bin (when relevant) + `~/bin:/usr/local/bin:/usr/bin:/bin`
  - `LANG`/`LC_ALL` inherited, or `C.UTF-8` if unset
  - `GIT_TERMINAL_PROMPT=0`
  - Never inherit `GIT_DIR` or `GIT_WORK_TREE`.
- **SH-04 (logging).** Before running, log the command line with secrets masked, the working directory and the timeout. After running, log the exit code and duration. All output goes to the operation log, masked.
- **SH-05 (timeouts and cancel).** On timeout or Ctrl+C, terminate the whole **process group**:
  1. start the command under `setsid` when available
  2. `posix_kill(-pgid, SIGTERM)`
  3. after 10 s, SIGKILL

  Without `posix`, fall back to `Process::stop(10)`. Report `E_TIMEOUT` with the step name and the limit.
- **SH-06 (out-of-memory detection).** Exit 137, `Killed` in the output, or `JavaScript heap out of memory` → `E_OOM`: "The build ran out of memory (CloudLinux/LVE limits are common on cPanel). Raise the account's memory limit, set Node max memory in Manage site → Node, or build in CI."
- **SH-07 (disk full detection).** `Disk quota exceeded` or `No space left on device` in a failed command's output → `E_DISK`, with the quota figures from `Quota::get_quota_info`.

### 7.18 Logging, history and secret masking

- **LOG-01.** Each operation (deploy, rollback, recover, env change, PHP change, key rotation, remove) writes `logs/<UTC-ts>-<action>.log`, mode 600:
  - a header with tool version, Tool PHP, site, user and host
  - every step with its timing, commands, and output
  - a footer with the result and exit code
- **LOG-02.** Keep the last 50 log files per site; delete older ones at the end of each operation.
- **LOG-03.** `history.jsonl` gets one line per operation (§8.6). It is never truncated. Lines older than 2 years MAY be pruned.
- **LOG-04 (masking, `Support/Masker`).** Before anything is written to a log or the terminal, replace with `••••`:
  - the GitHub token
  - every `.env` value whose key matches ENV-05
  - the contents of `COMPOSER_AUTH`
  - DB passwords
  - any `Authorization:` header
  - any `https://user:pass@` credentials

  The Masker is loaded with the current secrets at the start of each operation. A unit test feeds each secret type through Shell output and asserts it never appears.

### 7.19 Locks and the state file

- **LCK-01.** Each site operation that changes anything takes an exclusive `flock` on `sites/<site>/.lock`, non-blocking. If the lock is held → `E_LOCKED`, showing `pid`, `action` and `started_at` from the lock file's contents: "Another cpdeploy operation (deploy, started 03:02 by PID 4121) is running for shop." The flock is released automatically if the process dies.
- **LCK-02.** Downloads into `tools/` take `tools/.lock` in blocking mode, waiting up to 10 minutes.
- **LCK-03 (state file).** Written atomically at every phase change of a deploy or rollback (§8.7). Deleted when the operation finishes, successfully or with a handled failure.
  - A state file that exists while the lock is **free** means the previous operation was interrupted. Run recovery (§11.9) before anything else touches the site.
- **LCK-04 (signals).**
  - With `pcntl`, SIGINT, SIGTERM and SIGHUP set a "cancel requested" flag. The runner stops the current process and the Deployer runs the phase's failure handling.
  - During the go-live critical section (§11.5 steps G1–G8, GL-01), signals are **deferred** until the section completes.
  - Without `pcntl`, the process dies and the state file drives recovery on the next run.

### 7.20 UI rendering rules

- **UI-01.** Menus use `select` (arrow keys + Enter). Long lists (repos, commits, releases) use `search`. Every option label is ≤ 74 characters.
- **UI-02.** Every select inside a flow includes `← Back` (except the first screen) and `Cancel`. Ctrl+C cancels the current flow and asks "Cancel and discard?" when there are side effects to clean up (wizard).
- **UI-03.** Text prompts in the wizard accept `<` as "go back one step". The hint says so.
- **UI-04.** Status symbols: `✓` success, `✗` failure, `⚠` warning, `?` question, `❯` cursor, `●` live, `○` other, `↺` rolled back. ASCII fallback (UI-07): `[ok] [x] [!] [?] > * - <`.
- **UI-05.** Times are shown in the server's local timezone as relative ("2h ago") plus absolute on detail screens. Everything is stored in UTC.
- **UI-06.** Sizes are human-readable (KB, MB, GB, base 1024). Durations: `38s`, `1m 41s`.
- **UI-07.** Use ASCII symbols when `LANG`, `LC_ALL` or `LC_CTYPE` does not contain `UTF-8`, or when `ui.unicode: false`.
- **UI-08.** Non-TTY output (`PlainReporter`): one line per step, `[HH:MM:SS] ✓ Composer (38s)`, no cursor movement, no spinners.
- **UI-09.** When a step fails, show the last 15 output lines under it, and the log path.

---

## 8. Configuration and data formats

- **CFG-01.** YAML files are read and written with `symfony/yaml`. The tool rewrites whole files, so **comments are not preserved**. Every file the tool writes starts with this header comment: `# Managed by cpdeploy. Edit with "cpdeploy config <site> edit" or the menus.`
- **CFG-02.** Every file has a `schema:` integer. `SiteSchema` / `GlobalSchema` migrate older schemas forward:
  1. back up the old file as `<file>.schema<N>.bak`
  2. migrate
  3. validate
  4. write

  A file with a **newer** schema than the tool supports → `E_CONFIG_NEWER` ("Update cpdeploy").
- **CFG-03.** Unknown keys → warning (possibly a typo), kept on rewrite. Invalid values → `E_CONFIG_INVALID`, listing every problem with its key path and the allowed values.

### 8.1 Global settings: `~/cpdeploy/config.yml` (600)

```yaml
schema: 1
defaults:
  keep_releases: 5          # used for new sites
  sync_multiphp: true       # used for new sites
  health_check: true        # used for new sites
timeouts:                   # seconds
  git: 300
  composer: 900
  node_install: 1200
  node_build: 1200
  artisan: 300
  migrate: 1800
  custom: 600
  http: 30
mirrors:
  node: https://nodejs.org/dist
  composer: https://getcomposer.org/download
ui:
  unicode: auto             # auto | true | false
  color: auto               # auto | true | false
  editor: ""                # "" = $VISUAL → $EDITOR → nano → vi
update:
  repo: "OWNER/cpdeploy"    # GitHub repo that publishes releases (default baked in at build time)
```

The GitHub token is **not** stored here. It lives in `~/cpdeploy/secrets/github-token` (600).

### 8.2 Site settings: `~/cpdeploy/sites/<site>/site.yml` (600)

```yaml
schema: 1
name: shop                      # ^[a-z0-9][a-z0-9-]{0,30}$, unique, cannot change after creation
type: laravel                   # laravel | static | php | custom
strategy: releases              # reserved; only "releases" in v1
created_at: "2026-09-29T03:05:12Z"
site_dir: cpdeploy_sites/shop.example.com   # the site's folder, relative to ~ (LAY-04); set at creation, never changes

repo:
  owner: acme
  name: shop
  branch: main                  # branch a normal deploy uses
  transport: ssh22              # ssh22 | ssh443 (auto-detected, GIT-04)
  deploy_key_id: 123456789      # GitHub key id when added with the token; null when added by hand

domain:
  name: shop.example.com
  docroot: /home/brainbean/shop.example.com   # absolute, from DomainInfo
  web_dir: public               # folder inside a release that is served; "" = release root
  ip: 203.0.113.10              # refreshed from DomainInfo at every deploy (HTTP-03)
  converted_at: null            # set at the first go-live
  backup: null                  # set at the first go-live, e.g. backups/docroot-20260929-030512

php:
  version: "8.2"                # major.minor
  family: ea                    # ea | alt
  sync_multiphp: true           # set the domain's MultiPHP version at go-live (PHP-06)

node:
  version: auto                 # auto | none | "20" | "20.11.1" | range | lts/*
  package_manager: auto         # auto | npm | pnpm | yarn
  build_script: build           # package.json script to run; "" = no frontend build
  expect_files: []              # [] = preset default (NODE-11)
  build_outputs: [public/build] # copied from the live release when the build is skipped (NODE-13)
  remove_node_modules: true
  max_old_space_mb: null        # e.g. 2048 → NODE_OPTIONS=--max-old-space-size=2048

composer:
  version: "2"                  # 2 | stable | 2.2 | exact version
  install_flags: "--no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress"
  allow_no_lock: false

steps:                          # when each built-in step runs (§11.4)
  composer_install: ask         # every | ask | off (off only if the repo has no composer.json)
  frontend_build: every         # every | ask | off
  storage_link: every           # every | off
  optimize: every               # every | off
  migrate: ask                  # every | ask | off
  maintenance: with_migrations  # with_migrations | off
  seed: off                     # first | ask | off   (php artisan db:seed --force)
  queue_restart: off            # every | off

custom_commands: []
# - name: Generate sitemap                 # shown in progress output
#   run: "php artisan sitemap:generate"    # bash -c, cwd = release (before_activate) or current (after_activate)
#   phase: before_activate                 # before_activate | after_activate
#   when: every                            # every | ask | first | off
#   timeout: 300
#   on_error: fail                         # fail | warn

shared:
  files: [.env]
  dirs: [storage/app, storage/logs, storage/framework/cache, storage/framework/sessions]
  docroot_files: [.user.ini, php.ini]
  docroot_dirs: [.well-known]

releases:
  keep: 5                       # 2–30, counting the live release; protected releases are kept in addition

maintenance:
  options: "--retry=60"         # extra flags for php artisan down
  secret: null                  # optional bypass secret → https://domain/<secret>

health_check:
  enabled: true
  path: /                       # e.g. /up for Laravel 11+
  expect: "200-399"             # status range, or a list like "200,301,302"
  timeout: 20                   # seconds per attempt
  attempts: 3                   # 5 s apart
  on_failure: ask               # ask | rollback | keep

database:
  created_by_cpdeploy: false
  name: null
  user: null                    # the password is only in shared/.env
```

### 8.3 Validation rules (`SiteSchema`)

| Rule | Detail |
|---|---|
| VAL-01 | `name` must match the pattern, be unique among sites, and not be a reserved word: `menu, add, deploy, rollback, releases, status, check, php, node, env, artisan, down, up, logs, config, key, token, recover, remove, self-update, settings, help, list` |
| VAL-02 | `type` is one of the 4 values. `strategy` must be `releases` |
| VAL-03 | `domain.docroot` is absolute and passes DOC-01 (a–c, e) at save time. (d) and (f) are checked at go-live |
| VAL-04 | `domain.web_dir` is relative, contains no `..`, and has no leading `/` |
| VAL-05 | `php.version` matches `^\d\.\d$`. The binary must exist at deploy time (not at save time, so a config copied from another server still loads) |
| VAL-06 | `node.version` is parseable per NODE-02, or `auto` / `none` |
| VAL-07 | Step "when" values are within each step's allowed set (see the comments above). `composer_install: off` is only valid when the repo has no `composer.json` (checked at deploy) |
| VAL-08 | `shared.dirs` / `shared.files`: relative, no `..`, no overlap between entries, and they MUST NOT include `storage/framework/views`, `bootstrap/cache`, `vendor`, `node_modules` or anything under the `web_dir` (REL-02) |
| VAL-09 | `releases.keep` is between 2 and 30 |
| VAL-10 | `health_check.expect` parses to ranges within 100–599. `timeout` is 1–120. `attempts` is 1–10 |
| VAL-11 | `custom_commands[*].run` is non-empty, `name` ≤ 40 characters, `timeout` 1–7200 |
| VAL-12 | `maintenance.secret`, if set, matches `^[A-Za-z0-9-]{8,64}$` |

### 8.4 Presets (`resources/presets/*.yml`)

A preset is a partial `site.yml` merged under the user's answers. Differences from the Laravel values shown in §8.2:

| Key | laravel | static | php | custom |
|---|---|---|---|---|
| `domain.web_dir` | `public` | `dist` (confirmed in wizard) | `public` if it exists, else `""` | asked |
| `steps.composer_install` | `ask` | `off` (`ask` if composer.json exists) | `ask` if composer.json exists, else `off` | `off` |
| `steps.frontend_build` | `every` if a build script exists, else `off` | `every` | `every` if a build script exists, else `off` | `off` |
| `steps.storage_link / optimize / migrate / seed / queue_restart` | as §8.2 | `off` | `off` | `off` |
| `steps.maintenance` | `with_migrations` | `off` | `off` | `off` |
| `shared.files` | `[.env]` | `[]` | `[.env]` if `.env.example` exists **and** `web_dir` is not `""` (SEC-11) | `[]` |
| `shared.dirs` | Laravel storage dirs | `[]` | `[]` | `[]` |
| `node.expect_files` | Vite: `public/build/manifest.json` or `public/build/.vite/manifest.json`; Mix: `public/mix-manifest.json` | web dir non-empty | none | none |
| `node.build_outputs` | `[public/build]` (Mix: `[public/js, public/css, public/mix-manifest.json]`) | `[<web_dir>]` | `[]` | `[]` |
| `health_check.path` | `/` (wizard suggests `/up` for Laravel ≥ 11 if `routes/web.php` or `bootstrap/app.php` mentions `health:`) | `/` | `/` | `/` |

### 8.5 Release metadata: `releases/<id>/.release.json` (644)

```json
{
  "schema": 1,
  "id": "20260929-030512",
  "status": "live",                       // building | ready | live | failed
  "protected": false,                     // protected releases are never pruned
  "commit": "f00ba12c9d…", "short": "f00ba12", "branch": "main", "ref": "main",
  "message": "Add coupon codes", "author": "Jay", "committed_at": "2026-09-28T21:45:00Z",
  "created_at": "2026-09-29T03:05:12Z", "activated_at": "2026-09-29T03:06:53Z",
  "php":      { "version": "8.2.27", "family": "ea", "binary": "/opt/cpanel/ea-php82/root/usr/bin/php" },
  "node":     { "version": "20.19.5", "package_manager": "npm 10.9.0" },
  "composer": { "ran": true, "reused_from": null, "version": "2.8.12" },
  "build":    { "ran": true, "reused_from": null },
  "migrations": { "ran": true, "list": ["2026_09_28_add_coupons_table"] },
  "laravel": "12.31.0",
  "durations": { "export": 2, "composer": 38, "frontend_build": 54, "optimize": 3, "total": 101 },
  "deployed_by": "brainbean@server1 (cpdeploy 1.0.0)"
}
```

Status transitions:

- `building` → `ready` (build complete) → `live` (activated) → `ready` (after another release goes live)
- `building` → `failed`

A rollback sets the target to `live` and the previous release to `ready`.

### 8.6 History: `history.jsonl` (600), one JSON object per line

```json
{"ts":"2026-09-29T03:06:55Z","action":"deploy","result":"success","exit_code":0,
 "release":"20260929-030512","from_release":"20260928-181002","commit":"f00ba12","from_commit":"c0ffee1",
 "duration_s":101,"log":"logs/20260929-030512-deploy.log","user":"brainbean","tool":"1.0.0",
 "notes":["composer: installed (lock changed)","migrations: 2 ran","health: 200 in 0.4s"]}
```

- `action` values: `deploy`, `rollback`, `recover`, `php-change`, `node-change`, `env-change`, `key-rotate`, `site-create`, `site-remove`.
- `result` values: `success`, `warning` (completed, with warnings), `failed`, `cancelled`, `interrupted`.

### 8.7 State file: `.deploy-state.json` (600)

```json
{"schema":1,"operation":"deploy","pid":4121,"started_at":"2026-09-29T03:05:10Z",
 "phase":"migrating","release":"20260929-030512","live_before":"20260928-181002",
 "maintenance_on":"20260928-181002","migrations_started":true,
 "multiphp_before":"ea-php82","multiphp_after":null,"docroot_converted":false,"switched":false}
```

- Deploy phases, in order: `planning`, `building`, `maintenance`, `migrating`, `multiphp`, `switching`, `converting_docroot`, `switched`, `finishing`.
- Rollback phases: `preparing`, `multiphp`, `switching`, `switched`, `finishing`.

### 8.8 Lock file contents: `.lock`

After taking the flock, the holder overwrites the file with `{"pid":…, "action":"deploy", "started_at":"…", "user":"…", "tool":"1.0.0"}`. Readers use it only to build the `E_LOCKED` message.

---

## 9. Interactive UI specification

The mockups show layout and wording. Implementations MAY adjust spacing, but MUST keep the options, their order, the defaults and the behaviour.

### 9.1 Global behaviour

- **UIG-01.** `cpdeploy` with no arguments: TTY → main menu. No TTY → print the command list, exit 0.
- **UIG-02 (first run).** If `~/cpdeploy` is missing:
  1. create it (LAY-02)
  2. show "Welcome to cpdeploy"
  3. run a quick server check (Tool, Programs, cPanel groups only) and show only ⚠ and ✗ lines
  4. continue to the main menu
- **UIG-03 (banners).** Shown above the main menu, in this order:
  1. interrupted operations: "⚠ A deploy of shop was interrupted. → Recover now" (selectable, runs §11.9)
  2. sites whose live release is in maintenance mode → *Turn off*
  3. GitHub token expiring in under 14 days, or invalid
- **UIG-04.** Every screen starts with a title line: `cpdeploy · <context>` (e.g. `cpdeploy · shop · Deploy`).
- **UIG-05.** After an action finishes, return to the menu it was started from. Show "Press Enter to continue" only after output the user needs to read (deploy summary, check results, logs).
- **UIG-06.** State-changing actions take the site lock (LCK-01). While a site is locked, its menu entries show `(busy: deploy in progress)` and choosing one shows `E_LOCKED`.

### 9.2 Main menu

```
 cpdeploy 1.0.0 · brainbean@server1 · 3 sites

  SITE    DOMAIN              LIVE      LAST
  shop    shop.example.com    f00ba12   ✓ deployed 2h ago
  blog    blog.example.com    9f8e7d6   ✗ deploy failed 10m ago
  admin   admin.example.com   1234abc   ↺ rolled back 3d ago

 ❯ Deploy a site
   Add a new site
   Manage a site
   Logs & history
   Server check
   Settings
   Quit
```

- The LAST column shows one of: `✓ deployed <when>`, `✗ deploy failed <when>`, `↺ rolled back <when>` (ASCII `<`), `– not deployed yet`, `… deploying now` (locked), `⚠ interrupted, recover`, `⚠ maintenance on`.
- With no sites: the table is replaced by "No sites yet. Add your first site to get started.", and the options are *Add a new site*, *Server check*, *Settings*, *Quit*.
- *Deploy a site* and *Manage a site*: with one site, go straight to it. With up to 8, use a `select`. With more, use a `search`.
- *Logs & history* shows the last 30 operations across all sites (§9.5.12 format).

### 9.3 Add-a-site wizard (10 steps)

**Frame.** Each screen's title is `Add a site · Step N/10 · <Step name>`.

**WIZ-01 (nothing written until Review).** Before Step 10's confirmation, the only permitted side effects are:

- creating the deploy key (and registering it on GitHub)
- a temporary bare clone in `tmp/cpd-wizard-<rand>/`
- a Composer download into `tools/`

The first two are recorded in `WizardTransaction`.

**WIZ-02 (navigation).** Selects include `← Back`, and text prompts accept `<` (UI-02, UI-03). Going back keeps the answers already given. Changing an earlier answer that later steps depend on (repo, branch, project type, domain) resets the dependent steps' answers and says so: "Changed repository — steps 3–9 will be asked again".

**WIZ-03 (cancel).**

1. Ask "Cancel adding this site?".
2. If the transaction has side effects, also ask "Remove the deploy key created for this site? (also deletes it from GitHub)". Default Yes.
3. Delete the temporary clone.

#### Step 1: Repository

```
 Add a site · Step 1/10 · Repository
 How do you want to pick the repository?
 ❯ Choose from my GitHub repos
   Paste a repository URL
   Cancel
```

- If no valid token exists, the first option reads "Choose from my GitHub repos (add a GitHub token in Settings first)". Selecting it explains how to add a token and returns here.
- **Repo list.** `search` over `full_name`, showing `private · updated 2d ago`.
- **URL input.** Accepts:
  - `git@github.com:o/r(.git)`
  - `https://github.com/o/r(.git)`
  - `ssh://git@ssh.github.com:443/o/r(.git)`
  - `o/r`

  Anything else → "That isn't a GitHub repository address. Examples: acme/shop or git@github.com:acme/shop.git".
- **Branch.** With a token: a `select` of branches, default = `default_branch`. Without one, the branch is chosen in Step 2.
- **Site name.** `text`, default = repo name lowercased and sanitised (VAL-01). Validated as you type.
- **Legacy import.** If §10.6 finds sites from the old script, this step first asks "Import a site set up with cpanel-git-setup.sh?", listing them plus "No, add a new site".

#### Step 2: GitHub access

```
 Add a site · Step 2/10 · GitHub access
 ✓ Connected to GitHub (port 22)
 ✓ Created deploy key ~/.ssh/cpdeploy_shop
 ✓ Added read-only deploy key to acme/shop          ← with a token
 ✓ Access OK
```

Without a token:

```
 Add this deploy key to acme/shop:
   https://github.com/acme/shop/settings/keys/new
   Title:  cpdeploy · shop · brainbean@server1
   Key:    ssh-ed25519 AAAAC3Nz… cpdeploy:shop@server1
   Leave "Allow write access" unchecked.

 ❯ I've added it — check access
   Show the key again
   ← Back
   Cancel
```

- A failed check says why (GIT-06) and returns to the same options.
- After access works: choose the branch (if not already chosen), default = remote HEAD (GIT-15).
- Then clone the bare mirror into `tmp/cpd-wizard-…` with a spinner: "Downloading repository…".
- Port 443 fallback message: "Port 22 is blocked here — using GitHub's port 443."

#### Step 3: Project type

```
 Add a site · Step 3/10 · Project type
 Detected: Laravel 12.31 (laravel/framework in composer.lock)
 ❯ Laravel
   Static site / SPA (Vite, React, Vue…)
   Plain PHP
   Custom (I'll define the steps)
   ← Back
```

- Submodules or LFS at the branch head → show `E_SUBMODULES` or `E_LFS`. The only options are *← Back* (pick another branch) and *Cancel*.

#### Step 4: Domain and folder

```
 Add a site · Step 4/10 · Domain
 Deploy to which domain?
 ❯ shop.example.com       ~/shop.example.com       empty
   example.com            ~/public_html            214 items
   blog.example.com       ~/public_html/blog       unavailable: inside site "www"
   staging.example.com    ~/staging                used by site "staging"
   Other folder…
   ← Back
```

- Domains come from `DomainInfo::domains_data` (main, addon, sub; parked domains are excluded).
- The status column shows one of: `empty`, `N items`, `app found`, `symlink`, `used by site "x"`, `unavailable: <reason>`.
- Selecting an unavailable row shows the full DOC-01 message and stays on this screen. Laravel Prompts selects have no disabled state **(verify; use it if one exists)**, so validation rejects the selection instead.
- **Non-empty folder notice:** "This folder has 214 items. At the first deploy they will be moved to ~/cpdeploy/sites/shop/backups/ (nothing is deleted)."
- **Main domain** (`public_html`): additional notice that `public_html` will become a link. DOC-01(d) applies: it is blocked if other domains live inside it.
- **Existing Laravel app detected** (`artisan` + `.env` found in the docroot, its parent, or a user-chosen folder):

  ```
  Found an existing Laravel app at ~/shop (has .env and storage/).
  ❯ Import its .env and storage/ (recommended)
    Start fresh
    Choose another folder…
  ```

  Import **copies** (never moves) the files at Create time.
- **Other folder…:** `text`, absolute path or `~/…`, validated with DOC-01.

#### Step 5: How the site is served

```
 Add a site · Step 5/10 · How the site is served
 Each deploy is built in its own release folder, then the domain switches to it
 instantly. Failed builds never touch the live site; rollback takes a second.

 Served folder inside each release:  public        (Laravel)
 ❯ Continue
   ← Back
```

- Static and plain PHP show a `select` of candidates instead: `dist`, `build`, `public`, `(release root)`, `Other…`, with the detected value pre-selected.

#### Step 6: PHP version

```
 Add a site · Step 6/10 · PHP version
 composer.lock needs PHP ^8.2 and: intl, gd, bcmath, pdo_mysql, …
 Domain currently uses: PHP 8.1 (MultiPHP)

   PHP 7.4 (ea)   ✗ too old (needs ^8.2)
   PHP 8.1 (ea)   ✗ too old (needs ^8.2)
 ❯ PHP 8.2 (ea)   ✓ compatible
   PHP 8.3 (ea)   ⚠ missing extension: intl
   ← Back
 Also set this as the domain's PHP version (MultiPHP) at go-live?  ❯ Yes  No
```

- Compatibility comes from PHP-05. It needs Composer, so show "Getting Composer (one time)…" if it isn't downloaded yet.
- **Default:** the domain's current version if it is compatible, otherwise the highest compatible version.
- **No compatible version:**
  - explain per version what is missing
  - fix hint: "Enable extensions in cPanel → MultiPHP INI Editor / Select PHP Version, or ask your host"
  - options *Continue anyway (the build will fail until fixed)* / *← Back*
- **CloudLinux PHP Selector domains:** show the two options from PHP-06 instead of the Yes/No question.

#### Step 7: Node.js

```
 Add a site · Step 7/10 · Node.js
 package.json has "build": "vite build"   ·   .nvmrc says 20   ·   npm (package-lock.json)
 ❯ Node 20.19.5   installed (ea-nodejs20)
   Node 22.20.0   download (~30 MB)
   Other version…
   No frontend build
   ← Back
```

- With no `package.json`, or no build script, the step is skipped and the Review shows "Frontend build: none".
- Options list installed versions that satisfy the spec, then the best downloadable version (NODE-04), then *Other version…* (`text`, validated by NODE-02).
- Picking a version that doesn't match `.nvmrc` shows "The repo's .nvmrc says 20 — the site setting will override it".
- *Change package manager…* and *Build script…* are available when more than one choice exists.
- glibc too old → `E_GLIBC_OLD` for downloads; only installed versions are offered.

#### Step 8: Environment and database

```
 Add a site · Step 8/10 · Environment (.env)
 ❯ Create from .env.example and fill in the essentials
   Paste an existing .env
   I'll add it later (deploys are blocked until it exists)
   ← Back
```

- **Create:** prompts, pre-filled:
  - `APP_NAME` (from `.env.example` or the site name)
  - `APP_URL` (`https://<domain>`)

  Then show the automatic values: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY=base64:••••` (generated).
- **Paste:** `textarea`. Validated with ENV-01. Warn if `APP_KEY` is empty ("will be generated") or `APP_DEBUG=true`.
- **Imported** (Step 4): "Using .env from ~/shop/.env". Skip to the database connection test.
- Then:

  ```
  Database
  ❯ Create a new MySQL database and user   (brainbean_shop / brainbean_shop)
    Use an existing database…
    SQLite (file kept in shared/database/)
    Skip — I'll set DB_* myself
    ← Back
  ```

  - Names come from DB-01 and are shown before creation.
  - The database is created at **Create time** (WIZ-01), not here.
  - *Use an existing database…* asks for the database, user and password (hidden) and tests the connection right away (DB-04).

#### Step 9: Deploy steps

```
 Add a site · Step 9/10 · Deploy steps          select a step to change when it runs
 ❯ composer install --no-dev -o ........ ask each deploy
   Frontend build (npm run build) ...... every deploy
   php artisan storage:link ............ every deploy
   php artisan migrate --force ......... ask each deploy
   Maintenance mode .................... while migrations run
   php artisan optimize ................ every deploy
   php artisan db:seed --force ......... off
   php artisan queue:restart ........... off
   + Add a custom command…
   Keep last 5 releases
   Health check: GET / → expect 200–399
   Done
   ← Back
```

- Selecting a step opens a `select` of its allowed "when" values (§8.2) with one-line explanations.
- Custom command: name, command, phase, when, `on_error`.
- "Keep last N" uses `number` (2–30).
- Health check: path (`text`) and enabled (Yes/No).
- Defaults come from the preset (§8.4).

#### Step 10: Review

```
 Add a site · Step 10/10 · Review
   Site        shop
   Repo        acme/shop @ main  (deploy key added automatically)
   Domain      shop.example.com → releases (keep 5) · serves public/
   PHP 8.2 (ea, set in MultiPHP at go-live) · Node 20 (npm) · Composer 2
   .env        from .env.example · DB brainbean_shop (new)
   Every       frontend build · storage:link · optimize
   Ask         composer install · migrate
   Health      GET / → 200–399
 ❯ Create site and deploy now
   Create site only
   Edit a step…
   Cancel
```

- **WIZ-04 (Create, as a transaction).** Steps in order:
  1. Create `sites/<site>/` with the modes in §7.1, and move the temporary mirror to `repo.git`.
  2. Create the site folder `~/<sites_dir>/<domain>` (LAY-04; it must not exist yet) and the shared skeleton in it (REL-03 runs at the first deploy).
  3. Create the database and user (DB-01) and add the DB keys to `.env`.
  4. Write `shared/.env` (600), or copy the imported `.env`. Copy an imported `storage/` into `shared/storage/`.
  5. Test the database connection. Failure is a warning only.
  6. Write `site.yml`.
  7. Append `site-create` to history.

  If any step fails, undo the earlier steps in reverse order: drop the DB and user this run created, delete the site folder. Keep the deploy key, and offer to remove it. Show the error.
- **WIZ-05.** *Create site and deploy now* goes straight into the Deploy screen (§9.4) for the first deploy.

#### Non-interactive creation: `cpdeploy add --from=<file.yml>`

- The file is a `site.yml` (§8.2) plus a `setup:` section:
  - `env: example | file:<path> | none`
  - `app_url`
  - `database: create | existing | sqlite | none`
  - `db_existing: {name, user, password_env: VAR}` (the password is read from an environment variable, never from the file)
  - `deploy_now: bool`
- Without a token, the deploy key must be added by hand. The command prints the key and the instructions and exits 2. Running the same command again reuses the key and continues.

### 9.4 Deploy screen

**Order of events.** Maps to §11: Phase A (plan), Phase B (build), Phase C (go live), Phase D (finish).

```
 cpdeploy · shop · Deploy
 ⠋ Fetching from GitHub…
 Deploy shop: c0ffee1 → f00ba12 (3 new commits on main)
   f00ba12  Add coupon codes            Jay · 20m ago
   c0ffee1  Fix cart total rounding     Jay · 1h ago
   b4dcafe  Update dependencies         Dependabot · 1d ago
 Checks: ✓ disk 4.2 GB free · ✓ PHP 8.2 · ✓ Node 20 · ✓ .env · ✓ database
 ⚠ public/.htaccess on the live site was changed outside git  → Show diff

 ? composer install   composer.lock changed: 4 updated, 1 added
                      ❯ Yes, install     Skip (reuse vendor/ from live)
 ? Migrations         2 new:
                        2026_09_28_add_coupons_table
                        2026_09_28_add_discount_to_orders
                      ❯ Yes, run them    Skip
 Deploy f00ba12 to shop.example.com?   ❯ Deploy   Cancel

 ✓ Export              f00ba12 → releases/20260929-030512       2s
 ✓ Shared files        .env · storage · .well-known             0s
 ✓ Composer            PHP 8.2 · 94 packages                     38s
 ✓ Frontend build      Node 20 · npm ci + npm run build          54s
 ✓ storage:link                                                   0s
 ✓ optimize            config · events · routes · views          3s
 ✓ Maintenance on      (live release)
 ✓ Migrations          2 ran                                      2s
 ✓ Go live             current → 20260929-030512
 ✓ Maintenance off
 ✓ Health check        GET / → 200 in 0.4s
 ✓ Cleanup             kept 5 releases, removed 1

 Live in 1m 41s · https://shop.example.com
 Press Enter to continue
```

**Special cases at the plan stage**

| Case | Screen |
|---|---|
| First deploy | "First deploy of shop." If the docroot is not empty: "~/shop.example.com (214 items) will be moved to ~/cpdeploy/sites/shop/backups/ at go-live." No composer question (always installs). The migrations question is shown if any migration files exist, default Yes |
| Same commit already live | "Nothing new on main — f00ba12 is already live." Options: *Back* (default) / *Rebuild and redeploy f00ba12* |
| Rewind (the live commit is not an ancestor of the target) | "⚠ main was rewritten: 3 live commits are not in the new history, 2 new commits added." Options: *Deploy anyway* / *Cancel* (default) |
| Blocking check failed | List every ✗ with its fix. Offer inline fixes where available: *Generate APP_KEY now*, *Create .env now*, *Recover interrupted deploy*. Then *Back* |
| Warnings | Each ⚠ is one line. Deploy continues after confirmation |

**Result screens**

- **Success:** as in the mockup above, plus any warnings (`⚠ custom command "Warm cache" failed (on_error: warn)`).
- **Package migrations pending after go-live** (PLN-07):

  ```
  ⚠ 1 package migration is pending (not in your repo's database/migrations):
      2026_09_01_000000_create_telescope_entries_table
  ❯ Run it now (maintenance on for a few seconds)
    Not now
  ```

- **Build failure** (Phase B):

  ```
  ✗ Frontend build failed after 22s — your live site was not changed.
    …last 15 lines of output…
    Log: ~/cpdeploy/sites/shop/logs/20260929-030512-deploy.log
  ❯ View full log
    Retry
    Retry with changes…
    Back to menu
  ```

- **Migration failure:** "✗ Migration failed — the site is back up on the previous release (c0ffee1). These migrations completed before the failure: … The database may need attention before the next deploy." Options: *View full log* / *Back to menu*.
- **Health-check failure** (§11.6):

  ```
  ⚠ https://shop.example.com/ returned 500 after go-live (3 attempts).
  ❯ Roll back to 20260928-181002 (c0ffee1)
    Keep the new release
    View log
  ```

  If migrations ran, add: "Migrations ran in this deploy; the previous code may not work with the new database."

### 9.5 Manage a site

```
 cpdeploy · shop · shop.example.com · Laravel · PHP 8.2 · Node 20
 Live: f00ba12 "Add coupon codes" · deployed 2h ago · 5 releases
 ❯ Deploy now
   Deploy with changes…
   Roll back…
   Releases
   PHP version
   Node version
   Deploy steps
   Environment (.env)
   Laravel tools              (Laravel sites only)
   Branch
   Deploy key
   Composer credentials
   Logs & history
   Site info
   Remove site…
   ← Back
```

#### 9.5.1 Deploy with changes

- `multiselect` of one-off options:
  - *Different branch, tag or commit…* (then a `search` over branches, tags and the last 100 commits)
  - *Skip composer install* (reuse `vendor/`)
  - *Force composer install*
  - *Skip frontend build* (reuse the build output)
  - *Skip migrations*
  - *Skip optimize*
- Contradictory selections (skip + force composer) are rejected by validation.
- Then the normal Deploy screen; the questions already answered by these options are not asked again.
- CLI equivalents: `--ref`, `--composer=no|yes`, `--skip-build`, `--migrate=no`, `--skip-optimize`.

#### 9.5.2 Roll back

- `search` over kept releases, newest first, excluding the live one. Each row: `id · short commit · message · deployed <when> · PHP x.y`.
- Then show warnings (§11.8 RB-04) and ask for confirmation. Progress and result as in the Deploy screen.

#### 9.5.3 Releases

- Table: id, commit, message, created, PHP, status (`● live`, `ready`, `failed`), protected (🔒 / ASCII `P`), size (lazy).
- Selecting a release offers:
  - *Details* (the `.release.json` fields)
  - *Roll back to this release*
  - *Protect* / *Unprotect*
  - *Delete* (not allowed for the live release; asks for confirmation)

#### 9.5.4 PHP version

1. Show:
   - the site setting
   - the domain's current MultiPHP version (or "CloudLinux PHP Selector: alt-php 8.2")
   - the version recorded for the live release
   - *Check served version* (runs the HTTP-04 probe on demand)
2. Version list with PHP-05 compatibility against the **live commit**.
3. After choosing a version:
   - **Redeploy now with PHP 8.3? (recommended: rebuilds `vendor/` for 8.3; the domain switches to 8.3 at go-live)**
     - *Yes, deploy now*. Composer install is forced (PLN-02).
     - *No, only save the setting*. It takes effect at the next deploy.
   - If *No* and `sync_multiphp` is on: "Also switch the domain's PHP right now? The live release was built with PHP 8.2 — switching without rebuilding can break the site." Default **No**. If Yes: DOC-03, then `php_set_vhost_versions`, then capture the handler block, then the probe.
4. Every change writes a `php-change` history entry.

#### 9.5.5 Node version

- Options: *auto* (from the repo), version list, *Other…*, *None (no frontend build)*.
- Explain: "Used from the next deploy." Offer *Deploy now*.

#### 9.5.6 Deploy steps

- The same editor as wizard Step 9. Saves `site.yml`.

#### 9.5.7 Environment (.env)

- Options:
  - *View* (masked)
  - *Reveal values…* (asks for confirmation)
  - *Edit a variable…* (`search` over keys)
  - *Add a variable…*
  - *Remove a variable…*
  - *Open in editor*
  - *Restore a backup…* (list of `env-backups`, with a diff preview)
  - *Apply to live site* (ENV-08)
  - *← Back*
- Every change follows ENV-06 and then asks the ENV-08 question.

#### 9.5.8 Laravel tools

Everything runs in the **live** release with that release's PHP (PHP-07). State-changing items take the site lock.

- *Run artisan command…*: `text`. LAR-08 asks for typed confirmation on dangerous commands. Output streams live; interactive commands use `tty` mode.
- *Maintenance mode: turn on…* (options: retry seconds, optional secret, then show the bypass URL) / *Turn off*.
- *Rebuild caches* (`optimize`).
- *Clear caches* (`optimize:clear`). Warns that it also empties the application cache.
- *Migration status* (`migrate:status`).
- *Run pending migrations…*: shows the list, then asks for confirmation. Sequence: down (live) → `migrate --force` → up. Uses the same code as GoLive steps G1/G2.
- *Tinker* (`tty` mode).
- *View laravel.log*: last 100 lines of `shared/storage/logs/laravel.log`, or the newest `laravel-*.log`.
- *Show scheduler cron line* (LAR-07).

#### 9.5.9 Branch

- Fetch, then a `select` of remote branches, marking the current one.
- Save it to `repo.branch`, then offer *Deploy now*.

#### 9.5.10 Deploy key

- *Show public key*, *Test access* (GIT-06), *Rotate key* (GIT-18), *← Back*.

#### 9.5.11 Composer credentials

- *Edit auth.json* (editor, validated as JSON, mode 600) / *Remove* / *← Back*.
- Explains when this is needed: private Composer packages, Laravel Nova, and similar.

#### 9.5.12 Logs & history

- Table of the last 20 entries from `history.jsonl`: when, action, result, release, duration.
- Selecting an entry opens its log in the Pager.
- Filter: *Failures only*.

#### 9.5.13 Site info

- Paths: docroot → link target; release, shared and repo folders.
- Versions: site PHP, domain MultiPHP, served PHP (on demand), Node, Composer, Laravel.
- Disk usage: releases, shared, repo mirror (lazy).
- Database name and user; deploy key fingerprint; transport.

#### 9.5.14 Remove site

```
 Remove site "shop"
 What should happen to shop.example.com?
 ❯ Keep it running from a plain folder (~/shop-app)          [detach]
   Put back the folder from before cpdeploy (backups/docroot-20260929-030512)
   Leave an empty folder (the site goes offline)
 Also remove:
   ◼ Deploy key from GitHub and ~/.ssh/cpdeploy_shop
   ◼ Releases and the repo copy
   ◻ .env and uploads permanently  (otherwise moved to ~/cpdeploy/removed/shop-<ts>/)
   ◻ Database brainbean_shop and its user   (only offered if created by cpdeploy)
 Type the site name to confirm: _
```

- **RM-01.** The order is: docroot action (DOC-07) → GitHub key → local key → database (if ticked; also requires typing the database name) → move or delete shared → delete releases and `repo.git` (FS-03) → delete the site folder → history entry in `~/cpdeploy/removed/history.jsonl`.
- **RM-02.** If the docroot action fails, stop before deleting anything.
- **RM-03.** If the site was never activated (`domain.converted_at` is null), the docroot is left untouched and the docroot question is skipped.
- *Put back the folder* is only offered if the backup exists.

### 9.6 Settings

- *GitHub token*: *Set / replace* (hidden input, validated with `GET /user` before saving, shows the login and expiry) / *Test* / *Remove* / GH-03 guidance.
- *Defaults for new sites*: keep releases, MultiPHP sync, health check.
- *Timeouts*: every key in `config.yml → timeouts`.
- *Display*: unicode, colour, editor.
- *Refresh GitHub host keys* (GIT-03).
- *About*: tool version, Tool PHP path and version, phar path, config paths, update repo.
- *Check for updates* (§15.5).

### 9.7 Server check (`cpdeploy check`)

Groups and checks. Each line is `✓`, `⚠` or `✗` + detail + fix hint.

| Group | Checks |
|---|---|
| Tool | Tool PHP version and path; extensions `phar`, `mbstring`, `json`, `ctype`; `pcntl` and `posix` (⚠ if missing: "spinners static, Ctrl+C less graceful"); `proc_open`, `exec` and `shell_exec` allowed; not running as root |
| Programs | `git` (version), `ssh`, `ssh-keygen`, `tar`, `gzip`, `curl`, `stty`, `du`, GNU `cp`, `uapi`, `less` (⚠ only) |
| cPanel | `uapi` responds (`DomainInfo::domains_data`); MultiPHP available; installed PHP versions list; MySQL available (`get_restrictions`); CloudLinux / PHP Selector detected (info) |
| Account | Disk quota used/limit; inodes used/limit (⚠ > 80%, ✗ > 95%) |
| Network | github.com:22 or ssh.github.com:443; api.github.com (only with a token); getcomposer.org; nodejs.org (⚠ only: needed only for downloads) |
| GitHub | Token valid, login, expiry (⚠ < 14 days); host keys file matches the published fingerprints |
| Per site | Deploy key access (GIT-06); site PHP binary exists; Node resolvable (if a build is enabled); `.env` exists with mode 600 (auto-fix offered); docroot is the expected symlink; `current` exists and its release is `live`; shared dirs writable; no state file (else ⚠ with *Recover*); maintenance on (⚠); with `--probe`: served PHP = site PHP |

- Exit code 0 if there is no ✗, otherwise 3. `--json` outputs `{groups:[{name, checks:[{id, status, message, hint}]}]}`.

---

## 10. Command-line interface

### 10.1 Global behaviour and options

- **CLI-01 (root refusal).** If the effective UID is 0 → `E_ROOT`: "cpdeploy must run as the cPanel user, not root (files would be owned by root and the site would break). Run: su - <user> -s /bin/bash -c cpdeploy". The hidden option `--allow-root` exists for CI only and is not documented.
- **CLI-02 (global options).**

  | Option | Effect |
  |---|---|
  | `-n`, `--no-interaction` | Never prompt (§10.4) |
  | `-y`, `--yes` | Accept every default answer and confirmation |
  | `-v`, `-vv` | Show full command output live (`-vv` also shows the commands) |
  | `-q` | Errors only |
  | `--no-ansi` | No colour or cursor movement |
  | `--json` | Machine output, on commands that support it |
  | `--version` | Tool version, Tool PHP and phar path |
  | `-h`, `--help` | Help |

- **CLI-03.** Site arguments accept the site name. When a command needs a site and none is given in a TTY, show the site picker. Without a TTY → exit 2.

### 10.2 Commands

| Command | Arguments and options | Notes |
|---|---|---|
| `cpdeploy` / `menu` | | Main menu (TTY only) |
| `add` | `--from=<file.yml>` | Wizard (§9.3), or file-based creation |
| `deploy <site>` | `--ref=<branch\|tag\|sha>` · `--composer=auto\|yes\|no` · `--migrate=auto\|yes\|no` · `--seed=auto\|yes\|no` · `--skip-build` · `--skip-optimize` · `--force` (redeploy the same commit) · `--allow-rewind` · `--no-health-check` · `--on-health-fail=ask\|rollback\|keep` · `--recover` (run recovery first if needed) | §11 |
| `rollback <site> [release]` | `--previous` · `--yes` · `--no-health-check` | §11.8. Without a release: picker (TTY) or `--previous` |
| `releases <site>` | `protect <id>` · `unprotect <id>` · `delete <id>` · `--json` | |
| `status [site]` | `--json` | Site table / one-site detail |
| `check [site]` | `--probe` · `--json` · `--refresh-host-keys` | §9.7 |
| `php <site> [version]` | `--no-sync` · `--redeploy` / `--no-redeploy` · `--switch-now` | No version → show current. `--switch-now` = set MultiPHP immediately (§9.5.4) |
| `node <site> [version\|auto\|none]` | | No version → show current |
| `env <site> <sub>` | `list [--reveal]` · `get KEY` · `set KEY=VALUE [--apply]` · `unset KEY [--apply]` · `edit` · `apply` · `restore <backup>` | `set` with a value of `-` reads the value from stdin (for secrets) |
| `artisan <site> -- <args…>` | | Runs in the live release (PHP-07). LAR-08 confirmation (non-interactive: needs `--yes`) |
| `down <site>` | `--retry=N` · `--secret=S` | Live release |
| `up <site>` | | Live release |
| `logs <site> [id]` | `--last` · `--failed` · `--tail=N` · `--json` | No id → list |
| `config <site> <sub>` | `show` · `get <key.path>` · `set <key.path> <value>` · `edit` | Validated (§8.3). `edit` opens the editor and re-validates |
| `key <site> <sub>` | `show` · `test` · `rotate` | §7.4 |
| `token <sub>` | `set [--stdin]` · `test` · `remove` | §7.5 |
| `recover <site>` | `--yes` | §11.9 |
| `remove <site>` | `--detach` \| `--restore-backup` \| `--empty` · `--keep-key` · `--delete-shared` · `--drop-db` · `--yes` | §9.5.14. Non-interactive requires the docroot choice and `--yes` |
| `self-update` | `--check` · `--rollback` | §15.5 |

### 10.3 Exit codes

| Code | Meaning | Live site |
|---|---|---|
| 0 | Success (including "nothing to do") | changed as intended |
| 1 | Unexpected error (bug); stack trace in the log | see message |
| 2 | Usage error, invalid config, or an answer is needed in non-interactive mode | unchanged |
| 3 | Preflight failed / server check found ✗ | unchanged |
| 4 | Build failed (Phase B) | unchanged |
| 5 | Migration failed (live release back up) | old code live; database may be partly migrated |
| 6 | Go-live failed (recovery attempted; see message) | see message |
| 7 | Health check failed after go-live (rolled back or kept per option) | see message |
| 8 | Rollback failed | see message |
| 10 | Site is locked by another operation | unchanged |
| 11 | Interrupted operation needs recovery (`cpdeploy recover <site>`) | see message |
| 130 | Cancelled by the user (Ctrl+C or *Cancel*) | unchanged, or restored by the phase's failure handling |

### 10.4 Non-interactive rules

- **NI-01.** Non-interactive applies when `-n` is given, or STDIN or STDOUT is not a TTY.
- **NI-02.** A question that would be asked is resolved:
  1. from its explicit flag
  2. else, with `--yes`, the computed default (PLN rules)
  3. else → exit 2, naming every flag that would answer the open questions, e.g. "Needs answers: --composer=yes|no --migrate=yes|no (or --yes for the defaults)"
- **NI-03.** Confirmations (the final "Deploy?", rollback, remove, dangerous artisan commands) require `--yes`.
- **NI-04.** `--on-health-fail` default when not interactive:
  - `rollback` if no migrations ran in this deploy
  - `keep` if they did (rolling code back after migrations is riskier than keeping it)
- **NI-05.** Output uses `PlainReporter` (UI-08). With `--json`, stdout contains **only** the JSON document. Progress goes to stderr.

### 10.5 JSON output

- **status:**

  ```
  {sites:[{name, type, domain, branch,
           live:{release, commit, message, deployed_at, php, node} | null,
           last:{action, result, at} | null,
           maintenance: bool, locked: bool, interrupted: bool}]}
  ```

- **releases:**

  ```
  {site, live, releases:[{id, status, protected, commit, short, message, created_at, php, size_kb|null}]}
  ```

- **logs:** `{site, entries:[<history.jsonl objects>]}`
- **check:** see §9.7.
- **Schema versioning.** Every JSON document has `"schema": 1`. Fields are only ever added.

### 10.6 Importing sites from `cpanel-git-setup.sh`

- **LEG-01 (detection).** Directories `~/deployments/<name>/` that contain `deploy.conf` **and** `repo/.git`.
- **LEG-02 (reading).**
  - From `deploy.conf`: `BRANCH`, `DEST`, `SUBDIR`, `BUILD_CMD`, `POST_CMD`, `PHP_VERSION`, `NODE_VERSION`, `COMPOSER_VERSION`.
  - Owner and repo: from `git -C repo remote get-url origin` (the form `git@github-<name>:owner/repo.git`).
  - Deploy key: `~/.ssh/deploy_<name>`.
- **LEG-03 (pre-filling the wizard).**

  | Field | Value |
  |---|---|
  | Repo, branch | as read |
  | Domain | the domain whose docroot equals `DEST`, if any |
  | PHP | `PHP_VERSION`, if not `auto` |
  | Node | `NODE_VERSION` |
  | Project type | detected |

  - The old `BUILD_CMD` and `POST_CMD` are **shown** ("The old setup ran: …") so the user can confirm that the preset's steps cover them. They are not translated automatically.
  - The old deploy key is **copied** to `~/.ssh/cpdeploy_<site>` (it is already registered on GitHub), and access is tested immediately. The old key file is left in place.
- **LEG-04 (after a successful first deploy).**
  - Offer to remove the old crontab line (`# git-deploy:<name>`) and the old webhook file (`deploy-hook-<name>-*.php` in the hook dir).
  - Remind the user to delete the GitHub webhook, since cpdeploy is manual-only (D2).
  - Never delete `~/deployments/<name>` automatically. Tell the user it can be removed once they are happy.

---

## 11. Deploy engine

### 11.1 Phases

```
A. PLAN      nothing on the live site changes     lock → fetch → analyse → resolve runtimes → preflight → questions → confirm
B. BUILD     only the new release folder changes  export → shared links → composer → frontend → storage:link → optimize → custom → migration check
C. GO LIVE   short, ordered, recoverable          maintenance on → migrate → seed → MultiPHP → SWITCH → docroot → MultiPHP → maintenance off → after-activate → health
D. FINISH                                          statuses → history → prune → logs → unlock
```

- **ENG-01.** The Deployer writes the state file (§8.7) at every phase change. It removes the state file only when the run ends (success, handled failure or cancel).
- **ENG-02.** Different sites MAY deploy concurrently: each site has its own lock, and the tools lock covers shared downloads. The same site never runs two operations at once (LCK-01).
- **ENG-03.** Every step reports to the Reporter: `start(label)`, `line(text)`, `succeed(detail)`, `warn(text)`, `fail(error)`. Durations are recorded in `.release.json → durations`.

### 11.2 Phase A: plan and preflight

**Order.**

1. Take the lock (LCK-01).
2. If a state file exists → recovery (§11.9). Interactive: offer *Recover now*. Non-interactive: run it only with `--recover`, otherwise exit 11.
3. Load and validate `site.yml`.
4. Refresh `domain.ip` from DomainInfo.
5. Fetch (GIT-08).
6. Resolve the target (GIT-09).
7. Analyse changes (CHG-02).
8. Resolve runtimes, downloading Composer and Node if needed, with progress shown.
9. Run the preflight checks.
10. Ask the questions (§11.3).
11. Confirm.

**Preflight checks.** Blocking checks stop the deploy with exit 3. Warnings are shown and the deploy continues.

| ID | Check | Severity | Fix offered |
|---|---|---|---|
| PRE-01 | Not running as root | block | — |
| PRE-02 | `site.yml` valid | block | `cpdeploy config <site> edit` |
| PRE-03 | GitHub fetch succeeded (key, network, host key) | block | GIT-06 message; *Test key* / *Rotate key* |
| PRE-04 | Target ref exists; no submodules / LFS | block | — |
| PRE-05 | Same commit already live (without `--force`) | stop (exit 0, "nothing to do") | *Redeploy anyway* |
| PRE-06 | Rewind detected (without `--allow-rewind`) | block (non-interactive) / confirm (interactive) | — |
| PRE-07 | Site PHP binary exists, is CLI, and its major.minor equals `php.version` | block | list installed versions |
| PRE-08 | Platform requirements satisfied for the target lock (PHP-05) | block | name the missing extensions |
| PRE-09 | `composer.lock` present (if `composer.json` exists and `allow_no_lock` is false) | block | — |
| PRE-10 | Node resolvable, glibc OK, lockfile present (warn if not) | block / warn | — |
| PRE-11 | `shared/.env` exists and parses; mode 600 | block / auto-fix mode | *Create .env now* (EnvMenu) |
| PRE-12 | Laravel: `APP_KEY` set | block | *Generate APP_KEY now* |
| PRE-13 | Laravel: `APP_ENV=local` or `APP_DEBUG=true` | warn | — |
| PRE-14 | Database reachable (DB-04), checked when migrations might run or it is the first deploy | block if migrations will run, else warn | show the PDO error |
| PRE-15 | Disk and inodes: free ≥ 1.3 × estimate (estimate = live release size, or for a first deploy: repo archive × 1.2 + 400 MB); inodes free ≥ 60 000 when a Node build runs | block | "free space / lower keep_releases / delete old releases" |
| PRE-16 | Docroot safety (DOC-01, all of a–f) | block | message per rule |
| PRE-17 | Live `.htaccess` drift (DOC-05) | warn + choice | *Show diff* |
| PRE-18 | Live files drift (DOC-06) | warn | — |
| PRE-19 | Live release is in maintenance mode at deploy start | warn | "The site is in maintenance mode. The new release will be up after go-live; turn maintenance on again afterwards if you need it." |
| PRE-20 | MultiPHP version of the domain ≠ site PHP (sync on) | info | "Domain will switch from 8.1 to 8.2 at go-live" |
| PRE-21 | Docroot extras resync (REL-06) and handler block capture (DOC-04) | action | — |
| PRE-22 | Token expired while `deploy_key_id` is set | warn | "Key removal/rotation will need a new token" |
| PRE-23 | Domain still exists, and its document root in cPanel still equals `domain.docroot` (DOC-01 g) | block | `cpdeploy config <site> set domain.docroot <path>` |

### 11.3 Phase A: questions and the DeployPlan

- **PLN-01.** All questions are asked in Phase A, in this order:
  1. composer
  2. frontend build (only when its step is `ask`)
  3. migrations
  4. seed
  5. custom commands with `when: ask`
  6. the final confirmation

  After the confirmation, no questions are asked until Phase D (PLN-07).

- **PLN-02 (composer install).**

  | Situation | Question | Default / result |
  |---|---|---|
  | No `composer.json` in the target | none | skip entirely |
  | `steps.composer_install: every` | none | install |
  | First deploy, **or** the live release has no `vendor/` | none | install ("first deploy" / "no vendor to reuse") |
  | Site PHP ≠ the live release's build PHP (major.minor) | shown, with a note | **Yes** ("PHP changed 8.2 → 8.3: dependencies must be reinstalled"). Choosing Skip needs a second confirmation |
  | `composer.json` or `composer.lock` changed | shown with the lock diff summary | **Yes** |
  | No change | shown | **Skip** (reuse `vendor/` + `dump-autoload`, CMP-05) |
  | `--composer=yes/no` | none | per the flag (`no` is refused on first deploy / no vendor → exit 2) |

- **PLN-03 (migrations).**

  | Situation | Question | Default |
  |---|---|---|
  | `steps.migrate: off` | none | never |
  | `steps.migrate: every` | none | run if the post-build check finds pending migrations |
  | First deploy and migration files exist | shown, with the count | **Yes** |
  | Migration files added in `L..T`, and/or pending in the live release (checked with LAR-03 on the live release, using its PHP; best effort) | shown: list of up to 10 names + "and N more" + "N still pending from before" | **Yes** |
  | Only modified or deleted migration files | none; a warning instead: "Changed migration files don't re-run; create a new migration instead" | — |
  | Nothing added or pending | none | none (see PLN-07) |
  | `--migrate=yes/no` | none | per the flag |

- **PLN-04 (seed).**
  - `first`: runs on the first deploy only, no question.
  - `ask`: asked every deploy, default **No**.
  - `off`: never.
  - Seeding runs after migrations (G3).
- **PLN-05 (frontend).**
  - `every`: build.
  - `ask`: asked; default **Yes** if CHG-02 `nodeChanged`, or any file outside `vendor/`, `storage/`, `database/`, `tests/` changed; otherwise Skip.
  - Skip = reuse (NODE-13). If reuse is impossible, build.
- **PLN-06.** `DeployPlan` stores every answer plus its reason, e.g. `composer: install (lock changed)`. The reasons appear in the log and in the history notes.
- **PLN-07 (pending migrations found late).**
  - If the migration question was **not shown** (nothing detected) but the post-build check (B9) finds pending migrations (e.g. migrations loaded from packages), do **not** run them in Phase C. After a successful deploy:
    - interactive: show the prompt in §9.4 (*Run it now* runs `artisan down` → `migrate --force` → `artisan up`, all on the new live release)
    - non-interactive: log a warning, and add `result: warning` to history
  - If the user answered **Skip**, don't prompt again. Only log it.
- **PLN-08.** If the answer was Yes but B9 finds nothing pending → skip G1, G2 and G8 and note "nothing to migrate".
- **PLN-09.** If B9 can't tell (`known=false`) and the answer is Yes → run G1 → G2 → G8 anyway (migrate is harmless when nothing is pending).
- **PLN-10.** The final confirmation shows the target (commit, message), the domain, and the decisions in one line each. `--yes` skips it.

### 11.4 Phase B: build the new release

The live site is never touched in Phase B. On any failure:

1. Mark the release `failed` and update the state file.
2. Keep the release folder for inspection until the next successful deploy's cleanup (PR-01).
3. Exit 4 with "your live site was not changed".

| # | Step | Runs when | What it does | Timeout | Error |
|---|---|---|---|---|---|
| B1 | Export | always | Create `releases/<id>` (id collision → `-2` suffix); write `.release.json` with status `building`; GIT-13 | git | `E_EXPORT` |
| B2 | Shared links | always | REL-03/04 for `shared.files` and `shared.dirs`; create `storage/framework/views` and `bootstrap/cache` if missing (Laravel) | — | `E_SHARED` |
| B3 | Docroot extras + handler | always | REL-05; DOC-04 inject into `<web_dir>/.htaccess` (for the **site** PHP version) | — | `E_SHARED` |
| B4 | Composer | per PLN-02 | Install (CMP-04) **or** reuse (CMP-05). Composer scripts run here (`package:discover`) | composer | `E_COMPOSER` (+ `E_OOM` detection) |
| B5 | Frontend build | per PLN-05 | Resolve PM (NODE-07/08) → install → build → verify outputs (NODE-11) → remove `node_modules` (NODE-12) **or** reuse (NODE-13) | node_install / node_build | `E_NODE_INSTALL`, `E_NODE_BUILD`, `E_BUILD_OUTPUT`, `E_OOM` |
| B6 | storage:link | `steps.storage_link: every` (Laravel) | LAR-04 in the new release | artisan | `E_ARTISAN` |
| B7 | optimize | `steps.optimize: every` (Laravel) | `php artisan optimize` in the new release | artisan | `E_ARTISAN` (hint: "route caching fails with closure routes; a service provider may query the database at boot") |
| B8 | Custom (before_activate) | per command `when` | `bash -c <run>`, working directory = the release, site shims + Node on PATH | custom / per command | `on_error: fail` → `E_CUSTOM`; `warn` → warning |
| B9 | Migration check | Laravel and migrate not `off` | LAR-03 on the **new** release → `pending[]` / `known` | artisan | never fatal (`known=false`) |
| B10 | Ready | always | Write the manifest (DOC-06, excluding `.cpd-*` files); write the release marker (HC-01); status `ready`; durations | — | — |

**B-rules**

- **BLD-01.** Every step in B1–B8 uses the **site PHP** through the shims (CMP-02). Nothing in Phase B uses the Tool PHP.
- **BLD-02.** Before B4, reload the Masker with the `.env` secrets (LOG-04).
- **BLD-03.** Cancelling during Phase B (Ctrl+C): stop the running process (SH-05) → release `failed` → exit 130, "Cancelled — your live site was not changed".
- **BLD-04.** B2 links `.env` before B5, so frontend builds see `VITE_*` and similar variables from the shared `.env`. The build runs with the **production** `.env`; the user guide MUST mention this.

### 11.5 Phase C: go live

Let **L** = live release (null on first deploy) and **N** = new release. `phpChange` compares the site PHP with the domain's current MultiPHP version. Only when `sync_multiphp` is on and the domain is not on PHP Selector with option (b):

- `none`: same version
- `upgrade`: new version > current
- `downgrade`: new version < current
- `family`: ea ↔ alt; treated as `upgrade`

| # | Step | Runs when | Action | On failure |
|---|---|---|---|---|
| G1 | Maintenance on | Migrations will run (PLN-03/08/09) **and** L exists **and** `steps.maintenance: with_migrations` | Write the state file (`maintenance_on: L`), then `artisan down` in **L** with L's PHP (LAR-05) | Exit 6. Nothing changed (L is still up). N stays `ready` |
| G2 | Migrate | Migrations will run | `artisan migrate --force` in **N** (site PHP), with `migrations_started: true` in state | `artisan up` in L (if G1 ran); N → `failed`; record which migrations completed (LAR-03 before/after diff); exit 5 |
| G3 | Seed | PLN-04 | `artisan db:seed --force` in N | Same as G2 |
| G4 | MultiPHP (before switch) | `phpChange` ∈ {upgrade, family} | DOC-03 on the folder currently served (L's web dir, or on a first deploy the existing docroot folder) → `php_set_vhost_versions` → state `multiphp_after` | Up L (if G1); exit 6 with "migrations already ran" when G2 ran |
| G5 | **Switch** | always | FS-02: `current` → N; state `switched: true`; N `live` + `activated_at`; L `ready`. On a first deploy this only creates `current`: the domain doesn't serve it until G6 | Revert G4 → up L → exit 6 (the switch is atomic, so a failure means nothing switched) |
| G6 | Docroot conversion | First go-live (`domain.converted_at` null), or DOC-08 re-point | DOC-01 re-check → DOC-02 / DOC-08. Runs **after** G5, so the new docroot link never points at a missing `current` | Undo G5: on a first deploy remove `current`; on a re-point, switch `current` back to L. Then revert G4 → up L (if G1) → exit 6 |
| G7 | MultiPHP (after switch) | `phpChange` = downgrade | DOC-03 on N's web dir → `php_set_vhost_versions` | **Warning**, not a rollback: "Site is live on the new release but the domain's PHP is still 8.3 — set it in cPanel → MultiPHP Manager". `result: warning` |
| G8 | Maintenance off | G1 ran, or `.env` has `APP_MAINTENANCE_DRIVER=cache` | `artisan up` in **N** (warn only). **L is deliberately left in maintenance** (see below) | Warning |
| G9 | Capture handler block | a MultiPHP change happened (G4/G7) | DOC-04 capture from N | Warning |
| G10 | After-activate | `steps.queue_restart: every`, custom `after_activate` | `queue:restart`; custom commands with working directory = `current` | `on_error: fail` → `result: failed`, but no rollback (the code is already live); `warn` → warning |
| G11 | Health check | `health_check.enabled` and not `--no-health-check` | §11.6 | §11.6 |

- **GL-01 (critical section).** G1–G8 run with signals deferred (LCK-04).
  - If Ctrl+C is pressed during G2/G3, show: "Migrations are running — stopping now could leave the database half-migrated. Press Ctrl+C again within 3 s to force stop."
  - A forced stop kills the process group; the state file drives recovery.
- **GL-02 (why L stays in maintenance).** After the switch, PHP-FPM workers may keep resolving the docroot symlink to L for up to `realpath_cache_ttl` (default 120 s). The tool can't restart PHP-FPM without root. Leaving L in maintenance means those few requests get the maintenance page instead of old code running against the new database. `Rollback` (RB-06) runs `up` on its target, so L is fine to reuse later.
- **GL-03 (PHP ordering rationale).** Newer PHP usually runs older code, so on an upgrade the domain switches **before** the code (G4). On a downgrade it switches **after** (G7). Neither can be simultaneous with the symlink switch. Document the window as "a few seconds".
- **GL-04.** The log records how long visitors saw maintenance mode: the time from G1 to G8.

### 11.6 Health check

- **HC-01 (release marker).** In B10, write `<web_dir>/.cpd-release-<id>.txt` containing the release id.
  - After G6, GET `https://<domain>/.cpd-release-<id>.txt` (HTTP-03 resolve; 3 tries, 1 s apart). A matching body confirms the web server serves the new folder.
  - A mismatch is a **warning**: "The web server is not serving the new release — check the domain's document root in cPanel".
  - Delete the marker afterwards, and also at the end of Phase C when the health check is disabled.
- **HC-02 (health request).** GET `https://<domain><path>`: HTTPS first, falling back to HTTP if HTTPS can't connect; redirects followed; `health_check.timeout` per attempt; `attempts` tries 5 s apart.
  - Success = the first attempt whose status is in `expect`.
  - The TLS certificate is verified. On a TLS error, retry with `-k` and add a warning ("SSL certificate problem: …").
- **HC-03 (on failure).** Policy `health_check.on_failure`:
  - `ask` (interactive): the prompt in §9.4.
  - Non-interactive: NI-04.
  - `rollback`: run Rollback (§11.8) to L with `--yes` semantics, then exit 7.
  - `keep`: exit 7 with the result `warning`.
  - First deploy (no L): no rollback possible. Warn and exit 7.
- **HC-04.** Record the status, time and URL in the history notes: `health: 200 in 0.4s`.
- **HC-05 (PHP-FPM path cache after migrations).** If G1 ran, L is in maintenance and PHP-FPM workers may still serve L for up to `realpath_cache_ttl` (GL-02). A **503** response is therefore retried every 5 s for up to 130 s, showing "Waiting for PHP workers to pick up the new release… (up to 2 min)", before it counts as a failure. Other statuses follow HC-02.

### 11.7 Phase D: finish and cleanup

- **PR-01 (prune releases).**
  1. Sort releases by id, descending.
  2. **Keep:** the live release, protected releases, and the newest `releases.keep` releases with status `ready` or `live`.
  3. **Delete** (FS-03): every other `ready` release, every `failed` release, and any `building` release older than 1 hour that isn't this run's.
  4. Report "kept N, removed M (freed X MB)".
- **PR-02.** Log files: keep 50 (LOG-02). `.env` backups: keep 10. `tmp/`: LAY-03.
- **PR-03.** Write `history.jsonl` (§8.6), delete the state file, release the lock, then print the summary.
- **PR-04.** Cleanup failures are warnings. They never change the deploy result.

### 11.8 Rollback

- **RB-01.** Take the lock. If a state file exists → recovery first.
- **RB-02 (target).** Given id / `--previous` (newest `ready` release older than live) / picker. It must exist, have status `ready`, and contain `<web_dir>`; Laravel releases must also contain `vendor/`.
- **RB-03 (target PHP).** Use the PHP recorded in the target's `.release.json`. If that binary no longer exists → block with `E_PHP_MISSING`, listing the installed versions.
- **RB-04 (warnings, shown before confirming).**
  - Migrations recorded in releases **newer** than the target: "These database changes stay in place: … The older code may not work with them."
  - The target's PHP differs from the domain's current PHP: "The domain will switch to PHP 8.2" (if sync is on) or "The domain stays on PHP 8.3" (if not).
  - `.env` changed since the target was built (`.env` mtime > target `created_at`): "optimize will rebuild its config cache".
- **RB-05 (confirm).** Required interactively, or `--yes`.
- **RB-06 (steps, with the state file for each phase).**
  1. Re-link the target's shared paths (REL-04, idempotent). Inject the handler block for the **target's** PHP (DOC-04).
  2. If Laravel and optimize are enabled: `artisan optimize` in the target with the target's PHP. A failure aborts the rollback, nothing changed → exit 8.
  3. If the target is in maintenance mode (LAR-05 `isDown`): `artisan up` in the target.
  4. Apply the PHP ordering rule (GL-03) relative to the domain's current version.
  5. Switch (FS-02).
  6. Statuses: target `live`, previous `ready`.
  7. Health check (§11.6). On failure, offer to switch back.
- **RB-07.** History entry `rollback`. The log records the target and the previous live release.
- **RB-08.** Rollback never touches the database, `.env` or shared storage.

### 11.9 Recovery of interrupted operations

- **REC-01 (detection).** A state file exists and the lock is free (LCK-03). Checked at the start of every command that touches the site, and shown as a main-menu banner.
- **REC-02 (actions by recorded phase).** Each action is idempotent, so recovery can run twice.

| Phase in state | Recovery |
|---|---|
| `planning` | Delete the state file. Nothing else was changed |
| `building` | Mark the release `failed` (if its folder exists); delete the state file |
| `maintenance` / `migrating` | `artisan up` in `maintenance_on` (if set). Release `failed`. Report: "A deploy was interrupted during migrations. Check `php artisan migrate:status` — some migrations may have run." If `multiphp_after` was set: revert to `multiphp_before` |
| `multiphp` | Revert to `multiphp_before` if `multiphp_after` was set. Up L. Release `failed` |
| `switching` | Read where `current` points. If it points at the new release → continue as `converting_docroot`. Otherwise treat as `multiphp` |
| `converting_docroot` | If the docroot is the correct symlink → treat as `switched`. Otherwise: if the docroot is missing and the backup exists, move the backup back; on a first deploy remove `current`, or else point `current` back to `live_before`; revert MultiPHP; up L; release `failed` |
| `switched` / `finishing` | Finish the bookkeeping (statuses, `artisan up` in the new release, history `recover`). If `sync_multiphp` is on and the domain's MultiPHP version differs from the site PHP (an interrupted G7), warn with the fix. Suggest running a health check |

- **REC-03.** Recovery writes a `recover` history entry and its own log, then prints what it did and what the user should check.

### 11.10 Retry, redeploy and deploy-with-changes

- **RTY-01.** *Retry* on the failure screen starts a new deploy with the same `DeployPlan` answers and ref. It creates a new release id. The failed release is removed by the next cleanup.
- **RTY-02.** *Redeploy the same commit* (`--force`) goes through every phase. It creates a new release.
- **RTY-03.** A deploy with `--ref` records `ref` in `.release.json`. It does not change `repo.branch`.

---

## 12. Security requirements and invariants

### 12.1 Security requirements

- **SEC-01 (storage of secrets).** The token lives in `secrets/github-token` (600, folder 700). `.env` and `auth.json` are 600. Private keys are 600 in `~/.ssh` (700). The tool re-applies these modes on every start (LAY-02) and before every read of a secret.
- **SEC-02 (no secrets in config or output).** `site.yml` and `config.yml` never contain passwords or tokens. The one exception is `maintenance.secret`, a low-value bypass code for maintenance mode; `site.yml` is mode 600. Logs, terminal output and JSON output pass through the Masker (LOG-04).
- **SEC-03 (no secrets on command lines).**

  | Secret | How it is passed |
  |---|---|
  | GitHub token | curl `--config -` on stdin |
  | DB credentials for checks | environment variables (DB-04) |
  | Composer auth | `COMPOSER_AUTH` environment variable |
  | Values typed in `env set KEY=-` | read from stdin |

  The single exception is SEC-07.
- **SEC-04 (SSH trust).** `StrictHostKeyChecking=yes` against the tool's own verified `known_hosts`. Never `accept-new`, never `no`.
- **SEC-05 (least privilege on GitHub).** Deploy keys are always read-only. The recommended token only has Administration and Metadata permissions on the chosen repos.
- **SEC-06 (downloads).**
  - Composer and Node downloads are SHA-256-verified before use.
  - `self-update` verifies the release's `.sha256` file. Box's phar signature is kept enabled.
  - A failed verification deletes the file and never falls back to the unverified copy.
- **SEC-07 (documented exception).** `uapi Mysql create_user` takes the password as an argument. Mitigations:
  - the password is freshly generated and alphanumeric
  - the command runs for about a second
  - the server has a single cPanel account
  - the Masker hides it in logs

  Nothing else is passed this way.
- **SEC-08 (temporary files).** Created in `~/cpdeploy/tmp/` with mode 600 (folders 700), prefix `cpd-`, and deleted in `finally` blocks.
- **SEC-09 (web-visible temporary files).** Probe and marker files (HTTP-04, HC-01) have random names of at least 32 hex characters, contain only a version or a release id, and are deleted right after use.
- **SEC-10 (shell safety).**
  - User input reaches external commands only as separate array arguments, or shell-quoted inside the few `bash -c` pipelines (SH-01).
  - `custom_commands[*].run` is the user's own configuration and runs through `bash -c` by design. It is documented as "runs with your permissions".
- **SEC-11 (no web-exposed secrets).** Validation MUST block these combinations:
  - `domain.web_dir: ""` (the release root is served) together with shared files at the root (`.env`, `auth.json`), because `.env` would be downloadable. Message: "Serving the release root would expose .env. Put your public files in a folder such as public/."
  - Preflight also blocks if `<web_dir>/.env` exists in the new release.
- **SEC-12.** Docroot backups and the `removed/` folder are outside every web root (700).
- **SEC-13.** Running as root is refused (CLI-01).
- **SEC-14.** `token remove` deletes the token file with an overwrite first (best effort) and then `unlink`.
- **SEC-15.** Composer `auth.json` is never copied into a release. The tool deletes any `auth.json` that git exported into the release root, with a warning: "auth.json is committed to your repo — remove it and rotate those credentials".

### 12.2 Invariants

These MUST hold in every code path. Scenario tests assert them.

- **INV-01.** The live release's files are never modified. The only exceptions:
  - the maintenance files that `artisan down` and `artisan up` create and remove
  - an empty `.htaccess` created when missing (DOC-03), and cPanel's own rewrite of it during `php_set_vhost_versions`
  - temporary probe and marker files
  - `optimize` during a rollback (on the rollback target)
  - actions the user starts on the live site: *Laravel tools* and `cpdeploy artisan`/`down`/`up`, and ENV-08 *Apply to live site*
- **INV-02.** These are never deleted:
  - the live release
  - `shared/` or `backups/`, except by *Remove site* with typed confirmation
  - anything outside the paths in INV-07
- **INV-03.** Destructive artisan commands (LAR-08) never run automatically.
- **INV-04.** Nothing is ever written to GitHub except adding and removing this site's own deploy keys.
- **INV-05.** MultiPHP changes only ever apply to the site's own domain.
- **INV-06.** Deletes never follow symlinks (FS-03).
- **INV-07 (permitted write locations).**
  - `~/cpdeploy/**`
  - `~/bin/cpdeploy` (installer)
  - `~/.ssh/cpdeploy_*`
  - the site's docroot path (conversion, restore, detach)
  - `~/<site>-app/` (detach)
  - `~/.bashrc` and `~/.bash_profile` (installer: one marked PATH line)
  - the user crontab and the old webhook file (legacy import, after confirmation)
- **INV-08.** Every step that can affect the live site is recorded in the state file **before** it starts.
- **INV-09.** A deploy that ends before the switch (G5), or fails in G6, leaves `current` pointing at the same release as before it started (or absent, on a first deploy).

---

## 13. Error catalogue

Each error is a `CpdeployException` with an `ErrorCode`. The UI prints:

```
✗ <message>
  Live site: <affected / not changed>
  Fix: <hint>
  Log: <path, when there is one>
```

| Code | When | Message (template) | Hint | Exit |
|---|---|---|---|---|
| `E_ROOT` | EUID 0 | cpdeploy must run as the cPanel user, not root | `su - <user> -s /bin/bash -c cpdeploy` | 2 |
| `E_NOT_CPANEL` | `uapi` missing | This doesn't look like a cPanel account (uapi not found) | Run cpdeploy inside a cPanel account's shell | 3 |
| `E_CONFIG_INVALID` | Validation | `<file>` has problems: `<list>` | `cpdeploy config <site> edit` | 2 |
| `E_CONFIG_NEWER` | Schema too new | `<file>` was written by a newer cpdeploy | `cpdeploy self-update` | 2 |
| `E_LOCKED` | LCK-01 | Another cpdeploy operation (`<action>`, started `<time>` by PID `<pid>`) is running for `<site>` | Wait for it to finish | 10 |
| `E_INTERRUPTED` | REC-01, non-interactive | An earlier `<operation>` of `<site>` was interrupted | `cpdeploy recover <site>` | 11 |
| `E_NEEDS_ANSWER` | NI-02 | This needs answers: `<flags>` | Pass the flags or `--yes` | 2 |
| `E_GIT_AUTH` | GIT-06 | GitHub refused the deploy key for `<o>/<r>` | Manage site → Deploy key → Test / Rotate; check the key under the repo's Settings → Deploy keys | 3 |
| `E_GIT_HOSTKEY` | GIT-06 | GitHub's SSH host key doesn't match the known key | `cpdeploy self-update`, or `cpdeploy check --refresh-host-keys` | 3 |
| `E_GIT_NET` | GIT-06 | Can't reach GitHub on port 22 or 443 | Ask your host to allow outbound SSH to github.com | 3 |
| `E_GIT` | other git failure | Git failed: `<stderr>` | See the log | 3 |
| `E_REF_NOT_FOUND` | GIT-09 | `<ref>` isn't a branch, tag or commit in `<o>/<r>` | Check the name; the list comes from GitHub | 2 |
| `E_BRANCH_GONE` | GIT-09 | Branch `<b>` no longer exists on GitHub | Manage site → Branch | 3 |
| `E_SUBMODULES` / `E_LFS` | GIT-14 | This repo uses git submodules / Git LFS, which cpdeploy doesn't support yet | — | 3 |
| `E_EXPORT` | B1 | Couldn't extract commit `<sha>` | Disk space? See the log | 4 |
| `E_SHARED` | B2/B3 | Couldn't link shared path `<p>` | See the log | 4 |
| `E_TOKEN_INVALID` | GH-04 | The GitHub token is invalid or expired | Settings → GitHub token | 3 |
| `E_TOKEN_PERMS` | GH-04 | The token can't manage deploy keys for `<o>/<r>` | Give it Administration: Read and write on this repo, or add the key manually | 3 |
| `E_GITHUB_RATE` / `E_GITHUB_DOWN` | GH-04 | GitHub API rate limit reached (resets `<time>`) / GitHub API unavailable | Try later, or use the manual key flow | 3 |
| `E_UAPI` | CP-01 | cPanel refused `<Module>::<fn>`: `<errors>` | Depends on the error; shown verbatim | 3/6 |
| `E_PHP_MISSING` | PHP-04 | PHP `<v>` isn't installed on this server. Installed: `<list>` | Choose another version, or ask your host | 3 |
| `E_PLATFORM` | PHP-05 | PHP `<v>` is missing what composer.lock needs: `<list>` | Enable the extensions in MultiPHP INI Editor / Select PHP Version | 3 |
| `E_NO_LOCK` | CMP-04 | composer.lock is missing | Commit composer.lock, or set `composer.allow_no_lock: true` | 3 |
| `E_DOWNLOAD` | CMP-01 / NODE-05 | Couldn't download `<what>` from `<url>` | Network or mirror setting | 3/4 |
| `E_CHECKSUM` | CMP-01 / NODE-05 / SEC-06 | `<file>` failed its checksum — not used | Try again; if it repeats, check the mirror | 3/4 |
| `E_COMPOSER` | B4 | `composer install` failed | Last lines + log; common: memory (E_OOM), auth (Composer credentials) | 4 |
| `E_NODE_SPEC` / `E_NODE_NONE` / `E_NODE_ARCH` / `E_GLIBC_OLD` / `E_PM_UNSUPPORTED` | §7.9 | (per rule) | (per rule) | 3 |
| `E_NODE_INSTALL` / `E_NODE_BUILD` | B5 | `<pm> install` / `<pm> run <script>` failed | Last lines + log | 4 |
| `E_BUILD_OUTPUT` | NODE-11 | The build finished but `<file>` wasn't created | Check the build script and output folder | 4 |
| `E_OOM` | SH-06 | The build ran out of memory | Raise the memory limit, set Node max memory, or build in CI | 4 |
| `E_TIMEOUT` | SH-05 | `<step>` took longer than `<n>`s and was stopped | Raise `timeouts.<key>` in Settings | 4 |
| `E_ARTISAN` | B6/B7 | `php artisan <cmd>` failed | Last lines + log | 4 |
| `E_CUSTOM` | B8/G10 | Custom command "`<name>`" failed | Last lines + log | 4 |
| `E_ENV_MISSING` / `E_ENV_PARSE` | PRE-11 | .env is missing / has a syntax error on line `<n>` | Manage site → Environment | 3 |
| `E_APP_KEY` | PRE-12 | APP_KEY is empty | *Generate APP_KEY now* | 3 |
| `E_DB_CONNECT` / `E_DB_DRIVER` | DB-04 | Can't connect to the database: `<pdo message>` / PHP `<v>` lacks `<pdo driver>` | Check `DB_*` in .env / enable the extension | 3 |
| `E_DISK` / `E_INODES` | PRE-15 | Not enough disk space (need ≈`<x>` MB, `<y>` MB free) / inodes | Free space, lower `releases.keep`, delete old releases | 3 |
| `E_DOCROOT_UNSAFE` | DOC-01 | (rule-specific message) | (rule-specific) | 3 |
| `E_DOCROOT_MOVE` | DOC-02 | Couldn't move `<docroot>` to backups | See the log | 6 |
| `E_MIGRATE` | G2/G3 | Migration failed — the previous release is back up | Fix the migration; check `migrate:status` | 5 |
| `E_MULTIPHP` | G4 | Couldn't set PHP `<v>` for `<domain>` | cPanel → MultiPHP Manager | 6 |
| `E_GOLIVE` | G5/G6 | Couldn't switch the site to the new release | See the log; the site is still on `<L>` | 6 |
| `E_HEALTH` | §11.6 | `<url>` returned `<status>` after go-live | Rolled back / kept per policy | 7 |
| `E_ROLLBACK` | §11.8 | Rollback to `<id>` failed: `<reason>` | See the log | 8 |
| `E_CANCELLED` | Ctrl+C / Cancel | Cancelled | — | 130 |
| `E_UPDATE` | §15.5 | Update failed: `<reason>` | The current version is unchanged | 1 |

---

## 14. Operational targets and limits

| Target | Value |
|---|---|
| Main menu opens (10 sites, no network) | < 1 s |
| `status` (10 sites) | < 1 s |
| Deploy overhead, excluding Composer/npm/artisan time | < 15 s |
| Rollback, excluding `optimize` | < 10 s |
| `check` (no `--probe`) | < 20 s |
| Tool memory use | < 128 MB (the launcher sets `memory_limit=512M` as a safety margin) |
| Sites per account | tested up to 25 |
| Default timeouts | §8.1 |

---

## 15. Installation and distribution

### 15.1 Building the phar (`scripts/build.sh`)

1. Require a clean git tree (or `--dirty`), PHP ≥ 8.2, Composer 2, and Box 4.
2. Copy the project into a temp dir and run `composer install --no-dev --classmap-authoritative --no-interaction`.
3. Run `box compile` using `box.json`:
   - `main: bin/cpdeploy`, `output: dist/cpdeploy.phar`
   - `directories: [src, resources]`, plus vendor through Box's finder
   - `compression: NONE`, so the phar doesn't need `zlib` at runtime
   - `check-requirements: true`: Box's built-in checker verifies the PHP version and extensions when the phar starts
   - the `git-version` placeholder `@package_version@` in `src/Version.php` is replaced with the tag
   - Box's default signing algorithm is kept
4. Write `dist/cpdeploy.phar.sha256` (`sha256sum` format).
5. Smoke test: `php dist/cpdeploy.phar --version` must print the tag.

- **BLD-10.** Builds MUST be reproducible from a tag: pinned Box version, `composer.lock` committed, no network access during `box compile`.

### 15.2 Launcher (`scripts/launcher.sh` → `~/bin/cpdeploy`)

A POSIX `sh` script, with no bashisms, so it also works in jailshell.

- **LCH-01 (choosing the Tool PHP), in this order:**
  1. `$CPDEPLOY_PHP` if set
  2. the path cached in `~/cpdeploy/app/.php-path`, if it still works
  3. `/opt/cpanel/ea-php*/root/usr/bin/php` (highest version first)
  4. `/opt/alt/php*/usr/bin/php` (highest first)
  5. `command -v php`

  A candidate is accepted when `-r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("phar") && extension_loaded("mbstring") ? 0 : 1);'` exits 0. Cache the chosen path.
- **LCH-02 (functions).** Check that `proc_open`, `exec` and `shell_exec` are not in `disable_functions`, using `-r` with `ini_get`. If any are disabled:

  > cpdeploy needs proc_open, exec and shell_exec, which are disabled for PHP <ver> (disable_functions). Fix: in WHM → MultiPHP INI Editor, remove them from disable_functions for this PHP version, or run with CPDEPLOY_PHP_ARGS="-d disable_functions=" if your host allows it.

  Exit 3.
- **LCH-03.** No suitable PHP → "cpdeploy needs PHP 8.1 or newer with the phar and mbstring extensions. Installed: <list>. Ask your host to install ea-php82 (or newer) — it doesn't need to be your sites' version." Exit 3.
- **LCH-04 (running).** `exec "$PHP" $CPDEPLOY_PHP_ARGS -d memory_limit=512M -d display_errors=stderr "$HOME/cpdeploy/app/cpdeploy.phar" "$@"`.

### 15.3 Installer (`scripts/install.sh`)

**Usage:** `bash install.sh [./cpdeploy.phar] [--sha256=<hex>]`, or `bash install.sh --download [vX.Y.Z]` (from GitHub Releases of `update.repo`).

1. Refuse root. Check `sh`, `curl` (for `--download`) and `sha256sum`.
2. Find the Tool PHP (the same logic as LCH-01/02/03, embedded).
3. Verify the phar: the SHA-256 if given or downloaded, then `php cpdeploy.phar --version`.
4. Create `~/cpdeploy/app` (LAY-02 modes). If a previous phar exists, keep it as `cpdeploy.phar.prev`. Copy the new phar into place atomically (write a temp file, then rename).
5. Install the launcher to `~/bin/cpdeploy` (755), creating `~/bin` if needed.
6. If `~/bin` is not on `PATH`, append `export PATH="$HOME/bin:$PATH"  # added by cpdeploy` to `~/.bashrc` and `~/.bash_profile`. Only once: check for the marker first. Tell the user to open a new terminal or run `source ~/.bashrc`.
7. Run `cpdeploy check`, show its summary, and print next steps: "Run: cpdeploy".

**Uninstall.** `bash install.sh --uninstall` removes `~/bin/cpdeploy`, `~/cpdeploy/app/` and the PATH line. It **never** removes `~/cpdeploy/sites`. It prints: "Your sites keep running. Their data is in ~/cpdeploy/sites; remove it yourself if you're sure."

### 15.4 Release workflow (`.github/workflows/release.yml`)

On a tag matching `v*.*.*`:

1. Run the full CI (§16.6).
2. Build the phar on PHP 8.3 (§15.1).
3. Create a GitHub Release with `cpdeploy.phar`, `cpdeploy.phar.sha256` and `install.sh`, using the matching section of `CHANGELOG.md` as the notes.

Pre-release tags (`v1.2.0-rc.1`) are marked as pre-release and are ignored by `self-update` unless `--pre`.

### 15.5 Self-update

- **UPD-01.** `GET /repos/<update.repo>/releases/latest`, authenticated with the stored token if one exists (needed for a private tool repo). Compare versions with SemVer.
- **UPD-02.** Download the phar and the `.sha256` asset, verify them, run `new.phar --version`, then:
  1. copy the current phar to `.prev`
  2. atomically rename the new phar into place
  3. print the release notes

  Running processes keep the old file open, so updating is safe even while a deploy runs.
- **UPD-03.** `--check` only reports. `--rollback` swaps `.prev` back into place.

### 15.6 Using the source folder directly (development only)

On a test server: upload the repo, run `composer install`, then run `<php ≥ 8.1> bin/cpdeploy …`. Paths and behaviour are identical, because the root is `~/cpdeploy`. This MUST NOT be used on production servers. Document it in `docs/testing.md` only.

---

## 16. Testing strategy

### 16.1 Levels

| Level | Tool | Scope |
|---|---|---|
| Unit | PHPUnit | Pure logic: parsers, resolvers, validators, planners, masking, path safety |
| Feature | PHPUnit + harness | One service against fakes (fake `uapi`, fake PHP/Node, local git remotes, local HTTP servers) |
| Scenario | PHPUnit + harness | Full CLI runs (`bin/cpdeploy …` in a subprocess with a temp HOME) asserting files, symlinks, state, history, exit codes and invariants |
| Real-Laravel (CI) | shell + PHPUnit | A real `laravel/laravel` app, real Composer, real Node 22, SQLite |
| Manual QA | checklist | Real cPanel servers (§16.5) |

### 16.2 Harness and fakes (`tests/Support`, `tests/Fixtures`)

- **Temp HOME** per test. `CPDEPLOY_HOME` → `<tmp>/home/cpdeploy`. `HOME` → `<tmp>/home`.
- **Fake binaries dir** first on PATH:
  - **`uapi`** (PHP script): answers from JSON fixtures keyed by `Module/function`; records every call (arguments decoded) to `calls.jsonl`; can be told to fail specific calls; simulates `php_set_vhost_versions` by rewriting the handler block in the docroot's `.htaccess`, and `Mysql::*` state in a JSON file.
  - **Fake PHP versions:** wrapper scripts at fixture paths (`…/ea-php74/root/usr/bin/php`, `…/ea-php82/…`) that exec the real PHP with `CPD_FAKE_PHP=<tag>` set. Tests assert which binary ran by that tag. Search roots come from `CPDEPLOY_PHP_SEARCH_PATHS`.
  - **Fake Node:** `node` scripts printing a version; fake `npm` that simulates `ci` (creates `node_modules/`) and `run build` (writes `public/build/manifest.json`), with switches to fail or to "OOM" (exit 137).
  - **Fake artisan** (in fixture repos): implements `package:discover`, `storage:link`, `optimize` (writes `bootstrap/cache/config.php`), `migrate:status` (Laravel 11 format, driven by `storage/app/.fake-pending`), `migrate` (applies, or fails on demand), `down`/`up` (creates/removes `storage/framework/down`), `db:seed`, `queue:restart`. Fixture outputs for the Laravel 8/9/10/11/12/13 `migrate:status` formats are unit-tested separately.
- **Mirrors:** a PHP built-in server serving a fake `composer.phar`, which simulates `install`, `dump-autoload` and `check-platform-reqs --format=json` from fixtures, plus its `.sha256`. Also Node `index.json`, `SHASUMS256.txt` and tarballs. `mirrors.*` in `config.yml` points at it.
- **GitHub API fake:** a PHP built-in server with a router implementing §7.5's endpoints. It asserts the headers and records calls. `CPDEPLOY_GITHUB_API` points at it.
- **Git remote:** a local bare repo. `CPDEPLOY_GIT_URL_OVERRIDE=file://…`. SSH itself is covered by the optional `ssh-integration` CI job (a container running `sshd` that accepts the generated deploy key, with the host key injected into the test `known_hosts`).
- **Web:** a PHP built-in server whose docroot is the site's docroot symlink. Health, marker and probe requests use `CPDEPLOY_HTTP_OVERRIDE=<host>:<port>` (test-only: replaces the `--resolve` target and scheme).
- **Clock:** `CPDEPLOY_NOW` fixes the time. Release ids become deterministic.
- **Test-only env vars** (`CPDEPLOY_*_OVERRIDE`, search paths, `CPDEPLOY_NOW`) MUST be ignored unless `CPDEPLOY_TESTING=1`.

### 16.3 Required unit tests (minimum)

| Area | Cases |
|---|---|
| EnvFile | Byte-identical round-trip for a corpus (comments, blank lines, `export`, quotes, multi-line, `${VAR}`); set existing / commented / new key; quoting rules ENV-04; duplicate detection; secret masking list |
| RepoUrl | All accepted forms, the rejects, transport URLs (GIT-01) |
| HostKeys | Embedded keys' SHA256 fingerprints equal the published ones (Appendix B.6) |
| NodeResolver | Every NODE-02 form; highest satisfying installed; index.json selection incl. `lts/*`, `lts/<name>`, arch filtering |
| PhpService | Compatibility parsing (JSON and fallback); domain PHP detection incl. inherit and CloudLinux Selector; PHP change ordering (GL-03) |
| ChangeAnalyzer | Lock diff (added/removed/updated), migrations A/M/D, rewind, same commit, first deploy |
| PlanBuilder | Every row of the PLN-02/03/04/05 tables, flags, `--yes`, non-interactive errors (NI-02) |
| MigrationStatus | Output fixtures for Laravel 8–13; "Migration table not found"; unknown |
| HandlerBlock | Capture, rewrite ea↔alt / 7↔8 / `___lsphp`, inject into existing / missing `.htaccess`, idempotency |
| DocrootManager | Every DOC-01 rule, including other domains inside `public_html` and a docroot inside another site |
| Fs | Atomic write; atomic symlink swap; **safe delete never follows symlinks** (sentinel test); relative paths |
| Masker | Every secret type (token, env secrets, `COMPOSER_AUTH`, `Authorization`, URL credentials) never appears in output |
| SiteSchema | Every VAL rule; SEC-11; schema migration and "newer" rejection |
| ReleaseManager | Prune selection (keep, live, protected, failed, stale building); id collisions |
| Shell | Timeout kills the process group; env isolation (`GIT_DIR` not inherited); OOM detection |

### 16.4 Required scenario tests

Each scenario asserts: exit code, `current` target, release statuses, history entry, state file absent at the end (unless the scenario is an interruption), and the relevant invariants (INV-01, 02, 06, 09).

| # | Scenario | Expected |
|---|---|---|
| S-01 | First deploy of a Laravel fixture into an empty addon docroot | Docroot is a relative symlink; shared skeleton; `.env` linked; vendor installed; build outputs; `public/storage` link; optimize ran; migrations ran (answer Yes); health 200; history success |
| S-02 | First deploy into a non-empty `public_html` (main domain), no other domains inside | Original moved to `backups/`; `.well-known` and `.user.ini` moved to shared; handler block captured and injected |
| S-03 | `public_html` contains another domain's docroot | Blocked (DOC-01d), exit 3, nothing moved |
| S-04 | Second deploy, lock unchanged, composer answered Skip | `vendor/` copied (not hardlinked: inode check) + `dump-autoload` ran; answer recorded |
| S-05 | Lock changed | Composer question default Yes; install ran |
| S-06 | Site PHP changed 8.2 → 8.3 (upgrade) | Composer forced; MultiPHP set **before** the switch (uapi call order); handler block rewritten |
| S-07 | PHP downgrade 8.3 → 8.2 | MultiPHP set **after** the switch |
| S-08 | New migrations, answered Yes | `down` in L before migrate; migrate in N; switch; `up` in N; L still down (GL-02) |
| S-09 | Migration fails | L back up; N failed; `current` unchanged; exit 5; message lists the migrations that completed |
| S-10 | Frontend build fails | Exit 4; live untouched; failed release kept until next cleanup |
| S-11 | Build OOM (exit 137) | `E_OOM` message |
| S-12 | Composer checksum mismatch on first download | `E_CHECKSUM`; nothing cached |
| S-13 | Node version not installed | Downloaded from the mirror, verified, used; second deploy uses the cache |
| S-14 | Health check returns 500, interactive answer "Roll back" | Rolled back to L (`up` in L first); exit 7; history shows both entries |
| S-15 | Health 500, non-interactive, migrations ran | Kept (NI-04); exit 7 |
| S-16 | Same commit without `--force` | "Nothing to do", exit 0, no release created |
| S-17 | Rewind without `--allow-rewind`, non-interactive | Exit 3 |
| S-18 | Concurrent deploys of the same site | The second gets exit 10 `E_LOCKED` |
| S-19 | Kill -9 during `building` | Next run offers recovery; recovery marks the release failed; deploy works afterwards |
| S-20 | Kill -9 during `migrating` (after `down`) | Recovery runs `up` on L and warns about partial migrations |
| S-21 | Kill -9 between switch and finish | Recovery completes the bookkeeping; `current` → N |
| S-22 | Rollback `--previous` | Target optimize ran with the target's PHP; switch; statuses; history |
| S-23 | Rollback to a release built with another PHP (sync on) | MultiPHP ordering per GL-03 |
| S-24 | Prune with `keep=2`, one protected, one failed | Correct releases deleted; the sentinel in shared is untouched |
| S-25 | `.env` missing | PRE-11 blocks, exit 3 |
| S-26 | `APP_KEY` empty, interactive "Generate now" | Key written (backup made), deploy continues |
| S-27 | Live `.htaccess` edited outside git | PRE-17 warning; the non-interactive log contains it |
| S-28 | cPanel replaced `.user.ini` with a real file in the live release | REL-06 copies it to shared before the build |
| S-29 | Wizard (ScriptedAsker): token path, full Laravel setup, DB create | site.yml matches; DB and user created with the prefix; `.env` complete; key added via API with `read_only: true` |
| S-30 | Wizard cancel after the key was added | Key deleted locally and on GitHub; no site folder; tmp cleaned |
| S-31 | Wizard Create fails at `site.yml` write | DB and user dropped; site folder removed |
| S-32 | Key rotation | New key registered and tested, old key removed, id updated; failure leaves the old key |
| S-33 | Remove site — detach | `~/<site>-app` works (real files, `.env` copied); docroot → it; key removed; shared moved to `removed/` |
| S-34 | Remove site — restore backup | Original folder back in place |
| S-35 | `add --from` without a token | Prints the key, exit 2; re-run completes |
| S-36 | Non-interactive deploy needing answers without flags | Exit 2 listing the flags |
| S-37 | `--json` outputs | Validate against schemas (§10.5); stdout contains only JSON |
| S-38 | Submodules in the repo | `E_SUBMODULES` at preflight |
| S-39 | Legacy import | Pre-filled answers; old key copied; access OK; after deploy, cron line removal offered |
| S-40 | SEC-11 (`web_dir: ""` with shared `.env`) | Validation error |
| S-41 | PHP-FPM keeps serving L after the switch (simulated by serving L in the web fake) | HC-01 marker mismatch → warning only |
| S-42 | Composer skip impossible (L has no vendor) | Install forced, reason logged |

### 16.5 Manual QA checklist (real servers, before each release)

| # | Environment / action | Pass criteria |
|---|---|---|
| M-01 | AlmaLinux 9 + cPanel + EA4 PHP-FPM; install via `install.sh` from cPanel's web Terminal | `cpdeploy` opens; `check` has no ✗ |
| M-02 | Same, over SSH in an 80×24 terminal | All screens readable; no wrapping of labels |
| M-03 | Non-UTF-8 locale (`LANG=C`) | ASCII symbols |
| M-04 | Add a real Laravel 12/13 app with a token; first deploy to an addon domain | Site works over HTTPS; `/storage` files served; `.env` not downloadable |
| M-05 | Same without a token (manual key) | Works |
| M-06 | Main domain `public_html` conversion (no nested domains) | Site works; backup present; AutoSSL renew (WHM or cPanel → SSL/TLS Status → Run AutoSSL) still succeeds |
| M-07 | Change PHP in cPanel's MultiPHP Manager, then deploy | The new release keeps the version (handler block captured) |
| M-08 | Change PHP through cpdeploy with redeploy | Served PHP (probe) = new version |
| M-09 | CloudLinux 8/9 with CageFS + PHP Selector (inherit) | Detection shows alt-php; option (b) path works |
| M-10 | Deploy with migrations; watch the site during the deploy | Maintenance page for only a few seconds |
| M-11 | Rollback | Old version served within seconds |
| M-12 | Close the web Terminal tab mid-build; reopen | Recovery banner; recovery works |
| M-13 | Low memory account (LVE) building a large Vite app | `E_OOM` message is clear |
| M-14 | Account with DB prefixing off | DB and user names without prefix |
| M-15 | `self-update` from the previous release; `--rollback` | Works |
| M-16 | Uninstall | Sites keep running |

### 16.6 CI (`.github/workflows/ci.yml`)

| Job | Content |
|---|---|
| `lint` | php-cs-fixer (dry-run), `composer validate`, `shellcheck scripts/*.sh`, markdownlint on docs |
| `static` | PHPStan level 8 |
| `tests` | Unit + Feature + Scenario on PHP 8.1, 8.2, 8.3, 8.4, 8.5 (ubuntu-latest) |
| `real-laravel` | PHP 8.3 + Node 22: `composer create-project laravel/laravel`, SQLite, push to a local bare repo, run `cpdeploy add --from` (fakes only for `uapi`/web) and deploy twice (the second with Composer skipped), then roll back; assert HTTP 200 from `php -S` serving the docroot symlink |
| `phar` | Build the phar; run `--version`, `check --json` (with fakes) from the phar on PHP 8.1 |
| `ssh-integration` (optional, nightly) | sshd container; deploy key auth through GIT_SSH_COMMAND; host-key mismatch case |

---

## 17. Milestones (build order)

Each milestone ends with its acceptance criteria passing in CI, plus the manual checks it lists. Deploy works non-interactively (M3/M4) **before** the menus and wizard are built (M5/M6). This lets the core be tested early against hand-written `site.yml` files.

### M0: Foundation and distribution

**Tasks**

- `composer.json` (§6.1, platform pin), `bin/cpdeploy`, `Application` (CLI-01/02, error rendering §13), `Services`, `Version`.
- `Config/Paths` (§7.1, LAY-01/02/03), `GlobalConfig` + `GlobalSchema` (§8.1).
- Support:
  - `Shell` (SH-01…06)
  - `Fs` (FS-01…06)
  - `Masker` (LOG-04)
  - `Lock` (LCK-01/02)
  - `Clock`
  - `Log` (LOG-01…03)
  - `Errors` (§13 enum complete)
- UI: `Asker`/`Reporter` interfaces and all implementations, `Theme` (UI-04/07), `Format`, `Pager`.
- Test harness base (§16.2: temp HOME, fake bin dir, `CPDEPLOY_TESTING`).
- `scripts/build.sh`, `box.json`, `launcher.sh`, `install.sh` (§15.1–15.3).
- CI jobs `lint`, `static`, `tests`, `phar`.
- `cpdeploy --version`; `cpdeploy check` with the Tool and Programs groups.

**Acceptance**

- The phar builds, `install.sh` installs it on a real cPanel account, `cpdeploy check` runs (M-01 partially).
- Unit tests for Fs safe delete (sentinel), Masker and Shell timeouts pass.
- PHPStan level 8 is clean.

### M1: Server knowledge (cPanel and runtimes)

**Tasks**

- `Cpanel/*` (CP-01…05). Capture **real** `uapi` outputs from a test server as fixtures, and resolve every "(verify)" field.
- `PhpLocator`, `PhpService` detection (PHP-01…04, 07).
- `ComposerInstaller` (CMP-01).
- `NodeLocator`, `NodeResolver`, `NodeInstaller`, `PackageManager` (NODE-01…08).
- `Http` (HTTP-01…03).
- `check` groups: cPanel, Account, Network.

**Acceptance**

- The NodeResolver and PhpService unit tests pass.
- Feature tests for checksum-verified downloads pass.
- `check` on a real server shows correct PHP versions, domains, quota, and CloudLinux detection (on a CloudLinux box).

### M2: GitHub and git

**Tasks**

- `RepoUrl`, `Transport` (GIT-04).
- `HostKeys` + `resources/github_known_hosts` + fingerprint test (GIT-03).
- `SshCommand` (GIT-02), `GitRepository` (GIT-07…16), `DeployKeyService` (GIT-05/06/17/18/19).
- `GitHubApi` + `TokenStore` (GH-01…05).
- `token` command; `check` GitHub group.

**Acceptance**

- Feature tests pass against the fake API and local remotes, and the `ssh-integration` job is green.
- Manual: a deploy key is added through a real fine-grained token on a test repo, and access is verified.

### M3: Deploy engine core (non-interactive)

**Tasks**

- Configuration and state:
  - `SiteSchema`, `SiteConfig`, `SiteRegistry`, `Presets` (§8)
  - `ReleaseManager`, `Release` (§8.5)
  - `StateFile` (§8.7)
- Analysis and planning:
  - `ProjectDetector`, `ComposerInspector` (PHP-05), `NodeInspector`
  - `ChangeAnalyzer` (CHG-01/02)
  - `Preflight` (all PRE except 17/18)
  - `PlanBuilder` (PLN-01…10, flags, NI rules)
- Build and go-live:
  - build steps B1–B10
  - `DocrootManager` (DOC-01…04), `HandlerBlock`
  - `Laravel/*` (LAR-01…06)
  - `GoLive` G1–G10 (critical section, GL-01…04)
  - `HealthChecker` (HC-01, HC-02, HC-04)
  - Phase D (PR-01…04)
- Commands: `deploy`, `status`, `releases`, `config`.

**Acceptance**

- Scenarios S-01…S-13, S-16…S-18, S-24, S-25, S-36, S-38, S-40, S-41, S-42 pass.
- The `real-laravel` job (first and second deploy) passes.

### M4: Safety net (rollback, recovery, health policy, drift)

**Tasks**

- `Rollback` (RB-01…08) + `rollback` command.
- `Recovery` (REC-01…03) + `recover` command + interrupted-run detection.
- HC-03 policies and NI-04.
- DOC-05/06 (PRE-17/18) and REL-06.

**Acceptance**

- Scenarios S-14, S-15, S-19…S-23, S-27, S-28 pass.
- The `real-laravel` job includes the rollback.

### M5: Interactive deploy and management menus

**Tasks**

- Main menu and deploy:
  - `MenuCommand`, `MainMenu` (§9.2, UIG-01…06)
  - `DeployScreen` (§9.4, ARC-01 `TaskReporter`)
- Manage site:
  - `ManageSiteMenu` and every §9.5 submenu except Remove
  - `.env` (§7.12, `EnvMenu`, `env` command)
  - `LaravelToolsMenu` (+ `artisan`, `down`, `up`)
  - `PhpMenu` + `php` command (§9.5.4)
  - `NodeMenu` + `node`, `StepsMenu`
  - `KeyMenu` + `key` command (incl. rotation S-32)
  - Composer credentials, `LogsMenu` + `logs`, Site info

**Acceptance**

- Every menu path is exercised by ScriptedAsker tests.
- Scenario S-26 (inline *Generate APP_KEY now* from the deploy screen) passes.
- Manual M-02, M-03, M-10 and M-11 pass.

### M6: Add-site wizard and database

**Tasks**

- `AddSiteWizard`, `WizardState`, `WizardTransaction` (WIZ-01…05) and steps 1–10 (§9.3).
- `DatabaseService`, `DbCheck` (DB-01…05).
- `.env` creation (ENV-09).
- Importing an existing app.
- `add --from`.
- `LegacyImporter` (LEG-01…04).

**Acceptance**

- Scenarios S-29, S-30, S-31, S-35 and S-39 pass.
- Manual M-04, M-05, M-06 and M-14 pass.

### M7: Remove, settings, updates, polish, docs

**Tasks**

- `RemoveSiteFlow` + `remove` (RM-01/02, DOC-07).
- `SettingsMenu` (§9.6).
- `check --probe` and `--refresh-host-keys`.
- JSON outputs (§10.5).
- UI polish (UI-01…09, 80×24).
- `SelfUpdateCommand` (§15.5), `release.yml` (§15.4).
- README user guide, `docs/*`, CHANGELOG.

**Acceptance**

- Scenarios S-33, S-34 and S-37 pass.
- Manual M-07, M-08, M-09, M-12, M-13, M-15 and M-16 pass.
- All CI jobs are green. Tag `v1.0.0-rc.1`.

### M8: Release 1.0.0

Fix everything found in QA, complete the release checklist (§18.2), and tag `v1.0.0`.

---

## 18. Definition of done

### 18.1 For every task / pull request

- Code plus tests. Test docblocks reference the requirement IDs (`@covers-req`).
- PHPStan level 8 and php-cs-fixer are clean. All tests are green on PHP 8.1–8.5.
- User-facing strings follow §13's format and fit in 74 characters where they are labels.
- `README.md` is updated for user-visible changes, and `docs/decisions.md` for every judgement call made.
- No new dependency without an entry in `docs/decisions.md`. The PHP 8.1 platform pin must still resolve.

### 18.2 Release checklist

1. CI green, including `real-laravel` and `phar`.
2. Manual QA M-01…M-16 passed on **two** servers: AlmaLinux 9 + EA4 (PHP-FPM), and CloudLinux 8/9 + CageFS + PHP Selector.
3. CHANGELOG updated, version tagged, release assets present (`cpdeploy.phar`, `.sha256`, `install.sh`).
4. Fresh install from the release on a clean account works. `self-update` from the previous release works.

---

## Appendix A: Examples

### A.1 Static site `site.yml` (differences from §8.2)

```yaml
type: static
domain: { name: app.example.com, docroot: /home/brainbean/app.example.com, web_dir: dist }
php: { version: "8.3", family: ea, sync_multiphp: false }
node: { version: auto, build_script: build, expect_files: [], build_outputs: [dist] }
steps: { composer_install: off, frontend_build: every, storage_link: off, optimize: off,
         migrate: off, maintenance: off, seed: off, queue_restart: off }
shared: { files: [], dirs: [], docroot_files: [], docroot_dirs: [.well-known] }
```

### A.2 `cpdeploy add --from=shop.yml` (setup section)

```yaml
# … full site.yml keys as §8.2 …
setup:
  env: example                # example | file:/home/brainbean/shop.env | none
  app_url: https://shop.example.com
  database: create            # create | existing | sqlite | none
  db_existing: { name: brainbean_shop, user: brainbean_shop, password_env: SHOP_DB_PASSWORD }
  deploy_now: true
```

### A.3 Non-interactive deploy examples

```bash
cpdeploy deploy shop --yes                               # defaults for every question
cpdeploy deploy shop --composer=no --migrate=yes --yes   # explicit answers
cpdeploy deploy shop --ref=v2.4.0 --yes                  # a tag
cpdeploy rollback shop --previous --yes
cpdeploy status --json
```

---

## Appendix B: External interface reference

Verify everything marked (verify) during M1/M2 and replace this appendix's examples with captured real output.

### B.1 cPanel UAPI (command line, run as the cPanel user)

```bash
uapi --output=json DomainInfo domains_data format=hash
uapi --output=json LangPHP php_get_installed_versions
uapi --output=json LangPHP php_get_vhost_versions
uapi --output=json LangPHP php_get_system_default_version
uapi --output=json LangPHP php_set_vhost_versions vhost=shop.example.com version=ea-php83
uapi --output=json Mysql get_restrictions
uapi --output=json Mysql list_databases
uapi --output=json Mysql list_users
uapi --output=json Mysql create_database name=brainbean_shop
uapi --output=json Mysql create_user name=brainbean_shop password=<generated>
uapi --output=json Mysql set_privileges_on_database user=brainbean_shop database=brainbean_shop privileges=ALL%20PRIVILEGES
uapi --output=json Mysql delete_database name=brainbean_shop
uapi --output=json Mysql delete_user name=brainbean_shop
uapi --output=json Quota get_quota_info
```

- **Response envelope:** `{"apiversion":3, "module":"…", "func":"…", "result":{"status":1|0, "data":…, "errors":[…]|null, "warnings":…, "messages":…, "metadata":{…}}}`.
- Argument values must be URI-encoded.
- `Mysql::get_restrictions` real output (cPanel 11.138): `{"prefix":"accountname_", "max_database_name_length":64, "max_username_length":32}`. `prefix` is absent when prefixing is disabled.
- A failed call (real output; exit code 0): `[… +0000] warn [uapi] Could not find “x” in module “LangPHP”.` followed by `{"apiversion":3,…,"result":{"status":0,"data":null,"errors":["The system could not find the function “x” in the module “LangPHP”."],…}}`.
- `/usr/bin/php` on that server is **php-cgi**, not the CLI: PHP-01's CLI filter is required.

### B.2 GitHub REST API

- **Endpoints and permissions:** §7.5 (GH-02).
- **Headers:** `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`, `User-Agent`, `Authorization: Bearer`.
- **Deploy keys:** `POST /repos/{owner}/{repo}/keys` body `{"title","key","read_only":true}` → 201 with `id`. 422 = validation failed (e.g. the key is already in use). `DELETE …/keys/{id}` → 204.
- **Token-expiry header:** `github-authentication-token-expiration` (verify on `GET /user`).

### B.3 Laravel commands by version

| Purpose | Laravel 8 | 9–10 | 11+ |
|---|---|---|---|
| Pending migrations | `migrate:status` (table, `No` rows) | `migrate:status` (`… Pending` lines; 10 also has `--pending` as a filter) | `migrate:status --pending=<exit code>` |
| Maintenance files | `storage/framework/down` (+ `maintenance.php` in 8+) | same | same (file driver; `APP_MAINTENANCE_DRIVER=cache` also exists: G8 handles it) |
| `optimize` caches | config, routes | config, routes | config, events, routes, views |
| Health route | — | — | `/up` (default in new 11+ skeletons) |

### B.4 Composer

- **Downloads:** `https://getcomposer.org/download/{latest-stable|latest-2.x|latest-2.2.x (verify)|<version>}/composer.phar`, with the checksum at the same path plus `.sha256`.
- **Commands:**
  - `install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress`
  - `dump-autoload --optimize --no-dev`
  - `check-platform-reqs --lock --no-dev --format=json`
- **Environment:** `COMPOSER_AUTH`, `COMPOSER_MEMORY_LIMIT`, `COMPOSER_NO_INTERACTION`.

### B.5 Node.js distribution

- **Index:** `https://nodejs.org/dist/index.json`. Fields per version: `version`, `date`, `files`, `npm`, `lts` (`false` or the codename), `security`. `files` ids include `linux-x64`, `linux-arm64`, `linux-x64-glibc-217` (backlog).
- **Per version:** `https://nodejs.org/dist/v<ver>/SHASUMS256.txt` and `node-v<ver>-linux-<arch>.tar.gz`.
- **Corepack** is not distributed with Node.js 25 and later. cpdeploy installs pnpm and yarn through npm instead (NODE-08).
- **glibc:** official builds of Node ≥ 18 need glibc ≥ 2.28.

### B.6 GitHub SSH host keys (from GitHub's documentation)

Published SHA256 fingerprints:

- RSA `SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s`
- ECDSA `SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM`
- Ed25519 `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`

`resources/github_known_hosts` MUST contain these lines, plus the same three keys with the host `[ssh.github.com]:443`:

```
github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg=
github.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCj7ndNxQowgcQnjshcLrqPEiiphnt+VTTvDP6mHBL9j1aNUkY4Ue1gvwnGLVlOhGeYrnZaMgRK6+PKCUXaDbC7qtbW8gIkhL7aGCsOr/C56SJMy/BCZfxd1nWzAOxSDPgVsmerOBYfNqltV9/hWCqBywINIR+5dIg6JTJ72pcEpEjcYgXkE2YEFXV1JHnsKgbLWNlhScqb2UmyRkQyytRLtL+38TGxkxCflmO+5Z8CSSNY7GidjMIZ7Q4zMjA2n1nGrlTDkzwDCsw+wqFPGQA179cnfGWOWRVruj16z6XyvxvjJwbz0wQZ75XK5tKSb7FNyeIEs4TT4jk+S4dhPeAUC5y+bDYirYgM4GC7uEnztnZyaVWQ7B381AK4Qdrwt51ZqExKbQpTUNn+EjqoTwvqNj4kqx5QUCI0ThS/YkOxJCXmPUWZbhjpCg56i+2aB6CmK2JGhn57K5mj0MNdBXA4/WnwH6XoPWJzK5Nyu2zB3nAZp+S5hpQs+p1vN1/wsjk=
```

The unit test in §16.3 must confirm that these lines produce the fingerprints above.

### B.7 cPanel / CloudLinux / PHP-FPM facts the design relies on

- MultiPHP writes a `# php -- BEGIN cPanel-generated handler, do not edit` … `# php -- END cPanel-generated handler, do not edit` block with `AddHandler application/x-httpd-ea-phpNN …` into the docroot's `.htaccess` (DOC-04).
- Setting a vhost's PHP version fails if the docroot has no `.htaccess` (DOC-03).
- **CloudLinux:** MultiPHP has priority over PHP Selector. PHP Selector applies only when the domain is set to **inherit**, and the system default must be an ea-php version. `/usr/local/bin/php`, run from inside the user's folders, reflects the Selector choice (PHP-03).
- **PHP-FPM** caches resolved paths per worker (`realpath_cache_ttl`, default 120 s). After a symlink switch, some requests can reach the previous release for up to that long. Users cannot reload PHP-FPM without root (GL-02).
- **Apache** on cPanel commonly uses `SymLinksIfOwnerMatch`. Symlinks and their targets must have the same owner (PERM-03).

### B.8 Sources consulted for this plan

- Laravel Prompts documentation (functions, `form()`, `spin()`/`task()` and pcntl, fallbacks): https://laravel.com/docs/12.x/prompts, https://laravel.com/docs/13.x/prompts
- laravel/prompts on Packagist (v0.3.24, PHP ^8.1, ext-mbstring, symfony/console ^6.2|^7|^8): https://packagist.org/packages/laravel/prompts
- GitHub deploy keys REST API: https://docs.github.com/en/rest/deploy-keys/deploy-keys
- GitHub fine-grained token permissions: https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens
- GitHub SSH key fingerprints: https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints
- cPanel UAPI references (mirrored): `LangPHP::php_set_vhost_versions`, `DomainInfo::domains_data`, `Mysql::get_restrictions` (portal.thelinuxfix.com knowledge base); `Quota` fields: https://mcp.jethost.com/docs/cpanel/quota.html
- CloudLinux PHP Selector with cPanel: https://cloudlinux.zendesk.com/hc/en-us/articles/360014084800-PHP-Selector-Integration-with-cPanel
- cPanel inherited PHP and the `.htaccess` requirement: https://www.catalyst2.com/knowledgebase/server-management/the-inherited-php-version-on-cpanel-servers/
- Composer CLI (`check-platform-reqs --lock`, env vars): https://getcomposer.org/doc/03-cli.md
- Composer download URLs and checksums: https://getcomposer.org/download/
- Laravel `migrate:status --pending=<code>`: https://github.com/laravel/framework/pull/51341
- Node.js dist index format: https://github.com/nodejs/nodejs-dist-indexer
- Corepack not shipped from Node.js 25: https://github.com/nodejs/corepack/issues/722

---

## Appendix C: Backlog (not in v1)

| Item | Notes |
|---|---|
| WordPress preset | shared `wp-config.php`, `wp-content/uploads`; docroot = release root with `.htaccess` shared; plugin updates from the admin would be lost on deploy, so document or block them |
| "Copy into folder" strategy | for hosts where the docroot can't be a symlink |
| HTTPS + token git transport | for networks that block SSH on both ports; `GIT_CONFIG_*` env to inject the header |
| Git submodules / LFS | per-submodule deploy keys; `git lfs` availability |
| Build in CI, deploy artifact | GitHub Actions builds, cpdeploy downloads and activates; removes Node from the server |
| Hardlinked `vendor/` reuse | only with `vendor/composer` copied for real; needs care with packages that write inside vendor |
| Node glibc-217 builds | `unofficial-builds.nodejs.org` for CentOS 7-era servers |
| bun support | |
| GitLab / Bitbucket | |
| Root/WHM mode | multiple accounts, acting as each user |
| Notifications, auto-deploy | explicitly excluded by D2/D7; kept here only for completeness |
