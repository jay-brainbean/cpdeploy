<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Wizard;

use Cpdeploy\Wizard\LegacyImporter;
use PHPUnit\Framework\TestCase;

/**
 * LEG-02: reading deploy.conf.
 */
final class LegacyImporterTest extends TestCase
{
    /**
     * @covers-req LEG-02
     */
    public function testParseConf(): void
    {
        $conf = LegacyImporter::parseConf(implode("\n", [
            '# written by cpanel-git-setup.sh',
            'BRANCH="main"',
            "DEST='/home/u/public_html'",
            'SUBDIR=',
            'BUILD_CMD="npm ci && npm run build"',
            'export PHP_VERSION=8.2   # the domain PHP',
            'NODE_VERSION=auto',
            'POST_CMD="echo \"done\""',
            'not a line',
            '',
        ]));

        self::assertSame([
            'BRANCH' => 'main',
            'DEST' => '/home/u/public_html',
            'SUBDIR' => '',
            'BUILD_CMD' => 'npm ci && npm run build',
            'PHP_VERSION' => '8.2',
            'NODE_VERSION' => 'auto',
            'POST_CMD' => 'echo "done"',
        ], $conf);
    }
}
