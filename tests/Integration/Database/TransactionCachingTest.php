<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class TransactionCachingTest extends TestCase
{
    use CacheAssertions;

    public function testReadAfterOwnWriteInsideTransactionSeesTheWrite(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'committed', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('committed', DB::table('cars')->where('id', 1)->value('name'));

        DB::transaction(function (): void {
            DB::table('cars')->where('id', 1)->update(['name' => 'uncommitted']);

            $this->assertSame('uncommitted', DB::table('cars')->where('id', 1)->value('name'));
        });
    }

    public function testRolledBackRowsAreNeverServedFromTheCache(): void
    {
        DB::beginTransaction();
        DB::table('cars')->insert(['id' => 1, 'name' => 'phantom', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('phantom', DB::table('cars')->where('id', 1)->value('name'));
        DB::rollBack();

        $this->assertNull(DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testResultsReadInsideATransactionAreNotStored(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 1);
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::transaction(fn () => DB::table('cars')->where('id', 1)->get());

        $this->assertCacheMissing($key, $tags);
    }

    public function testReadsInsideATransactionAlwaysHitTheDatabase(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->value('name');

        $selects = $this->selectsDuring(fn () => DB::transaction(function (): void {
            DB::table('cars')->where('id', 1)->value('name');
        }));

        $this->assertSame(1, $selects);
    }

    public function testNestedCommitDefersInvalidationUntilTheOutermostCommit(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 1);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::beginTransaction();
        DB::beginTransaction();
        DB::table('cars')->where('id', 1)->update(['name' => 'after']);
        DB::commit();
        $this->assertCacheHas($key, $tags);
        DB::commit();

        $this->assertCacheMissing($key, $tags);
    }

    public function testSavepointRollbackKeepsInvalidationsOfTheOuterTransaction(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        DB::beginTransaction();
        DB::table('cars')->where('id', 1)->update(['name' => 'after']);
        DB::beginTransaction();
        DB::table('drivers')->insert(['id' => 1, 'name' => 'discarded']);
        DB::rollBack();
        DB::commit();

        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testInvalidationsOfARolledBackTransactionDoNotLeakIntoTheNext(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'row', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 1);
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::beginTransaction();
        DB::table('cars')->where('id', 1)->update(['name' => 'discarded']);
        DB::rollBack();

        $builder->get();
        DB::transaction(fn () => DB::table('drivers')->insert(['id' => 1, 'name' => 'unrelated']));

        $this->assertCacheHas($key, $tags);
    }

    private function selectsDuring(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select'),
        ));
    }
}
