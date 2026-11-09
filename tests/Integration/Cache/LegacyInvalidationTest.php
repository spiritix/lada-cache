<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Hosts on 5.x keep entries and tag sets under the keys that version wrote, which this version
 * never touches. Enabling legacy invalidation makes every write delete them too, so a rolling
 * deploy does not leave pods on the old version serving results the new version invalidated.
 */
final class LegacyInvalidationTest extends TestCase
{
    private function carsTag(): string
    {
        return 'tags:database:'.\Illuminate\Support\Facades\DB::getDatabaseName().':table_unspecific:cars';
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

        \Illuminate\Support\Facades\DB::table('cars')->insert(['id' => 1, 'name' => 'car', 'engine_id' => null, 'driver_id' => null]);

        $this->assertSame(0, (int) $redis->exists($redis->prefix($this->carsTag())));
        $this->assertSame(0, (int) $redis->exists($redis->prefix('legacy-hash')));
    }
}
