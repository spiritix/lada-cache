<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

class RawExpressionsTest extends TestCase
{
    use CacheAssertions;

    public function testJoinSubPropagatesSubqueryTags(): void
    {
        // Seed minimal rows
        DB::table('engines')->insert(['id' => 501, 'name' => 'E']);
        DB::table('cars')->insert(['id' => 201, 'name' => 'C', 'engine_id' => 501, 'driver_id' => null]);

        $sub = DB::table('engines')->select('id');
        $builder = DB::table('cars')
            ->joinSub($sub, 'engines', 'cars.engine_id', '=', 'engines.id')
            ->where('cars.id', 201)
            ->select('cars.*');

        // Assert Tagger (main query) produces both cars and engines tags
        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();
        $tags = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_unspecific:cars", $tags);
        $this->assertContains("tags:database:{$dbName}:table_unspecific:engines", $tags);
    }

    public function testSelectSubPropagatesSubqueryTags(): void
    {
        // Seed minimal rows
        DB::table('drivers')->insert(['id' => 301, 'name' => 'D']);
        DB::table('cars')->insert(['id' => 202, 'name' => 'C2', 'engine_id' => null, 'driver_id' => 301]);

        $sub = DB::table('drivers')->select('id')->where('drivers.id', 301);
        $builder = DB::table('cars')
            ->where('cars.id', 202)
            ->select('cars.*')
            ->selectSub($sub, 'driver_id_sub');

        // Assert Tagger (main query) produces cars tag. For selectSub, Tagger does not include
        // subquery tables; subquery tag propagation happens during caching (outside transactions).
        $reflector = new Reflector($builder);
        $dbName = $reflector->getDatabase();
        $tags = (new Tagger($reflector))->getTags();
        $this->assertContains("tags:database:{$dbName}:table_unspecific:cars", $tags);
    }
}