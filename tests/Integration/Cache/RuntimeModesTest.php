<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis as RedisFacade;
use PDO;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheHit;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\CacheUnavailableException;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\RedisAvailability;
use Spiritix\LadaCache\Tests\TestCase;
use Throwable;

final class RuntimeModesTest extends TestCase
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
        $app['config']->set('database.connections.unopened', [
            'driver' => 'sqlite',
            'database' => '/nonexistent-directory/database.sqlite',
        ]);
    }

    public function testScriptsAreReloadedAfterRedisForgetsThem(): void
    {
        $cache = new Cache(new Redis, new Encoder, new ExpirationPolicy(0));
        $cache->store($this->miss($cache), [['id' => 1]]);

        RedisFacade::connection((string) config('lada-cache.redis_connection'))->command('script', ['flush']);

        $this->assertInstanceOf(CacheHit::class, $cache->lookup('k', ['t'], []));
    }

    public function testCooldownOutlivesTheRequestWhenApcuIsAvailable(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            $this->markTestSkipped('Needs APCu with apc.enable_cli=1, as PHP-FPM pools use it across requests.');
        }

        $firstRequest = $this->unreachableRedis();
        $firstFailure = $this->failureOf(fn () => $firstRequest->evaluate('return 1', []));
        $nextRequest = $this->unreachableRedis();
        $nextFailure = $this->failureOf(fn () => $nextRequest->evaluate('return 1', []));

        $this->assertNotNull($firstFailure);
        $this->assertNotInstanceOf(CacheUnavailableException::class, $firstFailure);
        $this->assertInstanceOf(CacheUnavailableException::class, $nextFailure);
    }

    public function testCooldownStaysWithinTheWorkerWithoutApcu(): void
    {
        $redis = $this->unreachableRedis(RedisAvailability::processLocal());
        $this->failureOf(fn () => $redis->evaluate('return 1', []));

        $this->assertInstanceOf(CacheUnavailableException::class, $this->failureOf(fn () => $redis->evaluate('return 1', [])));
    }

    public function testResolvingAConnectionDoesNotOpenIt(): void
    {
        $connection = DB::connection('unopened');

        $this->assertSame('unopened', $connection->getName());
    }

    public function testExistsCachesWithoutFetchingRowColumns(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue(DB::table('cars')->where('id', 1)->exists());
        $this->assertTrue(DB::table('cars')->where('id', 1)->exists());
        DB::disableQueryLog();

        $queries = array_column(DB::getQueryLog(), 'query');
        $carQueries = array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'cars')));
        $this->assertCount(1, $carQueries);
        $this->assertStringContainsString('1 as lada_exists', $carQueries[0]);
    }

    public function testUpdateOrInsertWithCallbackValuesInvalidates(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        DB::table('cars')->updateOrInsert(['id' => 1], static fn (bool $exists): array => ['name' => $exists ? 'updated' : 'inserted']);

        $this->assertSame('updated', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testPretendedReadsAreNotCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'real', 'engine_id' => null, 'driver_id' => null]);

        DB::pretend(fn () => DB::table('cars')->where('id', 1)->value('name'));

        $this->assertSame('real', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testFetchModesBypassTheCacheAndReachPdo(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'first', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->select('id', 'name')->get();

        $pairs = DB::table('cars')->select('id', 'name')->fetchUsing(PDO::FETCH_KEY_PAIR)->get()->all();

        $this->assertSame([1 => 'first'], $pairs);
    }

    public function testInsertOrIgnoreReturningInvalidates(): void
    {
        $this->requireDatabaseDriver('sqlite');
        $this->assertNull(DB::table('cars')->where('id', 1)->first());

        DB::table('cars')->insertOrIgnoreReturning(['id' => 1, 'name' => 'returned'], ['id'], 'id');

        $this->assertSame('returned', DB::table('cars')->where('id', 1)->first()?->name);
    }

    private function unreachableRedis(?RedisAvailability $availability = null): Redis
    {
        config(['lada-cache.redis_connection' => 'unreachable']);

        return new Redis(null, $availability);
    }

    private function failureOf(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function miss(Cache $cache): CacheMiss
    {
        $lookup = $cache->lookup('k', ['t'], []);
        $this->assertInstanceOf(CacheMiss::class, $lookup);

        return $lookup;
    }
}
