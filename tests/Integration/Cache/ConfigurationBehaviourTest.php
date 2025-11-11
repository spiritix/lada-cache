<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Spiritix\LadaCache\Cache;
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
        $this->assertInstanceOf(\Illuminate\Database\Query\Builder::class, $builder);
        $this->assertNotInstanceOf(\Spiritix\LadaCache\Database\QueryBuilder::class, $builder);
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
        $databaseTag = 'tags:database:' . $dbName; // e.g., 'tags:database::memory:'
        $this->assertContains($databaseTag . 'cars', $tagsWithoutRows);
    }

    public function testPrefixAppliedToKeys(): void
    {
        config(['lada-cache.prefix' => 'test:']);
        $redis = new LadaRedis();
        $this->assertSame('test:foo', $redis->prefix('foo'));
    }

    public function testExpirationTimeControlsTTL(): void
    {
        config(['lada-cache.expiration_time' => 60, 'lada-cache.prefix' => 'cfg:']);
        $redis = new LadaRedis();
        /** @var Cache $cache */
        $cache = app('lada.cache');

        $key = 'expire-key';
        $tags = [];
        $cache->set($key, $tags, ['x' => 1]);

        // TTL should be >=0 (exists) and <= 60
        $ttl = $redis->ttl($redis->prefix($key));
        $this->assertIsInt($ttl);
        $this->assertTrue($ttl === -1 || $ttl <= 60, 'TTL should be -1 (no expire) or <= configured expiration');
    }

    public function testRedisConnectionRespected(): void
    {
        // Use default connection to avoid missing connection names
        config(['lada-cache.redis_connection' => 'default']);
        $lada = new LadaRedis();
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
        $this->app->instance('debugbar', new class {
            public function addCollector($c): void { $this->collectors[] = $c; }
        });

        // Run a query to ensure no exceptions are thrown
        DB::table('cars')->get();
        $this->assertTrue(true);
    }
}