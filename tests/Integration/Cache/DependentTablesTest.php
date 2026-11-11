<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spiritix\LadaCache\Generation;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\DependentTables;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Concerns\RecordsRedisCommands;
use Spiritix\LadaCache\Tests\TestCase;

final class DependentTablesTest extends TestCase
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
        $this->loadDependencyMap();
    }

    public function testDependentsAreReadFromTheSchema(): void
    {
        $dependents = DependentTables::load(DB::connection());

        $this->assertSame(['owner_names', 'owner_pet_counts'], $dependents['a:owners']);
        $this->assertSame(['owner_notes', 'owner_pet_counts', 'pet_toys', 'pets'], $dependents['d:owners']);
        $this->assertSame(['owner_notes', 'owner_pet_counts', 'pets'], $dependents['u:owners']);
        $this->assertSame($dependents['u:owners'], $dependents['u:owners:id']);
        $this->assertSame(['owner_pet_counts'], $dependents['a:pets']);
        $this->assertSame(['pet_events'], $dependents['gi:pets']);
        $this->assertSame(['pet_toys'], $dependents['d:pets']);
        $this->assertArrayNotHasKey('gu:pets', $dependents);
        $this->assertArrayNotHasKey('gd:pets', $dependents);
        $this->assertArrayNotHasKey('u:pets', $dependents);
        $this->assertSame(['owner_note_tags'], $dependents['d:owner_notes']);
        $this->assertContains('owners', $dependents['a:owner_names']);
        $this->assertArrayNotHasKey('d:owner_audits', $dependents);
        $this->assertArrayNotHasKey('u:owners:name', $dependents);
        $this->assertSame(["owner_notes\towner_id", 'owner_pet_counts', 'pet_toys', "pets\towner_id"], $dependents['dk:owners:id']);
        $this->assertSame(['owner_id'], $dependents['k:pets']);
        $this->assertSame(['owner_id'], $dependents['k:owner_notes']);
        $this->assertSame(['pet_id'], $dependents['k:pet_toys']);
        $this->assertArrayNotHasKey('k:owner_audits', $dependents);
    }

    public function testInsertsRefreshCachedReadsOfViewsOverTheTable(): void
    {
        $petCount = fn (): int => (int) DB::table('owner_pet_counts')->where('owner_id', 1)->value('pet_count');
        $this->assertSame(1, $petCount());

        DB::table('pets')->insert(['id' => 3, 'owner_id' => 1, 'name' => 'new']);

        $this->assertSame(2, $petCount());
    }

    public function testDeletesRefreshCachedReadsOfViewsOverTheTable(): void
    {
        $petCounts = fn (): array => array_map('intval', DB::table('owner_pet_counts')->orderBy('owner_id')->pluck('pet_count')->all());
        $this->assertSame([1, 1], $petCounts());

        DB::table('owners')->where('id', 1)->delete();

        $this->assertSame([1], $petCounts());
    }

    public function testInsertsRefreshCachedReadsOfTablesTheirTriggersWrite(): void
    {
        $events = fn (): int => DB::table('pet_events')->count();
        $this->assertSame(2, $events());

        DB::table('pets')->insert(['id' => 3, 'owner_id' => 1, 'name' => 'new']);

        $this->assertSame(3, $events());
    }

    public function testUpdatesKeepCachedReadsOfTablesOnlyInsertTriggersWrite(): void
    {
        DB::table('pet_events')->get();
        [$eventsKey, $eventsTags] = $this->cacheKeyAndTags(DB::table('pet_events'));

        DB::table('pets')->where('id', 1)->update(['name' => 'renamed']);
        DB::table('pets')->where('id', 2)->delete();

        $this->assertCacheHas($eventsKey, $eventsTags, 'The trigger fires on inserts only');
    }

    public function testWritesThroughAnUpdatableViewRefreshCachedReadsOfItsBaseTable(): void
    {
        $this->requireDatabaseDriver('mysql', 'pgsql');
        $this->assertSame('alice', DB::table('owners')->where('id', 1)->value('name'));

        DB::table('owner_names')->where('id', 1)->update(['name' => 'renamed']);

        $this->assertSame('renamed', DB::table('owners')->where('id', 1)->value('name'));
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

    public function testUpdatingAParentWithoutChangingItsKeyKeepsCachedReadsOfChildren(): void
    {
        DB::table('pets')->where('owner_id', 1)->get();
        DB::table('pet_toys')->where('pet_id', 1)->get();
        [$petsKey, $petsTags] = $this->cacheKeyAndTags(DB::table('pets')->where('owner_id', 1));
        [$toysKey, $toysTags] = $this->cacheKeyAndTags(DB::table('pet_toys')->where('pet_id', 1));

        DB::table('owners')->where('id', 1)->update(['name' => 'renamed']);

        $this->assertCacheHas($petsKey, $petsTags, 'owners.id did not change, so no foreign key fired');
        $this->assertCacheHas($toysKey, $toysTags);
    }

    public function testUpdatingAParentKeyKeepsCachedReadsOfDeleteOnlyGrandchildren(): void
    {
        DB::connection()->getPdo()->exec('delete from owner_notes');
        DB::table('pet_toys')->where('pet_id', 1)->get();
        [$toysKey, $toysTags] = $this->cacheKeyAndTags(DB::table('pet_toys')->where('pet_id', 1));

        DB::table('owners')->where('id', 1)->update(['id' => 10]);

        $this->assertSame(10, (int) DB::table('pets')->where('id', 1)->value('owner_id'));
        $this->assertCacheHas($toysKey, $toysTags, 'pets.id did not change and pet_toys has no update rule');
    }

    public function testUpdatingAQualifiedParentKeyRefreshesCachedReadsOfCascadedChildren(): void
    {
        DB::connection()->getPdo()->exec('delete from owner_notes');
        $this->assertSame(1, (int) DB::table('pets')->where('id', 1)->value('owner_id'));

        DB::table('owners')->where('id', 1)->update(['owners.id' => 10]);

        $this->assertSame(10, (int) DB::table('pets')->where('id', 1)->value('owner_id'));
    }

    public function testUpsertingParentsWithoutChangingTheirKeysKeepsCachedReadsOfChildren(): void
    {
        DB::table('pets')->where('owner_id', 1)->get();
        [$petsKey, $petsTags] = $this->cacheKeyAndTags(DB::table('pets')->where('owner_id', 1));

        DB::table('owners')->upsert([['id' => 1, 'name' => 'renamed'], ['id' => 3, 'name' => 'carol']], ['id'], ['name']);

        $this->assertSame('renamed', DB::table('owners')->where('id', 1)->value('name'));
        $this->assertCacheHas($petsKey, $petsTags, 'The upsert assigned no referenced column');
    }

    public function testDeletingAParentKeepsCachedReadsBelowANulledChild(): void
    {
        DB::table('owner_note_tags')->insert(['id' => 1, 'owner_note_id' => 1, 'name' => 'gold']);
        DB::table('owner_note_tags')->get();
        [$tagsKey, $tagsTags] = $this->cacheKeyAndTags(DB::table('owner_note_tags'));

        DB::table('owners')->where('id', 1)->delete();

        $this->assertNull(DB::table('owner_notes')->where('id', 1)->value('owner_id'));
        $this->assertCacheHas($tagsKey, $tagsTags, 'The note survived with its id, so its tags did not change');
    }

    public function testDeletingANulledChildRefreshesCachedReadsOfItsCascadedChildren(): void
    {
        DB::table('owner_note_tags')->insert(['id' => 1, 'owner_note_id' => 1, 'name' => 'gold']);
        $this->assertSame(1, DB::table('owner_note_tags')->count());

        DB::table('owner_notes')->where('id', 1)->delete();

        $this->assertSame(0, DB::table('owner_note_tags')->count());
    }

    public function testWritesMatchingNoRowsDoNotCascade(): void
    {
        DB::table('pets')->get();
        [$petsKey, $petsTags] = $this->cacheKeyAndTags(DB::table('pets'));

        DB::table('owners')->where('id', 999)->delete();
        DB::table('owners')->where('id', 999)->update(['id' => 1000]);

        $this->assertCacheHas($petsKey, $petsTags);
    }

    public function testTruncatingAParentRefreshesCachedReadsOfEveryReferencingTable(): void
    {
        $this->requireDatabaseDriver('pgsql');
        $this->assertSame(1, DB::table('owner_audits')->count());

        DB::table('owners')->truncate();

        $this->assertSame(0, DB::table('owner_audits')->count(), 'TRUNCATE ... CASCADE empties restricting children too');
    }

    public function testRawWritesMatchingNoRowsDoNotCascade(): void
    {
        DB::table('pets')->get();
        [$petsKey, $petsTags] = $this->cacheKeyAndTags(DB::table('pets'));

        DB::delete('delete from owners where id = ?', [999]);
        DB::update('update owners set id = ? where id = ?', [1000, 999]);

        $this->assertCacheHas($petsKey, $petsTags);
    }

    public function testDeletingAParentKeepsCachedReadsOfChildrenOfOtherParents(): void
    {
        DB::table('pets')->where('owner_id', 2)->get();
        $this->assertSame(['rex'], DB::table('pets')->where('owner_id', 1)->pluck('name')->all());
        [$otherKey, $otherTags] = $this->cacheKeyAndTags(DB::table('pets')->where('owner_id', 2));

        DB::table('owners')->where('id', 1)->delete();

        $this->assertCacheHas($otherKey, $otherTags, 'Only pets of owner 1 were deleted');
        $this->assertSame([], DB::table('pets')->where('owner_id', 1)->pluck('name')->all());
    }

    public function testDeletingAParentRefreshesUnpinnedAndOtherwisePinnedReadsOfChildren(): void
    {
        $this->assertSame(2, DB::table('pets')->count());
        $this->assertSame('rex', DB::table('pets')->where('name', 'rex')->value('name'));
        $this->assertSame('rex', DB::table('pets')->whereIn('owner_id', [1, 2])->orderBy('id')->value('name'));

        DB::table('owners')->where('id', 1)->delete();

        $this->assertSame(1, DB::table('pets')->count());
        $this->assertNull(DB::table('pets')->where('name', 'rex')->value('name'));
        $this->assertSame('tom', DB::table('pets')->whereIn('owner_id', [1, 2])->orderBy('id')->value('name'));
    }

    public function testDeletingAParentKeepsCachedReadsOfNulledChildrenOfOtherParents(): void
    {
        DB::table('owner_notes')->insert(['id' => 2, 'owner_id' => 2, 'body' => 'regular']);
        DB::table('owner_notes')->where('owner_id', 2)->get();
        $this->assertSame(['vip'], DB::table('owner_notes')->where('owner_id', 1)->pluck('body')->all());
        $this->assertSame(0, DB::table('owner_notes')->whereNull('owner_id')->count());
        [$otherKey, $otherTags] = $this->cacheKeyAndTags(DB::table('owner_notes')->where('owner_id', 2));

        DB::table('owners')->where('id', 1)->delete();

        $this->assertCacheHas($otherKey, $otherTags, 'Only notes of owner 1 were nulled');
        $this->assertSame([], DB::table('owner_notes')->where('owner_id', 1)->pluck('body')->all());
        $this->assertSame(1, DB::table('owner_notes')->whereNull('owner_id')->count());
    }

    public function testDeletingParentsByOtherColumnsInvalidatesChildrenTableWide(): void
    {
        DB::table('pets')->where('owner_id', 2)->get();
        [$otherKey, $otherTags] = $this->cacheKeyAndTags(DB::table('pets')->where('owner_id', 2));

        DB::table('owners')->where('name', 'alice')->delete();

        $this->assertCacheMissing($otherKey, $otherTags, 'The deleted keys are not known, so every pet read is refreshed');
        $this->assertSame(1, DB::table('pets')->count());
    }

    public function testReadsCachedBeforeTheDependencyMapIsKnownAreRefreshedByRowPreciseCascades(): void
    {
        app('lada.cache')->flush();
        $this->assertSame(['rex'], DB::table('pets')->where('owner_id', 1)->pluck('name')->all());

        DB::table('owners')->where('id', 1)->delete();

        $this->assertSame([], DB::table('pets')->where('owner_id', 1)->pluck('name')->all());
    }

    public function testReadsCachedBeforeTheDependencyMapIsKnownMissOnceWhenAWriteLoadsIt(): void
    {
        app('lada.cache')->flush();
        DB::table('pets')->where('owner_id', 2)->get();
        [$key, $tags] = $this->cacheKeyAndTags(DB::table('pets')->where('owner_id', 2));
        $this->assertCacheHas($key, $tags);

        DB::table('drivers')->where('id', 0)->delete();

        $this->assertCacheMissing($key, $tags, 'With the map known, the read takes the cascade tags of its pins instead');
        DB::table('pets')->where('owner_id', 2)->get();
        $this->assertCacheHas($key, $tags);
    }

    public function testDeletingAParentInsideATransactionRefreshesChildrenAtCommitOnly(): void
    {
        $this->assertSame(['rex'], DB::table('pets')->where('owner_id', 1)->pluck('name')->all());
        DB::table('pets')->where('owner_id', 2)->get();
        [$otherKey, $otherTags] = $this->cacheKeyAndTags(DB::table('pets')->where('owner_id', 2));

        DB::transaction(fn () => DB::table('owners')->whereIn('id', [1])->delete());

        $this->assertCacheHas($otherKey, $otherTags);
        $this->assertSame([], DB::table('pets')->where('owner_id', 1)->pluck('name')->all());
    }

    public function testRawDeleteRefreshesCachedReadsOfCascadedChildren(): void
    {
        $this->assertSame(2, DB::table('pets')->count());

        DB::delete('delete from owners where id = ?', [1]);

        $this->assertSame(1, DB::table('pets')->count());
    }

    public function testLargeTransactionsInvalidateWithABoundedScript(): void
    {
        $this->assertNull(DB::table('pets')->where('id', 100)->value('name'));

        $commands = $this->recordRedisCommandEvents(fn () => DB::transaction(function (): void {
            foreach (range(100, 100 + Invalidation::MAX_TAGS) as $id) {
                DB::table('pets')->insert(['id' => $id, 'owner_id' => 1, 'name' => "pet {$id}"]);
            }
        }));

        $this->assertSame('pet 100', DB::table('pets')->where('id', 100)->value('name'));
        $largestCommand = max(array_map(static fn (CommandExecuted $command): int => count($command->parameters, COUNT_RECURSIVE), $commands));
        $this->assertLessThan(100, $largestCommand);
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

    public function testMySqlTriggersWithEscapedQuotesAreReadAsCreated(): void
    {
        $this->requireDatabaseDriver('mysql', 'mariadb');
        DB::unprepared("create trigger drivers_record_rename after update on drivers for each row insert into materials (name) values (concat('it''s ', 'a \\'quoted\\' name'))");
        $this->loadDependencyMap();
        DB::table('drivers')->insert(['id' => 1, 'name' => 'alice']);
        $materials = fn (): int => DB::table('materials')->count();
        $this->assertSame(0, $materials());
        DB::table('pets')->get();
        [$petsKey, $petsTags] = $this->cacheKeyAndTags(DB::table('pets'));
        Log::spy();

        DB::table('drivers')->where('id', 1)->update(['name' => 'renamed']);

        $this->assertSame(1, $materials());
        $this->assertSame(['materials'], DependentTables::load(DB::connection())['gu:drivers']);
        $this->assertCacheHas($petsKey, $petsTags, 'Reading the trigger must not retire the cache');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function testMySqlTriggersAreReadInTheSqlModeTheyWereCreatedIn(): void
    {
        $this->requireDatabaseDriver('mysql', 'mariadb');
        $sqlMode = DB::scalar('select @@session.sql_mode');
        DB::statement("set session sql_mode = 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES'");
        DB::unprepared('create trigger drivers_record_rename after update on "drivers" for each row insert into "materials" (name) values (\'ends in a backslash \\\')');
        DB::statement('set session sql_mode = ?', [$sqlMode]);

        $this->assertSame(['materials'], DependentTables::load(DB::connection())['gu:drivers']);
    }

    public function testWritesFiringATriggerThatCannotBeReadRetireTheCacheWithoutReadingTheSchemaAgain(): void
    {
        $this->requireDatabaseDriver('sqlite');
        DB::unprepared('create trigger drivers_replace_material after update on drivers begin update or replace materials set name = new.name; end');
        Log::spy();
        $schemaReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$schemaReads): void {
            $schemaReads += (int) str_contains($query->sql, 'sqlite_master');
        });

        DB::table('drivers')->update(['name' => 'loads the dependency map']);
        $schemaReadsOfOneLoad = $schemaReads;
        $generation = $this->generationId();
        $this->assertSame(2, DB::table('pets')->count());
        DB::connection()->getPdo()->exec("insert into pets (id, owner_id, name) values (3, 1, 'written by the trigger')");

        DB::table('drivers')->update(['name' => 'fires the trigger']);

        $this->assertSame(3, DB::table('pets')->count());
        $this->assertNotSame($generation, $this->generationId());
        $this->assertGreaterThan(0, $schemaReadsOfOneLoad);
        $this->assertSame($schemaReadsOfOneLoad, $schemaReads);
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'every write firing it retires the whole cache')
            && $context['trigger'] === 'drivers_replace_material');
        Log::shouldNotHaveReceived('error');
    }

    public function testWritesNotFiringATriggerThatCannotBeReadKeepTheCache(): void
    {
        $this->requireDatabaseDriver('sqlite');
        DB::unprepared('create trigger drivers_replace_material after update on drivers begin update or replace materials set name = new.name; end');
        DB::table('owners')->where('id', 1)->update(['name' => 'loads the dependency map']);
        $this->assertSame(['*'], DependentTables::load(DB::connection())['gu:drivers']);
        DB::table('pets')->get();
        [$petsKey, $petsTags] = $this->cacheKeyAndTags(DB::table('pets'));
        $generation = $this->generationId();

        DB::table('drivers')->insert(['id' => 1, 'name' => 'alice']);
        DB::table('owner_audits')->where('id', 1)->update(['event' => 'renamed']);

        $this->assertSame($generation, $this->generationId());
        $this->assertCacheHas($petsKey, $petsTags);
    }

    public function testReadsCachedWhileTheSchemaWasUnreadableAreRefreshedByRowPreciseCascades(): void
    {
        $cache = app('lada.cache');
        $database = DB::connection()->getDatabaseName();
        $generation = new Generation($this->generationId());
        $cache->storeUnreadableDependents($generation, $database);
        $petsOfAlice = fn (): int => DB::table('pets')->where('owner_id', 1)->count();
        $this->assertSame(1, $petsOfAlice());
        $cache->storeDependents($generation, $database, DependentTables::load(DB::connection()));

        DB::table('owners')->where('id', 1)->delete();

        $this->assertSame(0, $petsOfAlice());
    }

    public function testWritesToAPostgresPartitionRefreshCachedReadsOfItsParent(): void
    {
        $this->createPartitionedReadings();
        $readings = fn (): int => DB::table('readings')->count();
        $this->assertSame(0, $readings());

        DB::table('readings_oslo')->insert(['id' => 1, 'city' => 'oslo', 'value' => 3]);
        $this->assertSame(1, $readings());

        DB::table('readings_oslo')->where('id', 1)->update(['value' => 9]);
        $this->assertSame(9, DB::table('readings')->where('id', 1)->value('value'));

        DB::table('readings_oslo')->where('id', 1)->delete();
        $this->assertSame(0, $readings());
    }

    public function testWritesToAPostgresPartitionedTableRefreshCachedReadsOfItsPartitions(): void
    {
        $this->createPartitionedReadings();
        $oslo = fn (): int => DB::table('readings_oslo')->count();
        $this->assertSame(0, $oslo());

        DB::table('readings')->insert(['id' => 1, 'city' => 'oslo', 'value' => 3]);
        $this->assertSame(1, $oslo());

        DB::table('readings')->where('id', 1)->update(['city' => 'rome']);
        $this->assertSame(0, $oslo());
        $this->assertSame(1, DB::table('readings_rome')->count());

        DB::table('readings')->where('id', 1)->delete();
        $this->assertSame(0, DB::table('readings_rome')->count());
    }

    public function testWritesToAPostgresPartitionKeepCachedReadsOfItsSiblings(): void
    {
        $this->createPartitionedReadings();
        DB::table('readings_rome')->get();
        [$romeKey, $romeTags] = $this->cacheKeyAndTags(DB::table('readings_rome'));

        DB::table('readings_oslo')->insert(['id' => 1, 'city' => 'oslo', 'value' => 3]);

        $this->assertCacheHas($romeKey, $romeTags);
    }

    public function testWritesToAPostgresTableRefreshCachedReadsOfTheTablesItInheritsFromAndBack(): void
    {
        $this->requireDatabaseDriver('pgsql');
        DB::unprepared('create table places (id bigint primary key, name text not null); create table capitals (country text) inherits (places)');
        $this->loadDependencyMap();
        $places = fn (): int => DB::table('places')->count();
        $this->assertSame(0, $places());

        DB::table('capitals')->insert(['id' => 1, 'name' => 'oslo', 'country' => 'norway']);
        $this->assertSame(1, $places());
        $this->assertSame(1, DB::table('capitals')->count());

        DB::table('places')->where('id', 1)->delete();
        $this->assertSame(0, DB::table('capitals')->count());
    }

    private function createPartitionedReadings(): void
    {
        $this->requireDatabaseDriver('pgsql');
        DB::unprepared(
            'create table readings (id bigint not null, city text not null, value int not null, primary key (id, city)) partition by list (city);'
            ." create table readings_oslo partition of readings for values in ('oslo');"
            ." create table readings_rome partition of readings for values in ('rome')"
        );
        $this->loadDependencyMap();
    }

    private function generationId(): string
    {
        $redis = new Redis;

        return (string) $redis->get($redis->prefix('generation'));
    }
}
