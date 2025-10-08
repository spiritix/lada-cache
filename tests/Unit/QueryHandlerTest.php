<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Redis\Connections\PredisConnection as RedisConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\MockObject\MockObject;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Invalidator;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class QueryHandlerTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $conn;
    private Redis $redis;
    private Cache $cache;
    private Invalidator $invalidator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lada-cache.prefix' => 'p:']);

        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->conn = $this->getMockBuilder(RedisConnection::class)
            ->disableOriginalConstructor()
            ->addMethods(['exists', 'get', 'set', 'sadd'])
            ->getMock();

        $this->redis = new Redis($this->conn);
        $this->cache = new Cache($this->redis, new Encoder(), 0);
        $this->invalidator = new Invalidator($this->redis);
    }

    public function testCacheQueryMissStoresResultAndTags(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator);
        $builder = DB::table('cars');
        $handler->setBuilder($builder);

        // Simulate cache miss
        $this->conn->expects($this->any())
            ->method('exists')
            ->willReturn(0);

        // Expect a set() call for the cached payload
        $this->conn->expects($this->once())
            ->method('set')
            ->willReturn(null);

        // Expect tags to be attached (at least one sadd call for table tag)
        $this->conn->expects($this->atLeastOnce())
            ->method('sadd')
            ->willReturnCallback(function (string $tagKey, string $cacheKey): void {
                $this->assertStringStartsWith('p:', $tagKey);
                $this->assertStringStartsWith('p:', $cacheKey);
            });

        $executions = 0;
        $result = $handler->cacheQuery(function () use (&$executions): array {
            $executions++;
            return [['id' => 1, 'name' => 'car']];
        });

        $this->assertSame(1, $executions);
        $this->assertSame([['id' => 1, 'name' => 'car']], $result);
    }

    public function testCacheQueryHitReadsCachedValueAndRepairsTags(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator);
        $builder = DB::table('cars');
        $handler->setBuilder($builder);

        // Simulate cache hit regardless of key
        $this->conn->expects($this->any())
            ->method('exists')
            ->willReturn(1);

        $encoded = (new Encoder())->encode([['id' => 2]]);
        $this->conn->expects($this->any())
            ->method('get')
            ->willReturn($encoded);

        // On hit, handler calls repairTagMembership => expect at least one sadd
        $this->conn->expects($this->atLeastOnce())
            ->method('sadd')
            ->willReturnCallback(function (string $tagKey, string $cacheKey): void {
                $this->assertStringStartsWith('p:', $tagKey);
                $this->assertStringStartsWith('p:', $cacheKey);
            });

        $executions = 0;
        $result = $handler->cacheQuery(function () use (&$executions): array {
            $executions++;
            return [['id' => 99]]; // should not be used on hit
        });

        $this->assertSame(0, $executions, 'Query closure should not run on cache hit');
        $this->assertSame([['id' => 2]], $result);
    }

    public function testCacheQueryBypassesCachingWhenRedisSetThrows(): void
    {
        $handler = new QueryHandler($this->cache, $this->invalidator);
        $builder = DB::table('cars');
        $handler->setBuilder($builder);

        // Force miss so set() is attempted
        $this->conn->expects($this->any())
            ->method('exists')
            ->willReturn(0);

        // Make set() throw to trigger bypass in QueryHandler
        $this->conn->expects($this->once())
            ->method('set')
            ->willReturnCallback(function (): void {
                throw new \RuntimeException('redis set failed');
            });

        $executions = 0;
        $result = $handler->cacheQuery(function () use (&$executions): array {
            $executions++;
            return [['id' => 3]];
        });

        // Note: current implementation retries the closure after catching; assert at least 1 execution
        $this->assertGreaterThanOrEqual(1, $executions);
        $this->assertSame([['id' => 3]], $result);
    }
}