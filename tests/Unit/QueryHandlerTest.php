<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Invalidator;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\TestCase;

class QueryHandlerTest extends TestCase
{
    private Redis $redis;
    private Cache $cache;
    private Invalidator $invalidator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'p:']);

        $this->redis = new Redis();
        $this->cache = new Cache($this->redis, new Encoder(), 0);
        $this->invalidator = new Invalidator($this->redis);
    }

    public function testCacheQueryMissStoresResultAndTags(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator);
        $builder = DB::table('cars');
        $handler->setBuilder($builder);

        $executions = 0;
        $result = $handler->cacheQuery(function () use (&$executions): array {
            $executions++;
            return [['id' => 1, 'name' => 'car']];
        });

        $this->assertSame(1, $executions);
        $this->assertSame([['id' => 1, 'name' => 'car']], $result);

        // Assert cache key and tags present
        $reflector = new Reflector($builder);
        $generation = $this->redis->currentGeneration();
        $storedKey = $this->redis->prefix($generation->scope((new Hasher($reflector))->getHash()));
        $tags = (new Tagger($reflector))->getTags();
        $this->assertSame(1, (int) $this->redis->exists($storedKey));
        foreach ($generation->scopeAll($tags) as $tag) {
            $this->assertSame(1, (int) $this->redis->sismember($this->redis->prefix($tag), $storedKey));
        }
    }

    public function testCacheQueryMissesAfterFlushAdvancesTheGeneration(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator);
        $builder = DB::table('cars');

        $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1, 'name' => 'stale-schema']]);
        $this->cache->flush();
        $result = $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1, 'name' => 'fresh-schema']]);

        $this->assertSame([['id' => 1, 'name' => 'fresh-schema']], $result);
    }

    public function testWriteCapturedBeforeFlushIsNeverServedAfterIt(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator);
        $builder = DB::table('cars');

        $staleResult = $handler->setBuilder($builder)->cacheQuery(function (): array {
            $this->cache->flush();

            return [['id' => 1, 'name' => 'read-before-migration']];
        });
        $result = $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1, 'name' => 'read-after-migration']]);

        $this->assertSame([['id' => 1, 'name' => 'read-before-migration']], $staleResult);
        $this->assertSame([['id' => 1, 'name' => 'read-after-migration']], $result);
    }

    public function testCacheQueryHitReadsCachedValueAndRepairsTags(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator);
        $builder = DB::table('cars');
        $handler->setBuilder($builder);

        // Pre-seed cache value and remove tag membership to test repair
        $reflector = new Reflector($builder);
        $key = (new Hasher($reflector))->getHash();
        $tags = (new Tagger($reflector))->getTags();
        $encoded = (new Encoder())->encode([['id' => 2]]);
        $generation = $this->redis->currentGeneration();
        $storedKey = $this->redis->prefix($generation->scope($key));
        $this->redis->set($storedKey, $encoded);
        foreach ($generation->scopeAll($tags) as $tag) {
            $this->redis->srem($this->redis->prefix($tag), $storedKey);
        }

        $executions = 0;
        $result = $handler->cacheQuery(function () use (&$executions): array {
            $executions++;
            return [['id' => 99]]; // should not be used on hit
        });

        $this->assertSame(0, $executions, 'Query closure should not run on cache hit');
        $this->assertSame([['id' => 2]], $result);

        // Assert tag membership repaired
        foreach ($generation->scopeAll($tags) as $tag) {
            $this->assertSame(1, (int) $this->redis->sismember($this->redis->prefix($tag), $storedKey));
        }
    }
}