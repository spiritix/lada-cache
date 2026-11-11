<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\RawStatementClassifier;
use Spiritix\LadaCache\Support\RawStatementEffect;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Support\TransactionBoundary;
use Spiritix\LadaCache\Support\Write;

final class RawStatementClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function singleTableWrites(): iterable
    {
        yield 'insert' => ['insert into cars (name) values (?)', 'cars', 'mysql'];
        yield 'insert ignore' => ['INSERT IGNORE INTO `cars` (`name`) VALUES (?)', 'cars', 'mysql'];
        yield 'insert or replace' => ['insert or replace into "cars" ("id") values (?)', 'cars', 'sqlite'];
        yield 'insert select' => ['insert into cars (name) select name from drivers', 'cars', 'mysql'];
        yield 'replace' => ['replace into cars (id, name) values (?, ?)', 'cars', 'mysql'];
        yield 'update' => ['update cars set name = ? where id = ?', 'cars', 'mysql'];
        yield 'update with alias' => ['update cars c set name = ?', 'cars', 'pgsql'];
        yield 'update from (postgres)' => ['update cars set name = drivers.name from drivers where drivers.id = cars.driver_id', 'cars', 'pgsql'];
        yield 'delete' => ['delete from [cars] where id = ?', 'cars', 'sqlsrv'];
        yield 'delete everything' => ['DELETE FROM cars;', 'cars', 'mysql'];
        yield 'delete using (postgres)' => ['delete from cars using drivers where drivers.id = cars.driver_id', 'cars', 'pgsql'];
        yield 'truncate' => ['truncate table cars', 'cars', 'mysql'];
        yield 'truncate restart identity' => ['truncate cars restart identity', 'cars', 'pgsql'];
        yield 'leading comment' => ["-- backfill\n/* job 4 */ update cars set name = ?", 'cars', 'mysql'];
        yield 'table prefix stripped' => ['update app_cars set name = ?', 'cars', 'mysql'];
        yield 'explain analyze runs the write (postgres)' => ['explain analyze delete from cars where id = ?', 'cars', 'pgsql'];
        yield 'explain with analyze option (postgres)' => ['EXPLAIN (ANALYZE, BUFFERS) update cars set name = ?', 'cars', 'pgsql'];
        yield 'explain analyse spelling' => ['explain analyse verbose insert into cars (name) values (?)', 'cars', 'pgsql'];
    }

    #[DataProvider('singleTableWrites')]
    public function testSingleTableWritesInvalidateThatTable(string $sql, string $table, string $driver): void
    {
        $effect = RawStatementClassifier::classify($sql, str_contains($sql, 'app_') ? 'app_' : '', Dialects::forDriver($driver));

        $this->assertSame([$table], $effect->tables);
        $this->assertFalse($effect->changesEverything);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statementsThatChangeEverything(): iterable
    {
        yield 'alter table' => ['alter table cars add column color varchar(20)', 'mysql'];
        yield 'create table' => ['create table colors (id int)', 'mysql'];
        yield 'drop table' => ['drop table cars', 'mysql'];
        yield 'rename' => ['rename table cars to vehicles', 'mysql'];
        yield 'multi-table update assigning a derived table' => ['update cars join (select id from drivers) d on d.id = cars.driver_id set d.id = 1', 'mysql'];
        yield 'multi-table delete of an unknown alias' => ['delete x from cars c join drivers d on d.id = c.driver_id', 'mysql'];
        yield 'multi-table delete from another database' => ['delete c from other_db.cars c join drivers d on d.id = c.driver_id', 'mysql'];
        yield 'schema qualified' => ['update other_db.cars set name = ?', 'mysql'];
        yield 'stored procedure' => ['call recalculate_prices()', 'mysql'];
        yield 'writable cte' => ['with moved as (delete from cars returning *) insert into archive select * from moved', 'pgsql'];
        yield 'two statements' => ['update cars set name = 1; drop table drivers', 'mysql'];
        yield 'explain analyze of an unrecognized statement' => ['explain analyze call recalculate_prices()', 'pgsql'];
        yield 'unterminated string' => ["update cars set name = 'x", 'mysql'];
        yield 'sql server top' => ['delete top (10) from cars', 'sqlsrv'];
        yield 'sql server batch hiding a write behind a read' => ['select 1 delete from cars', 'sqlsrv'];
        yield 'sql server batch of two writes' => ['update drivers set name = 1 delete from cars', 'sqlsrv'];
    }

    #[DataProvider('statementsThatChangeEverything')]
    public function testUnscopedStatementsChangeEverything(string $sql, string $driver): void
    {
        $this->assertTrue(RawStatementClassifier::classify($sql, '', Dialects::forDriver($driver))->changesEverything);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sideEffectFreeStatements(): iterable
    {
        yield 'set' => ['set time_zone = "+00:00"', 'mysql'];
        yield 'savepoint' => ['SAVEPOINT trans2', 'mysql'];
        yield 'pragma' => ['PRAGMA foreign_keys = ON', 'sqlite'];
        yield 'temporary table' => ['create temporary table report_rows (id int)', 'mysql'];
        yield 'drop temporary table' => ['drop temporary table report_rows', 'mysql'];
        yield 'lock' => ['lock tables cars write', 'mysql'];
        yield 'analyze' => ['analyze table cars', 'mysql'];
        yield 'explain plans a write without running it' => ['explain delete from cars', 'mysql'];
        yield 'explain with options' => ['EXPLAIN (COSTS OFF, FORMAT JSON) update cars set name = ?', 'pgsql'];
        yield 'explain analyze of a read' => ['explain analyze select * from cars', 'pgsql'];
        yield 'explain a table (mysql)' => ['explain cars', 'mysql'];
        yield 'explain format (mysql)' => ['explain format=json delete from cars', 'mysql'];
        yield 'empty' => ['  ;  ', 'mysql'];
        yield 'semicolon in literal' => ["set @label = 'a;b'", 'mysql'];
        yield 'sql server read calling replace' => ["select replace(name, 'a', 'b') from cars", 'sqlsrv'];
    }

    #[DataProvider('sideEffectFreeStatements')]
    public function testSideEffectFreeStatementsInvalidateNothing(string $sql, string $driver): void
    {
        $this->assertTrue(RawStatementClassifier::classify($sql, '', Dialects::forDriver($driver))->isNone());
    }

    /**
     * @return array<string, array{string, string, TransactionBoundary|null}>
     */
    public static function transactionStatements(): array
    {
        return [
            'begin' => ['begin', 'sqlite', TransactionBoundary::Begins],
            'begin immediate' => ['BEGIN IMMEDIATE', 'sqlite', TransactionBoundary::Begins],
            'start transaction' => ['start transaction read write', 'mysql', TransactionBoundary::Begins],
            'sql server transaction' => ['BEGIN TRAN', 'sqlsrv', TransactionBoundary::Begins],
            'sql server block' => ['BEGIN SELECT 1 END', 'sqlsrv', null],
            'commit' => ['commit', 'mysql', TransactionBoundary::Ends],
            'end' => ['END TRANSACTION', 'pgsql', TransactionBoundary::Ends],
            'rollback' => ['rollback', 'pgsql', TransactionBoundary::Ends],
            'rollback to a savepoint' => ['rollback to savepoint before_import', 'mysql', null],
            'savepoint' => ['savepoint before_import', 'mysql', null],
            'a read' => ['select 1', 'mysql', null],
        ];
    }

    #[DataProvider('transactionStatements')]
    public function testStatementsBeginningOrEndingATransactionAreTold(string $sql, string $driver, ?TransactionBoundary $boundary): void
    {
        $effect = RawStatementClassifier::classify($sql, '', Dialects::forDriver($driver));

        $this->assertSame($boundary, $effect->transaction);
        $this->assertTrue($effect->isNone());
    }

    public function testReadsInvalidateNothingAndProceduresChangeEverything(): void
    {
        $mysql = Dialects::forDriver('mysql');
        $pgsql = Dialects::forDriver('pgsql');

        $this->assertTrue(RawStatementClassifier::classify('select * from cars', '', $mysql)->isNone());
        $this->assertTrue(RawStatementClassifier::classify('(select 1) union (select 2)', '', $mysql)->isNone());
        $this->assertTrue(RawStatementClassifier::classify('with recent as (select 1) select * from recent', '', $mysql)->isNone());
        $this->assertTrue(RawStatementClassifier::classify('call report_prices()', '', $mysql)->changesEverything);
        $this->assertTrue(RawStatementClassifier::classify('with recent as (select 1) update cars set name = 1', '', $mysql)->changesEverything);
        $this->assertTrue(RawStatementClassifier::classify('table cars', '', $pgsql)->isNone());
        $this->assertEquals(RawStatementEffect::writes(['cars'], Write::insert()), RawStatementClassifier::classify('insert into cars (name) values (?) returning id', '', $pgsql));
        $this->assertEquals(RawStatementEffect::writes(['cars'], Write::delete()), RawStatementClassifier::classify('delete from cars where id = ? returning *', '', $pgsql));
    }

    /**
     * @return iterable<string, array{string, list<string>, Write, string}>
     */
    public static function classifiedWrites(): iterable
    {
        yield 'insert' => ['insert into cars (name) values (?)', ['cars'], Write::insert(), 'mysql'];
        yield 'insert naming update in a literal' => ["insert into cars (name) values ('update')", ['cars'], Write::insert(), 'mysql'];
        yield 'insert on duplicate key update' => ['INSERT INTO cars (id) SELECT id FROM drivers ON DUPLICATE KEY UPDATE name = ?, `cars`.`driver_id` = VALUES(driver_id)', ['cars'], Write::update(['name', 'driver_id']), 'mysql'];
        yield 'insert on conflict do update' => ['insert into cars (id) values (1) on conflict (id) do update set name = excluded.name', ['cars'], Write::update(['name']), 'pgsql'];
        yield 'insert on conflict do nothing' => ['insert into cars (id) values (1) on conflict do nothing', ['cars'], Write::insert(), 'pgsql'];
        yield 'insert or replace' => ['insert or replace into cars (id) values (?)', ['cars'], Write::delete(), 'sqlite'];
        yield 'replace' => ['replace into cars (id) values (?)', ['cars'], Write::delete(), 'mysql'];
        yield 'update' => ['update cars set name = ?, driver_id = driver_id + 1', ['cars'], Write::update(['name', 'driver_id']), 'mysql'];
        yield 'update of a tuple' => ['update cars set (name, driver_id) = (select name, id from drivers limit 1)', ['cars'], Write::update(['name', 'driver_id']), 'pgsql'];
        yield 'delete' => ['delete from cars where id = ?', ['cars'], Write::delete(), 'mysql'];
        yield 'delete with modifiers' => ['DELETE LOW_PRIORITY QUICK IGNORE FROM cars WHERE id = ?', ['cars'], Write::delete(), 'mysql'];
        yield 'truncate' => ['truncate table cars', ['cars'], Write::truncate(), 'mysql'];
        yield 'truncate cascade' => ['truncate cars, drivers cascade', ['cars', 'drivers'], Write::truncate(), 'pgsql'];
        yield 'merge' => ['merge into cars using drivers on cars.id = drivers.id when matched then delete', ['cars'], Write::merge(), 'pgsql'];
        yield 'schema qualified (postgres)' => ['update "public"."cars" set name = ?', ['cars'], Write::update(['name']), 'pgsql'];
        yield 'schema qualified (sql server)' => ['delete from dbo.[cars] where id = ?', ['cars'], Write::delete(), 'sqlsrv'];
        yield 'multi-table update of qualified columns' => ['update cars join drivers on drivers.id = cars.driver_id set cars.name = drivers.name where drivers.id = 1', ['cars'], Write::update(['name']), 'mysql'];
        yield 'comma update' => ['update cars, drivers set cars.name = drivers.name', ['cars'], Write::update(['name']), 'mysql'];
        yield 'multi-table update through an alias' => [
            'UPDATE permission_cache pc INNER JOIN (SELECT folder_trees.parent_id, COUNT(*) folder_count FROM folders INNER JOIN folder_trees ON folder_trees.child_id = folders.id GROUP BY folder_trees.parent_id) f ON pc.folder_id = f.parent_id SET pc.foldersCount = f.folder_count, `pc`.`touched` = CONCAT(?, f.folder_count) WHERE pc.user_id = ?',
            ['permission_cache'],
            Write::update(['foldersCount', 'touched']),
            'mysql',
        ];
        yield 'multi-table update of an unqualified column writes every joined table' => ['update cars join drivers on drivers.id = cars.driver_id set name = ?', ['cars', 'drivers'], Write::update(['name']), 'mysql'];
        yield 'multi-table delete' => ['delete cars from cars join drivers on drivers.id = cars.driver_id', ['cars'], Write::delete(), 'mysql'];
        yield 'multi-table delete through aliases' => ['DELETE pc, d FROM permission_cache pc LEFT JOIN users ON users.id = pc.user_id JOIN drivers AS d ON d.id = pc.id WHERE users.id IS NULL', ['drivers', 'permission_cache'], Write::delete(), 'mysql'];
        yield 'multi-table delete with wildcards' => ['delete cars.* from cars join drivers on drivers.id = cars.driver_id', ['cars'], Write::delete(), 'mysql'];
        yield 'multi-table delete using an alias' => ['delete from c using cars c join drivers on drivers.id = c.driver_id', ['cars'], Write::delete(), 'mysql'];
        yield 'delete using with an aliased target (postgres)' => ['delete from cars c using drivers d where d.id = c.driver_id', ['cars'], Write::delete(), 'pgsql'];
    }

    /**
     * @param  list<string>  $tables
     */
    #[DataProvider('classifiedWrites')]
    public function testWritesAreClassifiedByWhatTheyDoToRows(string $sql, array $tables, Write $write, string $driver): void
    {
        $this->assertEquals(RawStatementEffect::writes($tables, $write), RawStatementClassifier::classify($sql, '', Dialects::forDriver($driver)));
    }
}
