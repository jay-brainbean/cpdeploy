<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Laravel;

use Cpdeploy\Laravel\MigrationStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers-req LAR-03
 */
final class MigrationStatusTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/artisan/' . $name . '.txt');
    }

    public function testLaravel8Table(): void
    {
        $check = MigrationStatus::parse(8, self::fixture('migrate-status-8'), 0);

        self::assertTrue($check->known);
        self::assertSame(['2019_08_19_000000_create_failed_jobs_table', '2019_12_14_000001_create_personal_access_tokens_table'], $check->pending);
    }

    public function testLaravel9And10Lines(): void
    {
        $nine = MigrationStatus::parse(9, self::fixture('migrate-status-9'), 0);
        self::assertTrue($nine->known);
        self::assertSame(['2019_08_19_000000_create_failed_jobs_table'], $nine->pending);

        $ten = MigrationStatus::parse(10, self::fixture('migrate-status-10'), 0);
        self::assertTrue($ten->known);
        self::assertSame([], $ten->pending);
    }

    public function testLaravel11To13PendingExitCode(): void
    {
        foreach ([11, 12, 13] as $major) {
            $check = MigrationStatus::parse($major, self::fixture("migrate-status-{$major}-pending"), 3);
            self::assertTrue($check->known, "Laravel {$major}");
            self::assertSame(['2026_09_28_000000_add_coupons_table', '2026_09_28_000001_add_discount_to_orders'], $check->pending);
        }
        $none = MigrationStatus::parse(11, self::fixture('migrate-status-11-none'), 0);
        self::assertTrue($none->known);
        self::assertSame([], $none->pending);

        $ansi = MigrationStatus::parse(12, self::fixture('migrate-status-11-ansi'), 3);
        self::assertSame(['2026_09_28_000000_add_coupons_table'], $ansi->pending);
    }

    public function testMigrationTableNotFoundMeansAllPending(): void
    {
        $check = MigrationStatus::parse(12, self::fixture('migrate-status-no-table'), 1, ['a', 'b']);

        self::assertTrue($check->known);
        self::assertSame(['a', 'b'], $check->pending);
    }

    public function testAnythingElseIsUnknown(): void
    {
        self::assertFalse(MigrationStatus::parse(12, 'SQLSTATE[HY000] [2002] Connection refused', 1)->known);
        self::assertFalse(MigrationStatus::parse(9, 'Could not open input file: artisan', 1)->known);
        self::assertFalse(MigrationStatus::parse(12, 'garbage', 3)->known);
        // Unknown version: --pending=3 refused → the older text formats are tried.
        self::assertSame(['2019_08_19_000000_create_failed_jobs_table'], MigrationStatus::parse(null, self::fixture('migrate-status-9'), 1)->pending);
    }
}
