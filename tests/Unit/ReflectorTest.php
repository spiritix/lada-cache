<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\TestCase;

class ReflectorTest extends TestCase
{
    public function testGetDatabaseReturnsCurrentDatabaseName(): void
    {
        $builder = DB::table('cars');
        $reflector = new Reflector($builder);

        $this->assertSame(':memory:', $reflector->getDatabase());
    }

    public function testGetTablesFromSimpleFromAndJoins(): void
    {
        $b = DB::table('cars')
            ->join('drivers', 'cars.driver_id', '=', 'drivers.id')
            ->leftJoin('engines as e', 'cars.engine_id', '=', 'e.id');

        $tables = (new Reflector($b))->getTables();

        $this->assertContains('cars', $tables);
        $this->assertContains('drivers', $tables);
        // Alias is stripped to base table name "engines"
        $this->assertContains('engines', $tables);
    }

    public function testGetTablesFromJoinSubAndWhereExists(): void
    {
        $sub = DB::table('engines')->select('id');
        $b = DB::table('cars')
            ->joinSub($sub, 'engines', 'cars.engine_id', '=', 'engines.id')
            ->whereExists(function ($q) {
                $q->from('materials')
                  ->whereColumn('materials.id', 'cars.id');
            });

        $tables = (new Reflector($b))->getTables();

        // joinSub alias equals base table name in this test, so it should be recognized
        $this->assertContains('engines', $tables);
        // whereExists subquery table
        $this->assertContains('materials', $tables);
        $this->assertContains('cars', $tables);
    }

    public function testGetTablesIncludesUnionSubqueries(): void
    {
        $b1 = DB::table('cars');
        $b2 = DB::table('drivers');

        $b = $b1->union($b2);

        $tables = (new Reflector($b))->getTables();

        $this->assertContains('cars', $tables);
        $this->assertContains('drivers', $tables);
    }

    public function testGetTablesHandlesExpressionFromAlias(): void
    {
        $sub = DB::table('materials')->select('id');
        $b = DB::query()->fromSub($sub, 'materials')->join('cars', 'cars.id', '=', 'materials.id');

        $tables = (new Reflector($b))->getTables();

        $this->assertContains('materials', $tables);
        $this->assertContains('cars', $tables);
    }

    public function testGetRowsFromBasicAndInWheresQualifiedAndUnqualified(): void
    {
        // Base table cars with unqualified id and qualified id
        $b = DB::table('cars')
            ->where('id', '=', 7)
            ->orWhere('cars.id', '=', 5)
            ->orWhereIn('drivers.id', [1, 2]);

        $rows = (new Reflector($b))->getRows();

        $this->assertArrayHasKey('cars', $rows);
        $this->assertEqualsCanonicalizing([7, 5], $rows['cars']);
        // Joined table references with primary key id should be captured as well
        $this->assertArrayHasKey('drivers', $rows);
        $this->assertEqualsCanonicalizing([1, 2], $rows['drivers']);
    }

    public function testGetParametersReflectsQueryBindings(): void
    {
        $b = DB::table('cars')->where('name', 'like', '%x%')->where('id', '=', 10);
        $params = (new Reflector($b))->getParameters();

        $this->assertNotEmpty($params);
        $this->assertContains('%x%', $params);
        $this->assertContains(10, $params);
    }

    public function testGetTypeDetectsSelectByDefault(): void
    {
        $b = DB::table('cars')->where('id', 1);
        $this->assertSame(Reflector::QUERY_TYPE_SELECT, (new Reflector($b))->getType());
    }

    public function testGetSqlAndTypeForInsert(): void
    {
        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_INSERT, ['name' => 'c']);
        $sql = $r->getSql();
        $this->assertNotSame('', $sql);
        $this->assertSame(Reflector::QUERY_TYPE_INSERT, $r->getType());
    }

    public function testGetSqlForInsertGetId(): void
    {
        $b = DB::table('cars');
        $r = new Reflector($b, 'insertgetid', ['name' => 'c']);
        $this->assertNotSame('', $r->getSql());
    }

    public function testGetSqlAndTypeForUpdate(): void
    {
        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_UPDATE, ['name' => 'c']);
        $this->assertNotSame('', $r->getSql());
        $this->assertSame(Reflector::QUERY_TYPE_UPDATE, $r->getType());
    }

    public function testGetSqlAndTypeForDelete(): void
    {
        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_DELETE);
        $this->assertNotSame('', $r->getSql());
        $this->assertSame(Reflector::QUERY_TYPE_DELETE, $r->getType());
    }

    public function testGetSqlAndTypeForTruncate(): void
    {
        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_TRUNCATE);
        $this->assertNotSame('', $r->getSql());
        $this->assertSame(Reflector::QUERY_TYPE_TRUNCATE, $r->getType());
    }
}