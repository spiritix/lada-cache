<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * A fromSub() query reads the tables of its subquery, so writes to those tables must
 * invalidate the outer query. The alias of the subquery is not a real table.
 */
final class FromSubQueryTest extends TestCase
{
    public function testQueryBuilderSubqueryIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $count = fn (): int => DB::query()
            ->fromSub(DB::table('cars')->select('id'), 'per_car')
            ->count();

        $this->assertSame(0, $count());

        DB::table('cars')->insert(['name' => 'car']);

        $this->assertSame(1, $count());
    }

    public function testClosureSubqueryIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $count = fn (): int => DB::query()
            ->fromSub(fn (Builder $query) => $query->from('cars')->select('id'), 'per_car')
            ->count();

        $this->assertSame(0, $count());

        DB::table('cars')->insert(['name' => 'car']);

        $this->assertSame(1, $count());
    }

    public function testEloquentSubqueryIsRefreshedByModelCreate(): void
    {
        $count = fn (): int => DB::query()
            ->fromSub(Car::query()->select('id'), 'per_car')
            ->count();

        $this->assertSame(0, $count());

        Car::create(['name' => 'car']);

        $this->assertSame(1, $count());
    }

    public function testRelationSubqueryIsRefreshedByModelCreate(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        $count = fn (): int => DB::query()
            ->fromSub($driver->cars()->select('id'), 'per_car')
            ->count();

        $this->assertSame(0, $count());

        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $this->assertSame(1, $count());
    }

    public function testFromSubOnEloquentBuilderIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $count = fn (): int => Driver::query()
            ->fromSub(DB::table('cars')->select('id'), 'drivers')
            ->count();

        $this->assertSame(0, $count());

        DB::table('cars')->insert(['name' => 'car']);

        $this->assertSame(1, $count());
    }

    public function testTwoLevelAggregateIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $total = fn (): int => (int) DB::query()
            ->fromSub(
                DB::table('cars')
                    ->select('driver_id', DB::raw('count(*) as cars_count'))
                    ->whereNotNull('driver_id')
                    ->groupBy('driver_id'),
                'per_driver'
            )
            ->sum('cars_count');

        $this->assertSame(1, $total());

        Car::create(['name' => 'other', 'driver_id' => $driver->id]);

        $this->assertSame(2, $total());
    }

    public function testNestedSubqueryIsRefreshedByInsertIntoInnermostTable(): void
    {
        $count = fn (): int => DB::query()
            ->fromSub(
                DB::query()->fromSub(DB::table('cars')->select('id'), 'inner_cars'),
                'outer_cars'
            )
            ->count();

        $this->assertSame(0, $count());

        DB::table('cars')->insert(['name' => 'car']);

        $this->assertSame(1, $count());
    }

    public function testPaginationTotalIsRefreshedByInsertIntoSubqueryTable(): void
    {
        $total = fn (): int => DB::query()
            ->fromSub(DB::table('cars')->select('id'), 'per_car')
            ->paginate(10)
            ->total();

        $this->assertSame(0, $total());

        DB::table('cars')->insert(['name' => 'car']);

        $this->assertSame(1, $total());
    }

    public function testSubqueryTablesDoNotLeakIntoOtherQueries(): void
    {
        $this->loadDependencyMap();
        $driver = Driver::create(['name' => 'driver']);

        DB::query()->fromSub(DB::table('cars')->select('id'), 'per_car');

        $drivers = fn (): int => DB::table('drivers')->count();
        $this->assertSame(1, $drivers());

        $before = $this->countQueries($drivers);
        DB::table('cars')->insert(['name' => 'car', 'driver_id' => $driver->id]);
        $after = $this->countQueries($drivers);

        $this->assertSame(0, $before, 'The drivers count should be cached.');
        $this->assertSame(0, $after, 'A write to cars must not invalidate the drivers count.');
    }

    private function countQueries(callable $callback): int
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $callback();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }
    }
}
