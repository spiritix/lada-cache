<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class SoftDeletesTest extends TestCase
{
    use MakesTestModels;

    public function test_soft_delete_hides_from_default_queries_and_with_trashed_shows(): void
    {
        $car = $this->makeCar(['name' => 'sd1']);
        $id = $car->id;

        // Soft delete
        $car->delete();
        Assert::assertTrue($car->trashed());

        // Default queries should not find it
        Assert::assertNull(Car::find($id));
        Assert::assertSame(0, Car::where('id', $id)->count());

        // withTrashed should find it
        $with = Car::withTrashed()->find($id);
        Assert::assertNotNull($with);
        Assert::assertTrue($with->trashed());
    }

    public function test_only_trashed_and_restore(): void
    {
        $alive = $this->makeCar(['name' => 'alive']);
        $trashed = $this->makeCar(['name' => 'trashme']);
        $trashed->delete();

        $only = Car::onlyTrashed()->pluck('name')->all();
        Assert::assertContains('trashme', $only);
        Assert::assertNotContains('alive', $only);

        // Restore the trashed one
        $trashed->restore();
        Assert::assertFalse($trashed->fresh()->trashed());

        // now onlyTrashed should be empty for that record
        Assert::assertSame(0, Car::onlyTrashed()->where('id', $trashed->id)->count());
        // default scope should include it again
        Assert::assertSame(1, Car::where('id', $trashed->id)->count());
    }

    public function test_force_delete_removes_permanently(): void
    {
        $car = $this->makeCar(['name' => 'force']);
        $id = $car->id;

        // Soft delete first
        $car->delete();
        Assert::assertTrue(Car::withTrashed()->findOrFail($id)->trashed());

        // Force delete
        $car->forceDelete();

        // Should not exist even withTrashed
        Assert::assertNull(Car::withTrashed()->find($id));
        Assert::assertSame(0, Car::withTrashed()->where('id', $id)->count());
    }
}

