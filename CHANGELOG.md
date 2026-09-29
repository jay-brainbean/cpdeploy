# Changelog

All notable changes to cpdeploy are listed here. Versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

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
