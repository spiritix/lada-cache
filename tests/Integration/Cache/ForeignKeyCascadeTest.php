<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Support\ForeignKeyDependents;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Concerns\RecordsRedisCommands;
use Spiritix\LadaCache\Tests\TestCase;

final class ForeignKeyCascadeTest extends TestCase
{
    use CacheAssertions;
    use RecordsRedisCommands;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('owners')->insert([['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']]);
        DB::table('pets')->insert([['id' => 1, 'owner_id' => 1, 'name' => 'rex'], ['id' => 2, 'owner_id' => 2, 'name' => 'tom']]);
        DB::table('pet_toys')->insert([['id' => 1, 'pet_id' => 1, 'name' => 'ball']]);
        DB::table('owner_notes')->insert([['id' => 1, 'owner_id' => 1, 'body' => 'vip']]);
        DB::table('owner_audits')->insert([['id' => 1, 'owner_id' => 2, 'event' => 'signup']]);
    }

    public function testDependentsAreReadFromTheSchema(): void
    {
        $dependents = ForeignKeyDependents::load(DB::connection());

        $this->assertSame(['owner_notes', 'pet_toys', 'pets'], $dependents['owners']);
        $this->assertSame(['pet_toys'], $dependents['pets']);
        $this->assertArrayNotHasKey('owner_audits', $dependents);
    }

    public function testDeletingAParentRefreshesCachedReadsOfCascadedChildren(): void
    {
        $this->assertSame(2, DB::table('pets')->count());
        $this->assertSame(1, DB::table('pet_toys')->count());

        DB::table('owners')->where('id', 1)->delete();

        $this->assertSame(1, DB::table('pets')->count());
        $this->assertSame(0, DB::table('pet_toys')->count());
    }

    public function testDeletingAParentRefreshesCachedRowLookupsOfCascadedChildren(): void
    {
        $this->assertSame('rex', DB::table('pets')->where('id', 1)->value('name'));

        DB::table('owners')->where('id', 1)->delete();

        $this->assertNull(DB::table('pets')->where('id', 1)->value('name'));
    }

    public function testDeletingAParentRefreshesCachedReadsOfNulledChildren(): void
    {
        $this->assertSame(1, (int) DB::table('owner_notes')->where('id', 1)->value('owner_id'));

        DB::table('owners')->where('id', 1)->delete();

        $this->assertNull(DB::table('owner_notes')->where('id', 1)->value('owner_id'));
    }

    public function testUpdatingAParentKeyRefreshesCachedReadsOfCascadedChildren(): void
    {
        DB::connection()->getPdo()->exec('delete from owner_notes');
        $this->assertSame(1, (int) DB::table('pets')->where('id', 1)->value('owner_id'));

        DB::table('owners')->where('id', 1)->update(['id' => 10]);

        $this->assertSame(10, (int) DB::table('pets')->where('id', 1)->value('owner_id'));
    }

    public function testRawDeleteRefreshesCachedReadsOfCascadedChildren(): void
    {
        $this->assertSame(2, DB::table('pets')->count());

        DB::delete('delete from owners where id = ?', [1]);

        $this->assertSame(1, DB::table('pets')->count());
    }

    public function testDeletingInsideATransactionRefreshesCascadedChildrenAtCommit(): void
    {
        $this->assertSame(2, DB::table('pets')->count());

        DB::transaction(fn () => DB::table('owners')->where('id', 1)->delete());

        $this->assertSame(1, DB::table('pets')->count());
    }

    public function testRestrictingForeignKeysKeepChildrenCached(): void
    {
        $builder = DB::table('owner_audits')->where('id', 1);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::table('owners')->where('id', 1)->delete();

        $this->assertCacheHas($key, $tags);
    }

    public function testInsertsIntoAParentKeepChildrenCached(): void
    {
        $builder = DB::table('pets')->where('id', 1);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::table('owners')->insert(['id' => 3, 'name' => 'carol']);

        $this->assertCacheHas($key, $tags);
    }

    public function testConfiguredDependentTablesAreInvalidatedToo(): void
    {
        config(['lada-cache.dependent_tables' => ['drivers' => ['materials']]]);
        DB::table('materials')->insert(['id' => 1, 'name' => 'steel']);
        $this->assertSame('steel', DB::table('materials')->where('id', 1)->value('name'));
        DB::connection()->getPdo()->exec("update materials set name = 'written by a trigger' where id = 1");

        DB::table('drivers')->update(['name' => 'anyone']);

        $this->assertSame('written by a trigger', DB::table('materials')->where('id', 1)->value('name'));
    }

    public function testChangedDependentTablesApplyWithoutRetiringTheCache(): void
    {
        DB::table('drivers')->count();
        DB::table('drivers')->update(['name' => 'stores the dependency map']);
        config(['lada-cache.dependent_tables' => ['drivers' => ['materials']]]);
        DB::table('materials')->insert(['id' => 1, 'name' => 'steel']);
        $this->assertSame('steel', DB::table('materials')->where('id', 1)->value('name'));
        DB::connection()->getPdo()->exec("update materials set name = 'written by a trigger' where id = 1");

        DB::table('drivers')->update(['name' => 'anyone']);

        $this->assertSame('written by a trigger', DB::table('materials')->where('id', 1)->value('name'));
    }

    public function testWritesToTablesExcludedFromCachingStillCascade(): void
    {
        config(['lada-cache.exclude_tables' => ['owners']]);
        $this->assertSame(2, DB::table('pets')->count());

        DB::table('owners')->where('id', 1)->delete();

        $this->assertSame(1, DB::table('pets')->count());
    }

    public function testDependentsAreReadOncePerGeneration(): void
    {
        DB::table('pets')->count();
        DB::table('owners')->where('id', 1)->update(['name' => 'first write']);

        $commands = $this->recordRedisCommands(fn () => DB::table('owners')->where('id', 2)->update(['name' => 'second write']));

        $this->assertSame(['evalsha'], $commands);
    }
}
