<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * The tables a parsed write statement changes rows of. Multi-table forms name their targets by
 * alias or table name among the relations they join; a target that resolves to no named table,
 * such as a derived table, cannot be known and throws.
 */
final class WriteTargets
{
    /**
     * @return non-empty-list<list<string>>
     */
    public static function of(InsertStatement|UpdateStatement|DeleteStatement|TruncateStatement|MergeStatement $statement): array
    {
        return match (true) {
            $statement instanceof InsertStatement => [$statement->table],
            $statement instanceof MergeStatement => [$statement->target],
            $statement instanceof TruncateStatement => $statement->tables,
            $statement instanceof UpdateStatement => self::ofUpdate($statement),
            $statement instanceof DeleteStatement => self::ofDelete($statement),
        };
    }

    /**
     * The columns an update or upsert assigns, without their qualifiers; null when it assigns
     * none of existing rows.
     *
     * @return list<string>|null
     */
    public static function assignedColumns(InsertStatement|UpdateStatement $statement): ?array
    {
        $assignments = $statement instanceof InsertStatement ? $statement->conflictAssignments : $statement->assignments;

        if ($assignments === null) {
            return null;
        }

        $columns = [];
        foreach ($assignments as $assignment) {
            foreach ($assignment->targets as $target) {
                $columns[] = $target[array_key_last($target)];
            }
        }

        return $columns;
    }

    /**
     * @return non-empty-list<list<string>>
     */
    private static function ofUpdate(UpdateStatement $statement): array
    {
        if (count($statement->tables) === 1 && $statement->tables[0]->name !== null) {
            return [$statement->tables[0]->name];
        }

        $targets = [];
        foreach ($statement->assignments as $assignment) {
            foreach ($assignment->targets as $target) {
                $resolved = count($target) === 1
                    ? self::namedTables($statement->tables)
                    : self::resolve(array_slice($target, 0, -1), $statement->tables);
                foreach ($resolved as $name) {
                    $targets[implode("\0", $name)] = $name;
                }
            }
        }

        return self::nonEmpty(array_values($targets), 'UPDATE');
    }

    /**
     * MySQL's `DELETE a, b FROM ...` deletes the listed targets and `DELETE FROM a USING ...` the
     * relations after FROM, both resolved among the joined relations; otherwise the relations
     * after FROM are the targets.
     *
     * @return non-empty-list<list<string>>
     */
    private static function ofDelete(DeleteStatement $statement): array
    {
        if ($statement->targets !== []) {
            $targets = [];
            foreach ($statement->targets as $target) {
                array_push($targets, ...self::resolve($target, $statement->from));
            }

            return self::nonEmpty($targets, 'DELETE');
        }

        if ($statement->using !== null) {
            $targets = [];
            foreach ($statement->from as $relation) {
                if ($relation->name === null) {
                    throw new ParserException('DELETE ... USING deletes from a relation that is not a table');
                }
                $resolved = count($relation->name) === 1 ? self::references($statement->using)[strtolower($relation->name[0])] ?? [] : [];
                array_push($targets, ...($resolved === [] ? [$relation->name] : $resolved));
            }

            return self::nonEmpty($targets, 'DELETE');
        }

        return self::nonEmpty(self::namedTables($statement->from, true), 'DELETE');
    }

    /**
     * @param  list<string>  $qualifier
     * @param  list<TableFactor>  $relations
     * @return list<list<string>>
     */
    private static function resolve(array $qualifier, array $relations): array
    {
        if (count($qualifier) !== 1) {
            return [$qualifier];
        }

        return self::references($relations)[strtolower($qualifier[0])]
            ?? throw new ParserException('Cannot tell which table '.json_encode($qualifier[0]).' refers to');
    }

    /**
     * @param  list<TableFactor>  $relations
     * @return array<string, list<list<string>>> lowercase alias or unqualified name => the tables it may refer to
     */
    private static function references(array $relations): array
    {
        $references = [];

        foreach ($relations as $relation) {
            foreach ($relation->tables() as $table) {
                $name = $table->name ?? [];
                foreach (array_filter([$table->alias, $name[array_key_last($name)] ?? null]) as $reference) {
                    $references[strtolower($reference)][implode("\0", $name)] = $name;
                }
            }
        }

        return array_map(array_values(...), $references);
    }

    /**
     * @param  list<TableFactor>  $relations
     * @return list<list<string>>
     */
    private static function namedTables(array $relations, bool $requireAllNamed = false): array
    {
        $names = [];

        foreach ($relations as $relation) {
            $tables = $relation->tables();

            if ($requireAllNamed && $tables === []) {
                throw new ParserException('The statement writes a relation that is not a table');
            }

            foreach ($tables as $table) {
                if ($table->name !== null) {
                    $names[] = $table->name;
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<list<string>>  $targets
     * @return non-empty-list<list<string>>
     */
    private static function nonEmpty(array $targets, string $statement): array
    {
        return $targets !== [] ? $targets : throw new ParserException("Cannot tell which tables the {$statement} writes");
    }
}
