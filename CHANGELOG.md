# Changelog

All notable changes to cpdeploy are listed here. Versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- M0 foundation: the `cpdeploy` command with `--version` and `check` (Tool and
  Programs groups, `--json`).
- Safe process runner, atomic file writes and symlink swaps, safe tree
  deletion, secret masking, locks, and per-operation logs.
- `~/cpdeploy` folder layout with enforced permissions.
- Phar build (`scripts/build.sh`), launcher (`~/bin/cpdeploy`) and installer
  (`install.sh`, including `--download` and `--uninstall`).
- CI: lint, static analysis, tests on PHP 8.1–8.5, and a phar smoke test.
