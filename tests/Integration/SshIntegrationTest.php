<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Integration;

use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Git\SshCommand;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Tests\Support\TestCase;

/**
 * Real SSH: a throwaway sshd on 127.0.0.1 that accepts only the generated deploy
 * key, reached through GIT_SSH_COMMAND with cpdeploy's own known_hosts (GIT-02,
 * GIT-06, SEC-04). Runs when CPDEPLOY_SSH_INTEGRATION=1 (the ssh-integration CI job).
 */
final class SshIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $sshd = null;
    private int $port;
    private string $hostKeyPub;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CPDEPLOY_SSH_INTEGRATION') !== '1') {
            self::markTestSkipped('Set CPDEPLOY_SSH_INTEGRATION=1 to run (needs sshd)');
        }
        $sshd = is_executable('/usr/sbin/sshd') ? '/usr/sbin/sshd' : trim((string) shell_exec('command -v sshd'));
        if ($sshd === '') {
            self::fail('sshd is not installed');
        }

        mkdir($this->root, 0711, true);
        $dir = $this->tmp . '/sshd';
        mkdir($dir, 0700);
        exec('ssh-keygen -q -t ed25519 -N "" -f ' . escapeshellarg($dir . '/host_key'));
        $this->hostKeyPub = trim((string) file_get_contents($dir . '/host_key.pub'));

        // The deploy key sshd will accept.
        $key = $this->services()->deployKeys()->generate('shop', 'ci');
        file_put_contents($dir . '/authorized_keys', file_get_contents($key . '.pub'));
        chmod($dir . '/authorized_keys', 0600);

        // A bare repository to fetch over SSH.
        $work = $this->tmp . '/work';
        exec('git init -q -b main ' . escapeshellarg($work) . ' && git -C ' . escapeshellarg($work) . ' -c user.name=D -c user.email=d@e commit -q --allow-empty -m init && git clone -q --bare ' . escapeshellarg($work) . ' ' . escapeshellarg($this->tmp . '/remote.git'));

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $user = (string) (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user());
        file_put_contents($dir . '/sshd_config', implode("\n", [
            "Port {$this->port}",
            'ListenAddress 127.0.0.1',
            "HostKey {$dir}/host_key",
            "AuthorizedKeysFile {$dir}/authorized_keys",
            "PidFile {$dir}/sshd.pid",
            'PasswordAuthentication no',
            'KbdInteractiveAuthentication no',
            'PubkeyAuthentication yes',
            'PermitRootLogin prohibit-password',
            'StrictModes no',
            'UsePAM no',
            "AllowUsers {$user}",
            '',
        ]));
        if (posix_geteuid() === 0 && !is_dir('/run/sshd')) {
            mkdir('/run/sshd', 0755, true);
        }
        $proc = proc_open([$sshd, '-D', '-e', '-f', $dir . '/sshd_config'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/log', 'w'], 2 => ['file', $dir . '/log', 'w']], $pipes);
        self::assertIsResource($proc);
        $this->sshd = $proc;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                break;
            }
            usleep(100_000);
        }

        $this->env['CPDEPLOY_GIT_URL_OVERRIDE'] = "ssh://{$user}@127.0.0.1:{$this->port}{$this->tmp}/remote.git";
        $this->knownHosts($this->hostKeyPub);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->sshd)) {
            proc_terminate($this->sshd);
            proc_close($this->sshd);
        }
        parent::tearDown();
    }

    private function knownHosts(string $publicKey): void
    {
        [$type, $blob] = explode(' ', $publicKey);
        file_put_contents($this->root . '/known_hosts', "[127.0.0.1]:{$this->port} {$type} {$blob}\n");
    }

    /**
     * @covers-req GIT-02
     * @covers-req GIT-06
     */
    public function testDeployKeyAuthenticatesAndMirrorClones(): void
    {
        $services = $this->services();
        $branches = $services->deployKeys()->test(RepoUrl::parse('acme/shop'), 'shop', 'ssh22');
        self::assertSame(['main'], array_keys($branches));

        $mirror = $this->root . '/sites/shop/repo.git';
        mkdir(dirname($mirror), 0711, true);
        $ssh = SshCommand::build($services->deployKeys()->keyPath('shop'), $this->root . '/known_hosts');
        $url = $services->transport()->url(RepoUrl::parse('acme/shop'), 'ssh22');
        $services->git()->cloneMirror($url, $mirror, $ssh, 'acme/shop');
        $services->git()->fetch($mirror, $ssh, 'acme/shop');
        self::assertSame(['main'], $services->git()->branches($mirror));
    }

    /**
     * A host key that doesn't match cpdeploy's known_hosts is refused, never accepted.
     *
     * @covers-req GIT-03
     * @covers-req SEC-04
     */
    public function testHostKeyMismatchIsEGitHostkey(): void
    {
        exec('ssh-keygen -q -t ed25519 -N "" -f ' . escapeshellarg($this->tmp . '/other'));
        $this->knownHosts(trim((string) file_get_contents($this->tmp . '/other.pub')));

        try {
            $this->services()->deployKeys()->test(RepoUrl::parse('acme/shop'), 'shop', 'ssh22');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::GIT_HOSTKEY, $e->errorCode);
        }
        self::assertStringNotContainsString('127.0.0.1', (string) @file_get_contents($this->home . '/.ssh/known_hosts'), "The user's known_hosts is never touched");
    }

    /**
     * @covers-req GIT-06
     */
    public function testUnknownKeyIsEGitAuth(): void
    {
        $this->services()->deployKeys()->generate('blog', 'ci');

        try {
            $this->services()->deployKeys()->test(RepoUrl::parse('acme/shop'), 'blog', 'ssh22');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::GIT_AUTH, $e->errorCode);
        }
    }
}
