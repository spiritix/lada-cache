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

    public function testClosureSelectSubIsRefreshedByInsertIntoSubQueryTable(): void
    {
        $carCount = fn (): int => (int) DB::table('drivers')
            ->select(['car_count' => fn (Builder $query) => $query->from('cars')->selectRaw('count(*)')])
            ->value('car_count');
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $this->assertSame(0, $carCount());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => null]);

        $this->assertSame(1, $carCount());
    }

    public function testEloquentJoinSubIsRefreshedByWritesToTheSubQueryTable(): void
    {
        $driver = $this->makeDriver(['name' => 'd']);
        $car = $this->makeCar(['name' => 'before', 'driver_id' => $driver->id]);
        $joinedCarName = fn (): ?string => DB::table('drivers')
            ->joinSub(Car::query()->select('driver_id', 'name as car_name'), 'driver_cars', 'driver_cars.driver_id', '=', 'drivers.id')
            ->where('drivers.id', $driver->id)
            ->value('car_name');
        $this->assertSame('before', $joinedCarName());

        $car->update(['name' => 'after']);

        $this->assertSame('after', $joinedCarName());
    }

    public function testWhereInSubQueryIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $driversWithCars = fn (): int => DB::table('drivers')
            ->whereIn('id', DB::table('cars')->select('driver_id'))
            ->count();
        $this->assertSame(0, $driversWithCars());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => 1]);

        $this->assertSame(1, $driversWithCars());
    }

    public function testWhereNotInSubQueryIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $driversWithoutCars = fn (): int => DB::table('drivers')
            ->whereNotIn('id', DB::table('cars')->select('driver_id')->whereNotNull('driver_id'))
            ->count();
        $this->assertSame(1, $driversWithoutCars());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => 1]);

        $this->assertSame(0, $driversWithoutCars());
    }

    public function testFromSubIsRefreshedByInsertIntoSubQueryTable(): void
    {
        $carCount = fn (): int => DB::query()->fromSub(DB::table('cars')->select('id'), 'recent_cars')->count();
        $this->assertSame(0, $carCount());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => null]);

        $this->assertSame(1, $carCount());
    }

    public function testSubQueryColumnInWhereIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $driversWithCars = fn (): int => DB::table('drivers')
            ->where(DB::table('cars')->selectRaw('count(*)')->whereColumn('cars.driver_id', 'drivers.id'), '>', 0)
            ->count();
        $this->assertSame(0, $driversWithCars());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => 1]);

        $this->assertSame(1, $driversWithCars());
    }

    public function testSubQueryColumnInWhereBetweenIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $driversWithOneCar = fn (): int => DB::table('drivers')
            ->whereBetween(DB::table('cars')->selectRaw('count(*)')->whereColumn('cars.driver_id', 'drivers.id'), [1, 1])
            ->count();
        $this->assertSame(0, $driversWithOneCar());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => 1]);

        $this->assertSame(1, $driversWithOneCar());
    }

    public function testSubQueryOrderIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert([['id' => 1, 'name' => 'first'], ['id' => 2, 'name' => 'second']]);
        $namesByCarCount = fn (): array => DB::table('drivers')
            ->orderByDesc(DB::table('cars')->selectRaw('count(*)')->whereColumn('cars.driver_id', 'drivers.id'))
            ->orderBy('id')
            ->pluck('name')
            ->all();
        $this->assertSame(['first', 'second'], $namesByCarCount());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => 2]);

        $this->assertSame(['second', 'first'], $namesByCarCount());
    }

    public function testCrossJoinSubIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        $pairs = fn (): int => DB::table('drivers')->crossJoinSub(DB::table('cars')->select('id as car_id'), 'all_cars')->count();
        $this->assertSame(0, $pairs());

        DB::table('cars')->insert(['name' => 'c', 'engine_id' => null, 'driver_id' => null]);

        $this->assertSame(1, $pairs());
    }

    public function testJoinLateralTagsTheTablesItReads(): void
    {
        $builder = DB::table('drivers')->joinLateral(
            DB::table('cars')->whereColumn('cars.driver_id', 'drivers.id')->limit(1),
            'first_car'
        );

        $this->assertContains('cars', (new Reflector($builder))->getTables());
    }

    public function testSubQueryInsideJoinConditionIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        DB::table('cars')->insert(['id' => 1, 'name' => 'c', 'engine_id' => null, 'driver_id' => 1]);
        $carsWithEngines = fn (): int => DB::table('drivers')
            ->join('cars', function ($join): void {
                $join->on('cars.driver_id', '=', 'drivers.id')
                    ->whereIn('cars.id', DB::table('engines')->select('car_id'));
            })
            ->count();
        $this->assertSame(0, $carsWithEngines());

        DB::table('engines')->insert(['name' => 'e', 'car_id' => 1]);

        $this->assertSame(1, $carsWithEngines());
    }

    public function testExistsInsideJoinConditionIsRefreshedByInsertIntoSubQueryTable(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'd']);
        DB::table('cars')->insert(['id' => 1, 'name' => 'c', 'engine_id' => null, 'driver_id' => 1]);
        $carsWithEngines = fn (): int => DB::table('drivers')
            ->join('cars', function ($join): void {
                $join->on('cars.driver_id', '=', 'drivers.id')
                    ->whereExists(fn (Builder $query) => $query->from('engines')->whereColumn('engines.car_id', 'cars.id'));
            })
            ->count();
        $this->assertSame(0, $carsWithEngines());

        DB::table('engines')->insert(['name' => 'e', 'car_id' => 1]);

        $this->assertSame(1, $carsWithEngines());
    }
}
