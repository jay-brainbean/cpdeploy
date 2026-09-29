<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Ui;

use Cpdeploy\Support\Clock;
use Cpdeploy\Ui\MemoryReporter;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\TaskReporter;
use Cpdeploy\Ui\Theme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ReporterTest extends TestCase
{
    /**
     * @covers-req UI-08
     */
    public function testPlainReporterPrintsOneLinePerStep(): void
    {
        $out = new BufferedOutput();
        $clock = new Clock(new \DateTimeImmutable('2026-09-29T03:05:10Z'));
        $r = new PlainReporter($out, new Theme(true), $clock);
        $r->start('Composer');
        $r->line('Installing dependencies');
        $r->succeed();
        $r->warn('APP_DEBUG is true');

        $lines = explode("\n", trim($out->fetch()));
        self::assertCount(2, $lines);
        self::assertMatchesRegularExpression('/^\[\d\d:\d\d:\d\d\] ✓ Composer \(0s\)$/', $lines[0]);
        self::assertStringContainsString('⚠ APP_DEBUG is true', $lines[1]);
    }

    /**
     * @covers-req UI-09
     */
    public function testFailureShowsTheLast15LinesAndTheLog(): void
    {
        $out = new BufferedOutput();
        $r = new PlainReporter($out, new Theme(false), new Clock());
        $r->start('Frontend build');
        for ($i = 1; $i <= 20; $i++) {
            $r->line("line {$i}");
        }
        $r->fail('npm run build failed', '/logs/x.log');

        $text = $out->fetch();
        self::assertStringContainsString('[x] Frontend build', $text);
        self::assertStringNotContainsString('line 5' . "\n", $text);
        self::assertStringContainsString('line 6', $text);
        self::assertStringContainsString('line 20', $text);
        self::assertStringContainsString('Log: /logs/x.log', $text);
    }

    public function testPlainReporterShowsOutputOnlyWhenVerbose(): void
    {
        $out = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $r = new PlainReporter($out, new Theme(true), new Clock());
        $r->start('Composer');
        $r->line('Installing <b>');
        $r->succeed();

        self::assertStringContainsString('Installing <b>', $out->fetch());
    }

    /**
     * @covers-req ARC-01
     */
    public function testTaskReporterCollapsesTheStep(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        // A console output writing to memory, so sections can be inspected.
        $sectioned = new class ($stream) extends ConsoleOutput {
            /** @param resource $stream */
            public function __construct(private $stream)
            {
                parent::__construct(OutputInterface::VERBOSITY_NORMAL, false);
            }

            public function getStream()
            {
                return $this->stream;
            }
        };
        $r = new TaskReporter($sectioned, new Theme(true));
        $r->start('Composer');
        $r->line('Installing laravel/framework');
        $r->warn('abandoned package');
        $r->succeed('42 packages');

        rewind($stream);
        $text = (string) stream_get_contents($stream);
        self::assertStringContainsString('✓ Composer', $text);
        self::assertStringContainsString('42 packages', $text);
        self::assertStringContainsString('⚠ abandoned package', $text);
    }

    public function testMemoryReporterRecordsEvents(): void
    {
        $r = new MemoryReporter();
        $r->start('Export');
        $r->line('x');
        $r->succeed('done');
        $r->fail('boom');

        self::assertSame(['Export'], $r->of('start'));
        self::assertSame(['boom'], $r->of('fail'));
        self::assertStringContainsString('done', $r->allText());
    }
}
