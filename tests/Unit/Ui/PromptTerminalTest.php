<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A closed terminal or a stop signal must end a prompt instead of leaving the
 * process spinning (each case runs in its own PHP process: it touches STDIN).
 */
final class PromptTerminalTest extends TestCase
{
    public function testEndOfInputStopsTheRead(): void
    {
        $process = $this->php(<<<'PHP'
            $terminal = new Cpdeploy\Ui\PromptTerminal(new Cpdeploy\Support\Signals());
            try {
                $terminal->read();
                echo 'returned';
            } catch (Cpdeploy\Support\Errors\CpdeployException $e) {
                echo $e->errorCode->value, ': ', $e->getMessage();
            }
            PHP);
        $process->setInput('');
        $process->run();

        self::assertSame('E_CANCELLED: Stopped: the terminal was closed', $process->getOutput(), $process->getErrorOutput());
    }

    public function testInputIsReturned(): void
    {
        $process = $this->php(<<<'PHP'
            echo (new Cpdeploy\Ui\PromptTerminal(new Cpdeploy\Support\Signals()))->read();
            PHP);
        $process->setInput('y');
        $process->run();

        self::assertSame('y', $process->getOutput(), $process->getErrorOutput());
    }

    public function testSigtermStopsAReadThatIsWaiting(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            self::markTestSkipped('needs pcntl and posix');
        }
        $process = $this->php(<<<'PHP'
            $signals = new Cpdeploy\Support\Signals();
            $signals->install();
            echo "ready\n";
            try {
                (new Cpdeploy\Ui\PromptTerminal($signals))->read();
                echo 'returned';
            } catch (Cpdeploy\Support\Errors\CpdeployException $e) {
                echo $e->getMessage();
            }
            PHP);
        $input = new \Symfony\Component\Process\InputStream();
        $process->setInput($input);
        $process->start();
        $process->waitUntil(static fn (string $type, string $out): bool => str_contains($out, 'ready'));
        $process->signal(SIGTERM);
        $process->wait();
        $input->close();

        self::assertSame("ready\nStopped: cpdeploy was asked to stop (SIGTERM)", $process->getOutput(), $process->getErrorOutput());
    }

    public function testAPromptAfterAHangUpExits(): void
    {
        $process = $this->php(<<<'PHP'
            $signals = new Cpdeploy\Support\Signals();
            $signals->request(1);
            (new Cpdeploy\Ui\PromptsAsker($signals))->select('Which?', ['a' => 'A']);
            echo 'asked';
            PHP);
        $process->setInput('');
        $process->run();

        self::assertSame(129, $process->getExitCode());
        self::assertSame('', $process->getOutput());
    }

    private function php(string $code): Process
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $process = new Process([PHP_BINARY, '-r', "require '{$autoload}';\n{$code}"]);
        $process->setTimeout(10);

        return $process;
    }
}
