<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Runtime;

use Cpdeploy\Runtime\NodeSpec;
use Cpdeploy\Runtime\NodeVersion;
use Cpdeploy\Runtime\PackageManager;
use Cpdeploy\Runtime\PackageManagerChoice;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\LocalServer;
use Cpdeploy\Tests\Support\TestCase;
use Cpdeploy\Ui\MemoryReporter;

final class NodeTest extends TestCase
{
    private LocalServer $mirror;
    private string $www;

    protected function setUp(): void
    {
        parent::setUp();
        $this->www = $this->tmp . '/mirror';
        mkdir($this->www);
        file_put_contents($this->www . '/index.json', (string) json_encode([
            ['version' => 'v24.9.0', 'lts' => false, 'files' => ['linux-x64', 'linux-arm64']],
            ['version' => 'v22.20.0', 'lts' => 'Jod', 'files' => ['linux-x64', 'linux-arm64']],
            ['version' => 'v22.19.0', 'lts' => 'Jod', 'files' => ['linux-x64', 'linux-arm64']],
            ['version' => 'v21.7.3', 'lts' => false, 'files' => ['linux-x64']],
            ['version' => 'v20.19.5', 'lts' => 'Iron', 'files' => ['linux-arm64']],
            ['version' => 'v20.19.4', 'lts' => 'Iron', 'files' => ['linux-x64', 'linux-arm64']],
            ['version' => 'v16.20.2', 'lts' => 'Gallium', 'files' => ['linux-x64']],
        ]));
        $this->publishNode('22.20.0');
        $this->mirror = new LocalServer($this->www);
        mkdir($this->root, 0711, true);
        file_put_contents($this->root . '/config.yml', "schema: 1\nmirrors:\n  node: " . $this->mirror->url() . "\n");
        $this->env['CPDEPLOY_NODE_SEARCH_PATHS'] = $this->tmp . '/nodes/*/bin';
        $this->env['CPDEPLOY_ARCH'] = 'x86_64';
        $this->env['CPDEPLOY_GLIBC'] = '2.28';
    }

    protected function tearDown(): void
    {
        $this->mirror->stop();
        parent::tearDown();
    }

    /**
     * An official-looking tarball whose bin/node prints the version.
     */
    private function publishNode(string $version, string $arch = 'x64', bool $corruptSum = false): void
    {
        $build = $this->tmp . '/build';
        $name = "node-v{$version}-linux-{$arch}";
        mkdir("{$build}/{$name}/bin", 0777, true);
        file_put_contents("{$build}/{$name}/bin/node", "#!/bin/sh\necho v{$version}\n");
        chmod("{$build}/{$name}/bin/node", 0755);
        file_put_contents("{$build}/{$name}/bin/npm", "#!/bin/sh\necho 10.9.0\n");
        chmod("{$build}/{$name}/bin/npm", 0755);
        mkdir("{$this->www}/v{$version}", 0777, true);
        $tar = "{$this->www}/v{$version}/{$name}.tar.gz";
        exec('tar -czf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($build) . ' ' . escapeshellarg($name));
        exec('rm -rf ' . escapeshellarg($build));
        $sum = $corruptSum ? str_repeat('0', 64) : hash_file('sha256', $tar);
        file_put_contents("{$this->www}/v{$version}/SHASUMS256.txt", str_repeat('f', 64) . "  node-v{$version}-linux-armv7l.tar.gz\n{$sum}  {$name}.tar.gz\n");
    }

    private function installedNode(string $version): string
    {
        $bin = $this->tmp . "/nodes/n{$version}/bin";
        mkdir($bin, 0777, true);
        file_put_contents($bin . '/node', "#!/bin/sh\necho v{$version}\n");
        chmod($bin . '/node', 0755);

        return $bin;
    }

    /**
     * @covers-req NODE-03
     * @covers-req NODE-04
     */
    public function testHighestInstalledVersionSatisfyingTheSpec(): void
    {
        $this->installedNode('18.20.4');
        $this->installedNode('20.11.1');
        $this->installedNode('20.19.4');
        $this->installedNode('22.20.0');
        $resolver = $this->services()->nodeResolver();

        self::assertSame('20.19.4', $resolver->installed(NodeSpec::parse('20', '.nvmrc'))?->version);
        self::assertSame('20.11.1', $resolver->installed(NodeSpec::parse('20.11', '.nvmrc'))?->version);
        self::assertSame('22.20.0', $resolver->installed(NodeSpec::parse('>=18', '.nvmrc'))?->version);
        self::assertSame('22.20.0', $resolver->installed(null)?->version, 'No spec: newest installed');
        self::assertSame('22.20.0', $resolver->installed(NodeSpec::parse('lts/jod', '.nvmrc'))?->version);
        self::assertSame('20.19.4', $resolver->installed(NodeSpec::parse('lts/iron', '.nvmrc'))?->version);
        self::assertNull($resolver->installed(NodeSpec::parse('23', '.nvmrc')));
    }

    /**
     * @covers-req NODE-04
     */
    public function testNoNodeAndNoSpecIsENodeNone(): void
    {
        $this->expectExceptionObject(new \Cpdeploy\Support\Errors\CpdeployException(ErrorCode::NODE_NONE, 'No Node.js is installed and no Node version is set'));
        $this->services()->nodeResolver()->installed(null);
    }

    /**
     * @covers-req NODE-04
     */
    public function testIndexSelectionWithLtsAndArchFiltering(): void
    {
        $resolver = $this->services()->nodeResolver();

        self::assertSame('24.9.0', $resolver->fromIndex(NodeSpec::parse('latest', 'x'), 'x64'));
        self::assertSame('22.20.0', $resolver->fromIndex(NodeSpec::parse('lts/*', 'x'), 'x64'));
        self::assertSame('20.19.4', $resolver->fromIndex(NodeSpec::parse('lts/iron', 'x'), 'x64'), '20.19.5 has no x64 build');
        self::assertSame('20.19.5', $resolver->fromIndex(NodeSpec::parse('20', 'x'), 'arm64'));
        self::assertSame('21.7.3', $resolver->fromIndex(NodeSpec::parse('^21', 'x'), 'x64'));
        self::assertNull($resolver->fromIndex(NodeSpec::parse('19', 'x'), 'x64'));
        self::assertFileExists($this->root . '/tools/node/index.json', 'Cached for 24 h');

        unlink($this->www . '/index.json');
        self::assertSame('24.9.0', $this->services()->nodeResolver()->fromIndex(NodeSpec::parse('node', 'x'), 'x64'));
    }

    /**
     * S-13: a missing version is downloaded, verified and used; the next run finds it installed.
     *
     * @covers-req NODE-05
     */
    public function testDownloadVerifyExtractThenReuse(): void
    {
        $reporter = new MemoryReporter();
        $node = $this->services()->nodeInstaller()->install('22.20.0', $reporter);

        self::assertSame($this->root . '/tools/node/node-v22.20.0-linux-x64/bin', $node->binDir);
        self::assertSame(['Downloading Node.js 22.20.0'], $reporter->of('start'));
        self::assertSame([], glob($this->root . '/tools/cpd-extract-*') ?: []);

        // Next run: found among installed versions, no download.
        $this->mirror->stop();
        $found = $this->services()->nodeResolver()->installed(NodeSpec::parse('22', '.nvmrc'));
        self::assertNotNull($found);
        self::assertSame('22.20.0', $found->version);
        self::assertSame($node->binDir, $found->binDir);
    }

    /**
     * @covers-req NODE-05
     * @covers-req SEC-06
     */
    public function testChecksumMismatchInstallsNothing(): void
    {
        $this->publishNode('20.19.4', 'x64', true);

        try {
            $this->services()->nodeInstaller()->install('20.19.4');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::CHECKSUM, $e->errorCode);
        }
        self::assertDirectoryDoesNotExist($this->root . '/tools/node/node-v20.19.4-linux-x64');
    }

    /**
     * @covers-req NODE-06
     */
    public function testOldGlibcAndUnknownArchitecture(): void
    {
        $this->env['CPDEPLOY_GLIBC'] = '2.17';
        try {
            $this->services()->nodeInstaller()->install('22.20.0');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::GLIBC_OLD, $e->errorCode);
            self::assertStringContainsString('2.17', $e->getMessage());
        }

        $this->env['CPDEPLOY_GLIBC'] = '2.28';
        $this->env['CPDEPLOY_ARCH'] = 'ppc64le';
        $this->expectExceptionObject(new CpdeployException(ErrorCode::NODE_ARCH, "Official Node.js builds aren't available for this server's architecture (ppc64le)"));
        $this->services()->nodeInstaller()->install('22.20.0');
    }

    public function testArchMapping(): void
    {
        $this->env['CPDEPLOY_ARCH'] = 'aarch64';
        self::assertSame('arm64', $this->services()->nodeInstaller()->arch());
    }

    /**
     * @covers-req NODE-07
     */
    public function testPackageManagerDetection(): void
    {
        $detect = static function (?array $pkg, array $files, array $contents = []): PackageManagerChoice {
            return PackageManager::detect(
                $pkg,
                static fn (string $f): bool => in_array($f, $files, true),
                static fn (string $f): ?string => $contents[$f] ?? null,
            );
        };

        $pnpm = $detect(['packageManager' => 'pnpm@9.12.0+sha512.abc'], ['package-lock.json']);
        self::assertSame(['pnpm', '9.12.0'], [$pnpm->name, $pnpm->version]);
        self::assertSame('npm', $detect([], ['package-lock.json'])->name);
        self::assertSame('pnpm', $detect([], ['pnpm-lock.yaml'])->name);
        self::assertSame('yarn', $detect([], ['yarn.lock'])->name);
        self::assertSame('npm', $detect(null, [])->name);
        self::assertSame('default', $detect(null, [])->reason);

        $berry = $detect([], ['yarn.lock', '.yarnrc.yml'], ['.yarnrc.yml' => "nodeLinker: node-modules\nyarnPath: .yarn/releases/yarn-4.5.0.cjs\n"]);
        self::assertSame('yarn-berry', $berry->name);
        self::assertSame('.yarn/releases/yarn-4.5.0.cjs', $berry->yarnPath);

        foreach ([[[], ['yarn.lock', '.yarnrc.yml'], []], [[], ['bun.lockb'], []], [['packageManager' => 'bun@1.1.0'], [], []]] as [$pkg, $files, $contents]) {
            try {
                $detect($pkg, $files, $contents);
                self::fail('Expected E_PM_UNSUPPORTED');
            } catch (CpdeployException $e) {
                self::assertSame(ErrorCode::PM_UNSUPPORTED, $e->errorCode);
            }
        }
    }

    /**
     * @covers-req NODE-09
     * @covers-req NODE-10
     */
    public function testCommandsAndEnvironment(): void
    {
        $npm = new PackageManagerChoice('npm', null, 'x');
        self::assertSame([['npm', 'ci', '--no-audit', '--no-fund'], null], PackageManager::installCommand($npm, ['npm'], true));
        [$cmd, $warning] = PackageManager::installCommand($npm, ['npm'], false);
        self::assertSame(['npm', 'install', '--no-audit', '--no-fund'], $cmd);
        self::assertNotNull($warning);
        self::assertSame(['pnpm', 'install', '--frozen-lockfile'], PackageManager::installCommand(new PackageManagerChoice('pnpm', null, 'x'), ['pnpm'], true)[0]);
        self::assertSame(['yarn', 'install', '--frozen-lockfile', '--non-interactive'], PackageManager::installCommand(new PackageManagerChoice('yarn', null, 'x'), ['yarn'], true)[0]);
        self::assertSame(['node', 'y.cjs', 'install', '--immutable'], PackageManager::installCommand(new PackageManagerChoice('yarn-berry', null, 'x', 'y.cjs'), ['node', 'y.cjs'], true)[0]);
        self::assertSame(['npm', 'run', 'build'], PackageManager::runCommand(['npm'], 'build'));

        $opts = PackageManager::environment(new NodeVersion('22.20.0', '/n/bin'), 2048, ['/pm/bin']);
        self::assertSame(['/n/bin', '/pm/bin'], $opts->pathPrefix);
        self::assertSame(['NODE_OPTIONS' => '--max-old-space-size=2048'], $opts->env);
        self::assertSame([], PackageManager::environment(new NodeVersion('22.20.0', '/n/bin'), null)->env);
    }

    /**
     * @covers-req NODE-08
     */
    public function testPnpmIsInstalledWithNpmIntoTools(): void
    {
        $bin = $this->installedNode('22.20.0');
        // Fake npm: `npm install --prefix <dir> pnpm@<v>` creates <dir>/node_modules/.bin/pnpm.
        file_put_contents($bin . '/npm', "#!/bin/sh\nwhile [ \$# -gt 0 ]; do case \"\$1\" in --prefix) shift; dir=\"\$1\";; pnpm@*) pkg=\"\$1\";; esac; shift; done\nmkdir -p \"\$dir/node_modules/.bin\"\nprintf '#!/bin/sh\\necho %s\\n' \"\$pkg\" > \"\$dir/node_modules/.bin/pnpm\"\nchmod +x \"\$dir/node_modules/.bin/pnpm\"\n");
        chmod($bin . '/npm', 0755);
        $pm = $this->services()->packageManager();
        $node = new NodeVersion('22.20.0', $bin);

        [$cmd, $path] = $pm->ensure(new PackageManagerChoice('pnpm', '9.12.0', 'x'), $node);

        $dir = $this->root . '/tools/pm/pnpm-9.12.0/node_modules/.bin';
        self::assertSame([$dir . '/pnpm'], $cmd);
        self::assertSame([$dir], $path);
        self::assertSame([[$bin . '/npm'], []], $pm->ensure(new PackageManagerChoice('npm', null, 'x'), $node));
        self::assertSame([[$bin . '/node', '.yarn/r.cjs'], []], $pm->ensure(new PackageManagerChoice('yarn-berry', null, 'x', '.yarn/r.cjs'), $node));
        self::assertSame($cmd, $pm->ensure(new PackageManagerChoice('pnpm', '9.12.0', 'x'), $node)[0], 'Installed once');
    }
}
