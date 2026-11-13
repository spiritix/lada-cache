<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class WithoutCacheTest extends TestCase
{
    use CacheAssertions;

    public function testWithoutCacheBypassesReadCache(): void
    {
        DB::table('cars')->insert(['id' => 2010, 'name' => 'NoCacheRead', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 2010);

        // First read with withoutCache() must not populate the cache
        $result = $builder->withoutCache()->get();
        $this->assertNotEmpty($result);

        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheMissing($key, $tags, 'withoutCache should not populate Redis on read');
    }

    public function testWithoutCacheStillRunsTheUpdateInvalidation(): void
    {
        // Arrange: warm cache for the row
        DB::table('cars')->insert(['id' => 2020, 'name' => 'OriginalName', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2020);
        $builder->get();

        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->where('id', 2020)->withoutCache()->update(['name' => 'WithoutCacheWrite']);

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testWithoutCacheStillRunsTheInsertInvalidation(): void
    {
        // Warm cache on a "named row missing" query
        $builder = DB::table('cars')->where('name', 'InsertBypass');
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->withoutCache()
            ->insert(['id' => 2030, 'name' => 'InsertBypass', 'engine_id' => null, 'driver_id' => null]);

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testWithoutCacheStillRunsTheInsertGetIdInvalidation(): void
    {
        $builder = DB::table('cars')->where('name', 'InsertGetIdBypass');
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->withoutCache()
            ->insertGetId(['name' => 'InsertGetIdBypass', 'engine_id' => null, 'driver_id' => null]);

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testWithoutCacheStillRunsTheInsertOrIgnoreInvalidation(): void
    {
        $builder = DB::table('cars')->where('name', 'InsertOrIgnoreBypass');
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->withoutCache()
            ->insertOrIgnore(['id' => 2040, 'name' => 'InsertOrIgnoreBypass', 'engine_id' => null, 'driver_id' => null]);

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testWithoutCacheStillRunsTheUpsertInvalidation(): void
    {
        DB::table('cars')->insert(['id' => 2050, 'name' => 'UpsertBypassOriginal', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2050);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->withoutCache()
            ->upsert([['id' => 2050, 'name' => 'UpsertBypassNew']], ['id'], ['name']);

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testWithoutCacheStillRunsTheUpdateOrInsertInvalidation(): void
    {
        $builder = DB::table('cars')->where('id', 2060);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->where('id', 2060)->withoutCache()
            ->updateOrInsert(['id' => 2060], ['name' => 'UpdateOrInsertBypass']);

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testWithoutCacheStillRunsTheDeleteInvalidation(): void
    {
        DB::table('cars')->insert(['id' => 2070, 'name' => 'DeleteBypass', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2070);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->where('id', 2070)->withoutCache()->delete();

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testWithoutCacheStillRunsTheTruncateInvalidation(): void
    {
        DB::table('cars')->insert(['id' => 2080, 'name' => 'TruncateBypass', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2080);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->withoutCache()->truncate();

        $this->assertCacheMissing($key, $tags, 'Writes with withoutCache() must still invalidate, or other readers see stale rows');
    }

    public function testNormalWriteStillInvalidates(): void
    {
        // Regression sanity: the default write path must still invalidate
        DB::table('cars')->insert(['id' => 2090, 'name' => 'NormalWriteOriginal', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2090);
        $builder->get();

        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $this->assertCacheHas($key, $tags);

        DB::table('cars')->where('id', 2090)->update(['name' => 'NormalWriteAfter']);

        $this->assertCacheMissing($key, $tags, 'Default write path must invalidate');
    }

    public function testWithoutCacheReturnsBuilderForChaining(): void
    {
        $builder = DB::table('cars');
        $returned = $builder->withoutCache();

        $this->assertInstanceOf(QueryBuilder::class, $returned);
        $this->assertTrue($returned->isCacheBypassed());
    }

    public function testEloquentMacroPropagatesToUnderlyingQueryBuilder(): void
    {
        Car::query()->forceCreate(['id' => 2100, 'name' => 'EloquentMacro', 'engine_id' => null, 'driver_id' => null]);

        $eloquent = Car::query()->where('id', 2100);
        $eloquent->withoutCache();
        $underlying = $eloquent->getQuery();

        $this->assertInstanceOf(QueryBuilder::class, $underlying);
        $this->assertTrue($underlying->isCacheBypassed(), 'Eloquent macro must flip the underlying query builder flag');
    }

    public function testChainOrderingPreservesBypassFlag(): void
    {
        DB::table('cars')->insert(['id' => 2110, 'name' => 'ChainOrder', 'engine_id' => null, 'driver_id' => null]);

        // withoutCache() before where()
        $builderA = DB::table('cars')->withoutCache()->where('id', 2110);
        $builderA->get();
        [$keyA, $tagsA] = $this->cacheKeyAndTags($builderA);
        $this->assertCacheMissing($keyA, $tagsA, 'withoutCache->where chain must bypass');

        // where() before withoutCache()
        $builderB = DB::table('cars')->where('id', 2110)->withoutCache();
        $builderB->get();
        [$keyB, $tagsB] = $this->cacheKeyAndTags($builderB);
        $this->assertCacheMissing($keyB, $tagsB, 'where->withoutCache chain must bypass');
    }

    public function testExistsHonorsBypassFlag(): void
    {
        DB::table('cars')->insert(['id' => 2120, 'name' => 'ExistsBypass', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 2120)->withoutCache();
        $this->assertTrue($builder->exists());

        // exists() uses an internal clone-and-get path; verify that path also bypasses the cache
        $reflectorBuilder = DB::table('cars')->where('id', 2120)->limit(1);
        [$key, $tags] = $this->cacheKeyAndTags($reflectorBuilder);
        $this->assertCacheMissing($key, $tags, 'exists() under withoutCache must not populate Redis');
    }

    public function testNewQueryResetsBypassFlag(): void
    {
        $builder = DB::table('cars')->withoutCache();
        $this->assertTrue($builder->isCacheBypassed());

        $fresh = $builder->newQuery();
        $this->assertInstanceOf(QueryBuilder::class, $fresh);
        $this->assertFalse($fresh->isCacheBypassed(), 'newQuery() must not inherit the bypass flag');
    }
}
