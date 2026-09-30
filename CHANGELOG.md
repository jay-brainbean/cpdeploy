# Changelog

All notable changes to cpdeploy are listed here. Versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.0.0-rc.1 - 2026-09-30

First release candidate: everything in the plan's milestones M0–M7. Test it on
a real cPanel account before 1.0.0.

### Added

- MIT license.

- M7 *Remove site* and `cpdeploy remove <site>`: the domain keeps running from
  a plain copy (`--detach`), gets its old folder back (`--restore-backup`) or
  an empty one (`--empty`); then the deploy key, optionally the database, the
  releases and the site folder go, and `.env` and uploads are kept in
  `~/cpdeploy/removed/` unless `--delete-shared`.
- Settings in the menu: GitHub token, defaults for new sites (now used by the
  wizard and `add --from`), timeouts, display, GitHub host keys, About, and
  updates.
- `cpdeploy self-update [--check] [--rollback] [--pre]`: checksum-verified
  updates from GitHub releases, keeping the previous version.
- `cpdeploy check [site] [--probe]`: a group per site (deploy key, PHP, Node,
  `.env` and its mode, the docroot link, the live release, shared folders,
  interrupted operations, maintenance mode, and the PHP the domain really
  serves); `--refresh-host-keys` replaces GitHub's SSH host keys after you
  compare the fingerprints.
- JSON Schemas for `status`, `releases`, `logs` and `check --json` in
  `resources/schemas/`.
- A release workflow: a version tag runs the full CI and publishes
  `cpdeploy.phar`, its checksum and `install.sh`, with the notes from this
  file.
- M6 add-site wizard: `cpdeploy add` (and *Add a new site* in the menu) walks
  through repository, GitHub access (deploy key added with the token, or shown
  to add by hand), project type, domain, served folder, PHP (checked against
  `composer.lock`), Node, `.env` and database, deploy steps and a review. Back
  on every screen, nothing written before Create, Cancel removes the key and
  the download, and a failed Create is undone.
- New MySQL database and user through cPanel with a generated password, an
  existing database (tested right away), or SQLite; a production `.env` from
  `.env.example` with a new `APP_KEY`.
- Importing an existing Laravel app found in the domain's folder (`.env` and
  `storage/` copied), and sites of the old `cpanel-git-setup.sh`
  (`~/deployments/<name>`), with removal of its cron line and webhook file after
  the first deploy.
- `cpdeploy add --from=<file.yml>`: a `site.yml` plus a `setup:` section
  creates the site without questions (and can deploy it).
- M5 menus: `cpdeploy` opens the main menu on a terminal (site table, banners
  for interrupted operations, maintenance mode and the GitHub token), the deploy
  screen (with *Retry* / *Retry with changes…* after a failure, and *Generate
  APP_KEY now*), *Deploy with changes…*, and Manage site with Roll back,
  Releases, PHP version (with the served-PHP probe), Node version, Deploy
  steps, Environment, Laravel tools, Branch, Deploy key, Composer credentials,
  Logs & history and Site info.
- Commands behind the same screens: `php`, `node`, `env`, `artisan`, `down`,
  `up`, `logs`, `key`, and `menu`.
- `.env` changes keep a backup (last 10), can be restored, and can be applied to
  the live site (`php artisan optimize`); values are quoted safely.
- M4 safety net: `cpdeploy rollback <site> [release] [--previous]` (§11.8) —
  target checks, the target's own PHP, warnings about migrations newer releases
  ran and PHP changes, optimize before the switch, maintenance off, MultiPHP
  ordering, health check with an offer to switch back.
- `cpdeploy recover <site>` and `deploy --recover` (§11.9): an interrupted
  deploy or rollback is finished or undone according to the phase it reached;
  `deploy` and `rollback` offer it on a terminal, other commands stop with exit
  11 until it is done.
- A failed health check after go-live is rolled back or kept
  (`health_check.on_failure`, `--on-health-fail`); without a terminal, rolled
  back unless migrations ran (HC-03, NI-04).
- Drift warnings at preflight: the live `.htaccess` changed outside git, with a
  diff and a chance to cancel (DOC-05), and files changed on the server since
  the last deploy, found through `.release-manifest` (DOC-06). A `.user.ini` or
  `php.ini` that cPanel replaced with a real file is copied into shared (REL-06).
- Scenario tests that really kill a deploy (building, migrating, after the
  switch) and recover it; the `real-laravel` job now also rolls back.
- M3 deploy engine: `cpdeploy deploy <site>` builds each deploy in its own
  release folder and switches to it atomically. Phase A plans (fetch, change
  analysis, runtimes, preflight checks, questions up front), Phase B builds
  (export, shared files and docroot extras, Composer install or vendor reuse,
  frontend build or reuse, `storage:link`, `optimize`, custom commands), Phase C
  goes live (maintenance only while migrations run, MultiPHP ordering, the
  switch, first-deploy docroot conversion with a backup, health check), Phase D
  cleans up and writes history.
- Sites described by `~/cpdeploy/sites/<site>/site.yml` with validation and
  per-type presets (Laravel, static, plain PHP, custom).
- `cpdeploy status [site] [--json]`, `cpdeploy releases <site>
  [protect|unprotect|delete <id>] [--json]`, `cpdeploy config <site>
  show|get|set|edit`.
- Scenario tests for the deploy paths and a `real-laravel` CI job.
- M2 GitHub and git: repository addresses, per-site read-only deploy keys
  (create, register with a token or by hand, test, rotate, remove), the bare
  mirror (clone, fetch, resolve branches/tags/commits, export, repair), pinned
  GitHub host keys, port 22 → 443 fallback, and the GitHub API.
- `cpdeploy token set|test|remove`, and a GitHub group in `cpdeploy check`.
- M1 server knowledge: cPanel UAPI access (domains, MultiPHP, MySQL, quota),
  PHP detection (EasyApache and CloudLinux alt-php, the domain's current PHP,
  PHP Selector), checksum-verified Composer and Node.js downloads, Node version
  resolution (`.nvmrc`, `engines`, LTS names), npm/pnpm/yarn detection, and an
  HTTP client that keeps tokens off the command line.
- `cpdeploy check`: new cPanel, Account and Network groups.
- `scripts/capture-fixtures.sh`: read-only capture of a server's cPanel facts
  for test fixtures.
- M0 foundation: the `cpdeploy` command with `--version` and `check` (Tool and
  Programs groups, `--json`).
- Safe process runner, atomic file writes and symlink swaps, safe tree
  deletion, secret masking, locks, and per-operation logs.
- `~/cpdeploy` folder layout with enforced permissions.
- Phar build (`scripts/build.sh`), launcher (`~/bin/cpdeploy`) and installer
  (`install.sh`, including `--download` and `--uninstall`).
- CI: lint, static analysis, tests on PHP 8.1–8.5, and a phar smoke test.
