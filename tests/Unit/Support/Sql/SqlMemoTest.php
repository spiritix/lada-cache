<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support\Sql;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spiritix\LadaCache\Support\Sql\MySqlDialect;
use Spiritix\LadaCache\Support\Sql\PostgreSqlDialect;
use Spiritix\LadaCache\Support\Sql\SqlMemo;

final class SqlMemoTest extends TestCase
{
    public function testSqlIsReadOncePerDialect(): void
    {
        /** @var SqlMemo<int> $memo */
        $memo = new SqlMemo;
        $mysql = new MySqlDialect;
        $postgres = new PostgreSqlDialect;
        $reads = 0;
        $read = static function () use (&$reads): int {
            return ++$reads;
        };

        $this->assertSame(1, $memo->remember($mysql, 'select 1', $read));
        $this->assertSame(1, $memo->remember($mysql, 'select 1', $read));
        $this->assertSame(2, $memo->remember($mysql, 'select 2', $read));
        $this->assertSame(3, $memo->remember($postgres, 'select 1', $read));
        $this->assertSame(3, $reads);
    }

    public function testTheOldestResultMakesRoomAtCapacity(): void
    {
        /** @var SqlMemo<int> $memo */
        $memo = new SqlMemo;
        $dialect = new MySqlDialect;
        $reads = 0;
        $read = static function () use (&$reads): int {
            return ++$reads;
        };

        for ($statement = 0; $statement <= SqlMemo::CAPACITY; $statement++) {
            $memo->remember($dialect, 'select '.$statement, $read);
        }

        $memo->remember($dialect, 'select '.SqlMemo::CAPACITY, $read);
        $this->assertSame(SqlMemo::CAPACITY + 1, $reads, 'The newest statement is kept');
        $memo->remember($dialect, 'select 0', $read);
        $this->assertSame(SqlMemo::CAPACITY + 2, $reads, 'The oldest statement was dropped');
    }

    public function testAReadThatThrowsIsReadAgain(): void
    {
        /** @var SqlMemo<int> $memo */
        $memo = new SqlMemo;
        $dialect = new MySqlDialect;

        try {
            $memo->remember($dialect, 'select 1', static fn (): int => throw new RuntimeException('does not parse'));
            $this->fail('The read must throw');
        } catch (RuntimeException) {
            $this->assertSame(7, $memo->remember($dialect, 'select 1', static fn (): int => 7));
        }
    }
}
