<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

final class CrossDatabaseTest extends TestCase
{
    private const string OTHER_DATABASE = 'lada_other';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireDatabaseDriver('mysql');
        DB::statement('create database if not exists '.self::OTHER_DATABASE);
        DB::statement('drop table if exists '.self::OTHER_DATABASE.'.items');
        DB::statement('create table '.self::OTHER_DATABASE.'.items (id int primary key, name varchar(20))');
        DB::statement('insert into '.self::OTHER_DATABASE.".items values (1, 'before')");

        $default = (string) config('database.default');
        config(['database.connections.other_database' => [...config("database.connections.{$default}"), 'database' => self::OTHER_DATABASE]]);
    }

    public function testQualifiedReadsRefreshAfterWritesOnAConnectionToThatDatabase(): void
    {
        $name = fn (): ?string => DB::table(self::OTHER_DATABASE.'.items')->where('id', 1)->value('name');
        $this->assertSame('before', $name());

        DB::connection('other_database')->table('items')->where('id', 1)->update(['name' => 'after']);

        $this->assertSame('after', $name());
    }

    public function testReadsOnAConnectionRefreshAfterQualifiedWritesFromAnother(): void
    {
        $name = fn (): ?string => DB::connection('other_database')->table('items')->where('id', 1)->value('name');
        $this->assertSame('before', $name());

        DB::table(self::OTHER_DATABASE.'.items')->where('id', 1)->update(['name' => 'after']);

        $this->assertSame('after', $name());
    }
}
