<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Hosts sharing one Redis may disagree on `consider_rows`, e.g. while a configuration
 * change rolls out; each one's writes must still invalidate the others' reads.
 */
class MixedConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
    }

    public function testWriteIgnoringRowsInvalidatesRowSpecificRead(): void
    {
        config(['lada-cache.consider_rows' => true]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        config(['lada-cache.consider_rows' => false]);
        DB::table('cars')->where('id', 1)->update(['name' => 'after']);

        config(['lada-cache.consider_rows' => true]);
        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testRowSpecificWriteInvalidatesReadIgnoringRows(): void
    {
        config(['lada-cache.consider_rows' => false]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        config(['lada-cache.consider_rows' => true]);
        DB::table('cars')->where('id', 1)->update(['name' => 'after']);

        config(['lada-cache.consider_rows' => false]);
        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }
}
