<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

class InvalidationMatrixTest extends TestCase
{
    public function testInsertInvalidatesCachedSelect(): void
    {
        // Prime: count cars named 'C'
        $before = DB::table('cars')->where('name', 'C')->count();
        // Cache primed by the first call
        DB::table('cars')->where('name', 'C')->count();

        // Mutate: insert a matching row
        DB::table('cars')->insert(['id' => 1001, 'name' => 'C', 'engine_id' => null, 'driver_id' => null]);

        // Read again → should reflect new count (invalidated and re-cached)
        $after = DB::table('cars')->where('name', 'C')->count();
        $this->assertSame($before + 1, $after);
    }

    public function testUpdateInvalidatesCachedSelect(): void
    {
        DB::table('cars')->insert(['id' => 1002, 'name' => 'Z', 'engine_id' => null, 'driver_id' => null]);

        $first = DB::table('cars')->where('id', 1002)->value('name');
        $this->assertSame('Z', $first);

        // Mutate: update the name
        DB::table('cars')->where('id', 1002)->update(['name' => 'W']);

        // Read again → should reflect updated value
        $second = DB::table('cars')->where('id', 1002)->value('name');
        $this->assertSame('W', $second);
    }

    public function testDeleteInvalidatesCachedSelect(): void
    {
        DB::table('cars')->insert(['id' => 1003, 'name' => 'DEL', 'engine_id' => null, 'driver_id' => null]);

        $exists1 = DB::table('cars')->where('id', 1003)->exists();
        $this->assertTrue($exists1);

        // Mutate: delete the row
        DB::table('cars')->where('id', 1003)->delete();

        $exists2 = DB::table('cars')->where('id', 1003)->exists();
        $this->assertFalse($exists2);
    }

    public function testTruncateInvalidatesCachedSelect(): void
    {
        DB::table('cars')->insert(['id' => 1004, 'name' => 'T1', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->insert(['id' => 1005, 'name' => 'T2', 'engine_id' => null, 'driver_id' => null]);

        $count1 = DB::table('cars')->count();
        $this->assertGreaterThanOrEqual(2, $count1);

        // Mutate: truncate table
        DB::table('cars')->truncate();

        $count2 = DB::table('cars')->count();
        $this->assertSame(0, $count2);
    }

    public function testUpsertInvalidatesCachedSelect(): void
    {
        // Insert baseline row
        DB::table('cars')->insert(['id' => 1006, 'name' => 'U1', 'engine_id' => null, 'driver_id' => null]);

        $first = DB::table('cars')->where('id', 1006)->value('name');
        $this->assertSame('U1', $first);

        // Mutate: upsert should update existing row
        DB::table('cars')->upsert([
            ['id' => 1006, 'name' => 'U2']
        ], ['id'], ['name']);

        $second = DB::table('cars')->where('id', 1006)->value('name');
        $this->assertSame('U2', $second);
    }

    public function testUpdateOrInsertInvalidatesCachedSelect(): void
    {
        // Prime on non-existing row (empty)
        $exists1 = DB::table('cars')->where('id', 1007)->exists();
        $this->assertFalse($exists1);

        // Mutate: create it via updateOrInsert
        DB::table('cars')->updateOrInsert(['id' => 1007], ['name' => 'Q']);

        $exists2 = DB::table('cars')->where('id', 1007)->exists();
        $this->assertTrue($exists2);
    }
}