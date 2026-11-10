<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * What a write did to the rows of its tables, which decides what else the database changed along
 * with them: every write changes the views over the table and what its triggers write, a delete
 * fires the delete rules of foreign keys referencing the table, and an update fires their update
 * rules only when it assigns a referenced column. A write matching no rows fires no foreign key.
 *
 * Each dependents field names, for one table, everything one kind of write to it changes; a write
 * looks up the fields that together cover it.
 */
final readonly class Write
{
    /**
     * @param  list<string>|null  $updatedColumns  null when any column may have been assigned
     */
    private function __construct(
        private bool $deletesRows,
        private ?array $updatedColumns,
    ) {}

    public static function insert(): self
    {
        return new self(false, []);
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

        return new self(false, array_map('strval', array_keys($names)));
    }

    public static function delete(): self
    {
        return new self(true, []);
    }

    /**
     * A write whose rows and columns are not known, such as a raw statement or a truncate.
     */
    public static function unknown(): self
    {
        return new self(true, null);
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
        if ($this->deletesRows) {
            return $this->updatedColumns === null ? [self::deleteField($table), self::anyUpdateField($table)] : [self::deleteField($table)];
        }

        if ($this->updatedColumns === null) {
            return [self::anyUpdateField($table)];
        }

        return [self::everyWriteField($table), ...array_map(static fn (string $column): string => self::columnUpdateField($table, $column), $this->updatedColumns)];
    }

    public static function everyWriteField(string $table): string
    {
        return 'a:'.$table;
    }

    public static function deleteField(string $table): string
    {
        return 'd:'.$table;
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
