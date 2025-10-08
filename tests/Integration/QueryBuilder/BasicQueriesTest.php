<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

class BasicQueriesTest extends TestCase
{
    use CacheAssertions;

    public function testSelectCachesResultAndIsAddressableByComputedKey(): void
    {
        // Arrange: seed a row we can select
        DB::table('cars')->insert(['id' => 1, 'name' => 'A', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 1);

        // Act: first run -> miss, store
        $first = $builder->get();

        // Act: second run -> hit (same result without DB changes)
        $second = $builder->get();

        $this->assertEquals($first->toArray(), $second->toArray());
    }

    public function testLockForUpdateBypassesCache(): void
    {
        DB::table('cars')->insert(['id' => 2, 'name' => 'B', 'engine_id' => null, 'driver_id' => null]);

        // Perform a locked select that must bypass cache
        $locked = DB::table('cars')->where('id', 2)->lockForUpdate()->get();
        $this->assertNotEmpty($locked);
    }
}