<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

class WriteMethodsTest extends TestCase
{
    public function testUpdateOrInsertWithCallbackValuesInsertsAndInvalidates(): void
    {
        $this->assertNull(DB::table('cars')->where('id', 1)->value('name'));

        DB::table('cars')->updateOrInsert(
            ['id' => 1],
            fn (bool $exists): array => ['name' => $exists ? 'updated' : 'inserted', 'engine_id' => null, 'driver_id' => null]
        );

        $this->assertSame('inserted', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testUpdateOrInsertWithCallbackValuesUpdatesAndInvalidates(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'original', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('original', DB::table('cars')->where('id', 1)->value('name'));

        DB::table('cars')->updateOrInsert(
            ['id' => 1],
            fn (bool $exists): array => ['name' => $exists ? 'updated' : 'inserted']
        );

        $this->assertSame('updated', DB::table('cars')->where('id', 1)->value('name'));
    }
}
