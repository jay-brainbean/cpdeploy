<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Menus\MainMenu;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Tests\Support\FakeGitHub;
use Cpdeploy\Tests\Support\TestCase;
use Cpdeploy\Ui\PlainReporter;
use Cpdeploy\Ui\ScriptedAsker;
use Cpdeploy\Ui\Theme;
use Cpdeploy\Wizard\WizardState;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * Settings (§9.6), in-process with a ScriptedAsker.
 */
final class SettingsMenuTest extends TestCase
{
    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUapi();
        $this->github = new FakeGitHub($this->tmp . '/github', ['releases' => ['jay-brainbean/cpdeploy' => [
            ['tag_name' => 'v1.0.0', 'draft' => false, 'prerelease' => false, 'body' => '', 'assets' => []],
        ]]]);
        $this->env['CPDEPLOY_GITHUB_API'] = $this->github->url();
        $this->env['CPDEPLOY_TEST_VERSION'] = '1.0.0';
        $this->env['CPDEPLOY_NOW'] = '2026-09-29T12:00:00Z';
    }

    protected function tearDown(): void
    {
        $this->github->stop();
        parent::tearDown();
    }

    public function testEverySettingsScreen(): void
    {
        $screen = $this->menu([
            ['What would you like to do?', 'settings'],
            // GitHub token
            ['Settings', 'token'],
            ['GitHub token', 'set'],
            ['GitHub token', 'github_pat_good_token_123456'],
            ['Settings', 'token'],
            ['GitHub token', 'test'],
            // Defaults for new sites
            ['Settings', 'defaults'],
            ['Defaults for new sites', 'keep'],
            ['Keep how many releases?', '3'],
            ['Defaults for new sites', 'sync'],
            ["Set the domain's MultiPHP version", false],
            ['Defaults for new sites', 'health'],
            ['Check new sites after each go-live?', false],
            ['Defaults for new sites', MenuContext::BACK],
            // Timeouts
            ['Settings', 'timeouts'],
            ['Select a timeout to change', 'composer'],
            ['composer install: seconds', '1200'],
            ['Select a timeout to change', MenuContext::BACK],
            // Display
            ['Settings', 'display'],
            ['Display', 'unicode'],
            ['Symbols', 'off'],
            ['Display', 'editor'],
            ['Editor command', 'nano -w'],
            ['Display', MenuContext::BACK],
            // About, updates
            ['Settings', 'about'],
            ['Settings', 'update'],
            ['Settings', MenuContext::BACK],
            ['What would you like to do?', 'quit'],
        ]);

        self::assertStringContainsString('Token saved for GitHub user jay', $screen);
        self::assertStringContainsString('The token works (GitHub user jay)', $screen);
        self::assertStringNotContainsString('github_pat_good_token_123456', $screen);
        self::assertStringContainsString('Update repo  jay-brainbean/cpdeploy', $screen);
        self::assertStringContainsString('cpdeploy 1.0.0 is up to date', $screen);

        $config = Yaml::parseFile($this->root . '/config.yml');
        self::assertSame(['keep_releases' => 3, 'sync_multiphp' => false, 'health_check' => false], $config['defaults']);
        self::assertSame(1200, $config['timeouts']['composer']);
        self::assertFalse($config['ui']['unicode']);
        self::assertSame('nano -w', $config['ui']['editor']);
        self::assertSame(0600, fileperms($this->root . '/config.yml') & 0777);

        // New sites start from these defaults (the wizard and add --from).
        $state = new WizardState();
        $state->applyDefaults($this->services()->config());
        $site = $state->config([]);
        self::assertSame(3, $site->keepReleases());
        self::assertFalse($site->healthEnabled());
        self::assertFalse($site->syncMultiPhp());
    }

    public function testInvalidValuesAreRefused(): void
    {
        $services = $this->services();
        $this->expectExceptionMessage('timeouts.git: must be a number of seconds from 5 to 86400');
        $services->changeConfig(['timeouts.git' => 1]);
    }

    /**
     * @param list<mixed> $answers
     */
    private function menu(array $answers): string
    {
        $asker = new ScriptedAsker($answers);
        $output = new BufferedOutput();
        $services = $this->services();
        $theme = new Theme(true);
        try {
            (new MainMenu(new MenuContext($services, $asker, $output, new PlainReporter($output, $theme, $services->clock()), $theme)))->run();
        } catch (\LogicException $e) {
            self::fail($e->getMessage() . "\n" . $output->fetch());
        }
        $text = $output->fetch();
        self::assertSame(0, $asker->remaining(), $text);

        return $text;
    }
}
