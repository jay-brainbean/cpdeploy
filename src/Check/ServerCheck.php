<?php

declare(strict_types=1);

namespace Cpdeploy\Check;

use Cpdeploy\Runtime\PhpInstall;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Ui\Format;

/**
 * `cpdeploy check` logic (§9.7). The GitHub and per-site groups arrive with
 * M2 and M3.
 */
final class ServerCheck
{
    public const GROUP_TOOL = 'Tool';
    public const GROUP_PROGRAMS = 'Programs';
    public const GROUP_CPANEL = 'cPanel';
    public const GROUP_ACCOUNT = 'Account';
    public const GROUP_NETWORK = 'Network';

    /** Account usage thresholds (§9.7): ⚠ above 80 %, ✗ above 95 %. */
    public const WARN_PERCENT = 80.0;
    public const FAIL_PERCENT = 95.0;

    /** Programs that must be present, with the hint shown when one is missing. */
    private const REQUIRED_PROGRAMS = [
        'ssh' => 'Needed to fetch from GitHub over SSH',
        'ssh-keygen' => 'Needed to create deploy keys',
        'tar' => 'Needed to extract releases',
        'gzip' => 'Needed to unpack Node downloads',
        'curl' => 'Needed for downloads and the GitHub API',
        'stty' => 'Needed by the interactive menus',
        'du' => 'Needed to measure disk usage',
    ];

    public function __construct(
        private readonly Shell $shell,
        private readonly SystemInfo $system,
        private readonly ?ServerCheckServices $services = null,
    ) {
    }

    /**
     * @param list<string>|null $only group names to run; null = all available
     * @return list<CheckGroup>
     */
    public function run(?array $only = null): array
    {
        $groups = [];
        if ($only === null || in_array(self::GROUP_TOOL, $only, true)) {
            $groups[] = new CheckGroup(self::GROUP_TOOL, $this->toolChecks());
        }
        if ($only === null || in_array(self::GROUP_PROGRAMS, $only, true)) {
            $groups[] = new CheckGroup(self::GROUP_PROGRAMS, $this->programChecks());
        }
        if ($this->services === null) {
            return $groups;
        }
        if ($only === null || in_array(self::GROUP_CPANEL, $only, true)) {
            $groups[] = new CheckGroup(self::GROUP_CPANEL, $this->cpanelChecks($this->services));
        }
        if ($only === null || in_array(self::GROUP_ACCOUNT, $only, true)) {
            $groups[] = new CheckGroup(self::GROUP_ACCOUNT, $this->accountChecks($this->services));
        }
        if ($only === null || in_array(self::GROUP_NETWORK, $only, true)) {
            $groups[] = new CheckGroup(self::GROUP_NETWORK, $this->networkChecks($this->services));
        }

        return $groups;
    }

    /**
     * @return list<CheckResult>
     */
    public function toolChecks(): array
    {
        $checks = [];
        $php = $this->system->phpVersion();
        $binary = $this->system->phpBinary();
        $checks[] = version_compare($php, '8.1.0', '>=')
            ? CheckResult::ok('tool.php', sprintf('Tool PHP %s (%s)', $php, $binary))
            : CheckResult::fail('tool.php', sprintf('Tool PHP %s is too old (%s)', $php, $binary), 'cpdeploy needs PHP 8.1 or newer; set CPDEPLOY_PHP to another PHP');

        foreach (['phar', 'mbstring', 'json'] as $ext) {
            $checks[] = $this->system->hasExtension($ext)
                ? CheckResult::ok('tool.ext.' . $ext, "Extension {$ext}")
                : CheckResult::fail('tool.ext.' . $ext, "Extension {$ext} is missing", "Enable {$ext} for the Tool PHP (WHM → MultiPHP INI Editor / EasyApache 4)");
        }
        $checks[] = $this->system->hasExtension('ctype')
            ? CheckResult::ok('tool.ext.ctype', 'Extension ctype')
            : CheckResult::ok('tool.ext.ctype', 'Extension ctype (using the built-in polyfill)');

        foreach (['pcntl' => 'spinners static, Ctrl+C less graceful', 'posix' => 'timeouts stop only the main process, not its children'] as $ext => $effect) {
            $checks[] = $this->system->hasExtension($ext)
                ? CheckResult::ok('tool.ext.' . $ext, "Extension {$ext}")
                : CheckResult::warn('tool.ext.' . $ext, "Extension {$ext} is missing: {$effect}", "Optional. Enable {$ext} for the Tool PHP if your host allows it");
        }

        $disabled = $this->system->disabledFunctions(['proc_open', 'proc_get_status', 'proc_terminate', 'exec', 'shell_exec']);
        $checks[] = $disabled === []
            ? CheckResult::ok('tool.functions', 'proc_open, exec and shell_exec are allowed')
            : CheckResult::fail(
                'tool.functions',
                'Disabled for the Tool PHP: ' . implode(', ', $disabled),
                'Remove them from disable_functions in WHM → MultiPHP INI Editor for this PHP version',
            );

        $user = $this->system->userName();
        $checks[] = $this->system->isRoot()
            ? CheckResult::fail('tool.user', 'Running as root', 'Run cpdeploy as the cPanel user: su - <user> -s /bin/bash -c cpdeploy')
            : CheckResult::ok('tool.user', "Running as {$user}");

        return $checks;
    }

    /**
     * @return list<CheckResult>
     */
    public function programChecks(): array
    {
        $checks = [$this->gitCheck()];

        foreach (self::REQUIRED_PROGRAMS as $program => $why) {
            $path = $this->shell->which($program);
            $checks[] = $path !== null
                ? CheckResult::ok('programs.' . $program, "{$program} ({$path})")
                : CheckResult::fail('programs.' . $program, "{$program} was not found", "{$why}. Ask your host to make it available in your shell");
        }

        $checks[] = $this->cpCheck();

        $uapi = $this->shell->which($this->system->uapiBinary());
        $checks[] = $uapi !== null
            ? CheckResult::ok('programs.uapi', "uapi ({$uapi})")
            : CheckResult::fail('programs.uapi', "This doesn't look like a cPanel account (uapi not found)", "Run cpdeploy inside a cPanel account's shell");

        $less = $this->shell->which('less');
        $checks[] = $less !== null
            ? CheckResult::ok('programs.less', "less ({$less})")
            : CheckResult::warn('programs.less', 'less was not found: long logs show only their last 200 lines', 'Optional');

        return $checks;
    }

    /**
     * @return list<CheckResult>
     */
    public function cpanelChecks(ServerCheckServices $s): array
    {
        if (!$s->uapi->available()) {
            return [CheckResult::fail('cpanel.uapi', "This doesn't look like a cPanel account (uapi not found)", "Run cpdeploy inside a cPanel account's shell")];
        }
        $checks = [];
        try {
            $domains = $s->domains->all();
            $checks[] = CheckResult::ok('cpanel.uapi', sprintf('cPanel answers (%d domains)', count($domains)));
        } catch (CpdeployException $e) {
            return [CheckResult::fail('cpanel.uapi', $e->getMessage(), $e->hint)];
        }

        try {
            $versions = $s->multiPhp->installedVersions();
            $default = $s->multiPhp->systemDefault();
            $checks[] = $versions === []
                ? CheckResult::warn('cpanel.multiphp', 'MultiPHP reports no PHP versions', 'Ask your host to install EasyApache 4 PHP versions')
                : CheckResult::ok('cpanel.multiphp', sprintf('MultiPHP: %s (default %s)', implode(', ', $versions), $default ?? 'unknown'));
        } catch (CpdeployException $e) {
            $checks[] = CheckResult::fail('cpanel.multiphp', 'MultiPHP is not available: ' . $e->getMessage(), 'cpdeploy needs MultiPHP (EasyApache 4) to manage PHP versions');
        }

        $installs = $s->phpLocator->installs();
        $checks[] = $installs === []
            ? CheckResult::warn('cpanel.php', 'No PHP command-line binaries found in /opt/cpanel or /opt/alt', 'Sites need an ea-php or alt-php version to build with')
            : CheckResult::info('cpanel.php', 'PHP for sites: ' . implode(', ', array_map(
                static fn (PhpInstall $i): string => $i->version . ' (' . $i->family . ')',
                $installs,
            )));

        try {
            $r = $s->mysql->restrictions();
            $checks[] = CheckResult::ok('cpanel.mysql', $r->prefix !== null
                ? sprintf('MySQL available (names start with %s)', $r->prefix)
                : 'MySQL available (database prefixing is off)');
        } catch (CpdeployException $e) {
            $checks[] = CheckResult::warn('cpanel.mysql', 'MySQL is not available: ' . $e->getMessage(), 'Only needed to create databases from cpdeploy');
        }

        $cl = $s->cloudLinux;
        if ($cl->isCloudLinux()) {
            $checks[] = CheckResult::info('cpanel.cloudlinux', sprintf(
                '%s%s%s',
                $cl->release() ?? 'CloudLinux',
                $cl->selectorAvailable() ? ', PHP Selector available' : '',
                $cl->cageFs() ? ', CageFS' : '',
            ));
        } else {
            $checks[] = CheckResult::info('cpanel.cloudlinux', 'Not CloudLinux (PHP Selector not used)');
        }

        return $checks;
    }

    /**
     * @return list<CheckResult>
     */
    public function accountChecks(ServerCheckServices $s): array
    {
        if (!$s->uapi->available()) {
            return [CheckResult::info('account.quota', 'Skipped: uapi not found')];
        }
        try {
            $q = $s->quota->quota();
        } catch (CpdeployException $e) {
            return [CheckResult::warn('account.quota', "Couldn't read the quota: " . $e->getMessage(), 'Disk space checks before deploys will be skipped')];
        }

        $disk = $q->megabyteLimit === null
            ? sprintf('Disk: %s used (no limit)', Format::bytes($q->megabytesUsed * 1048576))
            : sprintf('Disk: %s of %s used (%.0f%%)', Format::bytes($q->megabytesUsed * 1048576), Format::bytes($q->megabyteLimit * 1048576), $q->diskPercent());
        $inodes = $q->inodeLimit === null
            ? sprintf('Inodes: %s used (no limit)', number_format($q->inodesUsed))
            : sprintf('Inodes: %s of %s used (%.0f%%)', number_format($q->inodesUsed), number_format($q->inodeLimit), $q->inodePercent());

        return [
            self::usage('account.disk', $disk, $q->diskPercent(), 'Free space, lower releases.keep, or ask your host for more'),
            self::usage('account.inodes', $inodes, $q->inodePercent(), 'Remove files you no longer need (node_modules, caches, old backups)'),
        ];
    }

    private static function usage(string $id, string $message, ?float $percent, string $hint): CheckResult
    {
        return match (true) {
            $percent !== null && $percent > self::FAIL_PERCENT => CheckResult::fail($id, $message, $hint),
            $percent !== null && $percent > self::WARN_PERCENT => CheckResult::warn($id, $message, $hint),
            default => CheckResult::ok($id, $message),
        };
    }

    /**
     * @return list<CheckResult>
     */
    public function networkChecks(ServerCheckServices $s): array
    {
        $composer = self::hostPort($s->config->mirror('composer'));
        $node = self::hostPort($s->config->mirror('node'));
        $targets = ['github.com:22', 'ssh.github.com:443', $composer, $node];
        if ($s->hasToken) {
            $targets[] = 'api.github.com:443';
        }
        $ok = $s->probe->reachable(array_values(array_unique($targets)));

        $checks = [];
        if ($ok['github.com:22']) {
            $checks[] = CheckResult::ok('network.github', 'GitHub over SSH (github.com:22)');
        } elseif ($ok['ssh.github.com:443']) {
            $checks[] = CheckResult::ok('network.github', 'GitHub over SSH on port 443 (ssh.github.com; port 22 is blocked)');
        } else {
            $checks[] = CheckResult::fail('network.github', "Can't reach GitHub on port 22 or 443", 'Ask your host to allow outbound SSH to github.com');
        }
        if ($s->hasToken) {
            $checks[] = $ok['api.github.com:443']
                ? CheckResult::ok('network.api', 'GitHub API (api.github.com:443)')
                : CheckResult::fail('network.api', "Can't reach api.github.com:443", 'Needed for the GitHub token; ask your host to allow outbound HTTPS');
        }
        $checks[] = $ok[$composer]
            ? CheckResult::ok('network.composer', "Composer downloads ({$composer})")
            : CheckResult::warn('network.composer', "Can't reach {$composer}", 'Only needed to download Composer; ask your host or change mirrors.composer');
        $checks[] = $ok[$node]
            ? CheckResult::ok('network.node', "Node.js downloads ({$node})")
            : CheckResult::warn('network.node', "Can't reach {$node}", 'Only needed to download Node.js; ask your host or change mirrors.node');

        return $checks;
    }

    private static function hostPort(string $url): string
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : $url;
        $scheme = is_array($parts) && isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $port = is_array($parts) && isset($parts['port']) ? $parts['port'] : ($scheme === 'http' ? 80 : 443);

        return $host . ':' . $port;
    }

    private function gitCheck(): CheckResult
    {
        if ($this->shell->which('git') === null) {
            return CheckResult::fail('programs.git', 'git was not found', 'Ask your host to install git (2.20 or newer)');
        }
        $result = $this->shell->run(['git', '--version'], new RunOptions(timeout: 10));
        if (!$result->successful() || preg_match('/(\d+\.\d+(?:\.\d+)?)/', $result->stdout, $m) !== 1) {
            return CheckResult::warn('programs.git', 'git is installed but its version could not be read', '');
        }
        $version = $m[1];
        if (version_compare($version, '2.3', '<')) {
            return CheckResult::fail('programs.git', "git {$version} is too old (2.3 or newer is required)", 'Ask your host to update git');
        }
        if (version_compare($version, '2.20', '<')) {
            return CheckResult::warn('programs.git', "git {$version} (2.20 or newer is recommended)", 'Ask your host to update git');
        }

        return CheckResult::ok('programs.git', "git {$version}");
    }

    private function cpCheck(): CheckResult
    {
        if ($this->shell->which('cp') === null) {
            return CheckResult::fail('programs.cp', 'cp was not found', 'Ask your host to make GNU coreutils available');
        }
        $result = $this->shell->run(['cp', '--version'], new RunOptions(timeout: 10));
        if (!$result->successful() || !str_contains($result->stdout, 'GNU')) {
            return CheckResult::fail('programs.cp', 'cp is not GNU cp (cp -a is needed)', 'Ask your host to make GNU coreutils available');
        }

        return CheckResult::ok('programs.cp', 'GNU cp');
    }
}
