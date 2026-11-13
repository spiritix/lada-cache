<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\RedisAvailability;
use Spiritix\LadaCache\Tests\TestCase;

final class SchemaChangeTest extends TestCase
{
    public function testCacheReadsOnlyQueryTheTablesTheyRead(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        DB::table('cars')->where('id', 1)->value('name');
        DB::table('cars')->where('id', 1)->value('name');
        DB::disableQueryLog();

        $this->assertSame(
            [DB::table('cars')->select('name')->where('id', 1)->limit(1)->toSql()],
            array_column(DB::getQueryLog(), 'query'),
        );
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

        event(new DatabaseRefreshed);

        $this->assertNotSame($generation, $this->generationId());
    }

    public function testRetireAfterSchemaChangeIgnoresTheCooldown(): void
    {
        $availability = RedisAvailability::processLocal();
        $availability->markUnavailable();
        $cache = new Cache(new Redis(null, $availability), new Encoder, new ExpirationPolicy(0));
        $generation = $this->generationId();

        $cache->retireAfterSchemaChange();

        $this->assertNotSame($generation, $this->generationId());
    }

    private function generationId(): string
    {
        $redis = new Redis;

        return (string) $redis->get($redis->prefix('generation'));
    }
}
