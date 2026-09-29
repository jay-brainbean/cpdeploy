<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Feature\Git;

use Cpdeploy\Git\GitRepository;
use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;

/**
 * The bare mirror against a local "GitHub": a bare repo reached through file:// (GIT-01 override).
 */
final class GitRepositoryTest extends TestCase
{
    private string $work;
    private string $remote;
    private string $mirror;
    private GitRepository $git;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = $this->tmp . '/work';
        $this->remote = $this->tmp . '/remote.git';
        $this->mirror = $this->root . '/sites/shop/repo.git';
        mkdir($this->work);
        mkdir($this->root . '/sites/shop', 0711, true);

        $this->g('init', '-q', '-b', 'main');
        file_put_contents($this->work . '/composer.json', '{"require":{"php":"^8.2"}}');
        mkdir($this->work . '/public');
        file_put_contents($this->work . '/public/index.php', '<?php echo 1;');
        mkdir($this->work . '/tests');
        file_put_contents($this->work . '/tests/Secret.php', 'not deployed');
        file_put_contents($this->work . '/.gitattributes', "/tests export-ignore\n");
        $this->commit('First commit');
        $this->g('tag', 'v1.0');
        $this->g('checkout', '-q', '-b', 'feature');
        file_put_contents($this->work . '/feature.txt', 'f');
        $this->commit('Feature work');
        $this->g('checkout', '-q', 'main');
        exec('git clone -q --bare ' . escapeshellarg($this->work) . ' ' . escapeshellarg($this->remote));
        $this->env['CPDEPLOY_GIT_URL_OVERRIDE'] = 'file://' . $this->remote;
        $this->git = $this->services()->git();
    }

    private function g(string ...$args): string
    {
        $cmd = 'git -C ' . escapeshellarg($this->work) . ' -c user.name=Dev -c user.email=dev@example.test ' . implode(' ', array_map('escapeshellarg', $args));
        exec($cmd . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $cmd . "\n" . implode("\n", $out));

        return implode("\n", $out);
    }

    private function commit(string $message): string
    {
        $this->g('add', '-A');
        $this->g('commit', '-q', '-m', $message);

        return trim($this->g('rev-parse', 'HEAD'));
    }

    private function push(string ...$refs): void
    {
        $this->g('push', '-q', $this->remote, ...$refs);
    }

    private function url(): string
    {
        return $this->services()->transport()->url(RepoUrl::parse('acme/shop'), RepoUrl::TRANSPORT_SSH22);
    }

    /**
     * @covers-req GIT-06
     * @covers-req GIT-15
     */
    public function testRemoteChecks(): void
    {
        $heads = $this->git->lsRemote($this->url(), 'ssh', 'acme/shop');

        self::assertSame(['feature', 'main'], array_keys($heads));
        self::assertSame('main', $this->git->defaultBranch($this->url(), 'ssh', 'acme/shop'));

        try {
            $this->git->lsRemote('file://' . $this->tmp . '/nope.git', 'ssh', 'acme/shop');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::GIT_AUTH, $e->errorCode, 'Could not read from remote repository');
        }
    }

    /**
     * @covers-req GIT-07
     * @covers-req GIT-08
     * @covers-req GIT-09
     */
    public function testMirrorFetchAndResolve(): void
    {
        $this->git->cloneMirror($this->url(), $this->mirror, 'ssh', 'acme/shop');

        self::assertSame(0700, fileperms($this->mirror) & 0777);
        self::assertSame("+refs/heads/*:refs/heads/*\n", shell_exec('git -C ' . escapeshellarg($this->mirror) . ' config remote.origin.fetch'));
        self::assertSame("false\n", shell_exec('git -C ' . escapeshellarg($this->mirror) . ' config core.logAllRefUpdates'));
        self::assertSame(['feature', 'main'], $this->git->branches($this->mirror));

        $main = $this->git->resolve($this->mirror, 'main', 'acme/shop', 'main');
        self::assertSame($main, $this->git->resolve($this->mirror, 'v1.0', 'acme/shop'));
        self::assertSame($main, $this->git->resolve($this->mirror, substr($main, 0, 7), 'acme/shop'));
        self::assertSame($main, $this->git->resolve($this->mirror, $main, 'acme/shop'));

        // New commit on main, feature branch deleted on the remote.
        file_put_contents($this->work . '/new.txt', 'n');
        $second = $this->commit('Second commit');
        $this->push('main');
        $this->push(':feature');
        $this->git->fetch($this->mirror, 'ssh', 'acme/shop');

        self::assertSame($second, $this->git->resolve($this->mirror, 'main', 'acme/shop', 'main'));
        self::assertSame(['main'], $this->git->branches($this->mirror), 'Deleted branches are pruned');

        try {
            $this->git->resolve($this->mirror, 'feature', 'acme/shop', 'feature');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::BRANCH_GONE, $e->errorCode);
            self::assertSame('Choose another branch: Manage site → Branch.', $e->hint);
        }
        try {
            $this->git->resolve($this->mirror, 'no-such-ref', 'acme/shop', 'main');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::REF_NOT_FOUND, $e->errorCode);
            self::assertSame(2, $e->exitCode());
        }
    }

    /**
     * @covers-req GIT-10
     * @covers-req GIT-11
     * @covers-req GIT-12
     */
    public function testReadingCommitsAndFiles(): void
    {
        $this->git->cloneMirror($this->url(), $this->mirror, 'ssh', 'acme/shop');
        $main = $this->git->resolve($this->mirror, 'main', 'acme/shop');
        $feature = $this->git->resolve($this->mirror, 'feature', 'acme/shop');

        self::assertSame('{"require":{"php":"^8.2"}}', $this->git->show($this->mirror, $main, 'composer.json'));
        self::assertNull($this->git->show($this->mirror, $main, 'package.json'));

        $commit = $this->git->commit($this->mirror, $feature);
        self::assertSame('Feature work', $commit->subject);
        self::assertSame('Dev', $commit->author);
        self::assertSame(substr($feature, 0, strlen($commit->short)), $commit->short);
        self::assertGreaterThan(1700000000, $commit->timestamp);

        self::assertSame(['Feature work'], array_map(static fn ($c) => $c->subject, $this->git->log($this->mirror, $main, $feature)));
        self::assertTrue($this->git->isAncestor($this->mirror, $main, $feature));
        self::assertFalse($this->git->isAncestor($this->mirror, $feature, $main), 'Going back is a rewind');
        self::assertSame([['A', 'feature.txt']], $this->git->diffNames($this->mirror, $main, $feature));
    }

    /**
     * @covers-req GIT-13
     */
    public function testExportRespectsExportIgnore(): void
    {
        $this->git->cloneMirror($this->url(), $this->mirror, 'ssh', 'acme/shop');
        $release = $this->root . '/sites/shop/releases/r1';
        mkdir($release, 0755, true);

        $this->git->export($this->mirror, $this->git->resolve($this->mirror, 'main', 'acme/shop'), $release);

        self::assertFileExists($release . '/public/index.php');
        self::assertDirectoryDoesNotExist($release . '/tests', 'export-ignore paths are not deployed');

        $this->expectExceptionObject(new CpdeployException(ErrorCode::EXPORT, 'x'));
        $this->git->export($this->mirror, str_repeat('0', 40), $this->root . '/sites/shop/releases/r2');
    }

    /**
     * @covers-req GIT-14
     */
    public function testSubmodulesAndLfsAreRefused(): void
    {
        file_put_contents($this->work . '/.gitmodules', "[submodule \"x\"]\n\tpath = x\n\turl = https://example.test/x.git\n");
        $sub = $this->commit('Add submodule file');
        file_put_contents($this->work . '/.gitmodules', '');
        unlink($this->work . '/.gitmodules');
        file_put_contents($this->work . '/.gitattributes', "*.psd filter=lfs diff=lfs merge=lfs -text\n");
        $lfs = $this->commit('Use LFS');
        $this->push('main');
        $this->git->cloneMirror($this->url(), $this->mirror, 'ssh', 'acme/shop');

        $this->git->assertSupported($this->mirror, $this->git->resolve($this->mirror, 'v1.0', 'acme/shop'));
        foreach ([$sub => ErrorCode::SUBMODULES, $lfs => ErrorCode::LFS] as $sha => $code) {
            try {
                $this->git->assertSupported($this->mirror, $sha);
                self::fail('No exception');
            } catch (CpdeployException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    /**
     * @covers-req GIT-16
     */
    public function testRecloneReplacesADamagedMirror(): void
    {
        $this->git->cloneMirror($this->url(), $this->mirror, 'ssh', 'acme/shop');
        // Damage the pack file, as a disk error would.
        $packs = glob($this->mirror . '/objects/pack/*.pack') ?: [];
        self::assertNotSame([], $packs);
        chmod($packs[0], 0644);
        $h = fopen($packs[0], 'r+');
        self::assertIsResource($h);
        fseek($h, 40);
        fwrite($h, str_repeat('garbage', 5));
        fclose($h);
        exec('git -C ' . escapeshellarg($this->mirror) . ' fetch origin 2>&1; git -C ' . escapeshellarg($this->mirror) . ' log -1 main 2>&1', $out);
        self::assertTrue(GitRepository::isCorruption(implode("\n", $out)), implode("\n", $out));

        $this->git->reclone($this->url(), $this->mirror, 'ssh', 'acme/shop', '20260929-120000');

        self::assertSame(['feature', 'main'], $this->git->branches($this->mirror));
        self::assertDirectoryDoesNotExist($this->mirror . '.broken-20260929-120000');
    }

    /**
     * @covers-req GIT-16
     */
    public function testFailedRecloneRestoresTheOldMirror(): void
    {
        $this->git->cloneMirror($this->url(), $this->mirror, 'ssh', 'acme/shop');
        file_put_contents($this->mirror . '/marker', 'old');

        try {
            $this->git->reclone('file://' . $this->tmp . '/gone.git', $this->mirror, 'ssh', 'acme/shop', '20260929-120000');
            self::fail('No exception');
        } catch (CpdeployException) {
        }
        self::assertFileExists($this->mirror . '/marker');
        self::assertDirectoryDoesNotExist($this->mirror . '.broken-20260929-120000');
    }

    /**
     * @covers-req GIT-04
     */
    public function testTransportDetection(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $open = (string) stream_socket_get_name($listener, false);

        $this->env['CPDEPLOY_TCP_OVERRIDE'] = "github.com:22={$open},*=127.0.0.1:1";
        self::assertSame('ssh22', $this->services()->transport()->detect());
        $this->env['CPDEPLOY_TCP_OVERRIDE'] = "ssh.github.com:443={$open},*=127.0.0.1:1";
        self::assertSame('ssh443', $this->services()->transport()->detect());
        $this->env['CPDEPLOY_TCP_OVERRIDE'] = '*=127.0.0.1:1';
        try {
            $this->services()->transport()->detect();
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::GIT_NET, $e->errorCode);
        }
        fclose($listener);
    }
}
