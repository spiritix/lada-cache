<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class SubqueryTagPropagationCacheTest extends TestCase
{
    use CacheAssertions;

    public function testJoinSubResultIsInvalidatedByWritesToTheSubqueryTable(): void
    {
        DB::table('engines')->insert(['id' => 901, 'name' => 'E']);
        DB::table('cars')->insert(['id' => 801, 'name' => 'C', 'engine_id' => 901, 'driver_id' => null]);

        $joined = fn () => DB::table('cars')
            ->joinSub(DB::table('engines')->select('id', 'name as engine_name'), 'engines', 'cars.engine_id', '=', 'engines.id')
            ->where('cars.id', 801)
            ->value('engine_name');

        $this->assertSame('E', $joined());

        DB::table('engines')->where('id', 901)->update(['name' => 'E2']);

        $this->assertSame('E2', $joined());
    }

    public function testJoinSubResultSurvivesWritesToUnrelatedTables(): void
    {
        DB::table('engines')->insert(['id' => 902, 'name' => 'E']);
        DB::table('cars')->insert(['id' => 802, 'name' => 'C', 'engine_id' => 902, 'driver_id' => null]);

        $builder = DB::table('cars')
            ->joinSub(DB::table('engines')->select('id'), 'engines', 'cars.engine_id', '=', 'engines.id')
            ->where('cars.id', 802)
            ->select('cars.*');
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::table('drivers')->insert(['id' => 1, 'name' => 'unrelated']);

        $this->assertCacheHas($key, $tags);
    }
}
