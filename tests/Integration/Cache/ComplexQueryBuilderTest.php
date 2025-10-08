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

class ComplexQueryBuilderTest extends TestCase
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
        $d1 = Driver::factory()->create(['name' => 'Driver A']);
        $d2 = Driver::factory()->create(['name' => 'Driver B']);

        // Cars
        $c1 = Car::create(['name' => 'Car 1', 'driver_id' => $d1->id]);
        $c2 = Car::create(['name' => 'Car 2', 'driver_id' => $d1->id]);
        $c3 = Car::create(['name' => 'Car 3', 'driver_id' => $d2->id]);

        // Engines
        $e1 = Engine::factory()->create(['name' => 'V6', 'car_id' => $c1->id]);
        $e2 = Engine::factory()->create(['name' => 'V8', 'car_id' => $c2->id]);
        $e3 = Engine::factory()->create(['name' => 'I4', 'car_id' => $c3->id]);

        // Materials
        $mSteel = Material::factory()->create(['name' => 'steel']);
        $mAl   = Material::factory()->create(['name' => 'aluminum']);
        $mPl   = Material::factory()->create(['name' => 'plastic']);

        DB::table('car_material')->insert([
            ['car_id' => $c1->id, 'material_id' => $mSteel->id],
            ['car_id' => $c1->id, 'material_id' => $mAl->id],
            ['car_id' => $c2->id, 'material_id' => $mAl->id],
            ['car_id' => $c3->id, 'material_id' => $mPl->id],
        ]);

        return compact('d1', 'd2', 'c1', 'c2', 'c3', 'e1', 'e2', 'e3', 'mSteel', 'mAl', 'mPl');
    }

    public function test_complex_query_cached_and_invalidated_row_and_table_level(): void
    {
        $data = $this->seedData();

        // Build a deeply nested, multi-table query with unions, joins and exists subquery
        $subForIn = DB::table('cars as s1')
            ->select('s1.id')
            ->whereExists(function ($q): void {
                $q->from('car_material as cm')
                    ->join('materials as mm', 'mm.id', '=', 'cm.material_id')
                    ->whereColumn('cm.car_id', 's1.id')
                    ->where('mm.name', 'aluminum');
            });

        $unionSub = DB::table('cars as u1')->select('u1.id')->where('u1.name', 'like', 'Car%');

        $builder = DB::table('cars as c')
            ->select('c.id', 'c.name', 'd.name as driver', 'e.name as engine')
            ->join('drivers as d', 'd.id', '=', 'c.driver_id')
            ->leftJoin('engines as e', 'e.car_id', '=', 'c.id')
            ->whereIn('c.id', $subForIn)
            ->whereExists(function ($q): void {
                $q->from('engines as ee')->whereColumn('ee.car_id', 'c.id');
            })
            ->whereIn('c.id', function ($q) use ($unionSub): void {
                $q->from('cars as base')->select('base.id')->unionAll($unionSub);
            })
            ->orderBy('c.id');

        // Compute expected cache key and tags before executing
        $reflector = new Reflector($builder);
        $hasher = new Hasher($reflector);
        $tagger = new Tagger($reflector);
        $key = $hasher->getHash();
        $tags = $tagger->getTags();

        // Prime the cache
        $rows = $builder->get();
        Assert::assertGreaterThan(0, $rows->count());

        // Assert cache key exists and is member of relevant tags
        $this->assertCacheHas($key);
        foreach ($tags as $tag) {
            $this->assertTagHasKey($tag, $key);
        }

        // Row-level invalidation: update a targeted row
        $targetId = $rows->first()->id;
        DB::table('cars')->where('id', $targetId)->update(['name' => 'Car 1 (updated)']);

        // Cache should be invalidated
        $this->assertCacheMissing($key);

        // Re-prime and ensure key reappears
        $builder->get();
        $this->assertCacheHas($key);

        // Table-level invalidation: truncate one involved table
        DB::table('engines')->truncate();
        $this->assertCacheMissing($key);

    }
}
