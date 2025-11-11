<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\Tests\Database\Models\Car;

class CreateUpdateDeleteTest extends TestCase
{
    use MakesTestModels;

    public function test_create_via_factory_and_static_create(): void
    {
        $carA = $this->makeCar(['name' => 'alpha']);

        $carB = Car::create(['name' => 'bravo']);

        Assert::assertNotNull($carA->id);
        Assert::assertNotNull($carB->id);
        Assert::assertSame('alpha', Car::find($carA->id)?->name);
        Assert::assertSame('bravo', Car::find($carB->id)?->name);
    }

    public function test_update_or_create_invalidates_parent_relation_count(): void
    {
        // Parent without related engine
        $car = $this->makeCar(['name' => 'uo-parent']);

        // Prime the cache: relation count is 0
        $before = Car::where('id', $car->id)->withCount('engine')->firstOrFail();
        Assert::assertSame(0, (int) $before->engine_count);

        // Perform updateOrCreate on the hasOne relation
        // This should INSERT a related engine and invalidate the cached count
        $car->engine()->updateOrCreate(['car_id' => $car->id], ['name' => 'e-uo']);

        // Expect the count to reflect the new related row (should be 1)
        $after = Car::where('id', $car->id)->withCount('engine')->firstOrFail();
        Assert::assertSame(1, (int) $after->engine_count);
    }

    public function test_first_or_create_and_first_or_new(): void
    {
        $created = Car::firstOrCreate(['name' => 'unique-model']);
        Assert::assertTrue($created->exists);

        $found = Car::firstOrCreate(['name' => 'unique-model']);
        Assert::assertSame($created->id, $found->id);

        $new = Car::firstOrNew(['name' => 'not-persisted']);
        Assert::assertFalse($new->exists);
        $new->save();
        Assert::assertTrue($new->exists);
    }

    public function test_update_and_refresh(): void
    {
        $car = $this->makeCar(['name' => 'before']);

        $car->update(['name' => 'after']);
        $car->refresh();

        Assert::assertSame('after', $car->name);
        Assert::assertSame('after', Car::find($car->id)?->name);
    }

    public function test_update_or_create(): void
    {
        $created = Car::updateOrCreate(['name' => 'uo-target'], ['driver_id' => 5]);
        Assert::assertTrue($created->exists);
        Assert::assertSame(5, $created->driver_id);

        $updated = Car::updateOrCreate(['name' => 'uo-target'], ['driver_id' => 7]);
        Assert::assertSame($created->id, $updated->id);
        Assert::assertSame(7, $updated->driver_id);
    }

    public function test_upsert(): void
    {
        // Use primary key for SQLite compatibility: ON CONFLICT(id) ...
        Car::upsert([
            ['id' => 9001, 'name' => 'u1', 'driver_id' => 10, 'engine_id' => null],
            ['id' => 9002, 'name' => 'u2', 'driver_id' => 20, 'engine_id' => null],
        ], uniqueBy: ['id'], update: ['driver_id', 'updated_at']);

        $u1 = Car::find(9001);
        $u2 = Car::find(9002);
        Assert::assertNotNull($u1);
        Assert::assertNotNull($u2);
        Assert::assertSame(10, $u1->driver_id);
        Assert::assertSame(20, $u2->driver_id);

        // Update driver_id via second upsert call
        Car::upsert([
            ['id' => 9001, 'name' => 'u1', 'driver_id' => 30],
            ['id' => 9002, 'name' => 'u2', 'driver_id' => 40],
        ], uniqueBy: ['id'], update: ['driver_id', 'updated_at']);

        $u1->refresh();
        $u2->refresh();
        Assert::assertSame(30, $u1->driver_id);
        Assert::assertSame(40, $u2->driver_id);
    }

    public function test_new_model_save_populates_id(): void
    {
        $car = new Car(['name' => 'save-populates-id']);

        Assert::assertFalse($car->exists);

        $car->save();

        Assert::assertTrue($car->exists);
        Assert::assertNotNull($car->id);
        Assert::assertSame('save-populates-id', Car::find($car->id)?->name);
    }

    public function test_delete_and_destroy(): void
    {
        $car = $this->makeCar();
        $id = $car->id;

        $car->delete();
        Assert::assertNull(Car::find($id));

        $a = $this->makeCar();
        $b = $this->makeCar();
        $deleted = Car::destroy([$a->id, $b->id]);
        Assert::assertSame(2, $deleted);
        Assert::assertNull(Car::find($a->id));
        Assert::assertNull(Car::find($b->id));
    }
}