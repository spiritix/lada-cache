<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\Tests\Database\Models\Car;

class AggregatesTest extends TestCase
{
    use MakesTestModels;

    public function test_count_exists_and_doesnt_exist(): void
    {
        Assert::assertSame(0, Car::count());
        Assert::assertFalse(Car::exists());
        Assert::assertTrue(Car::doesntExist());

        $this->makeCar();
        $this->makeCar();

        Assert::assertSame(2, Car::count());
        Assert::assertTrue(Car::exists());
        Assert::assertFalse(Car::doesntExist());
    }

    public function test_min_max_sum_avg_value_and_pluck(): void
    {
        $a = $this->makeCar(['name' => 'a', 'driver_id' => 5]);
        $b = $this->makeCar(['name' => 'b', 'driver_id' => 10]);
        $c = $this->makeCar(['name' => 'c', 'driver_id' => 15]);

        Assert::assertSame(5, (int) Car::min('driver_id'));
        Assert::assertSame(15, (int) Car::max('driver_id'));
        Assert::assertSame(30, (int) Car::sum('driver_id'));
        Assert::assertSame(10.0, (float) Car::avg('driver_id'));

        Assert::assertContains(Car::value('name'), ['a', 'b', 'c']);

        $names = Car::orderBy('name', 'asc')->pluck('name')->all();
        Assert::assertSame(['a', 'b', 'c'], $names);
    }
}
