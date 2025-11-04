<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class SpecificIsolationExtendedTest extends TestCase
{
    use CacheAssertions;

    public function test_specific_update_on_one_table_does_not_invalidate_other_rows_or_tables(): void
    {
        // Seed two cars and one driver row. We'll cache car rows; driver stays untouched but ensures multi-table scenario.
        DB::table('cars')->insert([
            ['id' => 10, 'name' => 'A', 'engine_id' => null, 'driver_id' => null],
            ['id' => 11, 'name' => 'B', 'engine_id' => null, 'driver_id' => null],
        ]);
        DB::table('drivers')->insert(['id' => 501, 'name' => 'DRV']);

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
        $cache->set($k10, $t10, [['id' => 10, 'name' => 'A']]);
        $cache->set($k11, $t11, [['id' => 11, 'name' => 'B']]);

        $this->assertCacheHas($k10);
        $this->assertCacheHas($k11);

        // Specific update on cars.id=11 must not evict cached row id=10
        DB::table('cars')->where('id', 11)->update(['name' => 'B2']);

        $this->assertCacheHas($k10, 'Specific update should not evict other specific rows');
        $this->assertCacheMissing($k11, 'Row 11 cache should be invalidated');
    }

    public function test_update_where_in_invalidates_only_listed_rows(): void
    {
        DB::table('cars')->insert([
            ['id' => 20, 'name' => 'X', 'engine_id' => null, 'driver_id' => null],
            ['id' => 21, 'name' => 'Y', 'engine_id' => null, 'driver_id' => null],
            ['id' => 22, 'name' => 'Z', 'engine_id' => null, 'driver_id' => null],
        ]);

        $mk = function (int $id): array {
            $b = DB::table('cars')->where('id', $id);
            $r = new Reflector($b);
            return [(new Hasher($r))->getHash(), (new Tagger($r))->getTags()];
        };

        [$k20, $t20] = $mk(20);
        [$k21, $t21] = $mk(21);
        [$k22, $t22] = $mk(22);

        /** @var \Spiritix\LadaCache\Cache $cache */
        $cache = app('lada.cache');
        $cache->set($k20, $t20, [['id' => 20, 'name' => 'X']]);
        $cache->set($k21, $t21, [['id' => 21, 'name' => 'Y']]);
        $cache->set($k22, $t22, [['id' => 22, 'name' => 'Z']]);

        $this->assertCacheHas($k20);
        $this->assertCacheHas($k21);
        $this->assertCacheHas($k22);

        DB::table('cars')->whereIn('id', [20, 22])->update(['name' => 'UPD']);

        $this->assertCacheMissing($k20);
        $this->assertCacheHas($k21);
        $this->assertCacheMissing($k22);

        // Verify DB reflect changes for sanity
        Assert::assertSame('Y', DB::table('cars')->where('id', 21)->value('name'));
    }
}
