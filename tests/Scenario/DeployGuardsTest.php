<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\DeployScenario;

/**
 * Guards and bookkeeping: S-16, S-17, S-18, S-24, S-36, S-41, and the status,
 * releases and config commands.
 */
final class DeployGuardsTest extends DeployScenario
{
    /**
     * S-16: the same commit without --force → "nothing to do", exit 0, no release.
     *
     * @covers-req PRE-05
     */
    public function testSameCommitIsNothingToDo(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));

        $r = $this->deploy(['--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('is already live', $r['stdout']);
        self::assertCount(1, $this->releases());
        self::assertCount(1, $this->history());
        self::assertFileDoesNotExist($this->siteDir . '/.deploy-state.json');
    }

    /**
     * S-17: rewritten history without --allow-rewind, non-interactive → exit 3.
     *
     * @covers-req PRE-06
     * @covers-req GIT-12
     */
    public function testARewindIsBlockedWithoutAllowRewind(): void
    {
        $this->change(['routes/web.php' => "<?php // v2\n"], 'Second commit');
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->repo->git('reset', '-q', '--hard', 'HEAD~1');
        $this->repo->write('routes/web.php', "<?php // v2 rewritten\n");
        $this->repo->commit('Rewritten');
        $this->repo->push(force: true);

        $r = $this->deploy(['--yes']);
        $this->assertExit(3, $r);
        self::assertStringContainsString('main was rewritten: 1 live commit is not in the new history, 1 new commit added', $r['stderr']);
        self::assertStringContainsString('--allow-rewind', $r['stderr']);

        $this->assertExit(0, $this->deploy(['--allow-rewind', '--yes']));
    }

    /**
     * S-18: a second deploy of the same site while one runs → exit 10.
     *
     * @covers-req LCK-01
     * @covers-req ENG-02
     */
    public function testConcurrentDeploysAreRefused(): void
    {
        $lock = fopen($this->siteDir . '/.lock', 'c+');
        self::assertIsResource($lock);
        flock($lock, LOCK_EX);
        fwrite($lock, (string) json_encode(['pid' => 4121, 'action' => 'deploy', 'started_at' => '2026-09-29T03:02:00Z']));
        fflush($lock);

        $r = $this->deploy(['--yes']);

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertExit(10, $r);
        self::assertStringContainsString('Another cpdeploy operation (deploy, started', $r['stderr']);
        self::assertStringContainsString('PID 4121', $r['stderr']);
        self::assertSame([], $this->releases());
    }

    /**
     * S-36: a non-interactive deploy that needs answers, without flags → exit 2
     * naming every flag.
     *
     * @covers-req NI-02
     * @covers-req NI-03
     * @covers-req PLN-01
     */
    public function testNonInteractiveDeployNamesTheMissingFlags(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $lock = (string) file_get_contents($this->repo->work . '/composer.lock');
        $this->change([
            'composer.lock' => str_replace('3.9.0', '3.9.1', $lock),
            'database/migrations/2026_09_28_000000_add_coupons_table.php' => "<?php\n",
        ], 'Lock and migration');

        $r = $this->deploy([]);
        $this->assertExit(2, $r);
        self::assertStringContainsString('This needs answers: --composer=yes|no --migrate=yes|no (or --yes for the defaults)', $r['stderr']);
        self::assertCount(1, $this->releases());

        $flags = $this->deploy(['--composer=yes', '--migrate=no']);
        $this->assertExit(2, $flags);
        self::assertStringContainsString('--yes (to confirm the deploy)', $flags['stderr']);

        $this->assertExit(0, $this->deploy(['--composer=yes', '--migrate=no', '--yes']));
    }

    /**
     * S-41: PHP-FPM keeps serving L after the switch (the web fake serves L) → the
     * release marker doesn't match: a warning only.
     *
     * @covers-req HC-01
     * @covers-req SEC-09
     */
    public function testServingTheOldReleaseIsOnlyAWarning(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->startWeb((string) realpath($this->docroot));

        $r = $this->deploy(['--force', '--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('The web server is not serving the new release', $r['stdout']);
        self::assertSame('warning', $this->history()[1]['result']);
        self::assertSame([], glob($this->liveDir() . '/public/.cpd-release-*') ?: []);
    }

    /**
     * S-24: prune with keep=2, one protected release and one failed one.
     *
     * @covers-req PR-01
     * @covers-req FS-03
     * @covers-req INV-02
     * @covers-req INV-06
     */
    public function testPruneKeepsLiveProtectedAndNewest(): void
    {
        $this->writeSite(['releases' => ['keep' => 2]]);
        mkdir($this->siteFiles . '/shared/storage/app', 0755, true);
        file_put_contents($this->siteFiles . '/shared/storage/app/sentinel', 'keep me');

        $this->assertExit(0, $this->deploy(['--yes']));                 // A
        $a = basename($this->liveDir());
        sleep(1);
        $this->assertExit(0, $this->deploy(['--force', '--yes']));      // B
        $b = basename($this->liveDir());
        $this->assertExit(0, $this->runCli(['releases', self::SITE, 'protect', $a]));
        $this->env['CPD_FAKE_NPM'] = 'fail';
        $this->assertExit(4, $this->deploy(['--force', '--yes']));      // C (failed)
        unset($this->env['CPD_FAKE_NPM']);
        sleep(1);
        $this->assertExit(0, $this->deploy(['--force', '--yes']));      // D
        $d = basename($this->liveDir());
        sleep(1);
        $r = $this->deploy(['--force', '--yes']);                       // E
        $this->assertExit(0, $r);
        $e = basename($this->liveDir());

        self::assertSame([$a, $d, $e], array_keys($this->releases()));
        self::assertNotContains($b, array_keys($this->releases()));
        self::assertTrue($this->releases()[$a]['protected']);
        self::assertSame('keep me', file_get_contents($this->siteFiles . '/shared/storage/app/sentinel'));
        self::assertStringContainsString('kept 3 releases, removed 1', $r['stdout']);
    }

    /**
     * status / releases --json (§10.5) and config get / set.
     *
     * @covers-req CLI-03
     * @covers-req NI-05
     */
    public function testStatusReleasesAndConfigCommands(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $id = basename($this->liveDir());

        $status = $this->runCli(['status', '--json']);
        $this->assertExit(0, $status);
        $doc = json_decode($status['stdout'], true);
        self::assertSame(1, $doc['schema']);
        self::assertSame('shop', $doc['sites'][0]['name']);
        self::assertSame($id, $doc['sites'][0]['live']['release']);
        self::assertSame('8.2.31', $doc['sites'][0]['live']['php']);
        self::assertSame(['action' => 'deploy', 'result' => 'success'], array_slice($doc['sites'][0]['last'], 0, 2));
        self::assertFalse($doc['sites'][0]['locked']);
        self::assertFalse($doc['sites'][0]['interrupted']);

        $table = $this->runCli(['status']);
        self::assertStringContainsString(self::DOMAIN, $table['stdout']);

        $releases = $this->runCli(['releases', self::SITE, '--json']);
        $doc = json_decode($releases['stdout'], true);
        self::assertSame($id, $doc['live']);
        self::assertSame('live', $doc['releases'][0]['status']);
        self::assertIsInt($doc['releases'][0]['size_kb']);

        $delete = $this->runCli(['releases', self::SITE, 'delete', $id, '--yes']);
        $this->assertExit(2, $delete);
        self::assertStringContainsString('is the live release', $delete['stderr']);

        $this->assertExit(0, $this->runCli(['config', self::SITE, 'set', 'php.version', '8.3']));
        self::assertSame('8.3', trim($this->runCli(['config', self::SITE, 'get', 'php.version'])['stdout']));
        $this->assertExit(0, $this->runCli(['config', self::SITE, 'set', 'releases.keep', '8']));
        self::assertSame(8, $this->site()['releases']['keep']);
        $bad = $this->runCli(['config', self::SITE, 'set', 'releases.keep', '99']);
        $this->assertExit(2, $bad);
        self::assertStringContainsString('releases.keep: must be a whole number from 2 to 30', $bad['stderr']);
        self::assertSame(8, $this->site()['releases']['keep']);
        // LAY-04: the site's files live in site_dir; it can't be pointed elsewhere.
        $move = $this->runCli(['config', self::SITE, 'set', 'site_dir', 'elsewhere']);
        $this->assertExit(2, $move);
        self::assertStringContainsString("site_dir can't be changed", $move['stderr']);
        self::assertSame('cpdeploy_sites/' . self::DOMAIN, $this->site()['site_dir']);

        $noSite = $this->runCli(['deploy']);
        $this->assertExit(2, $noSite);
        self::assertStringContainsString('Sites: shop', $noSite['stderr']);
    }
}
