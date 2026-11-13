<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Closure;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Concerns\RecordsRedisCommands;
use Spiritix\LadaCache\Tests\Fuzz\Support\Workload;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * The cost contract for every read shape the fuzzer knows: repeating a read never reaches the
 * database and costs exactly one Redis round trip per query.
 */
final class RoundTripContractTest extends TestCase
{
    use RecordsRedisCommands;

    public function testRepeatedReadsCostOneRoundTripPerQueryAndNoDatabaseAccess(): void
    {
        $workload = new Workload(1, self::databaseDriver());
        $workload->seedData();
        DB::table('drivers')->update(['name' => 'loads the dependency map']);

        foreach ($workload->cachedReads(2) as $label => $read) {
            $firstRunQueries = $this->databaseQueriesDuring($read);
            $secondRunQueries = 0;

            $commands = $this->recordRedisCommands(function () use ($read, &$secondRunQueries): void {
                $secondRunQueries = $this->databaseQueriesDuring($read);
            });

            $this->assertSame(0, $secondRunQueries, "Repeated read \"{$label}\" reached the database");
            $this->assertSame(array_fill(0, $firstRunQueries, 'evalsha'), $commands, "Repeated read \"{$label}\" did not cost one round trip per query");
        }
    }

    private function databaseQueriesDuring(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
