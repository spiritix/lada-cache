<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\SqlTableScanner;

final class SqlTableScannerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function reads(): iterable
    {
        yield 'plain' => ['select * from cars', ['cars']];
        yield 'quoted' => ['select * from "cars" where "id" = ?', ['cars']];
        yield 'backticks with alias' => ['select * from `cars` as `c` inner join `drivers` d on d.id = c.driver_id', ['cars', 'drivers']];
        yield 'brackets' => ['select * from [cars]', ['cars']];
        yield 'raw exists subquery' => ['select * from "owners" where exists (select 1 from pets where pets.owner_id = owners.id)', ['owners', 'pets']];
        yield 'raw scalar subquery' => ['select (select count(*) from pet_toys) as toys from "owners"', ['pet_toys', 'owners']];
        yield 'comma join' => ['select * from cars c, drivers, engines e where 1 = 1', ['cars', 'drivers', 'engines']];
        yield 'schema qualified' => ['select * from "public"."cars"', ['cars']];
        yield 'derived table' => ['select * from (select id from pets) as p join owners on 1 = 1', ['pets', 'owners']];
        yield 'table names inside literals are ignored' => ["select * from cars where name = 'from drivers'", ['cars']];
        yield 'straight join' => ['select * from cars straight_join drivers on 1 = 1', ['cars', 'drivers']];
        yield 'lowercase and uppercase keywords' => ['SELECT * FROM cars LEFT JOIN drivers ON 1 = 1', ['cars', 'drivers']];
        yield 'no tables' => ['select 1', []];
    }

    #[DataProvider('reads')]
    public function testTablesAreFoundWhereverTheSqlNamesThem(string $sql, array $expected): void
    {
        $this->assertSame($expected, SqlTableScanner::tables($sql, ''));
    }

    public function testTablePrefixIsStripped(): void
    {
        $this->assertSame(['cars', 'pets'], SqlTableScanner::tables('select * from "lc_cars" where exists (select 1 from lc_pets)', 'lc_'));
    }

    public function testKeywordsAreNotMistakenForAliases(): void
    {
        $this->assertSame(['cars', 'drivers'], SqlTableScanner::tables('select * from cars where id in (select car_id from drivers group by car_id)', ''));
    }
}
