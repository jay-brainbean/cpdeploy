# Testing

## Running the tests

```sh
composer install
vendor/bin/phpunit                        # everything
vendor/bin/phpunit --testsuite Unit       # Unit | Feature | Scenario
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix --dry-run --diff
shellcheck scripts/*.sh
```

Tests reference requirement IDs in their docblocks as `@covers-req XXX-NN`.
Find the tests for a requirement with `grep -rn "covers-req FS-03" tests/`.

## Harness (`tests/Support/TestCase.php`)

Every test gets:

- a temporary HOME (`$this->home`), with cpdeploy's root at `$this->root`
  through `CPDEPLOY_HOME`;
- a fake-binaries folder (`$this->fakeBin`) that comes first on the PATH of
  every command the tool runs, through `CPDEPLOY_TEST_PATH`;
- `CPDEPLOY_TESTING=1`. Without it, every test-only variable is ignored, so
  none of them can change behaviour on a real server.

Helpers:

- `services()`: a `Services` factory wired to the test environment.
- `fakeBin($name, $script)`: add an executable to the fake-binaries folder.
- `fakeUapi()`: a fake `uapi` that answers from
  `tests/Fixtures/uapi/<Module>/<function>.json`.
- `runCli($args)`: run `bin/cpdeploy` in a subprocess with only the test
  environment. It adds the hidden `--allow-root` when the tests run as root
  (for example in a container).

## Test-only environment variables

| Variable | Effect |
|---|---|
| `CPDEPLOY_TESTING=1` | Enables every variable below |
| `CPDEPLOY_HOME` | cpdeploy's root instead of `~/cpdeploy` |
| `CPDEPLOY_TEST_PATH` | Folder placed first on the PATH of child processes |
| `CPDEPLOY_NOW` | Fixed current time (deterministic release ids) |
| `CPDEPLOY_UAPI_BIN` | The `uapi` binary to run |

Later milestones add `CPDEPLOY_GIT_URL_OVERRIDE`, `CPDEPLOY_GITHUB_API`,
`CPDEPLOY_HTTP_OVERRIDE` and `CPDEPLOY_PHP_SEARCH_PATHS` (plan §16.2).

## Running from the source folder (development only)

On a test server: upload the repository, run `composer install`, then run
`<php 8.1+> bin/cpdeploy …`. Paths and behaviour are the same as the phar,
because the root is `~/cpdeploy`. Never do this on a production server.

## Building the phar

```sh
scripts/build.sh            # from HEAD; the tree must be clean
scripts/build.sh --dirty    # include uncommitted changes
```

Outputs `dist/cpdeploy.phar`, `dist/cpdeploy.phar.sha256` and `dist/install.sh`
(with the launcher embedded). Needs PHP 8.2+, Composer 2 and git; Box 4.7.0 is
downloaded and checksum-verified if it isn't on PATH.
