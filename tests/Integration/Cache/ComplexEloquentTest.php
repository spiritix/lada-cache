<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Concerns\InteractsWithRedis;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\Database\Models\Engine;
use Spiritix\LadaCache\Tests\Database\Models\Material;
use Spiritix\LadaCache\Tests\TestCase;

class ComplexEloquentTest extends TestCase
{
    use InteractsWithRedis;
    use CacheAssertions;
    use MakesTestModels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushRedis();
    }

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

        // Reflect using underlying query builder to compute cache key and tags
        $reflector = new Reflector($eloquent->getQuery());
        $hasher = new Hasher($reflector);
        $tagger = new Tagger($reflector);
        $key = $hasher->getHash();
        $tags = $tagger->getTags();

        // Prime cache
        $rows = $eloquent->get();
        Assert::assertGreaterThan(0, $rows->count());

        $this->assertCacheHas($key);
        foreach ($tags as $tag) {
            $this->assertTagHasKey($tag, $key);
        }

        // Row-level invalidation: change material membership of first returned car
        $first = $rows->first();
        $first->materials()->sync([]); // remove all metals -> should invalidate
        $this->assertCacheMissing($key);

        // Re-prime and check again
        $eloquent->get();
        $this->assertCacheHas($key);

        // Table-level invalidation: truncate materials
        DB::table('materials')->truncate();
        $this->assertCacheMissing($key);
    }
}
