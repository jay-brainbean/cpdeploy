<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\DeployScenario;

/**
 * Changes made on the live site outside git (§7.14): .htaccess drift (DOC-05,
 * PRE-17), live-files drift (DOC-06, PRE-18), and docroot files replaced by
 * cPanel (REL-06). S-27, S-28.
 */
final class DriftTest extends DeployScenario
{
    private function firstDeploy(): string
    {
        $this->assertExit(0, $this->deploy(['--yes']));

        return $this->liveDir();
    }

    /**
     * S-27: the live .htaccess was edited outside git → a warning; the
     * non-interactive log contains it and the difference.
     *
     * @covers-req DOC-05
     * @covers-req PRE-17
     */
    public function testHtaccessEditedOutsideGitIsAWarning(): void
    {
        $live = $this->firstDeploy();
        file_put_contents($live . '/public/.htaccess', "Redirect 301 /old /new\n", FILE_APPEND);

        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('public/.htaccess on the live site was changed outside git', $r['stdout']);
        $log = $this->lastLog();
        self::assertStringContainsString('PRE-17 public/.htaccess differs from git', $log);
        self::assertStringContainsString('+ Redirect 301 /old /new', $log);
        self::assertStringNotContainsString('Files changed directly on the server', $r['stdout'], '.htaccess is reported once, by DOC-05');
        self::assertSame('warning', $this->history()[1]['result']);
    }

    /**
     * PRE-17 on a terminal: Show diff, then Cancel (nothing changes).
     *
     * @covers-req DOC-05
     */
    public function testHtaccessDriftOnATerminalShowsTheDiffAndCanCancel(): void
    {
        $live = $this->firstDeploy();
        file_put_contents($live . '/public/.htaccess', "Redirect 301 /old /new\n", FILE_APPEND);
        $this->env['CPDEPLOY_TEST_ANSWERS'] = (string) json_encode([
            ['was changed on the live site', 'diff'],
            ['was changed on the live site', 'cancel'],
        ]);

        $r = $this->deploy(['--force']);

        $this->assertExit(130, $r);
        self::assertStringContainsString('+ Redirect 301 /old /new', $r['stdout']);
        self::assertSame($live, $this->liveDir());
        self::assertCount(1, $this->releases());
    }

    /**
     * No drift, no warning: the manifest matches what B10 wrote even after
     * go-live (markers, caches and maintenance files are excluded).
     *
     * @covers-req DOC-06
     */
    public function testAnUntouchedReleaseHasNoDrift(): void
    {
        $live = $this->firstDeploy();
        self::assertFileExists($live . '/.release-manifest');
        $manifest = (string) file_get_contents($live . '/.release-manifest');
        self::assertStringContainsString('  routes/web.php', $manifest);
        self::assertStringNotContainsString('vendor/', $manifest);
        self::assertStringNotContainsString('.cpd-release-', $manifest);

        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        self::assertStringNotContainsString('changed', $r['stdout']);
        self::assertSame('success', $this->history()[1]['result']);
    }

    /**
     * PRE-18: files changed or added in the live release are listed (warning only).
     *
     * @covers-req DOC-06
     * @covers-req PRE-18
     */
    public function testLiveFilesChangedOnTheServerAreListed(): void
    {
        $live = $this->firstDeploy();
        file_put_contents($live . '/routes/web.php', "<?php // hot fix on the server\n");
        mkdir($live . '/app', 0755, true);
        file_put_contents($live . '/app/Hack.php', "<?php\n");
        file_put_contents($live . '/storage/framework/views/compiled.php', "<?php\n");

        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('Files changed directly on the server since the last deploy (they will not be in the new release): routes/web.php, app/Hack.php (new)', $r['stdout']);
        self::assertStringNotContainsString('compiled.php', $r['stdout']);
        self::assertSame('warning', $this->history()[1]['result']);
    }

    /**
     * S-28: cPanel replaced the linked .user.ini with a real file in the live
     * release → REL-06 copies it to shared before the build, and the new release
     * links it.
     *
     * @covers-req REL-06
     * @covers-req PRE-21
     */
    public function testUserIniReplacedByCpanelGoesToShared(): void
    {
        $live = $this->firstDeploy();
        file_put_contents($live . '/public/.user.ini', "memory_limit = 512M\n");

        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        $shared = $this->siteFiles . '/shared/docroot/.user.ini';
        self::assertSame("memory_limit = 512M\n", @file_get_contents($shared));
        $link = $this->liveDir() . '/public/.user.ini';
        self::assertTrue(is_link($link), 'the new release links .user.ini');
        self::assertSame(realpath($shared), realpath($link));
        self::assertContains('docroot: .user.ini copied from the live release into shared (it was replaced by a real file, e.g. by cPanel)', $this->history()[1]['notes']);
        self::assertStringNotContainsString('Files changed directly on the server', $r['stdout']);
    }
}
