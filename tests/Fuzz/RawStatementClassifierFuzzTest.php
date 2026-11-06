<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Spiritix\LadaCache\Support\RawStatementClassifier;
use Spiritix\LadaCache\Tests\Fuzz\Support\Workload;

/**
 * Generates writes to a known table, decorated the ways hand-written SQL varies, and checks
 * the classifier never reports them as side-effect free or as a write to another table.
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
            $sql = $this->decorate($this->write($prefix.$table));
            $effect = RawStatementClassifier::classify($sql, $prefix);

            $this->assertFalse($effect->isNone(), "Write classified as side-effect free (seed {$seed}): {$sql}");
            $this->assertTrue(
                $effect->changesEverything || $effect->tables === [$table],
                sprintf('Write to %s attributed to [%s] (seed %d): %s', $table, implode(', ', $effect->tables), $seed, $sql),
            );
        }
    }

    private function write(string $table): string
    {
        $name = $this->identifier($table);
        $other = $this->identifier($this->pick(self::TABLES));

        return $this->pick([
            "insert into {$name} (id, name) values (1, 'a')",
            "INSERT IGNORE INTO {$name} VALUES (1)",
            "insert or replace into {$name} (id) values (?)",
            "insert into {$name} select * from {$other}",
            "insert into {$name}(id) values (1) on conflict (id) do update set id = excluded.id",
            "insert into {$name} (id) values (1) on duplicate key update id = values(id)",
            "replace into {$name} values (1)",
            "update {$name} set name = 'a' where id = 1",
            "update {$name} t set name = ? where t.id in (select id from {$other})",
            "update {$name} as t set t.name = 'x;y'",
            "UPDATE LOW_PRIORITY {$name} SET name = name",
            "update {$name} set name = 'delete from {$other}'",
            "update {$name} join {$other} on 1 = 1 set name = 'a'",
            "update {$name}, {$other} set name = 'a'",
            "update {$name} set name = 1 returning id",
            "delete from {$name}",
            "delete from {$name} where id = ? returning *",
            "delete from {$name} t where t.id = 1",
            "DELETE QUICK FROM {$name} WHERE id > 5 ORDER BY id LIMIT 10",
            "delete {$name} from {$name} join {$other} on 1 = 1",
            "delete from {$name} using {$other} where 1 = 1",
            "truncate {$name}",
            "truncate table {$name}",
            "truncate {$name} restart identity",
            "with ids as (select id from {$other}) delete from {$name} where id in (select id from ids)",
            "with moved as (delete from {$name} returning *) select * from moved",
            "merge into {$name} using {$other} on 1 = 1 when matched then delete",
            "explain analyze update {$name} set name = 'a'",
            "alter table {$name} add column c int",
            "drop table {$name}",
            "create index i on {$name} (name)",
            "update {$name} set name = 'a'; select 1",
            "select 1; delete from {$name}",
        ]);
    }

    private function identifier(string $table): string
    {
        return $this->pick([$table, "`{$table}`", "\"{$table}\"", "[{$table}]", "db.{$table}", "\"public\".\"{$table}\""]);
    }

    private function decorate(string $sql): string
    {
        $sql = $this->random->getInt(0, 1) === 1 ? $this->randomCase($sql) : $sql;
        $sql = (string) preg_replace_callback('/ +/', fn (): string => $this->pick([' ', '  ', "\n", "\t", " \n  "]), $sql);
        $leading = $this->pick(['', "-- note\n", "/* job */ ", "# mysql comment\n", "/* a */ -- b\n", "\n  "]);
        $trailing = $this->pick(['', ';', ' ;', "\n", ' -- done']);

        return $leading.$sql.$trailing;
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
