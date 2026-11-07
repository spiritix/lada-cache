<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Application;

use Closure;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Query shapes taken from a production application, each asserting that a cached read is
 * served from the cache and refreshed by every kind of write that changes its result.
 */
abstract class ApplicationShapeTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('workspace_groups')->insert([
            ['id' => 1, 'name' => 'trial', 'package_key' => 'trial', 'token_limit' => null],
            ['id' => 2, 'name' => 'paid', 'package_key' => 'paid', 'token_limit' => 500],
        ]);
        DB::table('workspaces')->insert([
            ['id' => 1, 'group_id' => 1, 'name' => 'alpha', 'active' => true, 'token_limit' => null],
            ['id' => 2, 'group_id' => 2, 'name' => 'beta', 'active' => true, 'token_limit' => null],
            ['id' => 3, 'group_id' => 2, 'name' => 'gamma', 'active' => false, 'token_limit' => 100],
        ]);
        DB::table('members')->insert([
            ['id' => 1, 'workspace_id' => 1, 'name' => 'ann', 'logins' => 3],
            ['id' => 2, 'workspace_id' => 2, 'name' => 'bob', 'logins' => 7],
            ['id' => 3, 'workspace_id' => 2, 'name' => 'cid', 'logins' => 1],
        ]);
    }

    /**
     * Runs the read twice around nothing, asserting the second run is served from the cache,
     * then applies the write and asserts the read returns what the database now holds.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @param  Closure(): mixed  $write
     */
    protected function assertCachedThenRefreshed(Closure $read, Closure $write): void
    {
        $before = $read();
        $this->assertSame(0, $this->selectsDuring($read), 'The read was not served from the cache');
        $this->assertEquals($before, $read());

        $write();

        $after = $read();
        $this->assertNotEquals($before, $after, 'The write did not change the result, so the shape proves nothing');
        $this->assertEquals($this->uncached($read), $after, 'The cached read is stale after the write');
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    protected function uncached(Closure $read): mixed
    {
        config(['lada-cache.active' => false]);

        try {
            return $read();
        } finally {
            config(['lada-cache.active' => true]);
        }
    }

    protected function selectsDuring(Closure $queries): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $queries();
        } finally {
            DB::disableQueryLog();
        }

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_starts_with(ltrim(strtolower($entry['query']), '( '), 'select'),
        ));
    }
}
