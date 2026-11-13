<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Redis as LadaRedis;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\TestCase;

class ConfigurationBehaviourTest extends TestCase
{
    public function testActiveFalseDisablesCaching(): void
    {
        config(['lada-cache.active' => false]);

        $builder = DB::table('cars');

        // When inactive, DB::table() returns Laravel's default builder, not Lada's
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertNotInstanceOf(QueryBuilder::class, $builder);
    }

    public function testIncludeTablesOnlyCachesIncluded(): void
    {
        config(['lada-cache.active' => true, 'lada-cache.include_tables' => ['cars'], 'lada-cache.exclude_tables' => []]);

        $carsManager = new Manager(new Reflector(DB::table('cars')));
        $driversManager = new Manager(new Reflector(DB::table('drivers')));

        $this->assertTrue($carsManager->shouldCache());
        $this->assertFalse($driversManager->shouldCache());
    }

    public function testConsiderRowsTogglesRowSpecificTags(): void
    {
        // Seed data
        DB::table('cars')->insert(['id' => 777, 'name' => 'R', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 777);
        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();

        // With rows considered
        config(['lada-cache.consider_rows' => true]);
        $tagsWithRows = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_specific:cars", $tagsWithRows);
        $this->assertNotContains("tags:database:{$dbName}:table_unspecific:cars", $tagsWithRows);
        $this->assertContains("tags:database:{$dbName}:table_specific:cars:row:777", $tagsWithRows);

        // Without rows considered
        config(['lada-cache.consider_rows' => false]);
        $tagsWithoutRows = (new Tagger($reflector))->getTags();
        $this->assertSame(["tags:database:{$dbName}:table_unspecific:cars"], $tagsWithoutRows);
    }

    public function testPrefixAppliedToKeys(): void
    {
        config(['lada-cache.prefix' => 'test:']);
        $redis = new LadaRedis;
        $this->assertSame('test:foo', $redis->prefix('foo'));
    }

    public function testExpirationTimeControlsEntryAndVersionTtl(): void
    {
        config(['lada-cache.prefix' => 'cfg:']);
        $redis = new LadaRedis;
        $cache = new Cache($redis, new Encoder, new ExpirationPolicy(60));

        $miss = $cache->lookup('expire-key', ['tag'], []);
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, ['x' => 1]);

        $entryTtl = (int) $redis->ttl($redis->prefix($miss->generation->id.':e:expire-key'));
        $versionTtl = (int) $redis->ttl($redis->prefix($miss->generation->id.':v:tag'));
        $this->assertGreaterThan(0, $entryTtl);
        $this->assertLessThanOrEqual(60, $entryTtl);
        $this->assertGreaterThan(0, $versionTtl);
        $this->assertLessThanOrEqual(60, $versionTtl);
    }

    public function testPackageExpirationTimeDefaultsToSevenDays(): void
    {
        $packageConfig = require dirname(__DIR__, 3).'/config/lada-cache.php';

        $this->assertSame(604800, $packageConfig['expiration_time']);
        $this->assertSame(15, $packageConfig['expiration_jitter']);
    }

    public function testRedisConnectionRespected(): void
    {
        // Use default connection to avoid missing connection names
        config(['lada-cache.redis_connection' => 'default']);
        $lada = new LadaRedis;
        $connA = $lada->getConnection();
        $connB = RedisFacade::connection('default');
        $this->assertSame(get_class($connB), get_class($connA));
    }

    public function testDriverConfigReadable(): void
    {
        config(['lada-cache.driver' => 'redis']);
        $this->assertSame('redis', config('lada-cache.driver'));
    }

    public function testEnableDebugbarToggleDoesNotBreakQueries(): void
    {
        config(['lada-cache.enable_debugbar' => false]);
        DB::table('cars')->get();
        $this->assertTrue(true);

        config(['lada-cache.enable_debugbar' => true]);
        // Bind a fake debugbar to avoid missing service
        $this->app->instance('debugbar', new class
        {
            public function addCollector($c): void
            {
                $this->collectors[] = $c;
            }
        });

        // Run a query to ensure no exceptions are thrown
        DB::table('cars')->get();
        $this->assertTrue(true);
    }
}
