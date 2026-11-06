<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Closure;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\FailedInvalidations;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Support\RedisAvailability;
use Spiritix\LadaCache\Tests\TestCase;

final class SoundnessTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.redis.unreachable', [
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 0.2,
            'read_write_timeout' => 0.2,
        ]);
    }

    public function testTimeDependentQueriesAreNeverCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);

        $selects = $this->selectsDuring(function (): void {
            DB::table('cars')->whereRaw('created_at <= CURRENT_TIMESTAMP or id = 1')->get();
            DB::table('cars')->whereRaw('created_at <= CURRENT_TIMESTAMP or id = 1')->get();
        });

        $this->assertSame(2, $selects);
    }

    public function testBoundTimestampsStayCacheable(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);

        $selects = $this->selectsDuring(function (): void {
            DB::table('cars')->where('created_at', '<', '2030-01-01 00:00:00')->get();
            DB::table('cars')->where('created_at', '<', '2030-01-01 00:00:00')->get();
        });

        $this->assertSame(1, $selects);
    }

    public function testReplicaReadsOfRecentlyWrittenTablesAreNotStored(): void
    {
        $cache = new Cache(new Redis, new Encoder, new ExpirationPolicy(0));
        $miss = $cache->lookup('k', ['t']);
        $this->assertInstanceOf(CacheMiss::class, $miss);

        $cache->invalidate(Invalidation::of(['t'], 'db', 'default', [], 30));
        $afterWrite = $cache->lookup('k', ['t']);
        $this->assertInstanceOf(CacheMiss::class, $afterWrite);
        $cache->store($afterWrite, [['read from a lagging replica']], ['t']);

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k', ['t']));
    }

    public function testPrimaryReadsAreStoredRightAfterAWrite(): void
    {
        $cache = new Cache(new Redis, new Encoder, new ExpirationPolicy(0));
        $cache->invalidate(Invalidation::of(['t'], 'db', 'default', [], 30));
        $miss = $cache->lookup('k', ['t']);
        $this->assertInstanceOf(CacheMiss::class, $miss);

        $cache->store($miss, [['read from the primary']]);

        $this->assertNotInstanceOf(CacheMiss::class, $cache->lookup('k', ['t']));
    }

    public function testWritesOnConnectionsWithReplicasKeepReplicaReadsUncachedDuringTheLag(): void
    {
        $this->requireDatabaseDriver('mysql', 'pgsql');
        $connection = (string) config('database.default');
        $host = config("database.connections.{$connection}.host");
        config([
            "database.connections.{$connection}.read" => ['host' => [$host]],
            "database.connections.{$connection}.write" => ['host' => [$host]],
            'lada-cache.replica_lag' => 30,
        ]);
        DB::purge($connection);
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->value('name');

        DB::table('cars')->where('id', 1)->update(['name' => 'after']);
        $selects = $this->selectsDuring(function (): void {
            DB::table('cars')->where('id', 1)->value('name');
            DB::table('cars')->where('id', 1)->value('name');
        });

        $this->assertSame(2, $selects);
    }

    public function testWritesOnConnectionsWithoutReplicasDoNotBlockStores(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->value('name');
        DB::table('cars')->where('id', 1)->update(['name' => 'after']);
        DB::table('cars')->where('id', 1)->value('name');

        $selects = $this->selectsDuring(fn () => DB::table('cars')->where('id', 1)->value('name'));

        $this->assertSame(0, $selects);
    }

    public function testInvalidationRejectedByRedisIsReplayedBeforeTheCacheIsReadAgain(): void
    {
        $failed = FailedInvalidations::processLocal();
        $working = new QueryHandler(app('lada.cache'), $failed);
        $unreachable = new QueryHandler($this->unreachableCache(), $failed);
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $read = fn (): array => $working->cacheQuery(DB::table('cars')->where('id', 1), 
            fn (): array => DB::connection()->select('select name from cars where id = 1')
        );
        $this->assertSame('before', $read()[0]->name);

        DB::connection()->getPdo()->exec("update cars set name = 'after' where id = 1");
        $unreachable->invalidateQuery(DB::table('cars')->where('id', 1), 'update', ['name' => 'after']);

        $this->assertTrue($failed->hasPending());
        $this->assertSame('after', $read()[0]->name);
        $this->assertFalse($failed->hasPending());
    }

    public function testInvalidationRejectedByRedisIsReplayedByTheNextRequestThroughApcu(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            $this->markTestSkipped('Needs APCu with apc.enable_cli=1, as PHP-FPM pools use it across requests.');
        }

        $failingRequest = FailedInvalidations::detect('lada-cache:test-failed:', 60);
        $failingRequest->record(Invalidation::of(['t'], 'db', 'default', [], 0));

        $nextRequest = FailedInvalidations::detect('lada-cache:test-failed:', 60);
        $replayed = [];
        $nextRequest->replay(function (Invalidation $invalidation) use (&$replayed): void {
            $replayed = $invalidation->tags();
        });

        $this->assertSame(['t'], $replayed);
        $this->assertFalse(FailedInvalidations::detect('lada-cache:test-failed:', 60)->hasPending());
    }

    public function testFailingReplayKeepsInvalidationsPendingAndBypassesTheCache(): void
    {
        $failed = FailedInvalidations::processLocal();
        $failed->record(Invalidation::of(['t'], 'db', 'default', [], 0));
        $handler = new QueryHandler($this->unreachableCache(), $failed);
        $executions = 0;

        $handler->cacheQuery(DB::table('cars'), function () use (&$executions): array {
            $executions++;

            return [];
        });

        $this->assertSame(1, $executions);
        $this->assertTrue($failed->hasPending());
    }

    public function testRejectedSchemaChangeRetiresTheGenerationOnceRedisIsBack(): void
    {
        $failed = FailedInvalidations::processLocal();
        $unreachable = new QueryHandler($this->unreachableCache(), $failed);
        $working = new QueryHandler(app('lada.cache'), $failed);
        $generation = $this->generationId();

        $unreachable->schemaChanged(DB::connection());
        $working->cacheQuery(DB::table('cars'), fn (): array => []);

        $this->assertNotSame($generation, $this->generationId());
    }

    private function unreachableCache(): Cache
    {
        $configured = config('lada-cache.redis_connection');
        config(['lada-cache.redis_connection' => 'unreachable']);
        $redis = new Redis(null, RedisAvailability::processLocal());
        config(['lada-cache.redis_connection' => $configured]);

        return new Cache($redis, new Encoder, new ExpirationPolicy(0));
    }

    private function generationId(): string
    {
        $redis = new Redis;

        return (string) $redis->get($redis->prefix('generation'));
    }

    private function selectsDuring(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select'),
        ));
    }
}
