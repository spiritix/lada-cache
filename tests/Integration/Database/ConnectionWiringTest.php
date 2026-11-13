<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use PDO;
use Spiritix\LadaCache\Database\QueryBuilder as LadaQueryBuilder;
use Spiritix\LadaCache\Tests\TestCase;

class ConnectionWiringTest extends TestCase
{
    public function testDbTableReturnsLadaQueryBuilder(): void
    {
        $builder = DB::table('cars');
        $this->assertInstanceOf(LadaQueryBuilder::class, $builder);
    }

    public function testQueryGrammarAndProcessorAreUsable(): void
    {
        $connection = DB::connection();

        $grammar = $connection->getQueryGrammar();
        $this->assertNotNull($grammar);

        $processor = $connection->getPostProcessor();
        $this->assertNotNull($processor);

        // Smoke test: compile a simple select to ensure grammar is initialized.
        $sql = $grammar->compileSelect(DB::table('cars'));
        $this->assertIsString($sql);
        $this->assertNotSame('', $sql);
    }

    public function testSchemaGrammarIsMirroredAndUsable(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $this->assertNotNull($schema, 'Expected schema builder to be initialized');

        // Smoke test: ensure hasTable() call does not throw due to null grammar
        $this->assertIsBool($schema->hasTable('cars'));
    }

    public function testResolvingAConnectionDoesNotConnect(): void
    {
        config(['database.connections.lazy' => [
            'driver' => 'sqlite',
            'database' => sys_get_temp_dir().'/lada-cache-missing-'.bin2hex(random_bytes(8)).'.sqlite',
            'prefix' => '',
        ]]);

        $connection = DB::connection('lazy');

        $this->assertSame('lazy', $connection->getName());
        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function testReadConnectionIsOpenedOnFirstRead(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'lada-cache-');
        config(['database.connections.split' => [
            'driver' => 'sqlite',
            'read' => ['database' => $database],
            'write' => ['database' => $database],
            'prefix' => '',
        ]]);

        try {
            $connection = DB::connection('split');
            $this->assertInstanceOf(Closure::class, $connection->getRawReadPdo());

            $connection->statement('create table things (id integer primary key)');
            $connection->table('things')->insert(['id' => 1]);

            $this->assertSame(1, $connection->table('things')->count());
            $this->assertNotSame($connection->getPdo(), $connection->getReadPdo());
        } finally {
            DB::purge('split');
            unlink($database);
        }
    }

    public function testReadsAndWritesShareTheLazilyOpenedConnection(): void
    {
        DB::purge();
        $connection = DB::connection();
        $connection->statement('create table things (id integer primary key)');
        $connection->table('things')->insert(['id' => 1]);

        $this->assertSame(1, $connection->table('things')->count());
        $this->assertSame($connection->getPdo(), $connection->getReadPdo());
    }

    public function testQueryGrammarEscapesThroughTheLadaConnection(): void
    {
        DB::purge();
        $connection = DB::connection();

        $connection->getQueryGrammar()->escape('value');

        $this->assertInstanceOf(PDO::class, $connection->getRawPdo());
    }

    public function testSchemaGrammarEscapesThroughTheLadaConnection(): void
    {
        DB::purge();
        $connection = DB::connection();

        $connection->getSchemaBuilder();
        $connection->getSchemaGrammar()->escape('value');

        $this->assertInstanceOf(PDO::class, $connection->getRawPdo());
    }
}
