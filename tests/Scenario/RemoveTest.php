<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Menus\MainMenu;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Tests\Support\DeployScenario;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\ScriptedAsker;
use Cpdeploy\Ui\Theme;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * *Remove site* (§9.5.14): S-33 (detach), S-34 (restore the old folder), the
 * empty folder, a site that never went live, and the menu path.
 */
final class RemoveTest extends DeployScenario
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->env['CPDEPLOY_NOW'] = '2026-09-29T12:00:00Z';
        mkdir($this->home . '/.ssh', 0700, true);
        file_put_contents($this->home . '/.ssh/cpdeploy_shop', "private\n");
        file_put_contents($this->home . '/.ssh/cpdeploy_shop.pub', "ssh-ed25519 AAAA cpdeploy:shop@test\n");
    }

    /**
     * S-33: the live release is copied to ~/shop-app with real files, the domain
     * points there and keeps working; the key goes; shared is kept in removed/.
     *
     * @covers-req RM-01
     * @covers-req DOC-07
     */
    public function testDetachKeepsTheSiteRunningFromAPlainFolder(): void
    {
        $this->assertExit(0, $this->deploy());
        $before = $this->get('/');

        $r = $this->runCli(['remove', self::SITE, '--detach', '--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('Site shop removed', $r['stdout']);
        $app = $this->home . '/shop-app';
        self::assertDirectoryExists($app);
        self::assertSame('shop-app/public', readlink($this->docroot), 'a relative link to the copy');
        self::assertFileExists($app . '/.env');
        self::assertFalse(is_link($app . '/.env'), '.env is a real file');
        self::assertStringContainsString('APP_NAME=Shop', (string) file_get_contents($app . '/.env'));
        self::assertSame(0600, fileperms($app . '/.env') & 0777);
        self::assertFalse(is_link($app . '/storage'), 'storage is a real folder');
        self::assertDirectoryExists($app . '/storage/framework');
        self::assertFileDoesNotExist($app . '/.release.json');
        $this->assertNoLinksInto($app, $this->siteDir);
        self::assertStringStartsWith('shop ok', $before);
        self::assertSame('shop ok shop-app', $this->get('/'), 'the site still answers, from the copy');

        // The key (by hand: no token), shared kept, the site folder gone.
        self::assertFileDoesNotExist($this->home . '/.ssh/cpdeploy_shop');
        self::assertStringContainsString('https://github.com/acme/shop/settings/keys', $r['stdout']);
        $kept = glob($this->root . '/removed/shop-*') ?: [];
        self::assertCount(1, $kept);
        self::assertFileExists($kept[0] . '/shared/.env');
        self::assertFileExists($kept[0] . '/site.yml');
        self::assertDirectoryDoesNotExist($this->siteDir);
        $history = array_map(static fn (string $l): array => (array) json_decode($l, true), file($this->root . '/removed/history.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame('shop', $history[0]['site']);
        self::assertSame('detach', $history[0]['docroot']);

        $status = $this->runCli(['status', '--json']);
        self::assertSame([], json_decode($status['stdout'], true)['sites']);
    }

    /**
     * S-34: the folder from before cpdeploy comes back.
     *
     * @covers-req DOC-07
     */
    public function testRestoreBackupPutsTheOriginalFolderBack(): void
    {
        mkdir($this->docroot, 0755, true);
        file_put_contents($this->docroot . '/index.html', 'the old site');
        $this->assertExit(0, $this->deploy());
        self::assertTrue(is_link($this->docroot));

        $r = $this->runCli(['remove', self::SITE, '--restore-backup', '--delete-shared', '--yes']);

        $this->assertExit(0, $r);
        self::assertFalse(is_link($this->docroot));
        self::assertSame('the old site', file_get_contents($this->docroot . '/index.html'));
        self::assertSame([], glob($this->root . '/removed/shop-*') ?: [], '--delete-shared keeps nothing');
        self::assertDirectoryDoesNotExist($this->siteDir);
    }

    /**
     * @covers-req DOC-07
     * @covers-req RM-03
     */
    public function testEmptyFolderAndNeverLiveSite(): void
    {
        $this->assertExit(0, $this->deploy());
        @mkdir($this->siteDir . '/shared/docroot/.well-known', 0755, true);
        file_put_contents($this->siteDir . '/shared/docroot/.well-known/security.txt', 'contact');

        // Without a terminal, the docroot choice and --yes are needed.
        $ask = $this->runCli(['remove', self::SITE]);
        $this->assertExit(2, $ask);
        self::assertStringContainsString('--detach', $ask['stderr']);
        self::assertStringContainsString('--empty', $ask['stderr']);
        self::assertStringNotContainsString('--restore-backup', $ask['stderr'], 'there is no backup to restore');
        self::assertStringContainsString('What should happen to ' . self::DOMAIN, $ask['stderr']);
        $confirm = $this->runCli(['remove', self::SITE, '--empty']);
        $this->assertExit(2, $confirm);
        self::assertStringContainsString('--yes', $confirm['stderr']);
        self::assertDirectoryExists($this->siteDir, 'nothing was removed');

        $r = $this->runCli(['remove', self::SITE, '--empty', '--keep-key', '--yes']);
        $this->assertExit(0, $r);
        self::assertFalse(is_link($this->docroot));
        self::assertSame(['.well-known'], array_values(array_diff(scandir($this->docroot) ?: [], ['.', '..'])));
        self::assertFileExists($this->home . '/.ssh/cpdeploy_shop', '--keep-key');

        // A site that never went live: its folder is not touched.
        $this->writeSite();
        mkdir($this->docroot . '/keep', 0755);
        $never = $this->runCli(['remove', self::SITE, '--yes']);
        $this->assertExit(0, $never);
        self::assertStringContainsString('never went live', $never['stdout']);
        self::assertDirectoryExists($this->docroot . '/keep');
        self::assertDirectoryDoesNotExist($this->siteDir);
    }

    /**
     * The menu path: Manage site → Remove site…, the typed confirmation, and
     * back to the main menu.
     *
     * @covers-req RM-01
     */
    public function testRemoveFromTheMenu(): void
    {
        $this->assertExit(0, $this->deploy());
        $asker = new ScriptedAsker([
            ['What would you like to do?', 'manage'],
            ['Manage shop', 'remove'],
            ['What should happen to', 'detach'],
            ['Also remove', ['key']],
            ['Type the site name to confirm', 'shop'],
            ['What would you like to do?', 'quit'],
        ]);
        $output = new BufferedOutput();
        $services = $this->services();
        $theme = new Theme(true);
        (new MainMenu(new MenuContext($services, $asker, $output, new PlainReporter($output, $theme, $services->clock()), $theme)))->run();
        $screen = $output->fetch();

        self::assertSame(0, $asker->remaining(), $screen);
        self::assertStringContainsString('cpdeploy · shop · Remove site', $screen);
        self::assertStringContainsString('Site shop removed', $screen);
        self::assertDirectoryDoesNotExist($this->siteDir);
        self::assertSame('shop-app/public', readlink($this->docroot));

        // A wrong name cancels.
        $this->writeSite();
        $asker = new ScriptedAsker([
            ['What would you like to do?', 'manage'],
            ['Manage shop', 'remove'],
            ['Also remove', []],
            ['Type the site name to confirm', 'shpo'],
            ['Manage shop', MenuContext::BACK],
            ['What would you like to do?', 'quit'],
        ]);
        (new MainMenu(new MenuContext($services, $asker, $output, new PlainReporter($output, $theme, $services->clock()), $theme)))->run();
        self::assertStringContainsString('Not removed.', $output->fetch());
        self::assertDirectoryExists($this->siteDir);
    }

    private function get(string $path): string
    {
        return (string) @file_get_contents('http://' . $this->env['CPDEPLOY_HTTP_OVERRIDE'] . $path);
    }

    private function assertNoLinksInto(string $dir, string $forbidden): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $path => $info) {
            if (is_link((string) $path)) {
                $target = realpath((string) $path);
                self::assertTrue($target === false || !str_starts_with($target, $forbidden . '/'), "{$path} still points into {$forbidden}");
            }
        }
    }
}
