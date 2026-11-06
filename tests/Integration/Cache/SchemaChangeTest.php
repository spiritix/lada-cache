<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\SchemaVersion;
use Spiritix\LadaCache\Support\RedisAvailability;
use Spiritix\LadaCache\Tests\TestCase;

final class SchemaChangeTest extends TestCase
{
    public function testRecordedMigrationStopsServingEntriesOfThePreviousSchemaWithoutAnyRetire(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'pre-migration', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('pre-migration', DB::table('cars')->where('id', 1)->value('name'));
        $generation = $this->generationId();

        $pdo = DB::connection()->getPdo();
        $pdo->exec("update cars set name = 'post-migration' where id = 1");
        $pdo->exec("insert into migrations (migration, batch) values ('2026_06_11_000000_out_of_band', 99)");
        $this->expireSchemaVersion();

        $this->assertSame('post-migration', DB::table('cars')->where('id', 1)->value('name'));
        $this->assertSame($generation, $this->generationId());
    }

    public function testUnchangedSchemaKeepsServingEntriesAfterTheSchemaVersionExpires(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'cached', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->value('name');
        DB::connection()->getPdo()->exec("update cars set name = 'changed outside lada' where id = 1");

        $this->expireSchemaVersion();

        $this->assertSame('cached', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testSchemaVersionIsReadFromTheDatabaseAtMostOncePerTtl(): void
    {
        DB::table('cars')->where('id', 1)->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        DB::table('cars')->where('id', 2)->get();
        DB::table('drivers')->get();
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_contains($entry['query'], 'migrations'),
        )));
    }

    public function testMissingMigrationsTableStillCaches(): void
    {
        config(['database.migrations' => 'table_that_does_not_exist']);
        $this->expireSchemaVersion();
        DB::table('cars')->insert(['id' => 1, 'name' => 'cached', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->value('name');
        DB::connection()->getPdo()->exec("update cars set name = 'changed outside lada' where id = 1");

        $this->assertSame('cached', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testMigrationInsideATransactionRetiresAgainAtCommit(): void
    {
        DB::beginTransaction();
        event(new MigrationEnded(new class extends Migration {}, 'up'));
        $retiredDuringMigration = $this->generationId();
        DB::table('cars')->insert(['id' => 1, 'name' => 'uncommitted schema', 'engine_id' => null, 'driver_id' => null]);
        DB::commit();

        $this->assertNotSame($retiredDuringMigration, $this->generationId());
    }

    public function testSchemaLoadedRetiresTheCache(): void
    {
        $generation = $this->generationId();

        event(new SchemaLoaded(DB::connection(), 'database/schema/sqlite-schema.sql'));

        $this->assertNotSame($generation, $this->generationId());
    }

    public function testDatabaseRefreshedRetiresTheCache(): void
    {
        $generation = $this->generationId();

        event(new DatabaseRefreshed());

        $this->assertNotSame($generation, $this->generationId());
    }

    public function testRetireAfterSchemaChangeIgnoresTheCooldown(): void
    {
        $availability = RedisAvailability::processLocal();
        $availability->markUnavailable();
        $cache = new Cache(new Redis(null, $availability), new Encoder(), new ExpirationPolicy(0));
        $generation = $this->generationId();

        $cache->retireAfterSchemaChange();

        $this->assertNotSame($generation, $this->generationId());
    }

    public function testEntryOfAnotherSchemaVersionIsNotServed(): void
    {
        $cache = new Cache(new Redis(), new Encoder(), new ExpirationPolicy(0));
        $this->expireSchemaVersion('db');
        $miss = $cache->lookup('k', ['t'], SchemaVersion::fixed('db', '1'));
        $this->assertInstanceOf(CacheMiss::class, $miss);
        $cache->store($miss, [['schema' => 1]]);

        $this->expireSchemaVersion('db');

        $this->assertInstanceOf(CacheMiss::class, $cache->lookup('k', ['t'], SchemaVersion::fixed('db', '2')));
    }

    private function generationId(): string
    {
        $redis = new Redis();

        return (string) $redis->get($redis->prefix('generation'));
    }

    private function expireSchemaVersion(?string $database = null): void
    {
        $redis = new Redis();
        $redis->del($redis->prefix('schema:'.($database ?? DB::connection()->getDatabaseName())));
    }
}
