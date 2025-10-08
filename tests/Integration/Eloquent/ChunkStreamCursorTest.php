<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class ChunkStreamCursorTest extends TestCase
{
    use MakesTestModels;

    protected function seedCars(int $count = 10): void
    {
        foreach (range(1, $count) as $i) {
            $this->makeCar(['name' => 'car-'.$i]);
        }
    }

    public function test_chunk(): void
    {
        $this->seedCars(7);

        $seen = [];
        Car::orderBy('id')->chunk(3, function ($cars) use (&$seen): void {
            foreach ($cars as $car) {
                $seen[] = $car->name;
            }
        });

        Assert::assertCount(7, $seen);
        Assert::assertSame('car-1', $seen[0]);
        Assert::assertSame('car-7', $seen[6]);
    }

    public function test_chunk_by_id(): void
    {
        $this->seedCars(9);

        $ids = [];
        Car::where('id', '>', 0)->chunkById(4, function ($cars) use (&$ids): void {
            foreach ($cars as $car) {
                $ids[] = $car->id;
            }
        }, column: 'id');

        sort($ids);
        Assert::assertSame(range(1, 9), $ids);
    }

    public function test_cursor(): void
    {
        $this->seedCars(5);

        $names = [];
        foreach (Car::orderBy('id')->cursor() as $car) {
            $names[] = $car->name;
        }

        Assert::assertSame(['car-1', 'car-2', 'car-3', 'car-4', 'car-5'], $names);
    }
}

