<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Docroot\HandlerBlock;
use Cpdeploy\Tests\Support\DeployScenario;

/**
 * Second deploys: Composer reuse and install, PHP changes, migrations
 * (S-04 … S-09, S-42).
 */
final class DeployUpdateTest extends DeployScenario
{
    private function firstDeploy(): string
    {
        $this->assertExit(0, $this->deploy(['--yes']));

        return basename((string) $this->current());
    }

    /**
     * @param list<array{call: string, args: array<string, string>, link?: ?string}> $calls
     * @return list<array{call: string, args: array<string, string>, link?: ?string}>
     */
    private static function setCalls(array $calls): array
    {
        return array_values(array_filter($calls, static fn ($c) => $c['call'] === 'LangPHP::php_set_vhost_versions'));
    }

    /**
     * S-04: lock unchanged, composer answered Skip → vendor/ copied (not
     * hardlinked) + dump-autoload.
     *
     * @covers-req CMP-05
     * @covers-req PLN-02
     * @covers-req PLN-06
     */
    public function testComposerSkipCopiesVendorAndDumpsTheAutoloader(): void
    {
        $first = $this->firstDeploy();
        $this->change(['routes/web.php' => "<?php // v2\n"], 'Change a route');

        $r = $this->deploy(['--composer=no', '--yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        $new = $this->liveDir();
        $old = $this->siteDir . '/releases/' . $first;
        self::assertNotSame($old, $new);
        self::assertFileExists($new . '/vendor/composer/dumped.txt');
        self::assertStringContainsString($new, (string) file_get_contents($new . '/vendor/composer/dumped.txt'));
        self::assertNotSame(fileinode($old . '/vendor/autoload.php'), fileinode($new . '/vendor/autoload.php'), 'vendor/ must be copied, not hardlinked');
        self::assertSame($first, $this->releases()[basename($new)]['composer']['reused_from']);
        self::assertContains('composer: reuse vendor/ (--composer=no)', $this->history()[1]['notes']);
    }

    /**
     * S-05: composer.lock changed → the default is to install.
     *
     * @covers-req PLN-02
     * @covers-req CHG-02
     */
    public function testLockChangedInstallsByDefault(): void
    {
        $this->firstDeploy();
        $lock = (string) file_get_contents($this->repo->work . '/composer.lock');
        $this->change(['composer.lock' => str_replace('3.9.0', '3.9.1', $lock)], 'Update monolog');

        $r = $this->deploy(['--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('composer: install (lock changed)', $r['stdout']);
        self::assertFileDoesNotExist($this->liveDir() . '/vendor/composer/dumped.txt');
        self::assertTrue($this->releases()[basename($this->liveDir())]['composer']['ran']);
    }

    /**
     * S-06: site PHP 8.2 → 8.3 (upgrade): Composer is reinstalled, MultiPHP is set
     * before the switch, the handler block is rewritten for 8.3.
     *
     * @covers-req GL-03
     * @covers-req PHP-06
     * @covers-req DOC-03
     * @covers-req DOC-04
     * @covers-req PLN-02
     */
    public function testPhpUpgradeSwitchesTheDomainBeforeTheCode(): void
    {
        $first = $this->firstDeploy();
        // cPanel's MultiPHP Manager wrote a handler block into the live .htaccess.
        $live = $this->siteDir . '/releases/' . $first . '/public/.htaccess';
        file_put_contents($live, HandlerBlock::BEGIN . "\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n</IfModule>\n" . HandlerBlock::END . "\n\n" . file_get_contents($live));
        $site = $this->site();
        $site['php']['version'] = '8.3';
        $this->writeSite($site);

        // Same commit, new PHP: a redeploy (what Manage site → PHP version does).
        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        $new = basename($this->liveDir());
        self::assertStringContainsString('composer: install (PHP changed 8.2 → 8.3)', $r['stdout']);
        $installed = json_decode((string) file_get_contents($this->liveDir() . '/vendor/composer/installed.json'), true);
        self::assertSame('ea-php83', $installed['installed_by']);
        $sets = self::setCalls($this->uapiCalls());
        self::assertCount(1, $sets);
        self::assertSame(['vhost' => self::DOMAIN, 'version' => 'ea-php83'], $sets[0]['args']);
        self::assertSame('releases/' . $first, $sets[0]['link'] ?? null, 'MultiPHP must change before the switch');
        self::assertStringContainsString('x-httpd-ea-php83 .php .php8', (string) file_get_contents($this->liveDir() . '/public/.htaccess'));
        self::assertSame('8.3.20', $this->releases()[$new]['php']['version']);
    }

    /**
     * S-07: PHP 8.3 → 8.2 (downgrade): MultiPHP is set after the switch.
     *
     * @covers-req GL-03
     */
    public function testPhpDowngradeSwitchesTheDomainAfterTheCode(): void
    {
        $this->vhostFixture('ea-php83');
        $site = $this->site();
        $site['php']['version'] = '8.3';
        $this->writeSite($site);
        $this->firstDeploy();
        $site['php']['version'] = '8.2';
        $this->writeSite($site);

        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        $sets = self::setCalls($this->uapiCalls());
        self::assertCount(1, $sets);
        self::assertSame('ea-php82', $sets[0]['args']['version']);
        self::assertSame($this->current(), $sets[0]['link'] ?? null, 'MultiPHP must change after the switch');
    }

    /**
     * S-08: new migrations answered Yes → down in L, migrate in N, switch, up in N;
     * L stays down.
     *
     * @covers-req GL-01
     * @covers-req GL-02
     * @covers-req LAR-05
     * @covers-req PLN-03
     * @covers-req PHP-07
     */
    public function testMigrationsRunWithMaintenanceOnTheLiveRelease(): void
    {
        $first = $this->firstDeploy();
        $this->change(['database/migrations/2026_09_28_000000_add_coupons_table.php' => "<?php\n"], 'Coupons');

        $r = $this->deploy(['--migrate=yes', '--yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        $new = basename($this->liveDir());
        $calls = array_values(array_filter(
            $this->artisanCalls(),
            static fn ($c) => in_array($c['cmd'], ['down', 'up', 'migrate'], true),
        ));
        $sequence = array_map(static fn ($c) => $c['cmd'] . '@' . $c['release'], $calls);
        self::assertSame(['migrate@' . $first, 'down@' . $first, 'migrate@' . $new, 'up@' . $new], $sequence);
        self::assertSame('ea-php82', $calls[1]['php'], 'down runs with the live release\'s PHP');
        self::assertFileExists($this->siteDir . '/releases/' . $first . '/storage/framework/down', 'L stays in maintenance (GL-02)');
        self::assertFileDoesNotExist($this->liveDir() . '/storage/framework/down');
        self::assertSame(['2026_09_28_000000_add_coupons_table'], $this->releases()[$new]['migrations']['list']);
        self::assertStringContainsString('Maintenance mode was on for', $this->lastLog());
    }

    /**
     * S-09: a migration fails → L is back up, N failed, current unchanged, exit 5.
     *
     * @covers-req GL-01
     * @covers-req INV-09
     */
    public function testAFailedMigrationBringsTheLiveReleaseBack(): void
    {
        $first = $this->firstDeploy();
        $this->change([
            'database/migrations/2026_09_28_000000_add_coupons_table.php' => "<?php\n",
            'database/migrations/2026_09_28_000001_add_discount_to_orders.php' => "<?php // FAKE_FAIL\n",
        ], 'Coupons and discounts');

        $r = $this->deploy(['--migrate=yes', '--yes']);

        $this->assertExit(5, $r);
        self::assertSame('releases/' . $first, $this->current());
        self::assertFileDoesNotExist($this->siteDir . '/releases/' . $first . '/storage/framework/down');
        $failed = array_filter($this->releases(), static fn ($r) => ($r['status'] ?? '') === 'failed');
        self::assertCount(1, $failed);
        self::assertStringContainsString('the site is back up on the previous release', $r['stderr']);
        self::assertStringContainsString('These migrations completed before the failure: 2026_09_28_000000_add_coupons_table', $r['stderr']);
        self::assertStringContainsString('Live site: affected', $r['stderr']);
        self::assertFileDoesNotExist($this->siteDir . '/.deploy-state.json');
        self::assertSame('failed', $this->history()[1]['result']);
        self::assertSame(5, $this->history()[1]['exit_code']);
    }

    /**
     * S-42: the live release has no vendor/ → install is forced, with the reason.
     *
     * @covers-req CMP-05
     * @covers-req PLN-02
     */
    public function testComposerSkipIsImpossibleWithoutVendor(): void
    {
        $first = $this->firstDeploy();
        exec('rm -rf ' . escapeshellarg($this->siteDir . '/releases/' . $first . '/vendor'));
        $this->change(['routes/web.php' => "<?php // v2\n"], 'Change a route');

        $refused = $this->deploy(['--composer=no', '--yes']);
        $this->assertExit(2, $refused);
        self::assertStringContainsString('no vendor to reuse', $refused['stderr']);

        $r = $this->deploy(['--yes']);
        $this->assertExit(0, $r);
        self::assertStringContainsString('composer: install (no vendor to reuse)', $r['stdout']);
        self::assertFileExists($this->liveDir() . '/vendor/autoload.php');
    }
}
