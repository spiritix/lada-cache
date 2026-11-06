<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use Illuminate\Database\Query\Builder;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * A whereIn subquery reads its own table, so writes to that table must invalidate the outer query.
 */
final class WhereInSubqueryTest extends TestCase
{
    public function test_eloquent_subquery_read_is_refreshed_by_insert_into_subquery_table(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        $count = fn (): int => Driver::query()
            ->whereKey($driver->id)
            ->whereIn('id', Car::query()->select('driver_id'))
            ->count();

        $this->assertSame(0, $count());

        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $this->assertSame(1, $count());
    }

    public function test_closure_subquery_read_is_refreshed_by_insert_into_subquery_table(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        $count = fn (): int => Driver::query()
            ->whereKey($driver->id)
            ->whereIn('id', fn (Builder $query) => $query->from('cars')->select('driver_id'))
            ->count();

        $this->assertSame(0, $count());

        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $this->assertSame(1, $count());
    }

    public function test_where_not_in_subquery_read_is_refreshed_by_insert_into_subquery_table(): void
    {
        $driver = Driver::create(['name' => 'driver']);
        $count = fn (): int => Driver::query()
            ->whereKey($driver->id)
            ->whereNotIn('id', Car::query()->select('driver_id'))
            ->count();

        $this->assertSame(1, $count());

        Car::create(['name' => 'car', 'driver_id' => $driver->id]);

        $this->assertSame(0, $count());
    }
}
