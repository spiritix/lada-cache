<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Database\QueryBuilder as LadaQueryBuilder;
use Spiritix\LadaCache\Tests\TestCase;

class ConnectionWiringTest extends TestCase
{
    public function testDbTableReturnsLadaQueryBuilder(): void
    {
        $builder = DB::table('cars');
        $this->assertInstanceOf(LadaQueryBuilder::class, $builder);
    }

    public function testSchemaGrammarIsMirroredAndUsable(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $this->assertNotNull($schema, 'Expected schema builder to be initialized');

        // Smoke test: ensure hasTable() call does not throw due to null grammar
        $this->assertIsBool($schema->hasTable('cars'));
    }
}
