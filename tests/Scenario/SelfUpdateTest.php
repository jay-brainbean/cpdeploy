<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\FakeGitHub;
use Cpdeploy\Tests\Support\TestCase;

/**
 * `cpdeploy self-update` (§15.5) against releases on a FakeGitHub. The
 * "phars" are small PHP scripts that print their version.
 */
final class SelfUpdateTest extends TestCase
{
    private FakeGitHub $github;
    private string $installed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUapi();
        $this->installed = $this->root . '/app/cpdeploy.phar';
        mkdir(dirname($this->installed), 0755, true);
        file_put_contents($this->installed, self::script('1.0.0'));
        $this->env['CPDEPLOY_TEST_VERSION'] = '1.0.0';

        $dir = $this->tmp . '/github';
        mkdir($dir . '/assets', 0777, true);
        file_put_contents($dir . '/assets/cpdeploy.phar', self::script('1.1.0'));
        file_put_contents($dir . '/assets/cpdeploy.phar.sha256', hash('sha256', self::script('1.1.0')) . "  cpdeploy.phar\n");
        file_put_contents($dir . '/assets/rc.phar', self::script('1.2.0-rc.1'));
        file_put_contents($dir . '/assets/rc.phar.sha256', hash('sha256', self::script('1.2.0-rc.1')) . "  cpdeploy.phar\n");
        $this->github = new FakeGitHub($dir, ['releases' => ['jay-brainbean/cpdeploy' => [
            self::release('v1.2.0-rc.1', true, 'rc.phar'),
            self::release('v1.1.0', false, 'cpdeploy.phar', "## 1.1.0\n- Better things"),
            self::release('v1.0.0', false, 'cpdeploy.phar'),
        ]]]);
        $this->env['CPDEPLOY_GITHUB_API'] = $this->github->url();
    }

    protected function tearDown(): void
    {
        $this->github->stop();
        parent::tearDown();
    }

    /**
     * @covers-req UPD-01
     * @covers-req UPD-02
     * @covers-req UPD-03
     */
    public function testCheckUpdateAndRollback(): void
    {
        $check = $this->runCli(['self-update', '--check']);
        self::assertSame(0, $check['exit'], $check['stderr']);
        self::assertStringContainsString('cpdeploy 1.1.0 is available (you have 1.0.0)', $check['stdout']);
        self::assertSame(self::script('1.0.0'), file_get_contents($this->installed), '--check changes nothing');

        $pre = $this->runCli(['self-update', '--check', '--pre']);
        self::assertStringContainsString('cpdeploy 1.2.0-rc.1 is available', $pre['stdout']);

        $update = $this->runCli(['self-update']);
        self::assertSame(0, $update['exit'], $update['stderr']);
        self::assertStringContainsString('Updated cpdeploy 1.0.0 → 1.1.0', $update['stdout']);
        self::assertStringContainsString('- Better things', $update['stdout']);
        self::assertSame(self::script('1.1.0'), file_get_contents($this->installed));
        self::assertSame(self::script('1.0.0'), file_get_contents($this->root . '/app/cpdeploy.phar.prev'));
        self::assertSame([], glob($this->root . '/tmp/cpd-update-*') ?: []);

        $this->env['CPDEPLOY_TEST_VERSION'] = '1.1.0';
        $again = $this->runCli(['self-update']);
        self::assertStringContainsString('cpdeploy 1.1.0 is up to date', $again['stdout']);

        $rollback = $this->runCli(['self-update', '--rollback']);
        self::assertSame(0, $rollback['exit'], $rollback['stderr']);
        self::assertStringContainsString('Rolled back to cpdeploy 1.0.0', $rollback['stdout']);
        self::assertSame(self::script('1.0.0'), file_get_contents($this->installed));
        self::assertSame(self::script('1.1.0'), file_get_contents($this->root . '/app/cpdeploy.phar.prev'));
    }

    /**
     * @covers-req UPD-02
     */
    public function testABadChecksumChangesNothing(): void
    {
        file_put_contents($this->tmp . '/github/assets/cpdeploy.phar.sha256', str_repeat('0', 64) . "  cpdeploy.phar\n");

        $r = $this->runCli(['self-update']);

        self::assertSame(1, $r['exit']);
        self::assertStringContainsString('Update failed: the downloaded phar does not match its SHA-256', $r['stderr']);
        self::assertStringContainsString('The current version is unchanged', $r['stderr']);
        self::assertSame(self::script('1.0.0'), file_get_contents($this->installed));
        self::assertFileDoesNotExist($this->root . '/app/cpdeploy.phar.prev');
    }

    public function testNotInstalledAndNothingToRollBack(): void
    {
        $rollback = $this->runCli(['self-update', '--rollback']);
        self::assertSame(1, $rollback['exit']);
        self::assertStringContainsString('there is no previous version', $rollback['stderr']);

        unlink($this->installed);
        $r = $this->runCli(['self-update']);
        self::assertSame(1, $r['exit']);
        self::assertStringContainsString("cpdeploy isn't installed at", $r['stderr']);
    }

    private static function script(string $version): string
    {
        return "<?php\necho 'cpdeploy {$version} · PHP ' . PHP_VERSION . PHP_EOL;\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function release(string $tag, bool $pre, string $asset, string $body = ''): array
    {
        return [
            'tag_name' => $tag,
            'draft' => false,
            'prerelease' => $pre,
            'body' => $body,
            'assets' => [
                ['name' => 'cpdeploy.phar', 'url' => '{base}/assets/' . $asset],
                ['name' => 'cpdeploy.phar.sha256', 'url' => '{base}/assets/' . $asset . '.sha256'],
            ],
        ];
    }
}
