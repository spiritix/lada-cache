<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\Support\RowExtractor;

class RowExtractorTest extends TestCase
{
    public function testExtractRowsFromBasicAndInWheres(): void
    {
        $b = DB::table('cars')
            ->where('id', '=', 3)
            ->orWhereIn('id', [7, 9]);

        $rows = RowExtractor::extractRows($b);

        $this->assertArrayHasKey('cars', $rows);
        $this->assertEqualsCanonicalizing([3, 7, 9], $rows['cars']);
    }

    public function testExtractRowsIgnoresOrWithNonPrimaryKeyBranch(): void
    {
        $b = DB::table('cars')->where('id', '=', 3)->orWhere('name', '=', 'x');

        $this->assertSame([], RowExtractor::extractRows($b));
    }

    public function testExtractRowsTreatsUpperCaseOrAsOr(): void
    {
        $b = DB::table('cars')->where('id', '=', 3)->where('name', '=', 'x', 'OR');

        $this->assertSame([], RowExtractor::extractRows($b));
    }

    public function testExtractRowsIgnoresOrAcrossTables(): void
    {
        $b = DB::table('cars')
            ->join('drivers', 'drivers.id', '=', 'cars.driver_id')
            ->where('cars.id', '=', 3)
            ->orWhere('drivers.id', '=', 5);

        $this->assertSame([], RowExtractor::extractRows($b));
    }

    public function testExtractRowsIgnoresNegatedPrimaryKey(): void
    {
        $b = DB::table('cars')->where('id', '=', 4)->whereNot('id', 3);

        $this->assertSame(['cars' => [4]], RowExtractor::extractRows($b));
    }

    public function testExtractRowsResolvesAliasForBaseTable(): void
    {
        $b = DB::table('cars as c')->where('c.id', '=', 11);

        $rows = RowExtractor::extractRows($b);

        $this->assertArrayHasKey('cars', $rows);
        $this->assertEquals([11], $rows['cars']);
    }

    public function testExtractRowsIgnoresNonPkColumns(): void
    {
        $b = DB::table('cars')->where('name', '=', 'x');

        $rows = RowExtractor::extractRows($b);

        $this->assertSame([], $rows);
    }
}
