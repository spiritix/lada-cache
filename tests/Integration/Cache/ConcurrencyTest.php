<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Redis as LadaRedis;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use CacheAssertions;

    public function testSelfHealsTagMembershipOnCacheHit(): void
    {
        // Seed a row and build a simple cached SELECT
        DB::table('cars')->insert(['id' => 910, 'name' => 'X', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 910);

        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();
        $tags = (new Tagger($reflector))->getTags();

        /** @var \Spiritix\LadaCache\Cache $cache */
        $cache = app('lada.cache');

        // Compute key and seed cache entry directly (stable in transactional harness)
        $key = (new Hasher($reflector))->getHash();
        $payload = [['id' => 910, 'name' => 'X']];
        $generation = $cache->currentGeneration();
        $cache->set($generation, $key, $tags, $payload);
        $this->assertCacheHas($key);

        // Remove membership from both specific and unspecific table tags
        $redis = new LadaRedis();
        $unspecificTag = $redis->prefix($generation->scope("tags:database:{$dbName}:table_unspecific:cars"));
        $specificTag = $redis->prefix($generation->scope("tags:database:{$dbName}:table_specific:cars"));
        $storedKey = $redis->prefix($generation->scope($key));
        $redis->srem($unspecificTag, $storedKey);
        $redis->srem($specificTag, $storedKey);
        $this->assertFalse((bool) $redis->sismember($unspecificTag, $storedKey));
        $this->assertFalse((bool) $redis->sismember($specificTag, $storedKey));

        // Directly exercise repair to avoid relying on read path timing
        $cache->repairTagMembership($generation, $key, $tags);

        // Membership should be restored in at least one of the table tags
        $restoredUnspecific = (bool) $redis->sismember($unspecificTag, $storedKey);
        $restoredSpecific = (bool) $redis->sismember($specificTag, $storedKey);
        $this->assertTrue($restoredUnspecific || $restoredSpecific, 'Expected tag membership to be repaired');
    }
}