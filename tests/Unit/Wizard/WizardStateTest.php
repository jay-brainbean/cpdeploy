<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Wizard;

use Cpdeploy\Git\RepoUrl;
use Cpdeploy\Project\ProjectInfo;
use Cpdeploy\Wizard\Steps\PhpStep;
use Cpdeploy\Wizard\Steps\RepositoryStep;
use Cpdeploy\Wizard\Steps\ServingStep;
use Cpdeploy\Wizard\WizardState;
use PHPUnit\Framework\TestCase;

/**
 * The wizard's answers as a site.yml, and the small rules of its steps.
 */
final class WizardStateTest extends TestCase
{
    /**
     * @covers-req WIZ-01
     */
    public function testSiteDataWithOverridesAndDatabase(): void
    {
        $state = new WizardState();
        $state->repo = RepoUrl::parse('acme/shop');
        $state->branch = 'main';
        $state->name = 'shop';
        $state->type = 'laravel';
        $state->domain = 'shop.example.com';
        $state->docroot = '/home/u/shop.example.com';
        $state->phpVersion = '8.3';
        $state->keyId = 42;
        $state->dbMode = WizardState::DB_CREATE;
        $state->dbName = 'u_shop';
        $state->dbUser = 'u_shop';
        $state->dbPassword = 'never-stored';
        $state->overrides = ['steps.migrate' => 'every', 'releases.keep' => 3];

        $data = $state->siteData('2026-09-29T12:00:00Z');
        self::assertSame(['owner' => 'acme', 'name' => 'shop', 'branch' => 'main', 'transport' => 'ssh22', 'deploy_key_id' => 42], $data['repo']);
        self::assertSame(['created_by_cpdeploy' => true, 'name' => 'u_shop', 'user' => 'u_shop'], $data['database']);
        self::assertSame('every', $data['steps']['migrate']);
        self::assertSame(3, $data['releases']['keep']);
        self::assertStringNotContainsString('never-stored', (string) json_encode($data), 'the password never goes into site.yml');

        $config = $state->config([]);
        self::assertSame('every', $config->step('migrate'));
        self::assertSame(3, $config->keepReleases());
    }

    /**
     * @covers-req WIZ-02
     */
    public function testResetsAfterAChangedAnswer(): void
    {
        $state = new WizardState();
        $state->type = 'static';
        $state->domain = 'a.test';
        $state->webDir = 'dist';
        $state->dbMode = WizardState::DB_SQLITE;
        $state->overrides = ['steps.seed' => 'every'];

        $state->resetFromDomain();
        self::assertNull($state->domain);
        self::assertSame(WizardState::DB_NONE, $state->dbMode);
        self::assertSame('dist', $state->webDir);

        $state->resetFromType();
        self::assertNull($state->type);
        self::assertNull($state->webDir);
        self::assertSame([], $state->overrides);
    }

    /**
     * @covers-req VAL-01
     */
    public function testSuggestedSiteNames(): void
    {
        self::assertSame('my-shop', RepositoryStep::suggest('My_Shop'));
        self::assertSame('site', RepositoryStep::suggest('___'));
        self::assertSame(31, strlen(RepositoryStep::suggest(str_repeat('a', 50))));
    }

    public function testServedFolderValidation(): void
    {
        self::assertNull(ServingStep::validate('dist'));
        self::assertNull(ServingStep::validate('site/public/'));
        self::assertNotNull(ServingStep::validate('../x'));
        self::assertNotNull(ServingStep::validate('a//b'));
        self::assertNotNull(ServingStep::validate('~/x'));
    }

    public function testPhpRequirementLine(): void
    {
        $info = new ProjectInfo('laravel', ['require' => ['php' => '^8.2', 'ext-intl' => '*', 'ext-gd' => '*', 'laravel/framework' => '^12']], ['packages' => []], null, '12.31.0', true, false);
        self::assertSame('composer.lock needs PHP ^8.2 and: intl, gd', PhpStep::requirement($info));
        self::assertNull(PhpStep::requirement(new ProjectInfo('static', null, null, null, null, false, false)));
    }
}
