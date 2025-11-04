<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class SpecificIsolationTest extends TestCase
{
    use CacheAssertions;

    public function test_update_specific_row_does_not_invalidate_other_specific_row(): void
    {
        // Arrange: seed two rows
        DB::table('cars')->insert([
            ['id' => 10, 'name' => 'A', 'engine_id' => null, 'driver_id' => null],
            ['id' => 11, 'name' => 'B', 'engine_id' => null, 'driver_id' => null],
        ]);

        // Build two specific SELECT queries
        $b10 = DB::table('cars')->where('id', 10);
        $b11 = DB::table('cars')->where('id', 11);

        $r10 = new Reflector($b10);
        $r11 = new Reflector($b11);

        $k10 = (new Hasher($r10))->getHash();
        $k11 = (new Hasher($r11))->getHash();
        $t10 = (new Tagger($r10))->getTags();
        $t11 = (new Tagger($r11))->getTags();

        /** @var \Spiritix\LadaCache\Cache $cache */
        $cache = app('lada.cache');

        // Seed cache entries for both queries
        $cache->set($k10, $t10, [['id' => 10, 'name' => 'A']]);
        $cache->set($k11, $t11, [['id' => 11, 'name' => 'B']]);

        $this->assertCacheHas($k10, 'Expected specific cache for id=10 to be present before update');
        $this->assertCacheHas($k11, 'Expected specific cache for id=11 to be present before update');

        // Act: update only the second row
        DB::table('cars')->where('id', 11)->update(['name' => 'B2']);

        // Assert: cache for id=11 should be invalidated, but id=10 should remain
        $this->assertCacheMissing($k11, 'Expected specific cache for id=11 to be invalidated');
        $this->assertCacheHas($k10, 'Updating a specific row should not invalidate another specific row');
    }
}
