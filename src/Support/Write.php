<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * What a write did to the rows of its tables, which decides what else the database changed along
 * with them: every write changes the views over the table and what its triggers write, a delete
 * fires the delete rules of foreign keys referencing the table, an update fires their update rules
 * only when it assigns a referenced column, and a truncate empties every referencing table, as
 * PostgreSQL's `TRUNCATE ... CASCADE` does whatever the rules say. A write matching no rows fires
 * no foreign key.
 *
 * Each dependents field names, for one table, everything one kind of write to it changes; a write
 * looks up the fields that together cover it.
 */
final readonly class Write
{
    /**
     * @param  list<string>  $columns  the assigned columns of a `WriteKind::ColumnUpdate`, empty otherwise
     */
    private function __construct(
        private WriteKind $kind,
        private array $columns = [],
    ) {}

    public static function insert(): self
    {
        return new self(WriteKind::Insert);
    }

    /**
     * @param  array<int|string>  $columns  assigned columns, optionally qualified or with a JSON path
     */
    public static function update(array $columns): self
    {
        $names = [];
        foreach ($columns as $column) {
            $names[self::columnName((string) $column)] = true;
        }

        return new self(WriteKind::ColumnUpdate, array_map('strval', array_keys($names)));
    }

    public static function updateOfAnyColumn(): self
    {
        return new self(WriteKind::AnyUpdate);
    }

    public static function delete(): self
    {
        return new self(WriteKind::Delete);
    }

    public static function truncate(): self
    {
        return new self(WriteKind::Truncate);
    }

    /**
     * A write whose kind is not known, such as an explicit invalidation of a table.
     */
    public static function unknown(): self
    {
        return new self(WriteKind::Unknown);
    }

    /**
     * Narrows the write to the rows the database reported as affected: one affecting no rows
     * fired no foreign key.
     */
    public function affecting(int $rows): self
    {
        return $rows === 0 ? self::insert() : $this;
    }

    /**
     * @return list<string>
     */
    public function dependentsFields(string $table): array
    {
        return match ($this->kind) {
            WriteKind::Insert => [self::everyWriteField($table)],
            WriteKind::ColumnUpdate => [self::everyWriteField($table), ...array_map(static fn (string $column): string => self::columnUpdateField($table, $column), $this->columns)],
            WriteKind::AnyUpdate => [self::anyUpdateField($table)],
            WriteKind::Delete => [self::deleteField($table)],
            WriteKind::Truncate => [self::truncateField($table)],
            WriteKind::Unknown => [self::truncateField($table), self::anyUpdateField($table)],
        };
    }

    public static function everyWriteField(string $table): string
    {
        return 'a:'.$table;
    }

    public static function deleteField(string $table): string
    {
        return 'd:'.$table;
    }

    public static function truncateField(string $table): string
    {
        return 't:'.$table;
    }

    public static function anyUpdateField(string $table): string
    {
        return 'u:'.$table;
    }

    public static function columnUpdateField(string $table, string $column): string
    {
        return 'u:'.$table.':'.self::columnName($column);
    }

    /**
     * Compared case-insensitively, as MySQL and SQL Server compare column names; on PostgreSQL
     * that can only add a dependent, never miss one.
     */
    private static function columnName(string $column): string
    {
        $withoutPath = explode('->', $column, 2)[0];
        $parts = explode('.', $withoutPath);

        return strtolower(trim((string) end($parts), '`"[] '));
    }
}
