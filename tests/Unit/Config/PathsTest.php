<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Config;

use Cpdeploy\Config\Paths;
use Cpdeploy\Support\Environment;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    /**
     * @covers-req ARC-06
     */
    public function testLayoutMatchesSection71(): void
    {
        $p = new Paths('/home/brainbean');

        self::assertSame('/home/brainbean/cpdeploy', $p->root());
        self::assertSame('/home/brainbean/bin/cpdeploy', $p->launcher());
        self::assertSame('/home/brainbean/cpdeploy/app/cpdeploy.phar', $p->phar());
        self::assertSame('/home/brainbean/cpdeploy/app/cpdeploy.phar.prev', $p->previousPhar());
        self::assertSame('/home/brainbean/cpdeploy/config.yml', $p->configFile());
        self::assertSame('/home/brainbean/cpdeploy/secrets/github-token', $p->tokenFile());
        self::assertSame('/home/brainbean/cpdeploy/known_hosts', $p->knownHosts());
        self::assertSame('/home/brainbean/cpdeploy/tools/.lock', $p->toolsLock());
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/site.yml', $p->siteConfig('shop'));
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/.deploy-state.json', $p->stateFile('shop'));
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/repo.git', $p->mirror('shop'));
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/releases/20260929-030512', $p->release('shop', '20260929-030512'));
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/current', $p->current('shop'));
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/shared/.env', $p->sharedEnv('shop'));
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/shared/env-backups', $p->envBackupsDir('shop'));
        self::assertSame('/home/brainbean/cpdeploy/sites/shop/history.jsonl', $p->history('shop'));
        self::assertSame('/home/brainbean/.ssh/cpdeploy_shop', $p->deployKey('shop'));
    }

    /**
     * @covers-req LAY-02
     */
    public function testSkeletonModes(): void
    {
        $s = (new Paths('/h'))->skeleton();

        self::assertSame(0711, $s['/h/cpdeploy']);
        self::assertSame(0700, $s['/h/cpdeploy/secrets']);
        self::assertSame(0711, $s['/h/cpdeploy/tools']);
        self::assertSame(0700, $s['/h/cpdeploy/tmp']);
        self::assertSame(0700, $s['/h/cpdeploy/removed']);
        self::assertSame(0711, $s['/h/cpdeploy/sites']);
    }

    /**
     * CPDEPLOY_HOME is honoured only in test mode (§7.1, §16.2).
     */
    public function testCpdeployHomeOnlyInTestMode(): void
    {
        $prod = Paths::fromEnvironment(new Environment(['HOME' => '/home/u', 'CPDEPLOY_HOME' => '/tmp/evil']));
        $test = Paths::fromEnvironment(new Environment(['HOME' => '/home/u', 'CPDEPLOY_HOME' => '/tmp/t', 'CPDEPLOY_TESTING' => '1']));

        self::assertSame('/home/u/cpdeploy', $prod->root());
        self::assertSame('/tmp/t', $test->root());
    }

    public function testTestingVariablesAreIgnoredOutsideTestMode(): void
    {
        $env = new Environment(['CPDEPLOY_NOW' => '2026-01-01T00:00:00Z', 'CPDEPLOY_TESTING' => '0']);

        self::assertNull($env->testing('CPDEPLOY_NOW'));
        self::assertFalse($env->isTesting());
    }
}
