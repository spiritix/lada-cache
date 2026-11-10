<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\LadaCacheServiceProvider;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

final class DegradedModeTest extends TestCase
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

    public function testModelsAndQueryBuilderWorkWhileRedisIsUnreachable(): void
    {
        $this->pointLadaAtUnreachableRedis();

        $car = Car::create(['name' => 'created']);
        $car->update(['name' => 'updated']);

        $this->assertSame('updated', Car::query()->find($car->id)?->name);
        $this->assertSame(1, DB::table('cars')->count());
        $this->assertTrue(DB::table('cars')->where('id', $car->id)->exists());

        DB::transaction(fn () => DB::table('cars')->where('id', $car->id)->delete());

        $this->assertSame(0, Car::query()->count());
    }

    public function testInvalidScopeDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['lada-cache.scope' => 'everything']);
    }

    public function testInvalidExpirationJitterDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['lada-cache.expiration_jitter' => 150]);
    }

    public function testNonStringPrefixDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['lada-cache.prefix' => ['lada:']]);
    }

    public function testNonStringRedisConnectionDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['lada-cache.redis_connection' => 7]);
    }

    public function testNonStringExcludedTableDisablesTheCacheInsteadOfCrashing(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['lada-cache.exclude_tables' => ['cars', ['engines']]]);
    }

    public function testConfigurationOfAnEarlierVersionDisablesTheCacheNamingTheRenamedKeys(): void
    {
        $this->assertInvalidConfigurationDisablesTheCache(['lada-cache.expiration-time' => 3600, 'lada-cache.consider-rows' => true]);

        Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => str_contains($message, 'lada-cache.expiration-time was renamed to lada-cache.expiration_time')
            && str_contains($message, 'lada-cache.consider-rows was renamed to lada-cache.consider_rows'));
    }

    /**
     * @param  array<string, mixed>  $invalidConfiguration
     */
    private function assertInvalidConfigurationDisablesTheCache(array $invalidConfiguration): void
    {
        Log::spy();
        DB::table('cars')->insert(['id' => 1, 'name' => 'plain', 'engine_id' => null, 'driver_id' => null]);
        config($invalidConfiguration);

        (new LadaCacheServiceProvider($this->app))->register();

        $this->assertFalse((bool) config('lada-cache.active'));
        $this->assertNotInstanceOf(QueryBuilder::class, DB::table('cars'));
        $this->assertSame('plain', Car::query()->find(1)?->name);
        Log::shouldHaveReceived('error')->once();
    }

    private function pointLadaAtUnreachableRedis(): void
    {
        config(['lada-cache.redis_connection' => 'unreachable']);

        foreach (['lada.redis', 'lada.cache', 'lada.handler'] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }
}
