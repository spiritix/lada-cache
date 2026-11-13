<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\TestCase;

class RepeatedTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.consider_rows' => true]);
        DB::table('cars')->insert([
            ['id' => 1, 'name' => 'same', 'engine_id' => null, 'driver_id' => null],
            ['id' => 2, 'name' => 'same', 'engine_id' => null, 'driver_id' => null],
        ]);
    }

    public function testSelfJoinIsInvalidatedByWriteToAnotherRowItReads(): void
    {
        $twins = fn (): array => DB::table('cars')
            ->join('cars as twin', 'twin.name', '=', 'cars.name')
            ->where('cars.id', 1)
            ->orderBy('twin.id')
            ->pluck('twin.id')
            ->all();
        $this->assertEquals([1, 2], $twins());

        DB::table('cars')->where('id', 2)->update(['name' => 'other']);

        $this->assertEquals([1], $twins());
    }

    public function testSubqueryOnTheSameTableIsInvalidatedByWriteToAnotherRowItReads(): void
    {
        $hasTwin = fn (): bool => DB::table('cars')
            ->where('id', 1)
            ->whereExists(fn ($query) => $query->from('cars as twin')
                ->whereColumn('twin.name', 'cars.name')
                ->whereColumn('twin.id', '<>', 'cars.id'))
            ->exists();
        $this->assertTrue($hasTwin());

        DB::table('cars')->where('id', 2)->update(['name' => 'other']);

        $this->assertFalse($hasTwin());
    }

    public function testNestedWhereGroupsStillPinRows(): void
    {
        $builder = DB::table('cars')
            ->where('id', 1)
            ->where(fn ($query) => $query->where('name', 'same')->orWhere('name', 'other'));

        $this->assertSame(['cars' => [1]], (new Reflector($builder))->getRows());
    }
}
