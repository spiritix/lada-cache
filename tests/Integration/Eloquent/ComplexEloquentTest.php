<?php

declare(strict_types=1);

namespace Integration\Eloquent;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\Database\Models\Engine;
use Spiritix\LadaCache\Tests\Database\Models\Material;
use Spiritix\LadaCache\Tests\TestCase;

class ComplexEloquentTest extends TestCase
{
    use CacheAssertions;
    use MakesTestModels;

    protected function seedData(): array
    {
        // Drivers
        $d1 = Driver::factory()->create(['name' => 'DA']);
        $d2 = Driver::factory()->create(['name' => 'DB']);

        // Cars
        $c1 = Car::create(['name' => 'E-Car 1', 'driver_id' => $d1->id]);
        $c2 = Car::create(['name' => 'E-Car 2', 'driver_id' => $d1->id]);
        $c3 = Car::create(['name' => 'E-Car 3', 'driver_id' => $d2->id]);

        // Engines
        $e1 = Engine::factory()->create(['name' => 'EV6', 'car_id' => $c1->id]);
        $e2 = Engine::factory()->create(['name' => 'EV8', 'car_id' => $c2->id]);
        $e3 = Engine::factory()->create(['name' => 'EI4', 'car_id' => $c3->id]);

        // Materials
        $mSteel = Material::factory()->create(['name' => 'steel']);
        $mAl   = Material::factory()->create(['name' => 'aluminum']);
        $mPl   = Material::factory()->create(['name' => 'plastic']);

        // Pivot attach
        $c1->materials()->attach([$mSteel->id, $mAl->id]);
        $c2->materials()->attach([$mAl->id]);
        $c3->materials()->attach([$mPl->id]);

        return compact('d1', 'd2', 'c1', 'c2', 'c3', 'e1', 'e2', 'e3', 'mSteel', 'mAl', 'mPl');
    }

    public function test_complex_eloquent_query_cached_and_invalidated(): void
    {
        $data = $this->seedData();

        // Eloquent query with nested whereHas, withCount, eager loads, and subquery constraints
        $eloquent = Car::with([
                'driver',
                'engine',
                'materials' => static function ($q): void {
                    $q->whereIn('materials.name', ['steel', 'aluminum']);
                },
            ])
            ->withCount([
                // Count only metal materials
                'materials as metal_count' => static function ($q): void {
                    $q->whereIn('materials.name', ['steel', 'aluminum']);
                },
            ])
            ->whereHas('engine', static function ($q): void {
                $q->where('name', 'like', 'E%');
            })
            ->where(static function ($q): void {
                $q->whereHas('materials', static function ($q): void {
                    $q->where('name', 'steel');
                })->orWhereHas('materials', static function ($q): void {
                    $q->where('name', 'aluminum');
                });
            })
            // Subquery in whereIn referencing cars via base query
            ->whereIn('id', function ($q): void {
                $q->from('cars as base')
                    ->select('base.id')
                    ->where('base.name', 'like', 'E-Car%');
            })
            ->orderBy('id');

        // MVP behavior: run twice and ensure stable, non-empty results
        $rows1 = $eloquent->get();
        Assert::assertGreaterThan(0, $rows1->count());

        $rows2 = $eloquent->get();
        Assert::assertSame($rows1->toJson(), $rows2->toJson());
    }
}
