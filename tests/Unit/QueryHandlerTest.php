<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class QueryHandlerTest extends TestCase
{
    private Cache $cache;

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

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'p:']);

        $this->cache = new Cache(new Redis(), new Encoder(), new ExpirationPolicy(0));
    }

    public function testMissRunsQueryOnceAndHitServesStoredRows(): void
    {
        $handler = new QueryHandler($this->cache);
        $builder = DB::table('cars');
        $executions = 0;
        $query = function () use (&$executions): array {
            $executions++;

            return [['id' => 1, 'name' => 'car']];
        };

        $first = $handler->setBuilder($builder)->cacheQuery($query);
        $second = $handler->setBuilder($builder)->cacheQuery($query);

        $this->assertSame(1, $executions);
        $this->assertSame([['id' => 1, 'name' => 'car']], $first);
        $this->assertSame($first, $second);
    }

    public function testCacheHitCostsExactlyOneRedisCommand(): void
    {
        $handler = new QueryHandler($this->cache);
        $builder = DB::table('cars')->where('id', 1);
        $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1]]);

        $commands = $this->recordRedisCommands(fn () => $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1]]));

        $this->assertSame(['eval'], $commands);
    }

    public function testCacheMissCostsTwoRedisCommandsAndInvalidationOne(): void
    {
        $handler = new QueryHandler($this->cache);
        $builder = DB::table('cars')->where('id', 1);

        $missCommands = $this->recordRedisCommands(fn () => $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1]]));
        $invalidationCommands = $this->recordRedisCommands(fn () => $handler->setBuilder(DB::table('cars')->where('id', 1))->invalidateQuery('update', ['name' => 'x']));

        $this->assertSame(['eval', 'eval'], $missCommands);
        $this->assertSame(['eval'], $invalidationCommands);
    }

    public function testCacheQueryMissesAfterFlushRetiresTheGeneration(): void
    {
        $handler = new QueryHandler($this->cache);
        $builder = DB::table('cars');

        $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1, 'name' => 'stale-schema']]);
        $this->cache->flush();
        $result = $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1, 'name' => 'fresh-schema']]);

        $this->assertSame([['id' => 1, 'name' => 'fresh-schema']], $result);
    }

    public function testResultCapturedBeforeFlushIsNeverServedAfterIt(): void
    {
        $handler = new QueryHandler($this->cache);
        $builder = DB::table('cars');

        $staleResult = $handler->setBuilder($builder)->cacheQuery(function (): array {
            $this->cache->flush();

            return [['id' => 1, 'name' => 'read-before-migration']];
        });
        $result = $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1, 'name' => 'read-after-migration']]);

        $this->assertSame([['id' => 1, 'name' => 'read-before-migration']], $staleResult);
        $this->assertSame([['id' => 1, 'name' => 'read-after-migration']], $result);
    }

    public function testResultCapturedBeforeConcurrentInvalidationIsNeverServed(): void
    {
        $handler = new QueryHandler($this->cache);
        $builder = DB::table('cars')->where('id', 1);

        $handler->setBuilder($builder)->cacheQuery(function () use ($handler): array {
            $handler->setBuilder(DB::table('cars')->where('id', 1))->invalidateQuery('update', ['name' => 'after']);

            return [['id' => 1, 'name' => 'before']];
        });
        $result = $handler->setBuilder($builder)->cacheQuery(fn (): array => [['id' => 1, 'name' => 'after']]);

        $this->assertSame([['id' => 1, 'name' => 'after']], $result);
    }

    public function testFailingSqlRunsExactlyOnceAndPropagates(): void
    {
        $handler = new QueryHandler($this->cache);
        $attempts = 0;

        try {
            $handler->setBuilder(DB::table('cars'))->cacheQuery(function () use (&$attempts): array {
                $attempts++;

                return DB::select('select * from table_that_does_not_exist');
            });
            $this->fail('Expected the SQL error to propagate');
        } catch (QueryException) {
            $this->assertSame(1, $attempts);
        }
    }

    public function testUnreachableRedisFallsBackToTheDatabaseWithoutThrowing(): void
    {
        Log::spy();
        $handler = $this->handlerWithUnreachableRedis();
        $executions = 0;
        $query = function () use (&$executions): array {
            $executions++;

            return [['id' => 1]];
        };

        $first = $handler->setBuilder(DB::table('cars'))->cacheQuery($query);
        $second = $handler->setBuilder(DB::table('cars'))->cacheQuery($query);

        $this->assertSame([['id' => 1]], $first);
        $this->assertSame([['id' => 1]], $second);
        $this->assertSame(2, $executions);
        Log::shouldHaveReceived('warning')->once();
    }

    public function testUnreachableRedisIsSkippedAfterTheFirstFailure(): void
    {
        Log::spy();
        $handler = $this->handlerWithUnreachableRedis();

        for ($query = 0; $query < 5; $query++) {
            $handler->setBuilder(DB::table('cars'))->cacheQuery(fn (): array => []);
        }
        $handler->setBuilder(DB::table('cars')->where('id', 1))->invalidateQuery('update', ['name' => 'x']);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => ! str_contains($message, 'cooldown'));
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => str_contains($message, 'cooldown'));
    }

    public function testUnreachableRedisDuringInvalidationLogsAnErrorWithoutThrowing(): void
    {
        Log::spy();
        $handler = $this->handlerWithUnreachableRedis();

        $handler->setBuilder(DB::table('cars')->where('id', 1))->invalidateQuery('update', ['name' => 'x']);
        $handler->setBuilder(DB::table('cars')->where('id', 1))->invalidateQuery('update', ['name' => 'y']);

        Log::shouldHaveReceived('error')->twice();
    }

    private function handlerWithUnreachableRedis(): QueryHandler
    {
        config(['lada-cache.redis_connection' => 'unreachable']);

        return new QueryHandler(new Cache(new Redis(), new Encoder(), new ExpirationPolicy(0)));
    }

    /**
     * @return list<string>
     */
    private function recordRedisCommands(callable $callback): array
    {
        $commands = [];
        $connection = RedisFacade::connection((string) config('lada-cache.redis_connection'));
        $connection->setEventDispatcher(app('events'));
        app('events')->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
            $commands[] = strtolower($event->command);
        });

        $callback();
        $connection->unsetEventDispatcher();

        return $commands;
    }
}
