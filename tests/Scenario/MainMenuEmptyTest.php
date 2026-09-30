<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Menus\MainMenu;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Tests\Support\TestCase;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\ScriptedAsker;
use Cpdeploy\Ui\Theme;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The main menu without sites (§9.2), the first-run welcome (UIG-02) and
 * `cpdeploy` without a terminal (UIG-01).
 */
final class MainMenuEmptyTest extends TestCase
{
    /**
     * @covers-req UIG-02
     */
    public function testFirstRunWithoutSites(): void
    {
        $this->fakeUapi();
        $asker = new ScriptedAsker([
            ['What would you like to do?', 'add'],
            ['How do you want to pick the repository?', 'cancel'],
            ['Cancel adding this site?', true],
            ['What would you like to do?', 'check'],
            ['What would you like to do?', 'settings'],
            ['Settings', MenuContext::BACK],
            ['What would you like to do?', 'quit'],
        ]);
        $output = new BufferedOutput();
        $services = $this->services();
        $theme = new Theme(false);
        (new MainMenu(new MenuContext($services, $asker, $output, new PlainReporter($output, $theme, $services->clock()), $theme)))->run(true);
        $screen = $output->fetch();

        self::assertSame(0, $asker->remaining());
        self::assertStringContainsString('Welcome to cpdeploy', $screen);
        self::assertStringContainsString('No sites yet. Add your first site to get started.', $screen);
        self::assertStringContainsString('cpdeploy · Add a site · Step 1/10 · Repository', $screen);
        self::assertStringContainsString('Nothing was added.', $screen);
        self::assertStringContainsString('cpdeploy · Server check', $screen);
        self::assertMatchesRegularExpression('/\d+ ok, \d+ warnings?, \d+ problems?/', $screen);
        self::assertStringContainsString('cpdeploy · Settings', $screen);
        self::assertStringNotContainsString('✓', $screen, 'M-03: ASCII symbols without UTF-8');
    }

    /**
     * @covers-req UIG-01
     */
    public function testNoTerminalPrintsTheCommandList(): void
    {
        $r = $this->runCli([]);

        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertStringContainsString('Available commands:', $r['stdout']);
        self::assertStringContainsString('rollback', $r['stdout']);
        self::assertDirectoryDoesNotExist($this->root, 'the list does not create ~/cpdeploy');
    }

    /**
     * UIG-01 on a (scripted) terminal: `cpdeploy` alone opens the menu.
     *
     * @covers-req UIG-01
     */
    public function testNoArgumentsOnATerminalOpensTheMenu(): void
    {
        $this->fakeUapi();
        $this->env['CPDEPLOY_TEST_ANSWERS'] = (string) json_encode([['What would you like to do?', 'quit']]);

        $r = $this->runCli([]);

        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertStringContainsString('Welcome to cpdeploy', $r['stdout'], 'first run (UIG-02)');
        self::assertStringContainsString('No sites yet', $r['stdout']);
        self::assertDirectoryExists($this->root);
    }
}
