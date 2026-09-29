<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\DeployScenario;

/**
 * Interrupted operations (§11.9): a deploy is really killed with SIGKILL at a
 * given phase (the fake npm / artisan / a custom command read the PID from the
 * state file), then recovered. S-19, S-20, S-21.
 */
final class RecoveryTest extends DeployScenario
{
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFile = $this->siteDir . '/.deploy-state.json';
        $this->env['CPD_STATE_FILE'] = $this->stateFile;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return (array) json_decode((string) file_get_contents($this->stateFile), true);
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function recover(): array
    {
        return $this->runCli(['recover', self::SITE, '--yes'], null, 120);
    }

    /**
     * S-19: killed while building. The next deploy stops with exit 11 without a
     * terminal and offers recovery on one; recovery marks the release failed; the
     * deploy then works.
     *
     * @covers-req REC-01
     * @covers-req REC-02
     * @covers-req REC-03
     * @covers-req LCK-03
     */
    public function testKilledWhileBuilding(): void
    {
        $this->env['CPD_FAKE_NPM'] = 'kill';
        $killed = $this->deploy(['--yes']);
        self::assertNotSame(0, $killed['exit']);
        self::assertSame('building', $this->state()['phase']);
        $building = (string) $this->state()['release'];
        unset($this->env['CPD_FAKE_NPM']);

        $blocked = $this->deploy(['--yes']);
        $this->assertExit(11, $blocked);
        self::assertStringContainsString('An earlier deploy of shop was interrupted (phase: building', $blocked['stderr']);
        self::assertStringContainsString('cpdeploy recover shop', $blocked['stderr']);

        $status = $this->runCli(['status', self::SITE, '--json']);
        self::assertTrue(json_decode($status['stdout'], true)['sites'][0]['interrupted']);

        // On a terminal, the deploy offers recovery first, then continues.
        $this->env['CPDEPLOY_TEST_ANSWERS'] = (string) json_encode([['Recover it first', true], ['Deploy', 'deploy']]);
        $r = $this->deploy(['--migrate=yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        self::assertArrayNotHasKey($building, $this->releases(), 'the failed release is removed by the cleanup (PR-01)');
        $actions = array_column($this->history(), 'action');
        self::assertSame(['recover', 'deploy'], $actions);
        self::assertContains('Marked release ' . $building . ' failed (removed by the next cleanup)', $this->history()[0]['notes']);
        self::assertNotSame('releases/' . $building, $this->current());
    }

    /**
     * S-20: killed while migrating, after `down` in L. Recovery brings L back up
     * and warns that some migrations may have run.
     *
     * @covers-req REC-02
     * @covers-req REC-03
     */
    public function testKilledWhileMigrating(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $first = basename((string) $this->current());
        $this->change(['database/migrations/2026_03_01_000000_slow.php' => "<?php // FAKE_KILL\n"], 'A migration that never finishes');

        $killed = $this->deploy(['--composer=no', '--migrate=yes', '--yes']);
        self::assertNotSame(0, $killed['exit']);
        self::assertSame('migrating', $this->state()['phase']);
        $second = (string) $this->state()['release'];
        self::assertFileExists($this->liveDir() . '/storage/framework/down', 'L is in maintenance');

        $r = $this->recover();

        $this->assertExit(0, $r);
        $this->assertInvariants();
        self::assertSame('releases/' . $first, $this->current());
        self::assertFileDoesNotExist($this->liveDir() . '/storage/framework/down');
        self::assertSame('failed', $this->releases()[$second]['status']);
        self::assertStringContainsString('php artisan migrate:status', $r['stdout']);
        $last = $this->history()[1];
        self::assertSame(['recover', 'success'], [$last['action'], $last['result']]);
        self::assertContains('Maintenance mode off in ' . $first, $last['notes']);

        // Idempotent: nothing left to recover.
        $again = $this->recover();
        $this->assertExit(0, $again);
        self::assertStringContainsString('Nothing to recover', $again['stdout']);
    }

    /**
     * S-21: killed between the switch and the finish (in an after-activate
     * command). Recovery completes the bookkeeping; `current` stays on N.
     *
     * @covers-req REC-02
     */
    public function testKilledAfterTheSwitch(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $first = basename((string) $this->current());
        $this->change(['routes/web.php' => "<?php // v2\n"], 'Second version');
        $site = $this->site();
        $site['custom_commands'] = [[
            'name' => 'Die',
            'run' => 'pid=$(sed -n \'s/.*"pid":\([0-9]*\).*/\1/p\' "$CPD_STATE_FILE"); [ -n "$pid" ] && [ "$pid" -gt 1 ] && kill -9 "$pid"',
            'phase' => 'after_activate',
        ]];
        $this->writeSite($site);

        $killed = $this->deploy(['--composer=no', '--yes']);
        self::assertNotSame(0, $killed['exit']);
        self::assertSame('switched', $this->state()['phase']);
        $second = (string) $this->state()['release'];
        self::assertSame('releases/' . $second, $this->current());

        unset($site['custom_commands']);
        $this->writeSite($site);
        $r = $this->recover();

        $this->assertExit(0, $r);
        $this->assertInvariants();
        self::assertSame('releases/' . $second, $this->current());
        $releases = $this->releases();
        self::assertSame('live', $releases[$second]['status']);
        self::assertSame('ready', $releases[$first]['status']);
        self::assertStringContainsString('Check that the site works: https://' . self::DOMAIN, $r['stdout']);
        self::assertSame('recover', $this->history()[1]['action']);
    }

    /**
     * REC-02 for a rollback interrupted before its switch (state written by hand:
     * the rollback has no slow external step to kill it in).
     *
     * @covers-req REC-02
     */
    public function testInterruptedRollbackBeforeTheSwitch(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $live = basename((string) $this->current());
        file_put_contents($this->stateFile, (string) json_encode([
            'schema' => 1, 'operation' => 'rollback', 'pid' => 999999, 'started_at' => '2026-09-29T03:05:10Z',
            'phase' => 'preparing', 'release' => '20200101-000000', 'live_before' => $live,
            'maintenance_on' => null, 'migrations_started' => false, 'multiphp_before' => null,
            'multiphp_after' => null, 'docroot_converted' => false, 'switched' => false,
        ]));

        $blocked = $this->runCli(['releases', self::SITE, 'protect', $live]);
        $this->assertExit(11, $blocked);
        self::assertStringContainsString('An earlier rollback of shop was interrupted', $blocked['stderr']);

        $this->assertExit(0, $this->recover());
        $this->assertInvariants();
        self::assertSame('releases/' . $live, $this->current());
        self::assertSame('live', $this->releases()[$live]['status']);
    }
}
