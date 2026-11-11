<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * What a write did to the rows of its tables, which decides what else the database changed along
 * with them. Every write changes the views over the table and the configured dependents; it fires
 * the triggers of its event (an update only those of the columns it assigns); and when it changes
 * rows it fires foreign keys: a delete their delete rules, an update their update rules for the
 * referenced columns it assigns, and a truncate empties every referencing table, as PostgreSQL's
 * `TRUNCATE ... CASCADE` does whatever the rules say.
 *
 * Each dependents field names, for one table, everything one of these effects changes; a write
 * looks up the fields that together cover it.
 */
final readonly class Write
{
    /**
     * @param  list<string>  $columns  the assigned columns of a `WriteKind::ColumnUpdate`, empty otherwise
     * @param  bool  $changesRows  false when the database reported no affected rows, which fire no foreign key
     * @param  array{table: string, column: string, keys: ColumnKeys}|null  $deletedKeys  the primary key values a delete is pinned to
     */
    private function __construct(
        private WriteKind $kind,
        private array $columns = [],
        private bool $changesRows = true,
        private ?array $deletedKeys = null,
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
     * A MERGE, which may insert, update and delete rows but never truncates.
     */
    public static function merge(): self
    {
        return new self(WriteKind::Merge);
    }

    /**
     * A write whose kind is not known, such as an explicit invalidation of a table.
     */
    public static function unknown(): self
    {
        return new self(WriteKind::Unknown);
    }

    /**
     * Narrows the write to the rows the database reported as affected: one affecting no rows fires
     * no foreign key, while statement-level triggers of its event still fire.
     */
    public function affecting(int $rows): self
    {
        return $rows === 0 ? new self($this->kind, $this->columns, false) : $this;
    }

    /**
     * Narrows a delete to the rows of the table with these primary key values, so foreign keys
     * referencing that key change only the child rows referencing them. Other writes, and
     * deletes affecting no rows, are returned unchanged.
     */
    public function deletingKeys(string $table, string $column, ColumnKeys $keys): self
    {
        if ($this->kind !== WriteKind::Delete || ! $this->changesRows) {
            return $this;
        }

        return new self($this->kind, $this->columns, true, ['table' => $table, 'column' => self::columnName($column), 'keys' => $keys]);
    }

    /**
     * The row-precise fields of the table, each with the field used instead when the dependency
     * map has none and the keys it is narrowed to.
     *
     * @return list<array{field: string, fallback: string, keys: ColumnKeys}>
     */
    public function keyedDependentsFields(string $table): array
    {
        if ($this->deletedKeys === null || $this->deletedKeys['table'] !== $table) {
            return [];
        }

        return [[
            'field' => self::keyedDeleteField($table, $this->deletedKeys['column']),
            'fallback' => self::deleteField($table),
            'keys' => $this->deletedKeys['keys'],
        ]];
    }

    /**
     * @return list<string>
     */
    public function dependentsFields(string $table): array
    {
        $rowFields = $this->changesRows ? match ($this->kind) {
            WriteKind::Insert => [],
            WriteKind::ColumnUpdate => array_map(static fn (string $column): string => self::columnUpdateField($table, $column), $this->columns),
            WriteKind::AnyUpdate => [self::anyUpdateField($table)],
            WriteKind::Delete => $this->deletedKeys !== null && $this->deletedKeys['table'] === $table ? [] : [self::deleteField($table)],
            WriteKind::Truncate => [self::truncateField($table)],
            WriteKind::Merge => [self::deleteField($table), self::anyUpdateField($table)],
            WriteKind::Unknown => [self::truncateField($table), self::anyUpdateField($table)],
        } : [];

        $triggerFields = match ($this->kind) {
            WriteKind::Insert => [self::triggerField(TriggerEvent::Insert, $table)],
            WriteKind::ColumnUpdate => [
                self::triggerField(TriggerEvent::Update, $table),
                ...array_map(static fn (string $column): string => self::columnTriggerField($table, $column), $this->columns),
            ],
            WriteKind::AnyUpdate => [self::triggerField(TriggerEvent::Update, $table), self::anyColumnTriggerField($table)],
            WriteKind::Delete => [self::triggerField(TriggerEvent::Delete, $table)],
            WriteKind::Truncate => [self::triggerField(TriggerEvent::Truncate, $table), self::triggerField(TriggerEvent::Delete, $table)],
            WriteKind::Merge => [
                self::triggerField(TriggerEvent::Insert, $table),
                self::triggerField(TriggerEvent::Update, $table),
                self::anyColumnTriggerField($table),
                self::triggerField(TriggerEvent::Delete, $table),
            ],
            WriteKind::Unknown => [
                ...array_map(static fn (TriggerEvent $event): string => self::triggerField($event, $table), TriggerEvent::cases()),
                self::anyColumnTriggerField($table),
            ],
        };

        return [self::everyWriteField($table), ...$triggerFields, ...$rowFields];
    }

    /**
     * What a write to any table changes. Every stored dependency map has the field, so a map
     * without dependents exists; it lists `DependentTables::EVERY_TABLE` in the map kept while
     * the schema cannot be read.
     */
    public const string ANY_TABLE_FIELD = '';

    public static function everyWriteField(string $table): string
    {
        return 'a:'.TableName::tagged($table);
    }

    public static function deleteField(string $table): string
    {
        return 'd:'.TableName::tagged($table);
    }

    /**
     * A delete of rows by the referenced column's values: row-precise foreign key edges as
     * `<child>\t<column>\t<ColumnType>` lines, and everything else the delete changes as table
     * lines.
     */
    public static function keyedDeleteField(string $table, string $column): string
    {
        return 'dk:'.TableName::tagged($table).':'.self::columnName($column);
    }

    /**
     * The columns of a table that row-precise foreign key cascades come through, as
     * `<column>\t<ColumnType>` lines.
     */
    public static function cascadeColumnsField(string $table): string
    {
        return 'k:'.TableName::tagged($table);
    }

    public static function truncateField(string $table): string
    {
        return 't:'.TableName::tagged($table);
    }

    public static function anyUpdateField(string $table): string
    {
        return 'u:'.TableName::tagged($table);
    }

    public static function columnUpdateField(string $table, string $column): string
    {
        return 'u:'.TableName::tagged($table).':'.self::columnName($column);
    }

    /**
     * The triggers an event fires whatever columns it assigns; for updates, those without
     * `UPDATE OF` columns.
     */
    public static function triggerField(TriggerEvent $event, string $table): string
    {
        return 'g'.$event->value.':'.TableName::tagged($table);
    }

    /**
     * The `UPDATE OF` triggers an update assigning the column fires.
     */
    public static function columnTriggerField(string $table, string $column): string
    {
        return 'gc:'.TableName::tagged($table).':'.self::columnName($column);
    }

    /**
     * Every `UPDATE OF` trigger, for updates whose columns are not known.
     */
    public static function anyColumnTriggerField(string $table): string
    {
        return 'gc:'.TableName::tagged($table);
    }

    /**
     * Compared case-insensitively, as MySQL and SQL Server compare column names; on PostgreSQL
     * that can only add a dependent, never miss one.
     */
    public static function columnName(string $column): string
    {
        $withoutPath = explode('->', $column, 2)[0];
        $parts = explode('.', $withoutPath);

        return strtolower(trim((string) end($parts), '`"[] '));
    }
}
