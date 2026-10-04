<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\DeployScenario;

/**
 * Rollback (§11.8) and what happens when the health check fails after go-live
 * (HC-03, NI-04): S-14, S-15, S-22, S-23.
 */
final class RollbackTest extends DeployScenario
{
    private const BROKEN_INDEX = "<?php\nhttp_response_code(500);\necho 'boom';\n";

    /**
     * Two successful deploys; returns [first id, second id].
     *
     * @return array{0: string, 1: string}
     */
    private function twoReleases(): array
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $first = basename((string) $this->current());
        $this->change(['routes/web.php' => "<?php // v2\n"], 'Second version');
        $this->assertExit(0, $this->deploy(['--composer=no', '--yes']));

        return [$first, basename((string) $this->current())];
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function rollback(array $args): array
    {
        return $this->runCli(['rollback', self::SITE, ...$args], null, 120);
    }

    /**
     * S-22: rollback --previous. The target's optimize runs with the target's PHP,
     * `current` switches, the statuses and history follow.
     *
     * @covers-req RB-02
     * @covers-req RB-03
     * @covers-req RB-06
     * @covers-req RB-07
     * @covers-req RB-08
     */
    public function testRollbackToThePreviousRelease(): void
    {
        [$first, $second] = $this->twoReleases();
        $env = (string) file_get_contents($this->siteFiles . '/shared/.env');
        $calls = count($this->artisanCalls());

        $r = $this->rollback(['--previous', '--yes']);

        $this->assertExit(0, $r);
        $this->assertInvariants();
        self::assertSame('releases/' . $first, $this->current());
        $releases = $this->releases();
        self::assertSame('live', $releases[$first]['status']);
        self::assertSame('ready', $releases[$second]['status']);
        $optimize = array_values(array_filter(array_slice($this->artisanCalls(), $calls), static fn ($c) => $c['cmd'] === 'optimize'));
        self::assertCount(1, $optimize);
        self::assertSame($first, $optimize[0]['release']);
        self::assertSame('ea-php82', $optimize[0]['php']);
        $last = $this->history()[2];
        self::assertSame('rollback', $last['action']);
        self::assertSame('success', $last['result']);
        self::assertSame($first, $last['release']);
        self::assertSame($second, $last['from_release']);
        self::assertStringContainsString('-rollback.log', (string) $last['log']);
        self::assertMatchesRegularExpression('/^health: 200 in /m', implode("\n", $last['notes']));
        // RB-08: .env untouched; the health marker was removed.
        self::assertSame($env, file_get_contents($this->siteFiles . '/shared/.env'));
        self::assertSame([], glob($this->liveDir() . '/public/.cpd-release-*') ?: []);
        self::assertStringContainsString('Rolled back to ' . $first, $r['stdout']);
    }

    /**
     * RB-02 and RB-05: a named target, the picker's non-interactive fallback,
     * and confirmation without a terminal.
     *
     * @covers-req RB-02
     * @covers-req RB-05
     * @covers-req NI-03
     */
    public function testTargetsAndConfirmation(): void
    {
        [$first, $second] = $this->twoReleases();

        $none = $this->rollback([]);
        $this->assertExit(2, $none);
        self::assertStringContainsString('Pass its id or --previous', $none['stderr']);

        $live = $this->rollback([$second, '--yes']);
        $this->assertExit(8, $live);
        self::assertStringContainsString('it is already live', $live['stderr']);

        $unconfirmed = $this->rollback([$first]);
        $this->assertExit(2, $unconfirmed);
        self::assertStringContainsString('--yes (to confirm the rollback)', $unconfirmed['stderr']);
        self::assertSame('releases/' . $second, $this->current());

        $this->assertExit(0, $this->rollback([$first, '--yes', '--no-health-check']));
        self::assertSame('releases/' . $first, $this->current());

        // From the oldest release there is nothing earlier.
        $this->assertExit(8, $this->rollback(['--previous', '--yes']));
    }

    /**
     * RB-02 via the picker, RB-05 confirmation, on a (scripted) terminal.
     *
     * @covers-req RB-02
     * @covers-req RB-05
     */
    public function testPickerAndConfirmationOnATerminal(): void
    {
        [$first] = $this->twoReleases();
        $this->env['CPDEPLOY_TEST_ANSWERS'] = (string) json_encode([['Roll back to which release', $first], ['Roll back ' . self::DOMAIN, true]]);

        $r = $this->rollback([]);

        $this->assertExit(0, $r);
        self::assertSame('releases/' . $first, $this->current());
    }

    /**
     * RB-02: a failed release can't go live; RB-06 step 2: an optimize failure
     * stops the rollback before anything changed (exit 8).
     *
     * @covers-req RB-02
     * @covers-req RB-06
     */
    public function testOptimizeFailureChangesNothing(): void
    {
        [$first, $second] = $this->twoReleases();
        $this->env['CPD_FAKE_OPTIMIZE_FAIL'] = '1';

        $r = $this->rollback(['--previous', '--yes']);

        $this->assertExit(8, $r);
        self::assertStringContainsString('php artisan optimize failed', $r['stderr']);
        self::assertStringContainsString('Live site: not changed', $r['stderr']);
        self::assertSame('releases/' . $second, $this->current());
        self::assertSame('failed', $this->history()[2]['result']);
        $this->assertInvariants();
        unset($this->env['CPD_FAKE_OPTIMIZE_FAIL']);
        self::assertSame('ready', $this->releases()[$first]['status']);
    }

    /**
     * S-23: rollback to a release built with PHP 8.2 while the domain is on 8.3
     * (sync on): a downgrade, so MultiPHP changes after the switch (GL-03), and
     * the handler block is injected for 8.2 (DOC-04). RB-04 warns first.
     *
     * @covers-req RB-04
     * @covers-req RB-06
     * @covers-req GL-03
     */
    public function testRollbackToAnotherPhpFollowsTheOrderingRule(): void
    {
        file_put_contents($this->docrootSeed(), "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit\n");
        $this->assertExit(0, $this->deploy(['--yes']));
        $first = basename((string) $this->current());
        $site = $this->site();
        $site['php']['version'] = '8.3';
        $this->writeSite($site);
        $this->assertExit(0, $this->deploy(['--force', '--yes']));
        $second = basename((string) $this->current());
        $before = count($this->uapiCalls());

        $r = $this->rollback(['--previous', '--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('The domain will switch to PHP 8.2 (ea-php83 now)', $r['stdout']);
        $sets = array_values(array_filter(array_slice($this->uapiCalls(), $before), static fn ($c) => $c['call'] === 'LangPHP::php_set_vhost_versions'));
        self::assertCount(1, $sets);
        self::assertSame('ea-php82', $sets[0]['args']['version']);
        self::assertSame('releases/' . $first, $sets[0]['link'] ?? null, 'a downgrade changes MultiPHP after the switch');
        self::assertStringContainsString('ea-php82', (string) file_get_contents($this->siteFiles . '/releases/' . $first . '/public/.htaccess'));
        self::assertSame('ready', $this->releases()[$second]['status']);
    }

    /**
     * S-14: the health check returns 500; on a terminal the answer is "Roll back".
     * L was in maintenance (migrations ran), so it comes up first; exit 7; history
     * has the deploy and the rollback.
     *
     * @covers-req HC-03
     * @covers-req RB-06
     * @covers-req GL-02
     */
    public function testHealthFailureAnsweredRollBack(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $first = basename((string) $this->current());
        $this->change([
            'public/index.php' => self::BROKEN_INDEX,
            'database/migrations/2026_02_01_000000_add_coupons.php' => "<?php // coupons\n",
        ], 'Broken release with a migration');
        $this->env['CPDEPLOY_TEST_ANSWERS'] = (string) json_encode([['Deploy', 'deploy'], ['returned 500', 'rollback']]);

        $r = $this->deploy(['--composer=no', '--migrate=yes']);

        $this->assertExit(7, $r);
        $this->assertInvariants();
        self::assertSame('releases/' . $first, $this->current());
        self::assertFileDoesNotExist($this->liveDir() . '/storage/framework/down');
        $calls = $this->artisanCalls();
        $down = array_keys(array_filter($calls, static fn ($c) => $c['cmd'] === 'down' && $c['release'] === $first));
        $up = array_keys(array_filter($calls, static fn ($c) => $c['cmd'] === 'up' && $c['release'] === $first));
        self::assertNotSame([], $down, 'L went into maintenance for the migrations (G1)');
        self::assertGreaterThan($down === [] ? -1 : max($down), $up === [] ? -1 : max($up), 'the rollback brought L back up (RB-06 step 3)');
        $history = $this->history();
        self::assertCount(3, $history);
        self::assertSame(['deploy', 'failed', 7], [$history[1]['action'], $history[1]['result'], $history[1]['exit_code']]);
        self::assertContains('health: failed; rolled back to ' . $first, $history[1]['notes']);
        self::assertSame(['rollback', 'success', $first], [$history[2]['action'], $history[2]['result'], $history[2]['release']]);
        self::assertStringContainsString('rolled back to ' . $first, $r['stdout']);
        self::assertStringContainsString('These database changes stay in place: 2026_02_01_000000_add_coupons', $r['stdout']);
    }

    /**
     * S-15: health 500, non-interactive, migrations ran → kept (NI-04), exit 7.
     *
     * @covers-req NI-04
     * @covers-req HC-03
     */
    public function testNonInteractiveHealthFailureAfterMigrationsKeeps(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $this->change([
            'public/index.php' => self::BROKEN_INDEX,
            'database/migrations/2026_02_01_000000_add_coupons.php' => "<?php // coupons\n",
        ], 'Broken release with a migration');

        $r = $this->deploy(['--composer=no', '--migrate=yes', '--yes']);

        $this->assertExit(7, $r);
        $this->assertInvariants();
        $second = basename((string) $this->current());
        self::assertSame('live', $this->releases()[$second]['status']);
        self::assertSame('warning', $this->history()[1]['result']);
        self::assertCount(2, $this->history());
        self::assertStringContainsString('the new release was kept (migrations ran in this deploy', $r['stdout']);
    }

    /**
     * NI-04: without migrations the non-interactive default is to roll back.
     *
     * @covers-req NI-04
     * @covers-req HC-03
     */
    public function testNonInteractiveHealthFailureWithoutMigrationsRollsBack(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $first = basename((string) $this->current());
        $this->change(['public/index.php' => self::BROKEN_INDEX], 'Broken release');

        $r = $this->deploy(['--composer=no', '--yes']);

        $this->assertExit(7, $r);
        $this->assertInvariants();
        self::assertSame('releases/' . $first, $this->current());
        self::assertSame(['deploy', 'rollback'], [$this->history()[1]['action'], $this->history()[2]['action']]);
    }

    /**
     * HC-03: --on-health-fail=keep overrides NI-04; a first deploy has nothing to
     * roll back to.
     *
     * @covers-req HC-03
     */
    public function testKeepPolicyAndFirstDeploy(): void
    {
        $this->repo->write('public/index.php', self::BROKEN_INDEX);
        $this->repo->commit('Broken from the start');
        $this->repo->push();

        $first = $this->deploy(['--yes']);
        $this->assertExit(7, $first);
        self::assertStringContainsString('this was the first deploy', $first['stdout']);
        self::assertNotNull($this->current());

        $this->change(['routes/web.php' => "<?php // v2\n"], 'Still broken');
        $keep = $this->deploy(['--composer=no', '--on-health-fail=keep', '--yes']);
        $this->assertExit(7, $keep);
        self::assertSame('live', $this->releases()[basename((string) $this->current())]['status']);
        self::assertCount(2, $this->history());
    }

    /**
     * The folder served before the first deploy, with a cPanel handler block
     * (created so the first conversion captures it).
     */
    private function docrootSeed(): string
    {
        if (!is_dir($this->docroot)) {
            mkdir($this->docroot, 0755, true);
        }

        return $this->docroot . '/.htaccess';
    }
}
