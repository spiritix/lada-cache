<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheHit;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Hosts on 6.x keep entries and tag sets under the keys that version wrote, which this version
 * never touches. Enabling legacy invalidation makes every write delete them too, so a rolling
 * deploy does not leave pods on the old version serving results the new version invalidated, and
 * makes this version's reads depend on the 6.x tag sets, so writes by pods still on 6.x invalidate
 * what this version cached.
 */
final class LegacyInvalidationTest extends TestCase
{
    private function carsTag(): string
    {
        return 'tags:database:'.DB::getDatabaseName().':table_unspecific:cars';
    }

    /**
     * What a 6.x host does to invalidate a tag: read and delete the tag set in one transaction,
     * then delete every key it listed, each member being a key without the connection prefix.
     */
    private function invalidateAs5x(Redis $redis, string $tag, bool $deleteMembers = true): void
    {
        $connection = $redis->getConnection();
        $members = $connection->command('smembers', [$redis->prefix($tag)]);
        $connection->command('del', [$redis->prefix($tag)]);

        if (! $deleteMembers) {
            return;
        }

        foreach ($members as $member) {
            $connection->command('del', [$member]);
        }
    }

    public function testWritesDeleteTheTagSetsAndEntriesOfEarlierVersions(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder);

        $redis->set($redis->prefix($this->carsTag()), 'unused');
        $redis->getConnection()->command('del', [$redis->prefix($this->carsTag())]);
        $redis->getConnection()->command('sadd', [$redis->prefix($this->carsTag()), $redis->prefix('legacy-hash')]);
        $redis->set($redis->prefix('legacy-hash'), 'serialized rows');

        $cache->invalidate(Invalidation::of([$this->carsTag()]));

        $this->assertSame(0, (int) $redis->exists($redis->prefix($this->carsTag())));
        $this->assertSame(0, (int) $redis->exists($redis->prefix('legacy-hash')));
    }

    public function testInvalidationLeavesEarlierVersionsEntriesAloneByDefault(): void
    {
        config(['lada-cache.legacy_invalidation' => false]);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder);

        $redis->getConnection()->command('sadd', [$redis->prefix($this->carsTag()), $redis->prefix('legacy-hash')]);
        $redis->set($redis->prefix('legacy-hash'), 'serialized rows');

        $cache->invalidate(Invalidation::of([$this->carsTag()]));

        $this->assertSame(1, (int) $redis->exists($redis->prefix($this->carsTag())));
        $this->assertSame(1, (int) $redis->exists($redis->prefix('legacy-hash')));
    }

    public function testWritesThroughABuilderAlsoClearEarlierVersionsEntries(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        $redis->getConnection()->command('sadd', [$redis->prefix($this->carsTag()), $redis->prefix('legacy-hash')]);
        $redis->set($redis->prefix('legacy-hash'), 'serialized rows');

        DB::table('cars')->insert(['id' => 1, 'name' => 'car', 'engine_id' => null, 'driver_id' => null]);

        $this->assertSame(0, (int) $redis->exists($redis->prefix($this->carsTag())));
        $this->assertSame(0, (int) $redis->exists($redis->prefix('legacy-hash')));
    }

    public function testEntriesMissAfterAnEarlierVersionInvalidatesTheirTag(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder);
        $miss = $cache->lookup('k', [$this->carsTag()], []);
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, ['before']);
        $this->assertInstanceOf(CacheHit::class, $cache->lookup('k', [$this->carsTag()], []));

        $this->invalidateAs5x($redis, $this->carsTag());

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k', [$this->carsTag()], []));
    }

    public function testEntriesMissWhileAnEarlierVersionHasDeletedTheTagSetButNotYetItsKeys(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder);
        $cache->store($cache->lookup('k', [$this->carsTag()], []), ['before']);

        $this->invalidateAs5x($redis, $this->carsTag(), deleteMembers: false);

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k', [$this->carsTag()], []));
    }

    public function testAResultReadBeforeAnEarlierVersionInvalidatesIsNeverServed(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder);
        $capturedBeforeWrite = $cache->lookup('k', [$this->carsTag()], []);
        $this->assertInstanceOf(CacheMiss::class, $capturedBeforeWrite);

        $this->invalidateAs5x($redis, $this->carsTag());
        $cache->store($capturedBeforeWrite, ['read before the write']);

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k', [$this->carsTag()], []));
    }

    public function testEntriesStayCachedWhileNoEarlierVersionInvalidates(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder);
        $cache->store($cache->lookup('k', [$this->carsTag()], []), ['before']);
        $cache->store($cache->lookup('other', [$this->carsTag()], []), ['other']);

        $hit = $cache->lookup('k', [$this->carsTag()], []);

        $this->assertInstanceOf(CacheHit::class, $hit);
        $this->assertSame(['before'], $hit->rows);
    }

    public function testReadsThroughABuilderSeeWritesInvalidatedByAnEarlierVersion(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame(['before'], Car::query()->pluck('name')->all());
        DB::connection()->getPdo()->exec("update cars set name = 'after'");
        $this->assertSame(['before'], Car::query()->pluck('name')->all());

        $this->invalidateAs5x($redis, $this->carsTag());

        $this->assertSame(['after'], Car::query()->pluck('name')->all());
    }

    public function testAnInsertByAnEarlierVersionReachesACachedLookupOfTheMissingRow(): void
    {
        config(['lada-cache.legacy_invalidation' => true]);
        $redis = new Redis;
        $this->assertNull(Car::query()->find(7));
        DB::connection()->getPdo()->exec("insert into cars (id, name) values (7, 'inserted by 6.x')");
        $this->assertNull(Car::query()->find(7));

        $this->invalidateAs5x($redis, $this->carsTag());

        $this->assertSame('inserted by 6.x', Car::query()->find(7)?->name);
    }

    public function testReadsLeaveTheTagSetsOfEarlierVersionsAloneByDefault(): void
    {
        config(['lada-cache.legacy_invalidation' => false]);
        $redis = new Redis;
        $cache = new Cache($redis, new Encoder);

        $cache->store($cache->lookup('k', [$this->carsTag()], []), ['rows']);

        $this->assertSame(0, (int) $redis->exists($redis->prefix($this->carsTag())));
        $this->assertSame(0, (int) $redis->exists($redis->prefix('legacy:'.$this->carsTag())));
    }
}
