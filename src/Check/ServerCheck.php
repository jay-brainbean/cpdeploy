<?php

declare(strict_types=1);

namespace Cpdeploy\Check;

use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Support\SystemInfo;

/**
 * `cpdeploy check` logic (§9.7). Later milestones add the cPanel, Account,
 * Network, GitHub and per-site groups.
 */
final class ServerCheck
{
    public const GROUP_TOOL = 'Tool';
    public const GROUP_PROGRAMS = 'Programs';

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
