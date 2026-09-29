<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Support;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Support\ProcessResult;
use Cpdeploy\Support\RunOptions;
use Cpdeploy\Support\Shell;
use Cpdeploy\Tests\Support\TestCase;

final class ShellTest extends TestCase
{
    /**
     * A timeout stops the whole process group, including grandchildren.
     *
     * @covers-req SH-05
     */
    public function testTimeoutKillsTheProcessGroup(): void
    {
        if (!function_exists('posix_kill') || !\Cpdeploy\Support\Signals::supported()
            || (!is_executable('/usr/bin/setsid') && !is_executable('/bin/setsid'))) {
            self::markTestSkipped('Needs posix, pcntl and setsid');
        }
        $pidFile = $this->tmp . '/child.pid';
        $shell = $this->services()->shell()->withKillGrace(1.0);

        $start = microtime(true);
        $result = $shell->run(
            ['bash', '-c', 'sleep 60 & echo $! > ' . Shell::quote($pidFile) . '; trap "" TERM; wait'],
            new RunOptions(timeout: 1.0),
        );
        $elapsed = microtime(true) - $start;

        self::assertTrue($result->timedOut);
        self::assertFalse($result->successful());
        self::assertLessThan(10, $elapsed, 'SIGKILL follows the grace period');
        $childPid = (int) trim((string) file_get_contents($pidFile));
        self::assertGreaterThan(0, $childPid);
        usleep(200_000);
        self::assertFalse(posix_kill($childPid, 0), 'The background child was killed with the group');
    }

    /**
     * @covers-req SH-05
     */
    public function testTimeoutBecomesETimeoutWithStepNameAndLimit(): void
    {
        $shell = $this->services()->shell()->withKillGrace(0.5);
        try {
            $shell->mustRun(['sleep', '5'], new RunOptions(timeout: 0.5, label: 'Composer'), ErrorCode::COMPOSER, 'composer install failed');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::TIMEOUT, $e->errorCode);
            self::assertStringContainsString('Composer took longer than 0s', $e->getMessage());
            self::assertSame(4, $e->exitCode());
        }
    }

    /**
     * @covers-req SH-03
     */
    public function testEnvironmentIsIsolated(): void
    {
        putenv('GIT_DIR=/tmp/should-not-leak');
        putenv('CPD_UNRELATED_SECRET=leak');
        putenv('SOME_RANDOM_VAR=leak');
        try {
            $env = $this->env + ['GIT_DIR' => '/tmp/should-not-leak', 'GIT_WORK_TREE' => '/x', 'SOME_RANDOM_VAR' => 'leak'];
            $shell = new Shell(new \Cpdeploy\Support\Environment($env), new \Cpdeploy\Support\Masker(), new \Cpdeploy\Support\Signals());
            $result = $shell->run(['env'], (new RunOptions())->withEnv(['EXTRA' => 'yes'])->withPathPrefix(['/opt/shims']));
        } finally {
            putenv('GIT_DIR');
            putenv('CPD_UNRELATED_SECRET');
            putenv('SOME_RANDOM_VAR');
        }
        $vars = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $vars[$k] = $v;
        }

        self::assertArrayNotHasKey('GIT_DIR', $vars);
        self::assertArrayNotHasKey('GIT_WORK_TREE', $vars);
        self::assertArrayNotHasKey('SOME_RANDOM_VAR', $vars);
        self::assertSame($this->home, $vars['HOME']);
        self::assertSame('0', $vars['GIT_TERMINAL_PROMPT']);
        self::assertSame('yes', $vars['EXTRA']);
        self::assertSame('C.UTF-8', $vars['LANG']);
        self::assertStringStartsWith('/opt/shims:' . $this->fakeBin . ':' . $this->home . '/bin:/usr/local/bin:/usr/bin:/bin', $vars['PATH']);
    }

    public function testTestPathIsIgnoredOutsideTestMode(): void
    {
        $env = $this->env;
        unset($env['CPDEPLOY_TESTING']);
        $shell = new Shell(new \Cpdeploy\Support\Environment($env), new \Cpdeploy\Support\Masker(), new \Cpdeploy\Support\Signals());

        self::assertStringNotContainsString($this->fakeBin, $shell->basePath());
    }

    /**
     * @covers-req SH-01
     */
    public function testArgumentsAreNotInterpretedByAShell(): void
    {
        $result = $this->services()->shell()->run(['echo', '$(whoami); `id` && rm -rf /nope']);

        self::assertSame("\$(whoami); `id` && rm -rf /nope\n", $result->stdout);
    }

    /**
     * @covers-req SH-01
     */
    public function testPipelineUsesPipefail(): void
    {
        $shell = $this->services()->shell();
        $ok = $shell->pipeline('printf ' . Shell::quote("a'b c") . ' | cat');
        $fail = $shell->pipeline('false | cat');

        self::assertSame("a'b c", $ok->stdout);
        self::assertTrue($ok->successful());
        self::assertFalse($fail->successful());
    }

    public function testStreamModeDeliversLinesFromBothStreams(): void
    {
        $lines = [];
        $this->services()->shell()->run(
            ['sh', '-c', 'echo one; echo two >&2; printf three'],
            (new RunOptions())->streaming(static function (string $l) use (&$lines): void {
                $lines[] = $l;
            }),
        );
        sort($lines);

        self::assertSame(['one', 'three', 'two'], $lines);
    }

    /**
     * @covers-req SH-06
     */
    public function testOutOfMemoryDetection(): void
    {
        self::assertTrue((new ProcessResult(['npm'], 137, '', '', 1.0))->isOutOfMemory());
        self::assertTrue((new ProcessResult(['npm'], 1, '', "FATAL ERROR: Reached heap limit Allocation failed - JavaScript heap out of memory\n", 1.0))->isOutOfMemory());
        self::assertTrue((new ProcessResult(['composer'], 1, "Killed\n", '', 1.0))->isOutOfMemory());
        self::assertFalse((new ProcessResult(['npm'], 1, 'Killed the dragon', '', 1.0))->isOutOfMemory());
        self::assertFalse((new ProcessResult(['npm'], 0, '', '', 1.0))->isOutOfMemory());

        $shell = $this->services()->shell();
        try {
            $shell->mustRun(['sh', '-c', 'exit 137'], new RunOptions(label: 'npm run build'), ErrorCode::NODE_BUILD, 'npm run build failed');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::OOM, $e->errorCode);
        }
    }

    /**
     * @covers-req SH-07
     */
    public function testDiskFullDetection(): void
    {
        self::assertTrue((new ProcessResult(['tar'], 2, '', "tar: write error: No space left on device\n", 1.0))->isDiskFull());
        self::assertTrue((new ProcessResult(['cp'], 1, '', "cp: Disk quota exceeded\n", 1.0))->isDiskFull());
        self::assertFalse((new ProcessResult(['cp'], 0, 'No space left on device', '', 1.0))->isDiskFull());
    }

    public function testOtherFailuresUseTheGivenCode(): void
    {
        try {
            $this->services()->shell()->mustRun(['false'], new RunOptions(), ErrorCode::ARTISAN, 'php artisan optimize failed');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::ARTISAN, $e->errorCode);
            self::assertSame(4, $e->exitCode());
        }
    }

    /**
     * @covers-req LCK-04
     */
    public function testCancelStopsTheRunningCommand(): void
    {
        $services = $this->services();
        $services->signals()->request();
        $result = $services->shell()->withKillGrace(0.5)->run(['sleep', '5'], new RunOptions(timeout: 10));

        self::assertTrue($result->cancelled);
        self::assertFalse($result->successful());
    }

    public function testCancelIsDeferredInsideACriticalSection(): void
    {
        $services = $this->services();
        $signals = $services->signals();
        $result = $signals->critical(static function () use ($signals, $services) {
            $signals->request();

            return $services->shell()->run(['sh', '-c', 'sleep 0.3; echo done']);
        });

        self::assertTrue($result->successful());
        self::assertSame("done\n", $result->stdout);
        self::assertTrue($signals->cancelRequested(), 'Reported once the section ends');
    }

    public function testQuote(): void
    {
        self::assertSame('abc/def-1.2', Shell::quote('abc/def-1.2'));
        self::assertSame("''", Shell::quote(''));
        self::assertSame("'a b'", Shell::quote('a b'));
        self::assertSame("'it'\\''s'", Shell::quote("it's"));
        self::assertSame("'\$HOME'", Shell::quote('$HOME'));
    }

    public function testWhichFindsFakeBinariesFirst(): void
    {
        $fake = $this->fakeBin('git', "#!/bin/sh\necho fake\n");

        self::assertSame($fake, $this->services()->shell()->which('git'));
        self::assertNull($this->services()->shell()->which('definitely-not-a-program-xyz'));
    }
}
