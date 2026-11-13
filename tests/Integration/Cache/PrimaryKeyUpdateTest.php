<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\TestCase;

class PrimaryKeyUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.consider_rows' => true]);
        DB::table('cars')->insert(['id' => 5, 'name' => 'moved', 'engine_id' => null, 'driver_id' => null]);
    }

    public function testUpdateAssigningPrimaryKeyInvalidatesCachedReadOfNewKey(): void
    {
        $this->assertNull(DB::table('cars')->where('id', 15)->value('name'));

        DB::table('cars')->where('id', 5)->update(['id' => 15]);

        $this->assertSame('moved', DB::table('cars')->where('id', 15)->value('name'));
    }

    public function testUpdateAssigningPrimaryKeyExpressionInvalidatesCachedReadOfNewKey(): void
    {
        $this->assertNull(DB::table('cars')->where('id', 15)->value('name'));

        DB::table('cars')->where('id', 5)->update(['cars.id' => DB::raw('id + 10')]);

        $this->assertSame('moved', DB::table('cars')->where('id', 15)->value('name'));
    }

    public function testUpdateKeepingPrimaryKeyStaysRowSpecific(): void
    {
        $builder = DB::table('cars')->where('id', 5);
        $tags = (new Tagger(new Reflector($builder, Reflector::QUERY_TYPE_UPDATE, ['name' => 'renamed'])))->getTags();

        $this->assertNotContains('tags:database:'.DB::connection()->getDatabaseName().':table_specific:cars', $tags);
    }
}
