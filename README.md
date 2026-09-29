# cpdeploy

A menu-driven command-line tool that deploys GitHub repositories (Laravel
first) to a cPanel account, building each deploy in its own release folder and
switching the site over atomically.

> **Status:** under development. Milestones M0–M3 are in place: the tool
> installs, `cpdeploy check` inspects the server, and `cpdeploy deploy` builds
> and activates releases for a site described by a hand-written `site.yml`.
> Rollback and recovery (M4), the menus (M5) and the add-site wizard (M6) come
> next. The specification is
> [cpdeploy-development-plan.md](cpdeploy-development-plan.md).

## Requirements

- A cPanel account (EasyApache 4) with shell access: SSH or cPanel's Terminal.
- Any PHP 8.1 or newer installed on the server, with the `phar` and `mbstring`
  extensions. It doesn't have to be the PHP version your sites use.
- `proc_open`, `exec` and `shell_exec` must be allowed for that PHP.
- `git`, `ssh`, `curl`, `tar` and the usual shell tools.

cpdeploy never needs root, and refuses to run as root.

## Install

Run these as the cPanel user:

```sh
curl -fsSLO https://github.com/jay-brainbean/cpdeploy/releases/latest/download/install.sh
bash install.sh --download
```

Or with a phar you downloaded yourself:

```sh
bash install.sh ./cpdeploy.phar --sha256=<hex from cpdeploy.phar.sha256>
```

The installer:

- finds a suitable PHP (newest EasyApache `ea-php`, then CloudLinux `alt-php`,
  then `php` on your PATH);
- verifies the phar's checksum;
- puts it in `~/cpdeploy/app/` and the `cpdeploy` command in `~/bin/`;
- adds `~/bin` to your PATH in `~/.bashrc` and `~/.bash_profile` if needed;
- runs `cpdeploy check`.

To use a specific PHP for the tool, set `CPDEPLOY_PHP=/path/to/php`.

## Check your server

```sh
cpdeploy check          # human-readable
cpdeploy check --json   # for scripts
```

It checks, in groups:

- **Tool:** the PHP running cpdeploy, its extensions and allowed functions.
- **Programs:** git, ssh, curl, tar and the other programs cpdeploy uses.
- **cPanel:** that cPanel answers, the MultiPHP versions, the PHP versions sites
  can build with, MySQL, and whether this is CloudLinux.
- **Account:** disk and inode use against the quota.
- **Network:** GitHub over SSH (port 22, or 443 as a fallback), and the
  Composer and Node.js download sites.
- **GitHub:** the optional token (valid, whose, when it expires) and GitHub's
  SSH host keys.

Each line is ✓ (fine), ⚠ (works, with a limitation) or ✗ (must be fixed), with
a hint. The command exits 3 when anything is ✗. Without a UTF-8 locale the
symbols are `[ok]`, `[!]` and `[x]`.

## GitHub token (optional)

cpdeploy reads your repositories with a read-only **deploy key** per site.
Without a token, you add that key on GitHub yourself (cpdeploy shows the key
and the link). With a token, cpdeploy adds and removes the keys for you.

Create a **fine-grained** token at github.com → Settings → Developer settings →
Fine-grained tokens:

- Repository access: only the repos you deploy
- Permissions: **Administration: Read and write** (Metadata: Read is added
  automatically)
- Expiration: your choice; cpdeploy warns 14 days before it expires

```sh
cpdeploy token set        # asks for it (hidden), checks it with GitHub, saves it
cpdeploy token test       # who it belongs to and when it expires
cpdeploy token remove
```

The token is stored in `~/cpdeploy/secrets/github-token` (mode 600) and never
appears on a command line or in logs. Classic tokens (`ghp_…`) work but grant
far more than cpdeploy needs.

cpdeploy trusts only GitHub's published SSH host keys (shipped with the tool
and written to `~/cpdeploy/known_hosts`); it never edits `~/.ssh/config` or
`~/.ssh/known_hosts`.

## Deploy a site

Until the add-site wizard arrives, a site is described by hand in
`~/cpdeploy/sites/<site>/site.yml` (mode 600). The smallest useful file:

```yaml
schema: 1
name: shop
type: laravel                     # laravel | static | php | custom
repo: { owner: acme, name: shop, branch: main }
domain: { name: shop.example.com, docroot: /home/you/shop.example.com }
php: { version: "8.3" }
```

Every other setting has a default (see the plan, §8.2 and §8.4). Also needed:

- the deploy key `~/.ssh/cpdeploy_shop`, added to the repository on GitHub
  (read-only);
- for Laravel, `~/cpdeploy/sites/shop/shared/.env` (mode 600) with `APP_KEY` set.
  The frontend build runs with this production `.env`, so `VITE_*` values come
  from it.

```sh
cpdeploy deploy shop                              # asks what it needs to know
cpdeploy deploy shop --yes                        # takes every default answer
cpdeploy deploy shop --composer=no --migrate=yes --yes
cpdeploy deploy shop --ref=v2.4.0 --yes           # a tag, branch or commit
cpdeploy deploy shop --force --yes                # rebuild the live commit
```

A deploy:

1. fetches the repository and works out what changed since the live release;
2. checks the server (PHP, Composer's platform requirements, `.env`, the
   database, disk space, the document root) — nothing has changed yet;
3. asks its questions up front: `composer install` (skip = reuse the live
   `vendor/`), the frontend build when set to ask, migrations, seeding;
4. builds a new release in `~/cpdeploy/sites/shop/releases/<id>`: the commit,
   the shared files (`.env`, `storage/`), `composer install`, `npm ci` and
   `npm run build`, `php artisan storage:link` and `php artisan optimize` —
   all with the site's own PHP and Node;
5. goes live: maintenance mode on the live release only while migrations run,
   then `current` switches to the new release in one step. On the first deploy
   the document root becomes a symlink to `current/public`; anything that was
   in it is moved to `~/cpdeploy/sites/shop/backups/`;
6. checks the site answers, removes old releases (5 are kept) and logs
   everything under `~/cpdeploy/sites/shop/logs/`.

If anything fails before the switch, the live site is not touched and the
command says so. Without a terminal, questions must be answered with flags or
`--yes`; the command lists the flags it needs.

Files marked `export-ignore` in `.gitattributes` are not deployed.

```sh
cpdeploy status                 # all sites
cpdeploy status shop --json
cpdeploy releases shop          # list releases
cpdeploy releases shop protect 20260929-030512
cpdeploy config shop get php.version
cpdeploy config shop set releases.keep 8
cpdeploy config shop edit       # opens site.yml in your editor, then validates it
```

Exit codes: 0 done (or nothing to do), 2 an answer or valid setting is missing,
3 a check failed, 4 the build failed, 5 a migration failed (the previous
release is back up), 6 go-live failed, 7 the site didn't pass the health check
after go-live (the new release is kept), 10 another operation is running,
11 an earlier operation was interrupted.

## Uninstall

```sh
bash install.sh --uninstall
```

This removes the `cpdeploy` command and `~/cpdeploy/app`. Your sites keep
running; their data stays in `~/cpdeploy/sites`.

## Files cpdeploy creates

| Path | What |
|---|---|
| `~/bin/cpdeploy` | The launcher |
| `~/cpdeploy/app/` | The tool (`cpdeploy.phar`, and the previous version) |
| `~/cpdeploy/config.yml` | Global settings (mode 600) |
| `~/cpdeploy/secrets/` | The optional GitHub token (mode 700) |
| `~/cpdeploy/known_hosts` | GitHub's SSH host keys |
| `~/.ssh/cpdeploy_<site>` | Each site's deploy key |
| `~/cpdeploy/tools/` | Downloaded Composer and Node, shared by all sites |
| `~/cpdeploy/sites/<site>/` | `site.yml`, `releases/`, `current`, `shared/`, `backups/`, `logs/`, `history.jsonl` |
| `<docroot>` | After the first deploy: a symlink to `~/cpdeploy/sites/<site>/current/<web_dir>` |

## Development

See [docs/testing.md](docs/testing.md) for running the tests and building the
phar, and [docs/architecture.md](docs/architecture.md) for how the code is
organised. Judgement calls are logged in [docs/decisions.md](docs/decisions.md).
