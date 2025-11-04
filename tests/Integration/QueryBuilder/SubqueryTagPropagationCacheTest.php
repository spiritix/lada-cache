<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Redis as LadaRedis;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class SubqueryTagPropagationCacheTest extends TestCase
{
    use CacheAssertions;

    public function testJoinSubCachesKeyUnderBothMainAndSubqueryTags(): void
    {
        DB::table('engines')->insert(['id' => 901, 'name' => 'E']);
        DB::table('cars')->insert(['id' => 801, 'name' => 'C', 'engine_id' => 901, 'driver_id' => null]);

        $sub = DB::table('engines')->select('id');
        $builder = DB::table('cars')
            ->joinSub($sub, 'engines', 'cars.engine_id', '=', 'engines.id')
            ->where('cars.id', 801)
            ->select('cars.*');

        $reflector = new Reflector($builder);
        $db = $reflector->getDatabase();
        $key = (new Hasher($reflector))->getHash();
        $tags = (new Tagger($reflector))->getTags();

        /** @var \Spiritix\LadaCache\Cache $cache */
        $cache = app('lada.cache');
        $cache->set($key, $tags, [['id' => 801, 'name' => 'C']]);
        $this->assertCacheHas($key);

        $redis = new LadaRedis();
        $carsSpecific = "tags:database:{$db}:table_specific:cars";
        $enginesUnspecific = "tags:database:{$db}:table_unspecific:engines";
        $this->assertTrue((bool) $redis->sismember($redis->prefix($carsSpecific), $redis->prefix($key)));
        $this->assertTrue((bool) $redis->sismember($redis->prefix($enginesUnspecific), $redis->prefix($key)));
    }
}
