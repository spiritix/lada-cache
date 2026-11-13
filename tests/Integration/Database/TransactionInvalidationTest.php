<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class TransactionInvalidationTest extends TestCase
{
    use CacheAssertions;

    public function testInvalidationIsQueuedInsideTransactionAndFlushedOnCommit(): void
    {
        DB::table('cars')->insert(['id' => 10, 'name' => 'T', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 10);
        $builder->get();

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key, 'Expected key to exist after initial cached select');

        DB::beginTransaction();
        try {
            DB::table('cars')->where('id', 10)->update(['name' => 'T2']);

            $this->assertCacheHas($key, 'Expected key to still exist before transaction commit (invalidation queued)');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->assertCacheMissing($key, 'Expected key to be invalidated after transaction commit');
    }

    public function testQueuedInvalidationsAreClearedOnRollback(): void
    {
        DB::table('cars')->insert(['id' => 11, 'name' => 'R', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 11);
        $builder->get();

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key, 'Expected key to exist after initial cached select');

        DB::beginTransaction();
        try {
            DB::table('cars')->where('id', 11)->update(['name' => 'R2']);
            DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->assertCacheHas($key, 'Expected key to remain after rollback (queued invalidations cleared)');
    }

    public function testNestedCommitDefersInvalidationUntilOutermostCommit(): void
    {
        $key = $this->cacheCar(12);

        DB::transaction(function () use ($key): void {
            DB::transaction(function (): void {
                DB::table('cars')->where('id', 12)->update(['name' => 'N2']);
            });

            $this->assertCacheHas($key, 'Expected key to survive the nested commit while the outer transaction is open');
        });

        $this->assertCacheMissing($key, 'Expected key to be invalidated after the outermost commit');
    }

    public function testSavepointRollbackKeepsInvalidationsOfEnclosingTransaction(): void
    {
        $key = $this->cacheCar(13);

        DB::transaction(function (): void {
            DB::table('cars')->where('id', 13)->update(['name' => 'S2']);

            DB::beginTransaction();
            DB::table('cars')->insert(['id' => 14, 'name' => 'S', 'engine_id' => null, 'driver_id' => null]);
            DB::rollBack();
        });

        $this->assertCacheMissing($key, 'Expected the outer write to invalidate after commit despite the savepoint rollback');
    }

    public function testSavepointRollbackDiscardsOnlyItsOwnInvalidations(): void
    {
        $key = $this->cacheCar(15);
        $otherKey = $this->cacheCar(16);

        DB::transaction(function (): void {
            DB::table('cars')->where('id', 15)->update(['name' => 'P2']);

            DB::beginTransaction();
            DB::table('cars')->where('id', 16)->update(['name' => 'P3']);
            DB::rollBack();
        });

        $this->assertCacheMissing($key, 'Expected the committed write to invalidate');
        $this->assertCacheHas($otherKey, 'Expected the rolled back write not to invalidate');
    }

    private function cacheCar(int $id): string
    {
        DB::table('cars')->insert(['id' => $id, 'name' => 'C', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', $id);
        $builder->get();

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key, 'Expected key to exist after initial cached select');

        return $key;
    }
}
