<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Spiritix\LadaCache\Support\RawStatementClassifier;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Tests\Fuzz\Support\Workload;

/**
 * Generates writes to a known table, decorated the ways hand-written SQL varies, and checks
 * the classifier, on every dialect, never reports them as side-effect free and never leaves the
 * written table out of the tables it reports.
 */
final class RawStatementClassifierFuzzTest extends TestCase
{
    private const int DEFAULT_ITERATIONS = 5000;

    private const array TABLES = ['cars', 'car_parts', 'Drivers', 'x1'];

    private Randomizer $random;

    public function testWritesAreNeverMissedOrAttributedToAnotherTable(): void
    {
        $seed = Workload::seedFromEnvironment();
        $this->random = new Randomizer(new Mt19937($seed));
        $iterations = Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $table = $this->pick(self::TABLES);
            $prefix = $this->pick(['', 'app_']);
            [$sql, $drivers] = $this->decorate($this->write($prefix.$table));

            if ($drivers === []) {
                continue;
            }

            $driver = $this->pick($drivers);
            $effect = RawStatementClassifier::classify($sql, $prefix, Dialects::forDriver($driver));

            $this->assertFalse($effect->isNone(), "Write classified as side-effect free on {$driver} (seed {$seed}): {$sql}");
            $this->assertTrue(
                $effect->changesEverything || (in_array($table, $effect->tables, true) && array_diff($effect->tables, self::TABLES) === []),
                sprintf('Write to %s attributed to [%s] on %s (seed %d): %s', $table, implode(', ', $effect->tables), $driver, $seed, $sql),
            );
        }
    }

    /**
     * A write and the databases whose syntax it is.
     *
     * @return array{string, list<string>}
     */
    private function write(string $table): array
    {
        [$name, $nameDrivers] = $this->identifier($table);
        [$other, $otherDrivers] = $this->identifier($this->pick(self::TABLES));
        $all = ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];

        [$sql, $drivers] = $this->pick([
            ["insert into {$name} (id, name) values (1, 'a')", $all],
            ["INSERT IGNORE INTO {$name} VALUES (1)", ['mysql']],
            ["insert or replace into {$name} (id) values (?)", ['sqlite']],
            ["insert into {$name} select * from {$other}", $all],
            ["insert into {$name}(id) values (1) on conflict (id) do update set id = excluded.id", ['pgsql', 'sqlite']],
            ["insert into {$name} (id) values (1) on duplicate key update id = values(id)", ['mysql']],
            ["replace into {$name} values (1)", ['mysql', 'sqlite']],
            ["update {$name} set name = 'a' where id = 1", $all],
            ["update {$name} t set name = ? where t.id in (select id from {$other})", ['pgsql', 'mysql']],
            ["update {$name} as t set t.name = 'x;y'", ['pgsql', 'mysql', 'sqlite']],
            ["UPDATE LOW_PRIORITY {$name} SET name = name", ['mysql']],
            ["update {$name} set name = 'delete from {$other}'", $all],
            ["update {$name} join {$other} on 1 = 1 set name = 'a'", ['mysql']],
            ["update {$name}, {$other} set name = 'a'", ['mysql']],
            ["update {$name} set name = 1 returning id", ['pgsql', 'sqlite']],
            ["delete from {$name}", $all],
            ["delete from {$name} where id = ? returning *", ['pgsql', 'sqlite']],
            ["delete from {$name} t where t.id = 1", ['pgsql', 'sqlsrv']],
            ["DELETE QUICK FROM {$name} WHERE id > 5 ORDER BY id LIMIT 10", ['mysql']],
            ["delete {$name} from {$name} join {$other} on 1 = 1", ['mysql', 'sqlsrv']],
            ["delete from {$name} using {$other} where 1 = 1", ['pgsql']],
            ["truncate {$name}", ['mysql', 'pgsql']],
            ["truncate table {$name}", ['mysql', 'pgsql', 'sqlsrv']],
            ["truncate {$name} restart identity", ['pgsql']],
            ["with ids as (select id from {$other}) delete from {$name} where id in (select id from ids)", $all],
            ["with moved as (delete from {$name} returning *) select * from moved", ['pgsql']],
            ["merge into {$name} using {$other} on 1 = 1 when matched then delete", ['pgsql', 'sqlsrv']],
            ["explain analyze update {$name} set name = 'a'", ['pgsql']],
            ["alter table {$name} add column c int", $all],
            ["drop table {$name}", $all],
            ["create index i on {$name} (name)", $all],
            ["update {$name} set name = 'a'; select 1", $all],
            ["select 1; delete from {$name}", $all],
        ]);

        return [$sql, array_values(array_intersect($drivers, $nameDrivers, $otherDrivers))];
    }

    /**
     * @return array{string, list<string>}
     */
    private function identifier(string $table): array
    {
        return $this->pick([
            [$table, ['mysql', 'pgsql', 'sqlite', 'sqlsrv']],
            ["`{$table}`", ['mysql', 'sqlite']],
            ["\"{$table}\"", ['pgsql', 'sqlite', 'sqlsrv']],
            ["[{$table}]", ['sqlsrv', 'sqlite']],
            ["db.{$table}", ['mysql', 'pgsql', 'sqlite', 'sqlsrv']],
            ["\"public\".\"{$table}\"", ['pgsql', 'sqlite', 'sqlsrv']],
        ]);
    }

    /**
     * @param  array{string, list<string>}  $write
     * @return array{string, list<string>}
     */
    private function decorate(array $write): array
    {
        [$sql, $drivers] = $write;
        $sql = $this->random->getInt(0, 1) === 1 ? $this->randomCase($sql) : $sql;
        $sql = (string) preg_replace_callback('/ +/', fn (): string => $this->pick([' ', '  ', "\n", "\t", " \n  "]), $sql);
        [$leading, $leadingDrivers] = $this->pick([
            ['', $drivers],
            ["-- note\n", $drivers],
            ['/* job */ ', $drivers],
            ["# mysql comment\n", ['mysql']],
            ["/* a */ -- b\n", $drivers],
            ["\n  ", $drivers],
        ]);
        $trailing = $this->pick(['', ';', ' ;', "\n", ' -- done']);

        return [$leading.$sql.$trailing, array_values(array_intersect($drivers, $leadingDrivers))];
    }

    private function randomCase(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\b(insert|into|update|delete|from|set|where|truncate|table|replace|ignore|with|merge|using|values|returning|join|explain|analyze)\b/i',
            fn (array $keyword): string => $this->pick([strtoupper($keyword[0]), strtolower($keyword[0]), ucfirst(strtolower($keyword[0]))]),
            $sql,
        );
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return TValue
     */
    private function pick(array $values): mixed
    {
        return $values[$this->random->getInt(0, count($values) - 1)];
    }
}
