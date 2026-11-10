<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\NonDeterministicSql;
use Spiritix\LadaCache\Support\Sql\Dialects;

final class NonDeterministicSqlTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function nonDeterministicQueries(): iterable
    {
        yield 'now()' => ['select * from cars where expires_at > now()'];
        yield 'NOW() uppercase' => ['select * from cars where expires_at > NOW()'];
        yield 'current_timestamp' => ['select * from cars where created_at <= current_timestamp'];
        yield 'current_timestamp()' => ['select * from cars where created_at <= CURRENT_TIMESTAMP()'];
        yield 'current_date arithmetic' => ['select * from t where d > current_date - interval 1 day'];
        yield 'sqlite date now' => ["select * from t where d < date('now')"];
        yield 'sqlite datetime now' => ["select * from t where d < datetime('now', '-1 day')"];
        yield 'rand order' => ['select * from t order by rand()'];
        yield 'random order' => ['select * from t order by random()'];
        yield 'uuid' => ['select uuid() as id'];
        yield 'gen_random_uuid' => ['select gen_random_uuid()'];
        yield 'clock_timestamp' => ['select clock_timestamp()'];
        yield 'sysdate' => ['select * from t where d < sysdate()'];
        yield 'unix_timestamp' => ['select * from t where ts < unix_timestamp()'];
        yield 'last_insert_id' => ['select last_insert_id()'];
        yield 'nextval' => ["select nextval('seq')"];
        yield 'current_user' => ['select * from grants where grantee = current_user'];
        yield 'getdate' => ['select * from t where d < getdate()'];
    }

    #[DataProvider('nonDeterministicQueries')]
    public function testNonDeterministicQueriesAreDetected(string $sql, string $driver = 'mysql'): void
    {
        $this->assertTrue(NonDeterministicSql::isNonDeterministic($sql, Dialects::forDriver($driver)));
    }

    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function deterministicQueries(): iterable
    {
        yield 'bound timestamp' => ['select * from cars where expires_at > ?'];
        yield 'quoted identifiers named like functions' => ['select `now`, `rand` from `random` where `current_date` = 1'];
        yield 'bracketed identifier named like a keyword' => ['select [current_date] from t', 'sqlsrv'];
        yield 'double quoted identifier named like a function' => ['select "now"() from t', 'pgsql'];
        yield 'function names inside string literals' => ["select * from cars where name = 'now() and rand()'"];
        yield 'escaped quote before function name' => ["select * from cars where name = 'it''s now()'"];
        yield 'column suffixed like a keyword' => ['select created_now, rand_seed from t'];
        yield 'qualified column named like a keyword' => ['select t.current_date from t'];
        yield 'plain aggregate' => ['select count(*) from cars where id in (1, 2)'];
    }

    #[DataProvider('deterministicQueries')]
    public function testDeterministicQueriesAreNotFlagged(string $sql, string $driver = 'mysql'): void
    {
        $this->assertFalse(NonDeterministicSql::isNonDeterministic($sql, Dialects::forDriver($driver)));
    }
}
