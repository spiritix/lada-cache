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
}
