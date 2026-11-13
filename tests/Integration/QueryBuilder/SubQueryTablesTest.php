<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\TestCase;

final class SubQueryTablesTest extends TestCase
{
    use MakesTestModels;

    public function testJoinSubBuiltBeforeAnotherQueryRunsKeepsItsSubQueryTables(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => 1]);
        $build = fn () => DB::table('drivers')
            ->joinSub(DB::table('cars')->select('driver_id', 'name as car_name'), 'driver_cars', 'driver_cars.driver_id', '=', 'drivers.id')
            ->where('drivers.id', 1);

        $first = $build();
        DB::table('drivers')->where('id', 1)->value('name');
        $this->assertSame('before', $first->value('car_name'));

        DB::table('cars')->where('id', 1)->update(['name' => 'after']);

        $second = $build();
        DB::table('drivers')->where('id', 1)->value('name');
        $this->assertSame('after', $second->value('car_name'));
    }

    public function testSelectSubTablesAreNotHandedToTheNextQuery(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'before']);
        DB::table('engines')->insert(['id' => 1, 'name' => 'e']);
        DB::table('cars')->selectSub(DB::table('drivers')->select('name')->limit(1), 'driver_name');

        $this->assertSame('e', DB::table('engines')->where('id', 1)->value('name'));
        DB::table('drivers')->insert(['id' => 2, 'name' => 'other']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        DB::table('engines')->where('id', 1)->value('name');
        DB::disableQueryLog();

        $this->assertSame([], DB::getQueryLog());
    }

    public function testSelectSubOnTheSameTableIsRefreshedByInsertIntoThatTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $driverCount = fn (): int => (int) DB::table('drivers')
            ->where('id', 1)
            ->selectSub(DB::table('drivers')->selectRaw('count(*)'), 'driver_count')
            ->value('driver_count');
        $this->assertSame(1, $driverCount());

        DB::table('drivers')->insert(['id' => 2, 'name' => 'e']);

        $this->assertSame(2, $driverCount());
    }

    public function testIncludeTablesSeesSubQueryTables(): void
    {
        config(['lada-cache.include_tables' => ['drivers']]);
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $carCount = fn (): int => (int) DB::table('drivers')
            ->where('id', 1)
            ->selectSub(DB::table('cars')->selectRaw('count(*)'), 'car_count')
            ->value('car_count');
        $this->assertSame(0, $carCount());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => null]);

        $this->assertSame(1, $carCount());
    }

    public function testWithCountIsRefreshedByCreatingARelatedModel(): void
    {
        $driver = $this->makeDriver(['name' => 'd']);
        $this->assertSame(0, Driver::withCount('cars')->findOrFail($driver->id)->cars_count);

        $this->makeCar(['name' => 'c', 'driver_id' => $driver->id]);

        $this->assertSame(1, Driver::withCount('cars')->findOrFail($driver->id)->cars_count);
    }
}
