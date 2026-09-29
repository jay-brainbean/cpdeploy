# cpdeploy

A menu-driven command-line tool that deploys GitHub repositories (Laravel
first) to a cPanel account, building each deploy in its own release folder and
switching the site over atomically.

> **Status:** under development. Milestones M0–M2 are in place: the tool
> installs, `cpdeploy check` inspects the server, and the GitHub token and
> deploy-key plumbing exists. Deploying arrives in later milestones. The specification is
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
| `~/cpdeploy/sites/` | One folder per site |

## Development

See [docs/testing.md](docs/testing.md) for running the tests and building the
phar, and [docs/architecture.md](docs/architecture.md) for how the code is
organised. Judgement calls are logged in [docs/decisions.md](docs/decisions.md).
