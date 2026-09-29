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
- `fakeUapi()`: a fake `uapi`. It answers from per-test overrides
  (`uapiFixture()`, including raw text output), then from
  `tests/Fixtures/uapi/<Module>/<function>.json`, which is **real output**
  captured on a cPanel server. `uapiFail('Module::function')` makes a call
  fail; `uapiCalls()` returns every call with its arguments decoded.
- `fakePhp($root, '8.2.31', 'ea'|'alt', $modules, $sapi)`: a fake PHP binary
  at `<root>/ea-php82/root/usr/bin/php` (or `<root>/php82/usr/bin/php`). It
  reports the given version, SAPI and modules to probes and runs everything
  else with the real PHP, with `CPD_FAKE_PHP=ea-php82` set.
- `LocalServer`: PHP's built-in web server on 127.0.0.1, for fake Composer and
  Node mirrors.
- `FakeGitHub`: a fake GitHub REST API (`tests/Support/FakeGitHub/router.php`)
  with the endpoints of plan §7.5. It checks the required headers, keeps
  deploy keys in `state.json`, records every request (`calls()`), answers 422
  for a key already in use, and simulates problem tokens: `expired` (401),
  `ratelimited` (403, rate limit), `boom` (500), and repos in `noAdmin` (403 on
  `/keys`).
- Git remotes are local bare repositories reached through
  `CPDEPLOY_GIT_URL_OVERRIDE=file://…`.
- `runCli($args)`: run `bin/cpdeploy` in a subprocess with only the test
  environment. It adds the hidden `--allow-root` when the tests run as root
  (for example in a container).

`phpunit.xml` sets `GIT_CONFIG_GLOBAL=/dev/null` and `GIT_CONFIG_NOSYSTEM=1`, so
your own git settings (commit or tag signing, `core.hooksPath`, templates) don't
reach the fixture repositories the tests build.

Tests never use the network: every TCP probe goes to a closed local port
(`CPDEPLOY_TCP_OVERRIDE`), and downloads come from `LocalServer` mirrors.

## Test-only environment variables

| Variable | Effect |
|---|---|
| `CPDEPLOY_TESTING=1` | Enables every variable below |
| `CPDEPLOY_HOME` | cpdeploy's root instead of `~/cpdeploy` |
| `CPDEPLOY_TEST_PATH` | Folder placed first on the PATH of child processes |
| `CPDEPLOY_NOW` | Fixed current time (deterministic release ids) |
| `CPDEPLOY_UAPI_BIN` | The `uapi` binary to run |
| `CPDEPLOY_PHP_SEARCH_PATHS` | Colon-separated folders scanned for `ea-phpNN/root/usr/bin/php` and `phpNN/usr/bin/php` |
| `CPDEPLOY_NODE_SEARCH_PATHS` | Colon-separated glob patterns of Node bin folders (replace ea-nodejs, alt-nodejs and nvm) |
| `CPDEPLOY_SYSTEM_ROOT` | Fake root for `/etc/cloudlinux-release`, `/opt/alt` and `/usr/local/bin/php` |
| `CPDEPLOY_ARCH`, `CPDEPLOY_GLIBC` | Replace `uname -m` and the glibc probe |
| `CPDEPLOY_TCP_OVERRIDE` | `host:port=ip:port,…` (`*` for any) for network probes |
| `CPDEPLOY_HTTP_NO_BACKOFF=1` | No waiting between HTTP retries and health-check attempts |
| `CPDEPLOY_GIT_URL_OVERRIDE` | Every git remote URL (e.g. `file:///…/remote.git`) |
| `CPDEPLOY_GITHUB_API` | The GitHub API base URL (a `FakeGitHub`) |
| `CPDEPLOY_HTTP_OVERRIDE` | `host:port` that health-check and release-marker requests go to, over plain HTTP |

## Deploy scenarios (`tests/Support/DeployScenario.php`)

The scenario tests (plan §16.4, `tests/Scenario/Deploy*Test.php`) run the real
CLI against:

- `tests/Fixtures/apps/laravel`: a small app pushed to a local bare "GitHub"
  repo, with a fake `artisan` that implements what cpdeploy runs
  (`package:discover`, `storage:link`, `optimize`, `migrate:status`, `migrate`,
  `down`, `up`, `db:seed`, `queue:restart`). Its "database" is
  `storage/app/.fake-db.json` in shared storage; a migration file containing
  `FAKE_FAIL` fails. Every call is logged to `storage/logs/fake-artisan.log`
  with the PHP that ran it (`artisanCalls()`);
- fake PHP 8.2 and 8.3 (`fakePhp()`), a local Composer mirror serving
  `tests/Fixtures/composer/fake-composer.php` as `composer.phar` with its
  checksum, and a fake Node 20 with `tests/Fixtures/node/fake-npm.sh`
  (`CPD_FAKE_NPM=fail|oom`);
- the fake `uapi`, whose MultiPHP answers are stateful and which records where
  `current` pointed at each call (`CPD_WATCH_LINK`), so tests can check the PHP
  change order (GL-03);
- `DocrootWeb`: PHP's built-in server that re-resolves the docroot symlink on
  every request, like Apache.

The site is a hand-written `site.yml` (`writeSite()`) and `shared/.env`
(`writeEnv()`).

## Real Laravel (`tests/RealLaravel`)

The CI job `real-laravel` creates a `laravel/laravel` project and deploys it
twice with real Composer and Node (fakes only for `uapi` and the web server):

```sh
composer create-project laravel/laravel /tmp/app
CPDEPLOY_REAL_LARAVEL_APP=/tmp/app vendor/bin/phpunit --testsuite RealLaravel
```

Without network access to getcomposer.org or GitHub's archives, add
`CPDEPLOY_REAL_LARAVEL_COMPOSER_PHAR=$(command -v composer)` (served from a local
mirror) and `CPDEPLOY_REAL_LARAVEL_COMPOSER_FLAGS="--no-dev --optimize-autoloader
--no-interaction --prefer-source --no-progress"`.

## SSH integration tests

`tests/Integration/SshIntegrationTest.php` starts its own `sshd` on 127.0.0.1
that accepts only a freshly generated deploy key, and checks real SSH through
cpdeploy's `GIT_SSH_COMMAND`: key accepted, host-key mismatch refused, unknown
key refused. It needs `sshd` and runs only when asked:

```sh
CPDEPLOY_SSH_INTEGRATION=1 vendor/bin/phpunit --testsuite Integration
```

CI runs it in the `ssh-integration` job.

## Fixtures from real servers

`tests/Fixtures/uapi` holds real `uapi` output. To capture another server
(for example a CloudLinux one), copy `scripts/capture-fixtures.sh` to it and
run it as the cPanel user:

```sh
bash capture-fixtures.sh          # anonymised: domains, IPs, user, hostname, database names
bash capture-fixtures.sh --raw    # real values
```

It is read-only and writes one archive to the home folder. Before
committing anything from it, check that no real names remain.

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
