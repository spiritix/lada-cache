<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\RowKeys;

/**
 * Generates cache tags for queries based on database, tables, and targeted rows.
 *
 * A read pinned to rows carries `table_specific` and one tag per row; any other read carries
 * `table_unspecific`. A write bumps `table_unspecific` and the tags of the rows it pins or
 * assigns, or both table tags when it cannot name its rows. With `consider_rows` disabled
 * every query only uses `table_unspecific`.
 */
final readonly class Tagger
{
    private const string PREFIX_DATABASE = 'tags:database:';

    private const string PREFIX_TABLE_SPECIFIC = ':table_specific:';

    private const string PREFIX_TABLE_UNSPECIFIC = ':table_unspecific:';

    private const string PREFIX_ROW = ':row:';

    private bool $considerRows;

    public function __construct(
        private Reflector $reflector,
        private AssignedRows $assignedRows = new AssignedRows,
    ) {
        $this->considerRows = (bool) config('lada-cache.consider_rows', true);
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        $tables = array_values(array_filter($this->reflector->getTables(), 'is_string'));

        if (! $this->considerRows) {
            return array_values(array_unique(array_map(function (string $table): string {
                [$database, $name] = $this->locate($table);

                return self::PREFIX_DATABASE.$database.self::PREFIX_TABLE_UNSPECIFIC.$name;
            }, $tables)));
        }

        $pinnedRows = $this->reflector->getRows();
        $type = $this->reflector->getType();
        $tags = [];

        foreach ($tables as $table) {
            [$database, $name] = $this->locate($table);
            $stringKeys = $this->reflector->usesStringKeys($table);
            $pinned = RowKeys::canonical($pinnedRows[$table] ?? [], $stringKeys);
            $assigned = $this->assignedRows->complete ? RowKeys::canonical($this->assignedRows->forTable($table), $stringKeys) : null;

            foreach ($this->tableTags($type, $name, $pinned, $assigned) as $tag) {
                $tags[self::PREFIX_DATABASE.$database.$tag] = true;
            }
        }

        return array_map('strval', array_keys($tags));
    }

    /**
     * Splits a qualified table name into the database and table it tags. MySQL qualifiers name
     * databases, so `reports.sales` shares tags with `sales` written on a connection to
     * `reports`; elsewhere they name schemas within the connection's database.
     *
     * @return array{string, string}
     */
    private function locate(string $table): array
    {
        $segments = explode('.', $table);
        $name = (string) array_pop($segments);

        if ($segments === [] || ! $this->reflector->qualifiersNameDatabases()) {
            return [$this->reflector->getDatabase(), $name];
        }

        return [(string) end($segments), $name];
    }

    /**
     * Prefixes that, followed by a table name, form every tag a read of that table can carry
     * at table level, so appending a table name invalidates all its cached reads.
     *
     * @return list<string>
     */
    public static function tableWideTagPrefixes(string $database): array
    {
        $databaseTag = self::PREFIX_DATABASE.$database;

        if (! (bool) config('lada-cache.consider_rows', true)) {
            return [$databaseTag.self::PREFIX_TABLE_UNSPECIFIC];
        }

        return [$databaseTag.self::PREFIX_TABLE_SPECIFIC, $databaseTag.self::PREFIX_TABLE_UNSPECIFIC];
    }

    /**
     * @param  list<string>|null  $pinned  rows the query's conditions restrict it to; null when unrepresentable
     * @param  list<string>|null  $assigned  rows the write assigns keys to; null when unknown or unrepresentable
     * @return list<string>
     */
    private function tableTags(string $type, string $table, ?array $pinned, ?array $assigned): array
    {
        $specific = self::PREFIX_TABLE_SPECIFIC.$table;
        $unspecific = self::PREFIX_TABLE_UNSPECIFIC.$table;
        $isPinned = $pinned !== null && $pinned !== [];

        return match ($type) {
            Reflector::QUERY_TYPE_SELECT => $isPinned ? [$specific, ...$this->rowTags($table, $pinned)] : [$unspecific],
            Reflector::QUERY_TYPE_UPDATE, Reflector::QUERY_TYPE_DELETE => $isPinned && $assigned !== null
                ? [$unspecific, ...$this->rowTags($table, [...$pinned, ...$assigned])]
                : [$specific, $unspecific],
            Reflector::QUERY_TYPE_INSERT => $assigned !== null
                ? [$unspecific, ...$this->rowTags($table, $assigned)]
                : [$specific, $unspecific],
            default => [$specific, $unspecific],
        };
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function rowTags(string $table, array $keys): array
    {
        return array_map(static fn (string $key): string => self::PREFIX_TABLE_SPECIFIC.$table.self::PREFIX_ROW.$key, $keys);
    }
}
