<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Deploy;

use Cpdeploy\Deploy\Release;
use Cpdeploy\Deploy\ReleaseManager;
use Cpdeploy\Deploy\StateFile;
use Cpdeploy\Support\Clock;
use Cpdeploy\Tests\Support\TestCase;
use DateTimeImmutable;
use DateTimeZone;

final class ReleaseManagerTest extends TestCase
{
    private const NOW = '2026-09-29T12:00:00Z';

    private function manager(): ReleaseManager
    {
        $services = $this->services();

        return new ReleaseManager($services->paths(), $services->fs(), new Clock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))));
    }

    private function release(string $id, string $status, bool $protected = false, ?string $created = null): Release
    {
        return new Release($id, '/x/' . $id, ['id' => $id, 'status' => $status, 'protected' => $protected, 'created_at' => $created ?? '2026-09-29T00:00:00Z']);
    }

    /**
     * @param list<Release> $list
     * @return list<string>
     */
    private static function ids(array $list): array
    {
        return array_map(static fn (Release $r): string => $r->id, $list);
    }

    /**
     * @covers-req PR-01
     */
    public function testPruneSelection(): void
    {
        $now = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $releases = [
            $this->release('20260929-110000', Release::BUILDING, created: '2026-09-29T11:30:00Z'), // recent, another run
            $this->release('20260929-100000', Release::LIVE),
            $this->release('20260929-090000', Release::FAILED),
            $this->release('20260929-080000', Release::READY),
            $this->release('20260929-070000', Release::READY, true),       // protected, in addition
            $this->release('20260929-060000', Release::READY),
            $this->release('20260929-050000', Release::BUILDING),          // stale
            $this->release('20260929-040000', Release::FAILED, true),      // protected failure stays
        ];

        $sel = ReleaseManager::pruneSelection($releases, '20260929-100000', 2, null, $now);

        self::assertSame(['20260929-110000', '20260929-100000', '20260929-080000', '20260929-070000', '20260929-040000'], self::ids($sel['keep']));
        self::assertSame(['20260929-090000', '20260929-060000', '20260929-050000'], self::ids($sel['delete']));
    }

    /**
     * @covers-req PR-01
     */
    public function testLiveCountsTowardKeepEvenWhenOlder(): void
    {
        $now = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $releases = [
            $this->release('20260929-100000', Release::READY),
            $this->release('20260929-090000', Release::READY),
            $this->release('20260929-080000', Release::LIVE), // after a rollback
        ];
        $sel = ReleaseManager::pruneSelection($releases, '20260929-080000', 2, null, $now);

        self::assertSame(['20260929-100000', '20260929-080000'], self::ids($sel['keep']));
        self::assertSame(['20260929-090000'], self::ids($sel['delete']));
    }

    /**
     * @covers-req FS-03
     * @covers-req INV-02
     */
    public function testCreateCollisionsPruneAndLive(): void
    {
        mkdir($this->root . '/sites/shop', 0711, true);
        mkdir($this->root . '/sites/shop/shared/storage', 0755, true);
        file_put_contents($this->root . '/sites/shop/shared/storage/sentinel', 'keep me');
        $manager = $this->manager();
        $fs = $this->services()->fs();

        $a = $manager->create('shop');
        $b = $manager->create('shop');
        self::assertSame('20260929-120000', $a->id);
        self::assertSame('20260929-120000-2', $b->id);
        self::assertSame(0755, fileperms($a->dir) & 0777);

        foreach ([$a, $b] as $r) {
            symlink('../../shared/storage', $r->dir . '/storage');
            $r->set('status', Release::READY);
            $r->save($fs);
        }
        $fs->linkRelative($this->root . '/sites/shop/current', $b->dir);
        self::assertSame($b->id, $manager->liveId('shop'));

        $manager->markLive($b, $a);
        self::assertSame(Release::LIVE, $manager->get('shop', $b->id)->status());
        self::assertSame(Release::READY, $manager->get('shop', $a->id)->status());
        self::assertSame(['20260929-120000-2', '20260929-120000'], self::ids($manager->all('shop')));

        $result = $manager->prune('shop', 2);
        self::assertSame(0, $result['removed']);

        $manager->protect('shop', $a->id, true);
        self::assertTrue($manager->get('shop', $a->id)->isProtected());

        $manager->delete('shop', $a->id);
        self::assertDirectoryDoesNotExist($a->dir);
        self::assertSame('keep me', file_get_contents($this->root . '/sites/shop/shared/storage/sentinel'));

        $this->expectExceptionMessage('is the live release');
        $manager->delete('shop', $b->id);
    }

    /**
     * @covers-req LCK-03
     */
    public function testStateFile(): void
    {
        mkdir($this->root . '/sites/shop', 0711, true);
        $file = $this->root . '/sites/shop/.deploy-state.json';
        $state = new StateFile($file, $this->services()->fs());
        self::assertFalse($state->exists());

        $state->begin('deploy', self::NOW, ['live_before' => '20260928-181002']);
        $state->update(['phase' => StateFile::MIGRATING, 'maintenance_on' => '20260928-181002']);

        $data = $state->read();
        self::assertIsArray($data);
        self::assertSame('migrating', $data['phase']);
        self::assertSame('20260928-181002', $data['live_before']);
        self::assertSame(0600, fileperms($file) & 0777);

        $state->delete();
        self::assertFileDoesNotExist($file);
    }
}
