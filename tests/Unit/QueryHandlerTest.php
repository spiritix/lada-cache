<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Events\LadaCacheActivity;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Invalidator;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\TtlResolver;

class QueryHandlerTest extends TestCase
{
    private Redis $redis;
    private Cache $cache;
    private Invalidator $invalidator;
    private TtlResolver $ttlResolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'p:']);

        $this->redis = new Redis();
        $this->cache = new Cache($this->redis, new Encoder(), 0);
        $this->invalidator = new Invalidator($this->redis);
        $this->ttlResolver = new TtlResolver();
    }

    public function testCacheQueryMissStoresResultAndTags(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator, $this->ttlResolver);
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
        $key = (new Hasher($reflector))->getHash();
        $tags = (new Tagger($reflector))->getTags();
        $this->assertSame(1, (int) $this->redis->exists($this->redis->prefix($key)));
        foreach ($tags as $tag) {
            $this->assertSame(1, (int) $this->redis->sismember($this->redis->prefix($tag), $this->redis->prefix($key)));
        }
    }

    public function testCacheQueryHitReadsCachedValueAndRepairsTags(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator, $this->ttlResolver);
        $builder = DB::table('cars');
        $handler->setBuilder($builder);

        // Pre-seed cache value and remove tag membership to test repair
        $reflector = new Reflector($builder);
        $key = (new Hasher($reflector))->getHash();
        $tags = (new Tagger($reflector))->getTags();
        $encoded = (new Encoder())->encode([['id' => 2]]);
        $this->redis->set($this->redis->prefix($key), $encoded);
        foreach ($tags as $tag) {
            $this->redis->srem($this->redis->prefix($tag), $this->redis->prefix($key));
        }

        $executions = 0;
        $result = $handler->cacheQuery(function () use (&$executions): array {
            $executions++;
            return [['id' => 99]]; // should not be used on hit
        });

        $this->assertSame(0, $executions, 'Query closure should not run on cache hit');
        $this->assertSame([['id' => 2]], $result);

        // Assert tag membership repaired
        foreach ($tags as $tag) {
            $this->assertSame(1, (int) $this->redis->sismember($this->redis->prefix($tag), $this->redis->prefix($key)));
        }
    }

    public function testCacheActivityFollowsCalibrationEnabledFlag(): void
    {
        config(['lada-cache.calibration.enabled' => true]);
        Event::fake([LadaCacheActivity::class]);

        $handler = new QueryHandler($this->cache, $this->invalidator, $this->ttlResolver);
        $handler->setBuilder(DB::table('cars'));

        $handler->cacheQuery(static fn (): array => [['id' => 1, 'name' => 'car']]);

        Event::assertDispatched(
            LadaCacheActivity::class,
            static fn (LadaCacheActivity $event): bool => $event->action === 'miss' && $event->table === 'cars',
        );
    }

    public function testCacheActivityIsSilentWhenCalibrationDisabled(): void
    {
        config(['lada-cache.calibration.enabled' => false]);
        Event::fake([LadaCacheActivity::class]);

        $handler = new QueryHandler($this->cache, $this->invalidator, $this->ttlResolver);
        $handler->setBuilder(DB::table('cars'));

        $handler->cacheQuery(static fn (): array => [['id' => 1, 'name' => 'car']]);

        Event::assertNotDispatched(LadaCacheActivity::class);
    }
}
