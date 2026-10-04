<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\DeployScenario;

/**
 * Failures before go-live leave the live site untouched (P2): S-10 … S-13, S-25,
 * S-38, S-40.
 */
final class DeployFailureTest extends DeployScenario
{
    /**
     * S-10: the frontend build fails → exit 4, live untouched, the failed release
     * is kept until the next successful deploy's cleanup.
     *
     * @covers-req BLD-03
     * @covers-req INV-01
     * @covers-req INV-09
     * @covers-req PR-01
     * @covers-req UI-09
     */
    public function testAFailedBuildLeavesTheLiveSiteAlone(): void
    {
        $this->assertExit(0, $this->deploy(['--yes']));
        $live = $this->current();
        $this->change(['resources/js/app.js' => "console.log('v2');\n"], 'Frontend change');

        $this->env['CPD_FAKE_NPM'] = 'fail';
        $r = $this->deploy(['--yes']);

        $this->assertExit(4, $r);
        self::assertSame($live, $this->current());
        self::assertStringContainsString('npm run build failed', $r['stderr']);
        self::assertStringContainsString('Live site: not changed', $r['stderr']);
        self::assertStringContainsString('Rollup failed to resolve import', $r['stdout'], 'the last output lines are shown (UI-09)');
        self::assertStringContainsString('Log: ', $r['stderr']);
        $failed = array_keys(array_filter($this->releases(), static fn ($r) => ($r['status'] ?? '') === 'failed'));
        self::assertCount(1, $failed);
        self::assertFileDoesNotExist($this->siteDir . '/.deploy-state.json');
        self::assertSame('failed', $this->history()[1]['result']);

        unset($this->env['CPD_FAKE_NPM']);
        $this->assertExit(0, $this->deploy(['--yes']));
        self::assertArrayNotHasKey($failed[0], $this->releases(), 'the failed release is removed by the next cleanup');
    }

    /**
     * S-11: the build runs out of memory → E_OOM.
     *
     * @covers-req SH-06
     */
    public function testOutOfMemoryIsExplained(): void
    {
        $this->env['CPD_FAKE_NPM'] = 'oom';

        $r = $this->deploy(['--yes']);

        $this->assertExit(4, $r);
        self::assertStringContainsString('The build ran out of memory', $r['stderr']);
        self::assertStringContainsString('Node max memory', $r['stderr']);
        self::assertNull($this->current());
    }

    /**
     * S-12: Composer fails its checksum on the first download → E_CHECKSUM, nothing cached.
     *
     * @covers-req CMP-01
     * @covers-req SEC-06
     */
    public function testComposerChecksumMismatch(): void
    {
        $this->publishComposer('<?php echo "tampered";', str_repeat('0', 64));

        $r = $this->deploy(['--yes']);

        $this->assertExit(3, $r);
        self::assertStringContainsString('failed its checksum', $r['stderr']);
        self::assertSame([], glob($this->root . '/tools/composer/*.phar') ?: []);
        self::assertSame([], $this->releases());
    }

    /**
     * S-13: the Node version isn't installed → downloaded, verified, used; the second
     * deploy uses the cached copy.
     *
     * @covers-req NODE-01
     * @covers-req NODE-04
     * @covers-req NODE-05
     */
    public function testAMissingNodeVersionIsDownloadedOnce(): void
    {
        $this->publishNode('22.20.0');
        $this->change(['.nvmrc' => "22\n"], 'Node 22');

        $r = $this->deploy(['--yes']);

        $this->assertExit(0, $r);
        self::assertStringContainsString('Downloading Node.js 22.20.0', $r['stdout']);
        self::assertFileExists($this->root . '/tools/node/node-v22.20.0-linux-x64/bin/node');
        self::assertStringContainsString('node=v22.20.0', (string) file_get_contents($this->liveDir() . '/public/build/info.txt'));
        self::assertSame('22.20.0', $this->releases()[basename($this->liveDir())]['node']['version']);

        // Cached: the mirror's tarball is no longer needed.
        exec('rm -rf ' . escapeshellarg($this->tmp . '/mirror/node/v22.20.0'));
        $again = $this->deploy(['--force', '--yes']);
        $this->assertExit(0, $again);
        self::assertStringNotContainsString('Downloading Node.js', $again['stdout']);
    }

    /**
     * S-25: .env is missing → PRE-11 blocks with exit 3.
     *
     * @covers-req PRE-11
     */
    public function testMissingEnvBlocks(): void
    {
        unlink($this->siteFiles . '/shared/.env');

        $r = $this->deploy(['--yes']);

        $this->assertExit(3, $r);
        self::assertStringContainsString('.env is missing', $r['stderr']);
        self::assertSame([], $this->releases());
    }

    /**
     * PRE-12 and PRE-11 together: every blocking check is listed at once.
     *
     * @covers-req PRE-12
     * @covers-req PRE-08
     */
    public function testBlockingChecksAreListedTogether(): void
    {
        $this->writeEnv("APP_KEY=\nAPP_ENV=local\n");
        $this->env['CPD_FAKE_PLATFORM_FAIL'] = '1';

        $r = $this->deploy(['--yes']);

        $this->assertExit(3, $r);
        self::assertStringContainsString('2 checks failed', $r['stderr']);
        self::assertStringContainsString('ext-intl (missing)', $r['stderr']);
        self::assertStringContainsString('APP_KEY is empty', $r['stderr']);
        self::assertStringContainsString('APP_ENV=local', $r['stdout']);
    }

    /**
     * S-38: git submodules are refused at preflight.
     *
     * @covers-req GIT-14
     * @covers-req PRE-04
     */
    public function testSubmodulesAreRefused(): void
    {
        $this->change(['.gitmodules' => "[submodule \"lib\"]\n\tpath = lib\n\turl = https://github.com/acme/lib.git\n"], 'Add a submodule');

        $r = $this->deploy(['--yes']);

        $this->assertExit(3, $r);
        self::assertStringContainsString('git submodules', $r['stderr']);
        self::assertSame([], $this->releases());
    }

    /**
     * S-40: web_dir "" with a shared .env → validation error.
     *
     * @covers-req SEC-11
     * @covers-req VAL-08
     */
    public function testServingTheReleaseRootWithEnvIsInvalid(): void
    {
        $this->writeSite(['domain' => ['web_dir' => '']]);

        $r = $this->deploy(['--yes']);

        $this->assertExit(2, $r);
        self::assertStringContainsString('would expose .env', $r['stderr']);
        self::assertStringContainsString('cpdeploy config shop edit', $r['stderr']);
    }

    /**
     * An official-looking Node build on the mirror: index.json, SHASUMS256.txt and
     * the tarball with a fake node and npm.
     */
    private function publishNode(string $version): void
    {
        $www = $this->tmp . '/mirror/node';
        $build = $this->tmp . '/node-build';
        $name = "node-v{$version}-linux-x64";
        $this->fakeNode($version, "{$build}/{$name}/bin");
        mkdir("{$www}/v{$version}", 0777, true);
        exec('tar -czf ' . escapeshellarg("{$www}/v{$version}/{$name}.tar.gz") . ' -C ' . escapeshellarg($build) . ' ' . escapeshellarg($name));
        file_put_contents("{$www}/v{$version}/SHASUMS256.txt", hash_file('sha256', "{$www}/v{$version}/{$name}.tar.gz") . "  {$name}.tar.gz\n");
        file_put_contents("{$www}/index.json", (string) json_encode([
            ['version' => 'v24.9.0', 'lts' => false, 'files' => ['linux-x64']],
            ['version' => "v{$version}", 'lts' => 'Jod', 'files' => ['linux-x64', 'linux-arm64']],
        ]));
    }
}
