<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Rollout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A rolling deploy from 5.x: hosts of both versions serve at once against one database and one
 * Redis, each a separate process. With legacy invalidation on, a write by a host of either
 * version must reach the cached reads of hosts of the other.
 */
final class RolloutTest extends TestCase
{
    private Pods $pods;

    protected function setUp(): void
    {
        $reason = Pods::skipReason();

        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        $this->pods = new Pods;
        $this->pods->run('new', 'reset');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function newHostWrites(): array
    {
        return [
            'query builder update' => ['update'],
            'model save' => ['model-save'],
            'DB::table() update' => ['db-update'],
            'raw statement' => ['raw-update'],
            'committed transaction' => ['tx-commit'],
        ];
    }

    #[DataProvider('newHostWrites')]
    public function testWritesByNewHostsReachTheCachedReadsOfOldHosts(string $write): void
    {
        $this->cacheReads('old');

        $this->pods->run('new', $write, 1, 'a2');

        $this->assertReadsMatchTheDatabase('old');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function oldHostWrites(): array
    {
        return [
            'query builder update' => ['update'],
            'model save' => ['model-save'],
            'committed transaction' => ['tx-commit'],
        ];
    }

    #[DataProvider('oldHostWrites')]
    public function testWritesByOldHostsReachTheCachedReadsOfNewHosts(string $write): void
    {
        $this->cacheReads('new');

        $this->pods->run('old', $write, 1, 'a2');

        $this->assertReadsMatchTheDatabase('new');
    }

    public function testInsertsAndDeletesReachHostsOfTheOtherVersion(): void
    {
        $this->cacheReads('old');
        $this->cacheReads('new');
        $this->pods->run('old', 'find', 5);
        $this->pods->run('new', 'find', 6);

        $this->pods->run('new', 'insert', 'e1');
        $this->pods->run('old', 'insert', 'f1');
        $this->pods->run('new', 'delete', 2);
        $this->pods->run('old', 'delete', 3);

        $this->assertSame(['a1', 'd1', 'e1', 'f1'], $this->pods->run('new', 'db-all'));
        $this->assertReadsMatchTheDatabase('old', [1, 2, 3, 4, 5, 6]);
        $this->assertReadsMatchTheDatabase('new', [1, 2, 3, 4, 5, 6]);
    }

    public function testRolledBackWritesLeaveBothVersionsServingTheCommittedRows(): void
    {
        $this->cacheReads('old');
        $this->cacheReads('new');

        $this->pods->run('new', 'tx-rollback', 1, 'never');
        $this->pods->run('old', 'tx-rollback', 2, 'never');

        $this->assertReadsMatchTheDatabase('old');
        $this->assertReadsMatchTheDatabase('new');
    }

    public function testAMigrationOnTheNewImageRetiresTheEntriesOfBothVersions(): void
    {
        $this->cacheReads('old');
        $this->cacheReads('new');
        $this->assertGreaterThan(0, $this->pods->run('new', 'legacy-keys'));

        $this->pods->run('new', 'migrate-add-color');

        $this->assertSame(0, $this->pods->run('new', 'legacy-keys'));
        $this->assertSame(['id' => 1, 'name' => 'a1', 'color' => 'red'], $this->pods->run('new', 'find', 1));
        $this->assertSame(['id' => 1, 'name' => 'a1', 'color' => 'red'], $this->pods->run('old', 'find', 1));
        $this->pods->run('old', 'update', 1, 'a2');
        $this->assertSame(['id' => 1, 'name' => 'a2', 'color' => 'red'], $this->pods->run('new', 'find', 1));
    }

    public function testASchemaChangeThatNeverFinishesItsMigrationBatchStillRetiresNewHostsEntries(): void
    {
        $this->cacheReads('new');

        $this->pods->run('new', 'migrate-failing');

        $this->assertSame(['id' => 1, 'name' => 'a1', 'color' => 'red'], $this->pods->run('new', 'find', 1));
    }

    public function testHostsOfBothVersionsStayFreshAfterRedisIsEmptiedMidRollout(): void
    {
        $this->cacheReads('old');
        $this->cacheReads('new');

        $this->pods->run('new', 'redis-emptied');
        $this->cacheReads('old');
        $this->cacheReads('new');
        $this->pods->run('old', 'update', 1, 'a2');
        $this->pods->run('new', 'update', 2, 'b2');

        $this->assertReadsMatchTheDatabase('old');
        $this->assertReadsMatchTheDatabase('new');
    }

    public function testRollingBackToOldHostsAndForwardAgainKeepsEveryHostFresh(): void
    {
        $this->cacheReads('old');
        $this->cacheReads('new');
        $this->pods->run('new', 'update', 1, 'a2');

        $this->assertReadsMatchTheDatabase('old');
        $this->pods->run('old', 'update', 2, 'b2');
        $this->pods->run('old', 'delete', 3);
        $this->assertReadsMatchTheDatabase('old');

        $this->assertReadsMatchTheDatabase('new');
    }

    public function testTurningLegacyInvalidationOffAfterTheRolloutKeepsNewHostsFresh(): void
    {
        $this->cacheReads('new');
        $withoutLegacy = $this->pods->with('ROLLOUT_LEGACY', '0');

        $this->assertSame(['a1', 'b1', 'c1', 'd1'], $withoutLegacy->run('new', 'all'));
        $withoutLegacy->run('new', 'update', 1, 'a2');

        $this->assertSame(['a2', 'b1', 'c1', 'd1'], $withoutLegacy->run('new', 'all'));
        $this->assertSame(['a2', 'b1', 'c1', 'd1'], $this->pods->run('new', 'all'));
    }

    public function testRollingBackAfterLegacyInvalidationIsOffNeedsAFlush(): void
    {
        $withoutLegacy = $this->pods->with('ROLLOUT_LEGACY', '0');
        $this->cacheReads('old');

        $withoutLegacy->run('new', 'update', 1, 'a2');

        $this->assertSame(['id' => 1, 'name' => 'a1'], $this->pods->run('old', 'find', 1));
        $withoutLegacy->run('new', 'flush');
        $this->assertReadsMatchTheDatabase('old');
    }

    public function testHostsUnderAClientKeyPrefixInvalidateEachOther(): void
    {
        $prefixed = $this->pods->with('ROLLOUT_REDIS_PREFIX', 'app_');
        $prefixed->run('new', 'reset');
        $this->cacheReadsOn($prefixed, 'old');
        $this->cacheReadsOn($prefixed, 'new');

        $prefixed->run('new', 'update', 1, 'a2');
        $prefixed->run('old', 'update', 2, 'b2');

        $this->assertReadsMatchTheDatabaseOn($prefixed, 'old');
        $this->assertReadsMatchTheDatabaseOn($prefixed, 'new');
    }

    private function cacheReads(string $version): void
    {
        $this->cacheReadsOn($this->pods, $version);
    }

    private function cacheReadsOn(Pods $pods, string $version): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $pods->run($version, 'find', $id);
        }

        $pods->run($version, 'all');
        $pods->run($version, 'count');
        $pods->run($version, 'where-in', 1, 3);
        $pods->run($version, 'where-name', 'a2');
        $pods->run($version, 'where-name', 'b2');
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertReadsMatchTheDatabase(string $version, array $ids = [1, 2, 3, 4]): void
    {
        $this->assertReadsMatchTheDatabaseOn($this->pods, $version, $ids);
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertReadsMatchTheDatabaseOn(Pods $pods, string $version, array $ids = [1, 2, 3, 4]): void
    {
        $all = $pods->run('new', 'db-all');

        $this->assertSame($all, $pods->run($version, 'all'), "{$version} all");
        $this->assertSame(count($all), $pods->run($version, 'count'), "{$version} count");

        foreach ($ids as $id) {
            $row = $pods->run('new', 'db-find', $id);
            $this->assertSame($row, $pods->run($version, 'find', $id), "{$version} find {$id}");

            if ($row !== null) {
                $this->assertSame([$id], $pods->run($version, 'where-name', $row['name']), "{$version} where-name {$row['name']}");
            }
        }

        $inNames = array_values(array_filter([$pods->run('new', 'db-find', 1)['name'] ?? null, $pods->run('new', 'db-find', 3)['name'] ?? null]));
        $this->assertSame($inNames, $pods->run($version, 'where-in', 1, 3), "{$version} where-in 1, 3");
    }
}
