<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Support\RowKeys;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Database\Models\License;
use Spiritix\LadaCache\Tests\TestCase;

class RowKeyEquivalenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.consider_rows' => true]);
        DB::table('cars')->insert(['id' => 5, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
    }

    public function testReadWithPaddedKeyIsInvalidatedByWriteWithIntegerKey(): void
    {
        $this->assertSame('before', DB::table('cars')->where('id', '05')->value('name'));

        DB::table('cars')->where('id', 5)->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', '05')->value('name'));
    }

    public function testReadWithIntegerKeyIsInvalidatedByWriteWithNumericStringKey(): void
    {
        $this->assertSame('before', DB::table('cars')->where('id', 5)->value('name'));

        DB::table('cars')->whereIn('id', ['5'])->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', 5)->value('name'));
    }

    public function testKeysTheDatabaseMayCastFallBackToTableTags(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Only MySQL casts "5abc" to 5.');
        }

        $this->assertSame('before', DB::table('cars')->where('id', '5abc')->value('name'));

        DB::table('cars')->where('id', 5)->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', '5abc')->value('name'));
    }

    public function testStringKeysDifferingInCaseShareRowTags(): void
    {
        $read = (new Tagger(new Reflector(License::query()->whereKey('ABC')->getQuery())))->getTags();
        $write = (new Tagger(new Reflector(
            License::query()->whereKey('abc')->getQuery(),
            Reflector::QUERY_TYPE_UPDATE,
            ['holder' => 'after'],
        )))->getTags();

        $this->assertNotSame([], array_intersect($read, $write));
    }

    public function testStringKeyReadIsInvalidatedByWriteDifferingInCaseOnCaseInsensitiveCollation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a case-insensitive collation.');
        }

        License::query()->create(['code' => 'ABC', 'holder' => 'before']);
        $this->assertSame('before', License::query()->find('ABC')?->holder);

        License::query()->whereKey('abc')->update(['holder' => 'after']);

        $this->assertSame('after', License::query()->find('ABC')?->holder);
    }

    public function testManyKeysAreTaggedAtTableLevel(): void
    {
        $builder = DB::table('cars')->whereIn('id', range(1, RowKeys::MAX_PER_TABLE + 1));
        $tags = (new Tagger(new Reflector($builder)))->getTags();

        $this->assertSame(['tags:database:'.DB::connection()->getDatabaseName().':table_unspecific:cars'], $tags);
    }

    public function testWriteToManyKeysInvalidatesReadsOfEachOfThem(): void
    {
        $this->assertSame('before', DB::table('cars')->where('id', 5)->value('name'));

        DB::table('cars')->whereIn('id', range(1, RowKeys::MAX_PER_TABLE + 1))->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', 5)->value('name'));
    }
}
