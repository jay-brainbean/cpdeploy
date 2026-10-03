<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Docroot\HandlerBlock;
use Cpdeploy\Docroot\LaravelRewrites;
use Cpdeploy\Tests\Support\DeployScenario;

/**
 * First deploys (S-01, S-02, S-03).
 */
final class DeployFirstTest extends DeployScenario
{
    /**
     * S-01: first deploy of a Laravel app into an empty addon docroot.
     *
     * @covers-req DOC-02
     * @covers-req REL-03
     * @covers-req REL-04
     * @covers-req LAR-04
     * @covers-req CMP-02
     * @covers-req BLD-01
     * @covers-req BLD-04
     * @covers-req HC-01
     * @covers-req HC-02
     * @covers-req HC-04
     * @covers-req LAY-01
     * @covers-req NODE-10
     * @covers-req NODE-12
     */
    public function testFirstDeployIntoAnEmptyAddonDocroot(): void
    {
        $r = $this->deploy(['--yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        $releases = $this->releases();
        self::assertCount(1, $releases);
        $id = (string) array_key_first($releases);
        $release = $releases[$id];
        self::assertSame('live', $release['status']);
        self::assertSame('releases/' . $id, $this->current());
        $live = $this->liveDir();

        // Docroot: a relative symlink to current/public.
        self::assertTrue(is_link($this->docroot));
        self::assertSame('cpdeploy/sites/shop/current/public', readlink($this->docroot));
        self::assertNotNull($this->site()['domain']['converted_at']);

        // Shared skeleton and links.
        self::assertSame('../../shared/.env', readlink($live . '/.env'));
        self::assertSame('../../../shared/storage/app', readlink($live . '/storage/app'));
        self::assertDirectoryExists($this->siteDir . '/shared/storage/app/public');
        self::assertDirectoryExists($this->siteDir . '/shared/storage/framework/sessions');
        self::assertDirectoryExists($live . '/storage/framework/views');
        self::assertFalse(is_link($live . '/storage/framework/views'));
        self::assertTrue(is_link($live . '/public/.well-known'));

        // Composer ran with the site PHP (via the shim for @php artisan).
        $installed = json_decode((string) file_get_contents($live . '/vendor/composer/installed.json'), true);
        self::assertSame('ea-php82', $installed['installed_by']);
        $calls = $this->artisanCalls();
        $discover = array_values(array_filter($calls, static fn ($c) => $c['cmd'] === 'package:discover'));
        self::assertSame('ea-php82', $discover[0]['php']);

        // Frontend build: outputs present, node_modules removed, production .env visible.
        self::assertFileExists($live . '/public/build/manifest.json');
        self::assertDirectoryDoesNotExist($live . '/node_modules');
        self::assertStringContainsString('env=yes', (string) file_get_contents($live . '/public/build/info.txt'));
        self::assertStringContainsString('nodeenv=unset ci=unset', (string) file_get_contents($live . '/public/build/info.txt'));

        // storage:link, optimize, migrations.
        self::assertSame('../storage/app/public', readlink($live . '/public/storage'));
        self::assertStringContainsString($id, (string) file_get_contents($live . '/bootstrap/cache/config.php'));
        self::assertSame(['2026_01_01_000000_create_users_table'], json_decode((string) file_get_contents($this->siteDir . '/shared/storage/app/.fake-db.json'), true));
        self::assertSame(['2026_01_01_000000_create_users_table'], $release['migrations']['list']);

        // Health check and marker cleanup.
        self::assertSame([], glob($live . '/public/.cpd-release-*') ?: []);
        $history = $this->history();
        self::assertCount(1, $history);
        self::assertSame('success', $history[0]['result']);
        self::assertSame($id, $history[0]['release']);
        self::assertMatchesRegularExpression('/^health: 200 in \d+\.\ds$/m', implode("\n", $history[0]['notes']));
        self::assertStringContainsString('Live in', $r['stdout']);

        // Secrets never reach the log (LOG-04).
        self::assertStringNotContainsString('supersecretpassword', $this->lastLog());
        self::assertStringContainsString('shop ok ' . $id, (string) @file_get_contents('http://127.0.0.1:' . $this->web?->port . '/'));
    }

    /**
     * S-02: first deploy into a non-empty public_html (no other domains inside).
     *
     * @covers-req DOC-02
     * @covers-req DOC-04
     * @covers-req REL-05
     * @covers-req SEC-12
     */
    public function testFirstDeployIntoANonEmptyPublicHtml(): void
    {
        $this->docroot = $this->home . '/public_html';
        $this->domainsFixture(siteDocroot: $this->docroot);
        $this->writeSite();
        $this->startWeb();
        mkdir($this->docroot . '/.well-known/acme-challenge', 0755, true);
        file_put_contents($this->docroot . '/.well-known/acme-challenge/token', 'acme');
        file_put_contents($this->docroot . '/.user.ini', "memory_limit = 512M\n");
        file_put_contents($this->docroot . '/index.html', 'old site');
        file_put_contents($this->docroot . '/.htaccess', HandlerBlock::BEGIN . "\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n</IfModule>\n" . HandlerBlock::END . "\n\nRedirect 301 /old /new\n");

        $r = $this->deploy(['--yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        self::assertSame('cpdeploy/sites/shop/current/public', readlink($this->docroot));
        $backups = glob($this->siteDir . '/backups/docroot-*') ?: [];
        self::assertCount(1, $backups);
        self::assertSame('old site', file_get_contents($backups[0] . '/index.html'));
        self::assertSame(0700, fileperms($this->siteDir . '/backups') & 0777);
        self::assertSame('backups/' . basename($backups[0]), $this->site()['domain']['backup']);

        // .well-known and .user.ini moved to shared and linked into the release.
        self::assertSame('acme', file_get_contents($this->siteDir . '/shared/docroot/.well-known/acme-challenge/token'));
        self::assertSame('acme', file_get_contents($this->docroot . '/.well-known/acme-challenge/token'));
        self::assertTrue(is_link($this->liveDir() . '/public/.user.ini'));

        // Handler block captured and injected at the top of the release's .htaccess.
        self::assertStringContainsString('x-httpd-ea-php82', (string) file_get_contents($this->siteDir . '/shared/php-handler.block'));
        $htaccess = (string) file_get_contents($this->liveDir() . '/public/.htaccess');
        self::assertStringStartsWith(HandlerBlock::BEGIN, $htaccess);
        self::assertStringContainsString('RewriteEngine On', $htaccess);
        self::assertStringContainsString('extra rules', $r['stdout']);
    }

    /**
     * A repo without public/.htaccess: the release gets Laravel's rewrite rules
     * (below the handler block), the deploy warns, and the next deploy doesn't
     * take the added rules for a manual edit (PRE-17).
     *
     * @covers-req DOC-04
     * @covers-req DOC-05
     */
    public function testLaravelRewriteRulesAreAddedWhenTheRepoHasNoHtaccess(): void
    {
        $this->docroot = $this->home . '/public_html';
        $this->domainsFixture(siteDocroot: $this->docroot);
        $this->writeSite();
        $this->startWeb();
        mkdir($this->docroot, 0755, true);
        file_put_contents($this->docroot . '/.htaccess', HandlerBlock::BEGIN . "\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n</IfModule>\n" . HandlerBlock::END . "\n");
        $this->repo->delete('public/.htaccess');
        $this->repo->commit('No public/.htaccess');
        $this->repo->push();

        $r = $this->deploy(['--yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        $htaccess = (string) file_get_contents($this->liveDir() . '/public/.htaccess');
        self::assertStringStartsWith(HandlerBlock::BEGIN, $htaccess);
        self::assertStringContainsString(LaravelRewrites::BEGIN, $htaccess);
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        self::assertStringContainsString('public/.htaccess is missing from your repo', $r['stdout']);

        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        self::assertStringNotContainsString('changed outside git', $r['stdout'] . $this->lastLog());
        self::assertStringContainsString('RewriteRule ^ index.php [L]', (string) file_get_contents($this->liveDir() . '/public/.htaccess'));
    }

    /**
     * S-03: public_html holds another domain's folder → blocked, nothing moved.
     *
     * @covers-req DOC-01
     * @covers-req PRE-16
     * @covers-req INV-09
     */
    public function testPublicHtmlWithAnotherDomainInsideIsRefused(): void
    {
        $this->docroot = $this->home . '/public_html';
        mkdir($this->docroot . '/blog', 0755, true);
        file_put_contents($this->docroot . '/index.html', 'old site');
        $this->domainsFixture([['blog.example.test', $this->docroot . '/blog']], $this->docroot);
        $this->writeSite();

        $r = $this->deploy(['--yes']);

        $this->assertExit(3, $r);
        self::assertStringContainsString('contains the folders of other domains: blog.example.test', $r['stderr']);
        self::assertStringContainsString('Live site: not changed', $r['stderr']);
        self::assertFalse(is_link($this->docroot));
        self::assertSame('old site', file_get_contents($this->docroot . '/index.html'));
        self::assertNull($this->current());
        self::assertSame([], $this->releases());
        self::assertFileDoesNotExist($this->siteDir . '/.deploy-state.json');
        self::assertSame('failed', $this->history()[0]['result']);
    }
}
