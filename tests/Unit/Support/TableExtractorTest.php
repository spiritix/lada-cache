<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\Support\TableExtractor;

class TableExtractorTest extends TestCase
{
    public function testExtractTablesFromSimpleFromJoinAndUnion(): void
    {
        $b1 = DB::table('cars')->join('drivers', 'drivers.id', '=', 'cars.driver_id');
        $b2 = DB::table('engines as e');
        $b = $b1->union($b2);

        $tables = TableExtractor::extractTables($b);

        $this->assertContains('cars', $tables);
        $this->assertContains('drivers', $tables);
        $this->assertContains('engines', $tables);
    }

    public function testExtractTablesFromExpressionFromAndJoinSub(): void
    {
        $sub = DB::table('materials')->select('id');
        $b = DB::query()->fromSub($sub, 'materials')->joinSub(DB::table('cars'), 'cars', 'materials.id', '=', 'cars.id');

        $tables = TableExtractor::extractTables($b);

        $this->assertContains('materials', $tables);
        $this->assertContains('cars', $tables);
    }

    public function testExtractTablesFromExistsSubquery(): void
    {
        $b = DB::table('cars')->whereExists(function ($q): void {
            $q->from('materials')->whereColumn('materials.id', 'cars.id');
        });

        $tables = TableExtractor::extractTables($b);

        $this->assertContains('cars', $tables);
        $this->assertContains('materials', $tables);
    }
}
