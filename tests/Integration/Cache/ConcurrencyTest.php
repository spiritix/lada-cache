<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Redis as LadaRedis;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use CacheAssertions;

    public function testResultReadBeforeAConcurrentWriteIsNeverServed(): void
    {
        DB::table('cars')->insert(['id' => 910, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 910);
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $cache = app('lada.cache');

        $slowReader = $this->lookupRead($key, $tags);
        $this->assertInstanceOf(CacheMiss::class, $slowReader);
        $rowsReadBeforeWrite = [['id' => 910, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]];

        DB::table('cars')->where('id', 910)->update(['name' => 'after']);
        $cache->store($slowReader, $rowsReadBeforeWrite);

        $this->assertCacheMissing($key, $tags);
        $this->assertSame('after', DB::table('cars')->where('id', 910)->value('name'));
    }

    public function testEvictedTagVersionMakesDependentEntriesMiss(): void
    {
        DB::table('cars')->insert(['id' => 911, 'name' => 'X', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 911);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        $redis = new LadaRedis;
        $generation = (string) $redis->get($redis->prefix('generation'));
        $redis->del($redis->prefix($generation.':v:'.$tags->tags[0]));

        $this->assertCacheMissing($key, $tags);
    }

    public function testEvictedGenerationKeyMakesEveryEntryMiss(): void
    {
        DB::table('cars')->insert(['id' => 912, 'name' => 'X', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 912);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        $redis = new LadaRedis;
        $redis->del($redis->prefix('generation'));

        $this->assertCacheMissing($key, $tags);
    }
}
