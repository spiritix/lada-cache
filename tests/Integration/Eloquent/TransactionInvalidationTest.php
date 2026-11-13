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

    public function testSpecificUpdateInTransactionInvalidatesAfterCommitOnly(): void
    {
        DB::table('cars')->insert(['id' => 601, 'name' => 'TX', 'engine_id' => null, 'driver_id' => null]);

        $b = DB::table('cars')->where('id', 601);
        $r = new Reflector($b);
        $k = (new Hasher($r))->getHash();
        $t = (new Tagger($r))->getTags();
        $this->seedCache($k, $t, [['id' => 601, 'name' => 'TX']]);
        $this->assertCacheHas($k, $t);

        DB::beginTransaction();
        DB::table('cars')->where('id', 601)->update(['name' => 'TX2']);
        // Should still be present before commit
        $this->assertCacheHas($k, $t);
        DB::commit();

        $this->assertCacheMissing($k, $t);
    }
}
