<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\RawStatementClassifier;
use Spiritix\LadaCache\Support\RawStatementEffect;
use Spiritix\LadaCache\Support\Write;

final class RawStatementClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function singleTableWrites(): iterable
    {
        yield 'insert' => ['insert into cars (name) values (?)', 'cars'];
        yield 'insert ignore' => ['INSERT IGNORE INTO `cars` (`name`) VALUES (?)', 'cars'];
        yield 'insert or replace' => ['insert or replace into "cars" ("id") values (?)', 'cars'];
        yield 'insert select' => ['insert into cars (name) select name from drivers', 'cars'];
        yield 'replace' => ['replace into cars (id, name) values (?, ?)', 'cars'];
        yield 'update' => ['update cars set name = ? where id = ?', 'cars'];
        yield 'update with alias' => ['update cars c set name = ?', 'cars'];
        yield 'update from (postgres)' => ['update cars set name = drivers.name from drivers where drivers.id = cars.driver_id', 'cars'];
        yield 'delete' => ['delete from [cars] where id = ?', 'cars'];
        yield 'delete everything' => ['DELETE FROM cars;', 'cars'];
        yield 'delete using (postgres)' => ['delete from cars using drivers where drivers.id = cars.driver_id', 'cars'];
        yield 'truncate' => ['truncate table cars', 'cars'];
        yield 'truncate restart identity' => ['truncate cars restart identity', 'cars'];
        yield 'leading comment' => ["-- backfill\n/* job 4 */ update cars set name = ?", 'cars'];
        yield 'table prefix stripped' => ['update app_cars set name = ?', 'cars'];
        yield 'explain analyze runs the write (postgres)' => ['explain analyze delete from cars where id = ?', 'cars'];
        yield 'explain with analyze option (postgres)' => ['EXPLAIN (ANALYZE, BUFFERS) update cars set name = ?', 'cars'];
        yield 'explain analyse spelling' => ['explain analyse verbose insert into cars (name) values (?)', 'cars'];
    }

    #[DataProvider('singleTableWrites')]
    public function testSingleTableWritesInvalidateThatTable(string $sql, string $table): void
    {
        $effect = RawStatementClassifier::classify($sql, str_contains($sql, 'app_') ? 'app_' : '');

        $this->assertSame([$table], $effect->tables);
        $this->assertFalse($effect->changesEverything);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statementsThatChangeEverything(): iterable
    {
        yield 'alter table' => ['alter table cars add column color varchar(20)'];
        yield 'create table' => ['create table colors (id int)'];
        yield 'drop table' => ['drop table cars'];
        yield 'rename' => ['rename table cars to vehicles'];
        yield 'multi-table update' => ['update cars join drivers on drivers.id = cars.driver_id set cars.name = drivers.name'];
        yield 'comma update' => ['update cars, drivers set cars.name = drivers.name'];
        yield 'multi-table delete' => ['delete cars from cars join drivers on drivers.id = cars.driver_id'];
        yield 'schema qualified' => ['update other_db.cars set name = ?'];
        yield 'stored procedure' => ['call recalculate_prices()'];
        yield 'writable cte' => ['with moved as (delete from cars returning *) insert into archive select * from moved'];
        yield 'two statements' => ['update cars set name = 1; drop table drivers'];
        yield 'explain analyze of an unrecognized statement' => ['explain analyze call recalculate_prices()'];
        yield 'merge' => ['merge into cars using drivers on (cars.id = drivers.id) when matched then update set name = drivers.name'];
    }

    #[DataProvider('statementsThatChangeEverything')]
    public function testUnscopedStatementsChangeEverything(string $sql): void
    {
        $this->assertTrue(RawStatementClassifier::classify($sql, '')->changesEverything);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sideEffectFreeStatements(): iterable
    {
        yield 'set' => ['set time_zone = "+00:00"'];
        yield 'savepoint' => ['SAVEPOINT trans2'];
        yield 'pragma' => ['PRAGMA foreign_keys = ON'];
        yield 'temporary table' => ['create temporary table report_rows (id int)'];
        yield 'drop temporary table' => ['drop temporary table report_rows'];
        yield 'lock' => ['lock tables cars write'];
        yield 'analyze' => ['analyze table cars'];
        yield 'explain plans a write without running it' => ['explain delete from cars'];
        yield 'explain with options' => ['EXPLAIN (COSTS OFF, FORMAT JSON) update cars set name = ?'];
        yield 'explain analyze of a read' => ['explain analyze select * from cars'];
        yield 'explain a table (mysql)' => ['explain cars'];
        yield 'explain format (mysql)' => ['explain format=json delete from cars'];
        yield 'empty' => ['  ;  '];
        yield 'semicolon in literal' => ["set @label = 'a;b'"];
    }

    #[DataProvider('sideEffectFreeStatements')]
    public function testSideEffectFreeStatementsInvalidateNothing(string $sql): void
    {
        $this->assertTrue(RawStatementClassifier::classify($sql, '')->isNone());
    }

    public function testReadsInvalidateNothingAndProceduresChangeEverything(): void
    {
        $this->assertTrue(RawStatementClassifier::classify('select * from cars', '')->isNone());
        $this->assertTrue(RawStatementClassifier::classify('(select 1) union (select 2)', '')->isNone());
        $this->assertTrue(RawStatementClassifier::classify('with recent as (select 1) select * from recent', '')->isNone());
        $this->assertTrue(RawStatementClassifier::classify('call report_prices()', '')->changesEverything);
        $this->assertTrue(RawStatementClassifier::classify('with recent as (select 1) update cars set name = 1', '')->changesEverything);
        $this->assertTrue(RawStatementClassifier::classify('table cars', '')->isNone());
        $this->assertEquals(RawStatementEffect::writes(['cars'], Write::insert()), RawStatementClassifier::classify('insert into cars (name) values (?) returning id', ''));
        $this->assertEquals(RawStatementEffect::writes(['cars'], Write::delete()), RawStatementClassifier::classify('delete from cars where id = ? returning *', ''));
    }

    /**
     * @return iterable<string, array{string, list<string>, Write}>
     */
    public static function classifiedWrites(): iterable
    {
        yield 'insert' => ['insert into cars (name) values (?)', ['cars'], Write::insert()];
        yield 'insert naming update in a literal' => ["insert into cars (name) values ('update')", ['cars'], Write::insert()];
        yield 'insert on duplicate key update' => ['INSERT INTO cars (id) SELECT id FROM drivers ON DUPLICATE KEY UPDATE name = ?', ['cars'], Write::updateOfAnyColumn()];
        yield 'insert on conflict do update' => ['insert into cars (id) values (1) on conflict (id) do update set name = excluded.name', ['cars'], Write::updateOfAnyColumn()];
        yield 'insert or replace' => ['insert or replace into cars (id) values (?)', ['cars'], Write::delete()];
        yield 'replace' => ['replace into cars (id) values (?)', ['cars'], Write::delete()];
        yield 'update' => ['update cars set name = ?', ['cars'], Write::updateOfAnyColumn()];
        yield 'delete' => ['delete from cars where id = ?', ['cars'], Write::delete()];
        yield 'truncate' => ['truncate table cars', ['cars'], Write::truncate()];
        yield 'truncate cascade' => ['truncate cars cascade', ['cars'], Write::truncate()];
    }

    /**
     * @param  list<string>  $tables
     */
    #[DataProvider('classifiedWrites')]
    public function testWritesAreClassifiedByWhatTheyDoToRows(string $sql, array $tables, Write $write): void
    {
        $this->assertEquals(RawStatementEffect::writes($tables, $write), RawStatementClassifier::classify($sql, ''));
    }
}
