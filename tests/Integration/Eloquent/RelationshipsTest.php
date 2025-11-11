<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\Database\Models\Engine;
use Spiritix\LadaCache\Tests\TestCase;

class RelationshipsTest extends TestCase
{
    use MakesTestModels;

    public function test_belongs_to_driver(): void
    {
        $driver = $this->makeDriver(['name' => 'd1']);
        $car = $this->makeCar(['name' => 'c1', 'driver_id' => $driver->id]);

        $loaded = Car::with('driver')->findOrFail($car->id);
        Assert::assertInstanceOf(Driver::class, $loaded->driver);
        Assert::assertSame('d1', $loaded->driver->name);
    }

    public function test_update_or_create_move_invalidates_counts(): void
    {
        $d1 = $this->makeDriver(['name' => 'd1']);
        $d2 = $this->makeDriver(['name' => 'd2']);
        $car = $this->makeCar(['name' => 'movable', 'driver_id' => $d1->id]);

        // Prime caches
        Assert::assertSame(1, (int) Driver::withCount('cars')->findOrFail($d1->id)->cars_count);
        Assert::assertSame(0, (int) Driver::withCount('cars')->findOrFail($d2->id)->cars_count);

        // Move child via updateOrCreate (specific UPDATE)
        Car::updateOrCreate(['id' => $car->id], ['driver_id' => $d2->id]);

        // Expect both parent counts to update
        Assert::assertSame(0, (int) Driver::withCount('cars')->findOrFail($d1->id)->cars_count);
        Assert::assertSame(1, (int) Driver::withCount('cars')->findOrFail($d2->id)->cars_count);
    }


    public function test_has_one_engine(): void
    {
        $car = $this->makeCar(['name' => 'c-has-one']);
        $engine = $car->engine()->create(['name' => 'e1']);

        $fresh = Car::with('engine')->findOrFail($car->id);
        Assert::assertInstanceOf(Engine::class, $fresh->engine);
        Assert::assertSame('e1', $fresh->engine->name);
        Assert::assertSame($car->id, $fresh->engine->car_id);
    }

    public function test_belongs_to_many_materials_attach_detach_sync(): void
    {
        $car = $this->makeCar(['name' => 'c-btm']);
        $m1 = $this->makeMaterial(['name' => 'steel']);
        $m2 = $this->makeMaterial(['name' => 'aluminum']);
        $m3 = $this->makeMaterial(['name' => 'plastic']);

        // Attach
        $car->materials()->attach([$m1->id, $m2->id]);
        $car->load('materials');
        Assert::assertSame([$m1->id, $m2->id], $car->materials->pluck('id')->sort()->values()->all());

        // Detach one
        $car->materials()->detach($m1->id);
        $car->refresh()->load('materials');
        Assert::assertSame([$m2->id], $car->materials->pluck('id')->all());

        // Sync
        $car->materials()->sync([$m2->id, $m3->id]);
        $car->refresh()->load('materials');
        Assert::assertSame([$m2->id, $m3->id], $car->materials->pluck('id')->sort()->values()->all());
    }

    public function test_eager_loading_constraints_and_where_has(): void
    {
        $driver1 = $this->makeDriver(['name' => 'drv-a']);
        $driver2 = $this->makeDriver(['name' => 'drv-b']);

        $car1 = $this->makeCar(['name' => 'A', 'driver_id' => $driver1->id]);
        $car2 = $this->makeCar(['name' => 'B', 'driver_id' => $driver2->id]);
        $car3 = $this->makeCar(['name' => 'C']);

        $engine1 = $car1->engine()->create(['name' => 'e-a']);
        $car2->engine()->create(['name' => 'e-b']);

        $with = Car::with(['driver', 'engine' => static fn ($q) => $q->where('name', 'e-a')])
            ->orderBy('id')
            ->get();

        Assert::assertCount(3, $with);
        Assert::assertSame('e-a', $with[0]->engine?->name);
        Assert::assertNull($with[1]->engine?->name);
        Assert::assertNull($with[2]->engine?->name);

        $hasEngineNamedEA = Car::whereHas('engine', static fn ($q) => $q->where('name', 'e-a'))->get();
        Assert::assertCount(1, $hasEngineNamedEA);
        Assert::assertSame($car1->id, $hasEngineNamedEA->first()->id);
    }
}