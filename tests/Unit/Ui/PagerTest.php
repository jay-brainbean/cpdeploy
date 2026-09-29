<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Ui;

use Cpdeploy\Tests\Support\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class PagerTest extends TestCase
{
    public function testWithoutATerminalOnlyTheLast200LinesAreShown(): void
    {
        $out = new BufferedOutput();
        $services = $this->services();
        $pager = $services->pager($out);
        $lines = array_map(static fn (int $i): string => "line {$i}", range(1, 250));

        $pager->show(implode("\n", $lines), false);

        $text = $out->fetch();
        self::assertStringContainsString('(showing the last 200 of 250 lines)', $text);
        self::assertStringNotContainsString("line 50\n", $text);
        self::assertStringContainsString("line 51\n", $text);
        self::assertStringContainsString('line 250', $text);
    }
}
