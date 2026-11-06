<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Support\RowKeys;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Database\Models\License;
use Spiritix\LadaCache\Tests\TestCase;

final class RowKeyEquivalenceTest extends TestCase
{
    use CacheAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cars')->insert(['id' => 5, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
    }

    public function testReadWithPaddedKeyRefreshesAfterWriteWithIntegerKey(): void
    {
        $this->assertSame('before', DB::table('cars')->where('id', '05')->value('name'));

        DB::table('cars')->where('id', 5)->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', '05')->value('name'));
    }

    public function testReadWithIntegerKeyRefreshesAfterWriteWithNumericStringKey(): void
    {
        $this->assertSame('before', DB::table('cars')->where('id', 5)->value('name'));

        DB::table('cars')->whereIn('id', ['5'])->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', 5)->value('name'));
    }

    public function testStringKeysIgnoreCaseLikeCaseInsensitiveCollations(): void
    {
        License::query()->create(['code' => 'ABC', 'holder' => 'before']);
        $this->assertSame('before', License::query()->find('ABC')?->holder);
        $readTags = (new Tagger(new Reflector(License::query()->whereKey('ABC')->getQuery())))->getTags();

        License::query()->whereKey('abc')->update(['holder' => 'after']);
        $writeTags = (new Tagger(new Reflector(License::query()->whereKey('abc')->getQuery(), Reflector::QUERY_TYPE_UPDATE, ['holder' => 'after'])))->getTags();

        $this->assertNotSame([], array_intersect($readTags, $writeTags));
    }

    public function testKeysTheDatabaseMayCastFallBackToTableTags(): void
    {
        $this->requireDatabaseDriver('mysql');
        $this->assertSame('before', DB::table('cars')->where('id', '5abc')->value('name'));

        DB::table('cars')->where('id', 5)->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', '5abc')->value('name'));
    }

    public function testManyKeysAreTaggedAtTableLevel(): void
    {
        $tags = (new Tagger(new Reflector(DB::table('cars')->whereIn('id', range(1, RowKeys::MAX_PER_TABLE + 1)))))->getTags();

        $this->assertSame(['tags:database:'.DB::connection()->getDatabaseName().':table_unspecific:cars'], $tags);
    }

    public function testReadOfManyKeysRefreshesAfterWriteToOneOfThem(): void
    {
        $ids = range(1, RowKeys::MAX_PER_TABLE + 1);
        $this->assertSame(['before'], DB::table('cars')->whereIn('id', $ids)->pluck('name')->all());

        DB::table('cars')->where('id', 5)->update(['name' => 'after']);

        $this->assertSame(['after'], DB::table('cars')->whereIn('id', $ids)->pluck('name')->all());
    }

    public function testWriteToManyKeysRefreshesReadsOfEachOfThem(): void
    {
        $this->assertSame('before', DB::table('cars')->where('id', 5)->value('name'));

        DB::table('cars')->whereIn('id', range(1, RowKeys::MAX_PER_TABLE + 1))->update(['name' => 'after']);

        $this->assertSame('after', DB::table('cars')->where('id', 5)->value('name'));
    }

    public function testUpdatingAKeyToAnExpressionRefreshesReadsOfTheNewKey(): void
    {
        $this->assertNull(DB::table('cars')->where('id', 15)->value('name'));

        DB::table('cars')->where('id', 5)->update(['id' => DB::raw('id + 10')]);

        $this->assertSame('before', DB::table('cars')->where('id', 15)->value('name'));
    }

    public function testUpdatesThatKeepTheKeyLeaveOtherRowsCached(): void
    {
        DB::table('cars')->insert(['id' => 6, 'name' => 'other', 'engine_id' => null, 'driver_id' => null]);
        $otherRow = DB::table('cars')->where('id', 6);
        $otherRow->get();
        [$key, $tags] = $this->cacheKeyAndTags($otherRow);

        DB::table('cars')->where('id', 5)->update(['name' => 'after']);

        $this->assertCacheHas($key, $tags);
    }
}
