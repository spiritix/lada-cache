<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

final class InvalidationExtendedTest extends TestCase
{
    use CacheAssertions;

    public function testBroadUpdateInvalidatesAllSpecificAndUnspecificForTable(): void
    {
        DB::table('cars')->insert([
            ['id' => 30, 'name' => 'A', 'engine_id' => null, 'driver_id' => null],
            ['id' => 31, 'name' => 'B', 'engine_id' => null, 'driver_id' => null],
        ]);

        $mk = function (int $id): array {
            $b = DB::table('cars')->where('id', $id);
            $r = new Reflector($b);

            return [(new Hasher($r))->getHash(), (new Tagger($r))->getTags()];
        };
        [$k30, $t30] = $mk(30);
        [$k31, $t31] = $mk(31);

        $broad = DB::table('cars'); // unspecific read
        $rb = new Reflector($broad);
        $kb = (new Hasher($rb))->getHash();
        $tb = (new Tagger($rb))->getTags();
        $this->seedCache($k30, $t30, [['id' => 30, 'name' => 'A']]);
        $this->seedCache($k31, $t31, [['id' => 31, 'name' => 'B']]);
        $this->seedCache($kb, $tb, [['id' => 30], ['id' => 31]]);

        $this->assertCacheHas($k30, $t30);
        $this->assertCacheHas($k31, $t31);
        $this->assertCacheHas($kb, $tb);

        // Broad update: no specific rows detectable
        DB::table('cars')->update(['name' => 'ALL']);

        $this->assertCacheMissing($k30, $t30);
        $this->assertCacheMissing($k31, $t31);
        $this->assertCacheMissing($kb, $tb);
    }

    public function testBroadDeleteInvalidatesAllSpecificAndUnspecificForTable(): void
    {
        DB::table('cars')->insert([
            ['id' => 40, 'name' => 'A', 'engine_id' => null, 'driver_id' => null],
            ['id' => 41, 'name' => 'B', 'engine_id' => null, 'driver_id' => null],
        ]);

        $mk = function (int $id): array {
            $b = DB::table('cars')->where('id', $id);
            $r = new Reflector($b);

            return [(new Hasher($r))->getHash(), (new Tagger($r))->getTags()];
        };
        [$k40, $t40] = $mk(40);
        [$k41, $t41] = $mk(41);
        $this->seedCache($k40, $t40, [['id' => 40, 'name' => 'A']]);
        $this->seedCache($k41, $t41, [['id' => 41, 'name' => 'B']]);

        $this->assertCacheHas($k40, $t40);
        $this->assertCacheHas($k41, $t41);

        // Broad delete (no where clause) invalidates all
        DB::table('cars')->delete();

        $this->assertCacheMissing($k40, $t40);
        $this->assertCacheMissing($k41, $t41);
    }

    public function testTruncateRemovesSpecificAndUnspecificCachedKeys(): void
    {
        DB::table('cars')->insert([
            ['id' => 50, 'name' => 'A', 'engine_id' => null, 'driver_id' => null],
            ['id' => 51, 'name' => 'B', 'engine_id' => null, 'driver_id' => null],
        ]);

        $broad = DB::table('cars');
        $rb = new Reflector($broad);
        $kb = (new Hasher($rb))->getHash();
        $tb = (new Tagger($rb))->getTags();

        $spec = DB::table('cars')->where('id', 50);
        $rs = new Reflector($spec);
        $ks = (new Hasher($rs))->getHash();
        $ts = (new Tagger($rs))->getTags();
        $this->seedCache($kb, $tb, [['id' => 50], ['id' => 51]]);
        $this->seedCache($ks, $ts, [['id' => 50, 'name' => 'A']]);

        $this->assertCacheHas($kb, $tb);
        $this->assertCacheHas($ks, $ts);

        DB::table('cars')->truncate();

        $this->assertCacheMissing($kb, $tb);
        $this->assertCacheMissing($ks, $ts);
    }

    public function testSpecificRowDeleteInvalidatesBroadSelect(): void
    {
        DB::table('cars')->insert(['id' => 60, 'name' => 'ToDelete', 'engine_id' => null, 'driver_id' => null]);

        // Prime broad cache (no filters)
        $countBefore = DB::table('cars')->count();
        $this->assertGreaterThanOrEqual(1, $countBefore);

        // Delete specific row
        DB::table('cars')->where('id', 60)->delete();

        // Broad read should reflect deletion (not stale cache)
        $countAfter = DB::table('cars')->count();
        $this->assertSame($countBefore - 1, $countAfter);
    }
}
