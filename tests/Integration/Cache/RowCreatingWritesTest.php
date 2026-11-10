<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

final class RowCreatingWritesTest extends TestCase
{
    public function testInsertWithoutPrimaryKeyInvalidatesCachedMissingRow(): void
    {
        $this->assertNull(DB::table('cars')->where('id', 1)->first());

        DB::table('cars')->insert(['name' => 'auto increment', 'engine_id' => null, 'driver_id' => null]);

        $this->assertNotNull(DB::table('cars')->where('id', 1)->first());
    }

    public function testInsertOrIgnoreWithoutPrimaryKeyInvalidatesCachedMissingRow(): void
    {
        $this->assertNull(DB::table('cars')->where('id', 1)->first());

        DB::table('cars')->insertOrIgnore(['name' => 'auto increment', 'engine_id' => null, 'driver_id' => null]);

        $this->assertNotNull(DB::table('cars')->where('id', 1)->first());
    }

    public function testInsertUsingInvalidatesCachedMissingRow(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'source']);
        $this->assertNull(DB::table('cars')->where('id', 1)->first());

        DB::table('cars')->insertUsing(['name'], DB::table('drivers')->select('name'));

        $this->assertNotNull(DB::table('cars')->where('id', 1)->first());
    }

    public function testInsertWithPrimaryKeyKeepsOtherRowLookupsCached(): void
    {
        $this->loadDependencyMap();
        DB::table('cars')->insert(['id' => 1, 'name' => 'cached', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->first();

        DB::table('cars')->insert(['id' => 2, 'name' => 'other', 'engine_id' => null, 'driver_id' => null]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        DB::table('cars')->where('id', 1)->first();
        DB::disableQueryLog();

        $this->assertSame([], DB::getQueryLog());
    }

    public function testUpdateChangingPrimaryKeyInvalidatesCachedMissingRowAtTheNewKey(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'moved', 'engine_id' => null, 'driver_id' => null]);
        $this->assertNull(DB::table('cars')->where('id', 2)->first());

        DB::table('cars')->where('id', 1)->update(['id' => 2]);

        $this->assertSame('moved', DB::table('cars')->where('id', 2)->first()?->name);
    }
}
