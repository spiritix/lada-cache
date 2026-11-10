<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * findMany()/whereKey() and BelongsTo eager loads on integer keys compile to whereIntegerInRaw.
 * They must be tagged with the rows they read, so a write to another row keeps them cached.
 */
class WhereIntegerInRawTest extends TestCase
{
    use MakesTestModels;

    public function testFindManySurvivesWriteToUnrelatedRow(): void
    {
        $this->loadDependencyMap();
        $read = $this->makeDriver(['name' => 'read']);
        $other = $this->makeDriver(['name' => 'other']);

        Driver::findMany([$read->id]);
        $other->update(['name' => 'other renamed']);

        Assert::assertSame(0, $this->countQueries(fn () => Driver::findMany([$read->id])));
    }

    public function testFindManyIsInvalidatedByWriteToItsRow(): void
    {
        $read = $this->makeDriver(['name' => 'read']);

        Driver::findMany([$read->id]);
        $read->update(['name' => 'read renamed']);

        Assert::assertSame('read renamed', Driver::findMany([$read->id])->first()->name);
    }

    public function testBelongsToEagerLoadIsScopedToLoadedParents(): void
    {
        $this->loadDependencyMap();
        $driver = $this->makeDriver(['name' => 'd1']);
        $other = $this->makeDriver(['name' => 'd2']);
        $car = $this->makeCar(['name' => 'c1', 'driver_id' => $driver->id]);

        Car::with('driver')->findOrFail($car->id);
        $other->update(['name' => 'd2 renamed']);

        Assert::assertSame(0, $this->countQueries(fn () => Car::with('driver')->findOrFail($car->id)));

        $driver->update(['name' => 'd1 renamed']);

        Assert::assertSame('d1 renamed', Car::with('driver')->findOrFail($car->id)->driver->name);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
