<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Support\Sql\ParserException;
use Spiritix\LadaCache\Support\SqlTableScanner;

final class SqlTableScannerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function reads(): iterable
    {
        yield 'plain' => ['select * from cars', ['cars'], 'mysql'];
        yield 'quoted' => ['select * from "cars" where "id" = ?', ['cars'], 'pgsql'];
        yield 'backticks with alias' => ['select * from `cars` as `c` inner join `drivers` d on d.id = c.driver_id', ['cars', 'drivers'], 'mysql'];
        yield 'brackets' => ['select * from [cars]', ['cars'], 'sqlsrv'];
        yield 'raw exists subquery' => ['select * from "owners" where exists (select 1 from pets where pets.owner_id = owners.id)', ['owners', 'pets'], 'pgsql'];
        yield 'raw scalar subquery' => ['select (select count(*) from pet_toys) as toys from "owners"', ['pet_toys', 'owners'], 'pgsql'];
        yield 'comma join' => ['select * from cars c, drivers, engines e where 1 = 1', ['cars', 'drivers', 'engines'], 'mysql'];
        yield 'schema qualified' => ['select * from "public"."cars"', ['public.cars'], 'pgsql'];
        yield 'derived table' => ['select * from (select id from pets) as p join owners on 1 = 1', ['owners', 'pets'], 'mysql'];
        yield 'table names inside literals are ignored' => ["select * from cars where name = 'from drivers'", ['cars'], 'mysql'];
        yield 'straight join' => ['select * from cars straight_join drivers on 1 = 1', ['cars', 'drivers'], 'mysql'];
        yield 'lowercase and uppercase keywords' => ['SELECT * FROM cars LEFT JOIN drivers ON 1 = 1', ['cars', 'drivers'], 'mysql'];
        yield 'no tables' => ['select 1', [], 'mysql'];
        yield 'index hint' => ['select sum(size) from `files` FORCE INDEX (`client_deleted_at_is_complete_size_index`) where client_id = ?', ['files'], 'mysql'];
        yield 'parenthesized join' => ['select * from cars, (drivers join engines on engines.id = drivers.engine_id)', ['cars', 'drivers', 'engines'], 'mysql'];
        yield 'nested joins without parentheses' => ['select * from cars join drivers join engines on engines.id = drivers.engine_id on drivers.id = cars.driver_id', ['cars', 'drivers', 'engines'], 'mysql'];
        yield 'join to a subquery' => ['select * from owners join (select owner_id from pets join pet_toys on pet_toys.pet_id = pets.id) t on t.owner_id = owners.id', ['owners', 'pets', 'pet_toys'], 'pgsql'];
        yield 'from inside extract is not a table' => ['select extract(year from created_at) from cars', ['cars'], 'pgsql'];
        yield 'is distinct from is not a table' => ['select * from cars where name is distinct from driver_name', ['cars'], 'pgsql'];
        yield 'comments hiding table names' => ["select * from cars /* join drivers */ -- join engines\n", ['cars'], 'pgsql'];
        yield 'mysql executable comment' => ['select * from cars /*!50000 join drivers on 1 = 1 */', ['cars', 'drivers'], 'mysql'];
        yield 'dollar quoted string' => ['select * from cars where name = $$ from drivers $$', ['cars'], 'pgsql'];
        yield 'backslash escaped quote' => ["select * from cars where name = 'it\\'s from drivers'", ['cars'], 'mysql'];
        yield 'self join' => ['select * from cars a join cars b on a.id = b.id', ['cars'], 'mysql'];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('reads')]
    public function testTablesAreFoundWhereverTheSqlNamesThem(string $sql, array $expected, string $driver): void
    {
        $tables = SqlTableScanner::tables($sql, '', Dialects::forDriver($driver));
        sort($tables);
        sort($expected);

        $this->assertSame($expected, $tables);
    }

    public function testReferencesAreCountedPerRelation(): void
    {
        $this->assertSame(
            ['cars' => 2, 'drivers' => 1],
            SqlTableScanner::tableReferenceCounts('select * from cars a join cars b on a.id = b.id where exists (select 1 from drivers)', '', Dialects::forDriver('mysql')),
        );
    }

    public function testUnknownFromClausesThrowRatherThanMissATable(): void
    {
        $this->expectException(ParserException::class);

        SqlTableScanner::tables('select * from cars pivot (sum(x) for y in (1)) p', '', Dialects::forDriver('sqlsrv'));
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function triggerBodies(): iterable
    {
        yield 'sqlite trigger' => ['CREATE TRIGGER t AFTER UPDATE OF name ON pets BEGIN insert into pet_events (pet_id) values (new.id); END', ['pet_events'], 'sqlite'];
        yield 'mysql body' => ['begin update owners set name = 1 where id = new.owner_id; delete from pet_toys where pet_id = old.id; end', ['owners', 'pet_toys'], 'mysql'];
        yield 'postgres function' => ['begin insert into audit."events" values (new.id) on conflict (id) do update set id = excluded.id; return new; end;', ['audit.events'], 'pgsql'];
        yield 'replace and merge' => ['replace into a values (1); merge into b using c on 1 = 1 when matched then delete', ['a', 'b'], 'mysql'];
        yield 'literals ignored' => ["insert into log values ('update owners set x')", ['log'], 'mysql'];
        yield 'select for update is not a write' => ['select * from pets for update of pets', [], 'pgsql'];
        yield 'multi-table update' => ['begin update owners o join pets p on p.owner_id = o.id set p.name = o.name where o.id = new.id; end', ['pets'], 'mysql'];
        yield 'sql server trigger' => ['CREATE TRIGGER t ON pets AFTER INSERT, UPDATE AS BEGIN UPDATE owners SET name = 1 FROM inserted WHERE owners.id = inserted.owner_id END', ['owners'], 'sqlsrv'];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('triggerBodies')]
    public function testWrittenTablesAreFoundInTriggerBodies(string $sql, array $expected, string $driver): void
    {
        $this->assertSame($expected, SqlTableScanner::writtenTables($sql, '', Dialects::forDriver($driver)));
    }

    public function testTriggerWritesThatDoNotParseThrowRatherThanMissATable(): void
    {
        $this->expectException(ParserException::class);

        SqlTableScanner::writtenTables('begin update (select 1) set x = 1; end', '', Dialects::forDriver('mysql'));
    }

    public function testTablePrefixIsStripped(): void
    {
        $this->assertSame(['cars', 'pets'], SqlTableScanner::tables('select * from "lc_cars" where exists (select 1 from lc_pets)', 'lc_', Dialects::forDriver('pgsql')));
    }

    public function testKeywordsAreNotMistakenForAliases(): void
    {
        $this->assertSame(['cars', 'drivers'], SqlTableScanner::tables('select * from cars where id in (select car_id from drivers group by car_id)', '', Dialects::forDriver('mysql')));
    }
}
