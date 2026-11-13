<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use PDO;
use Spiritix\LadaCache\Tests\TestCase;

class ReadModesTest extends TestCase
{
    public function testFetchUsingReachesPdoAfterTheDefaultModeWasCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'first', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->select('id', 'name')->get();

        $pairs = DB::table('cars')->select('id', 'name')->fetchUsing(PDO::FETCH_KEY_PAIR)->get()->all();

        $this->assertSame([1 => 'first'], $pairs);
    }

    public function testFetchUsingResultsAreNotServedToDefaultModeReads(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'first', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->select('id', 'name')->fetchUsing(PDO::FETCH_KEY_PAIR)->get();

        $rows = DB::table('cars')->select('id', 'name')->get();

        $this->assertEquals([(object) ['id' => 1, 'name' => 'first']], $rows->all());
    }

    public function testReadsInsidePretendAreNotCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'first', 'engine_id' => null, 'driver_id' => null]);

        DB::pretend(function (): void {
            $this->assertNull(DB::table('cars')->where('id', 1)->value('name'));
        });

        $this->assertSame('first', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testExistsCachesNoRowData(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'first', 'engine_id' => null, 'driver_id' => null]);

        DB::enableQueryLog();
        $exists = DB::table('cars')->where('id', 1)->exists();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($exists);
        $this->assertCount(1, $queries);
        $this->assertSame(
            DB::table('cars')->select(DB::raw('1'))->where('id', 1)->limit(1)->toSql(),
            $queries[0]['query'],
        );
    }
}
