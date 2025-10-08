<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\InteractsWithRedis;
use Spiritix\LadaCache\Tests\TestCase;

class UnionSubqueryTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushRedis();
    }

    public function testUnionIncludesTagsFromBothBranches(): void
    {
        // Seed minimal rows so builders are valid
        DB::table('cars')->insert(['id' => 801, 'name' => 'UC', 'engine_id' => null, 'driver_id' => null]);
        DB::table('drivers')->insert(['id' => 901, 'name' => 'UD']);

        $b1 = DB::table('cars')->select('id');
        $b2 = DB::table('drivers')->select('id');
        $union = $b1->union($b2);

        $reflector = new Reflector($union);
        $dbName = $reflector->getDatabase();
        $tags = (new Tagger($reflector))->getTags();

        $this->assertContains("tags:database:{$dbName}:table_unspecific:cars", $tags);
        $this->assertContains("tags:database:{$dbName}:table_unspecific:drivers", $tags);
    }

    public function testUnionAllIncludesTagsFromBothBranches(): void
    {
        DB::table('cars')->insert(['id' => 802, 'name' => 'UCA', 'engine_id' => null, 'driver_id' => null]);
        DB::table('drivers')->insert(['id' => 902, 'name' => 'UDA']);

        $b1 = DB::table('cars')->select('id');
        $b2 = DB::table('drivers')->select('id');
        $unionAll = $b1->unionAll($b2);

        $reflector = new Reflector($unionAll);
        $dbName = $reflector->getDatabase();
        $tags = (new Tagger($reflector))->getTags();

        $this->assertContains("tags:database:{$dbName}:table_unspecific:cars", $tags);
        $this->assertContains("tags:database:{$dbName}:table_unspecific:drivers", $tags);
    }
}