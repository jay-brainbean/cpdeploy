<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Check;

use Cpdeploy\Check\CheckResult;
use Cpdeploy\Check\ServerCheck;
use Cpdeploy\Support\Environment;
use Cpdeploy\Support\SystemInfo;
use Cpdeploy\Tests\Support\TestCase;

final class ServerCheckTest extends TestCase
{
    /**
     * @param list<string> $missingExtensions
     * @param list<string> $disabled
     */
    private function system(array $missingExtensions = [], array $disabled = [], bool $root = false, string $php = '8.3.12'): SystemInfo
    {
        return new class ($this->environment(), $missingExtensions, $disabled, $root, $php) extends SystemInfo {
            /**
             * @param list<string> $missing
             * @param list<string> $disabled
             */
            public function __construct(Environment $env, private array $missing, private array $disabled, private bool $root, private string $php)
            {
                parent::__construct($env);
            }

            public function phpVersion(): string
            {
                return $this->php;
            }

            public function hasExtension(string $name): bool
            {
                return !in_array($name, $this->missing, true);
            }

            public function disabledFunctions(array $functions): array
            {
                return array_values(array_intersect($functions, $this->disabled));
            }

            public function isRoot(): bool
            {
                return $this->root;
            }

            public function userName(): string
            {
                return $this->root ? 'root' : 'brainbean';
            }
        };
    }

    /**
     * @return array<string, CheckResult>
     */
    private function byId(ServerCheck $check): array
    {
        $out = [];
        foreach ($check->run() as $group) {
            foreach ($group->checks as $c) {
                $out[$c->id] = $c;
            }
        }

        return $out;
    }

    /**
     * @covers-req ARC-02
     */
    public function testToolGroup(): void
    {
        $checks = $this->byId(new ServerCheck($this->services()->shell(), $this->system(['pcntl', 'mbstring', 'ctype'], ['exec'])));

        self::assertSame(CheckResult::OK, $checks['tool.php']->status);
        self::assertSame(CheckResult::FAIL, $checks['tool.ext.mbstring']->status);
        self::assertSame(CheckResult::OK, $checks['tool.ext.ctype']->status, 'The Symfony polyfill covers ctype');
        self::assertSame(CheckResult::WARN, $checks['tool.ext.pcntl']->status);
        self::assertStringContainsString('spinners static', $checks['tool.ext.pcntl']->message);
        self::assertSame(CheckResult::OK, $checks['tool.ext.posix']->status);
        self::assertSame(CheckResult::FAIL, $checks['tool.functions']->status);
        self::assertStringContainsString('exec', $checks['tool.functions']->message);
        self::assertSame(CheckResult::OK, $checks['tool.user']->status);
    }

    /**
     * @covers-req SEC-13
     */
    public function testRootAndOldPhpFail(): void
    {
        $checks = $this->byId(new ServerCheck($this->services()->shell(), $this->system(root: true, php: '8.0.30')));

        self::assertSame(CheckResult::FAIL, $checks['tool.user']->status);
        self::assertSame(CheckResult::FAIL, $checks['tool.php']->status);
    }

    /**
     * @covers-req CP-02
     */
    public function testProgramsGroupUsesPathAndFakes(): void
    {
        $this->fakeBin('git', "#!/bin/sh\necho 'git version 2.18.1'\n");
        $this->fakeUapi();
        $checks = $this->byId(new ServerCheck($this->services()->shell(), $this->system()));

        self::assertSame(CheckResult::WARN, $checks['programs.git']->status);
        self::assertStringContainsString('2.18.1', $checks['programs.git']->message);
        self::assertSame(CheckResult::OK, $checks['programs.uapi']->status);
        self::assertSame(CheckResult::OK, $checks['programs.tar']->status);

        $this->fakeBin('git', "#!/bin/sh\necho 'git version 2.1.0'\n");
        self::assertSame(CheckResult::FAIL, $this->byId(new ServerCheck($this->services()->shell(), $this->system()))['programs.git']->status);
    }

    public function testMissingUapiMeansNotCpanel(): void
    {
        $env = $this->env + ['CPDEPLOY_UAPI_BIN' => 'uapi-that-does-not-exist'];
        $services = new \Cpdeploy\Services(new Environment($env));
        $checks = $this->byId(new ServerCheck($services->shell(), $services->system()));

        self::assertSame(CheckResult::FAIL, $checks['programs.uapi']->status);
        self::assertStringContainsString("doesn't look like a cPanel account", $checks['programs.uapi']->message);
    }
}
