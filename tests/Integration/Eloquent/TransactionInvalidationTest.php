<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class TransactionInvalidationTest extends TestCase
{
    use CacheAssertions;

    public function test_specific_update_in_transaction_invalidates_after_commit_only(): void
    {
        DB::table('cars')->insert(['id' => 601, 'name' => 'TX', 'engine_id' => null, 'driver_id' => null]);

        $b = DB::table('cars')->where('id', 601);
        $r = new Reflector($b);
        $k = (new Hasher($r))->getHash();
        $t = (new Tagger($r))->getTags();

        /** @var \Spiritix\LadaCache\Cache $cache */
        $cache = app('lada.cache');
        $cache->set($k, $t, [['id' => 601, 'name' => 'TX']]);
        $this->assertCacheHas($k);

        DB::beginTransaction();
        DB::table('cars')->where('id', 601)->update(['name' => 'TX2']);
        // Should still be present before commit
        $this->assertCacheHas($k);
        DB::commit();

        // After commit, ensure queued invalidations are flushed
        /** @var \Spiritix\LadaCache\QueryHandler $handler */
        $handler = $this->app->make('lada.handler');
        $handler->flushQueuedInvalidationsForConnection(DB::connection());
        $this->assertCacheMissing($k);
    }
}
