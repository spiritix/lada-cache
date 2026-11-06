<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Concerns\RecordsRedisCommands;
use Spiritix\LadaCache\Tests\TestCase;

final class RawStatementInvalidationTest extends TestCase
{
    use CacheAssertions;
    use RecordsRedisCommands;

    public function testRawUpdateRefreshesCachedReads(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame('before', DB::table('cars')->where('id', 1)->value('name'));

        DB::update('update cars set name = ? where id = ?', ['after', 1]);

        $this->assertSame('after', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testRawInsertRefreshesCachedMissingRow(): void
    {
        $this->assertNull(DB::table('cars')->where('id', 1)->first());

        DB::insert('insert into cars (id, name) values (?, ?)', [1, 'raw']);

        $this->assertSame('raw', DB::table('cars')->where('id', 1)->first()?->name);
    }

    public function testRawDeleteRefreshesCachedCounts(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'gone', 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame(1, DB::table('cars')->count());

        DB::delete('delete from cars where id = ?', [1]);

        $this->assertSame(0, DB::table('cars')->count());
    }

    public function testInsertReturningThroughSelectRefreshesCachedReads(): void
    {
        $this->requireDatabaseDriver('sqlite');
        $this->assertSame(0, DB::table('cars')->count());

        DB::select("insert into cars (name) values ('returned') returning id");

        $this->assertSame(1, DB::table('cars')->count());
    }

    public function testRawWriteKeepsUnrelatedTablesCached(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'driver']);
        $builder = DB::table('drivers')->where('id', 1);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::update('update cars set name = ?', ['other table']);

        $this->assertCacheHas($key, $tags);
    }

    public function testRawSchemaChangeRetiresTheWholeCache(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'driver']);
        $builder = DB::table('drivers')->where('id', 1);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $generation = $this->currentGeneration();

        DB::statement('create table colors (id integer primary key)');

        $this->assertNotSame($generation, $this->currentGeneration());
        $this->assertCacheMissing($key, $tags);
    }

    public function testMultipleUnpreparedStatementsRetireTheWholeCache(): void
    {
        $generation = $this->currentGeneration();

        DB::unprepared('create table colors (id integer primary key); insert into colors (id) values (1);');

        $this->assertNotSame($generation, $this->currentGeneration());
    }

    public function testRawReadsAndSessionStatementsKeepTheCache(): void
    {
        DB::table('drivers')->insert(['id' => 1, 'name' => 'driver']);
        $builder = DB::table('drivers')->where('id', 1);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);
        $generation = $this->currentGeneration();

        DB::select('select * from drivers');
        DB::statement(match (self::databaseDriver()) {
            'sqlite' => 'PRAGMA foreign_keys = ON',
            'pgsql' => "SET application_name = 'lada'",
            default => 'SET @lada_probe = 1',
        });
        DB::statement('create temporary table report_rows (id integer)');

        $this->assertSame($generation, $this->currentGeneration());
        $this->assertCacheHas($key, $tags);
    }

    public function testRawWriteInsideATransactionIsInvalidatedAtCommit(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 1);
        $builder->get();
        [$key, $tags] = $this->cacheKeyAndTags($builder);

        DB::beginTransaction();
        DB::update('update cars set name = ? where id = ?', ['after', 1]);
        $this->assertCacheHas($key, $tags);
        DB::commit();

        $this->assertCacheMissing($key, $tags);
    }

    public function testBuilderWritesInvalidateExactlyOnce(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->value('name');
        DB::table('drivers')->update(['name' => 'warm up foreign keys']);

        $commands = $this->recordRedisCommands(fn () => DB::table('cars')->where('id', 1)->update(['name' => 'after']));

        $this->assertSame(['evalsha'], $commands);
    }

    public function testWithoutCacheWritesStayUninvalidated(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'before', 'engine_id' => null, 'driver_id' => null]);
        DB::table('cars')->where('id', 1)->value('name');

        $commands = $this->recordRedisCommands(fn () => DB::table('cars')->withoutCache()->where('id', 1)->update(['name' => 'after']));

        $this->assertSame([], $commands);
    }

    private function currentGeneration(): string
    {
        $redis = new Redis();

        return (string) $redis->get($redis->prefix('generation'));
    }
}
