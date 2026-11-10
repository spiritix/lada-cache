<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\CascadeRead;
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

    private const string PREFIX_CASCADE = ':cascade:';

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
        $tables = $this->reflector->getTables();
        $pinnedRows = $this->considerRows ? $this->reflector->getRows() : [];
        $type = $this->reflector->getType();
        $tags = [];

        foreach ($tables as $table) {
            [$database, $name] = $this->locate($table);
            $stringKeys = $this->reflector->usesStringKeys($table);
            $pinned = $this->considerRows ? RowKeys::canonical($pinnedRows[$table] ?? [], $stringKeys) : null;
            $assigned = $this->considerRows && $this->assignedRows->complete ? RowKeys::canonical($this->assignedRows->forTable($table), $stringKeys) : null;

            foreach ($this->tableTags($type, $name, $pinned, $assigned) as $tag) {
                $tags[self::PREFIX_DATABASE.$database.$tag] = true;
            }
        }

        return array_map('strval', array_keys($tags));
    }

    /**
     * The tables of a read as foreign key cascades into them see it, with the values its wheres
     * pin each column to when rows are considered.
     *
     * @return list<CascadeRead>
     */
    public function cascadeReads(): array
    {
        $pins = $this->considerRows ? $this->reflector->getColumnPins() : [];
        $reads = [];

        foreach (array_filter($this->reflector->getTables(), 'is_string') as $table) {
            [$database, $name] = $this->locate($table);
            $columns = [];

            foreach ($pins[$table] ?? [] as $column => $values) {
                $keys = RowKeys::columnValues($values);
                if ($keys !== null) {
                    $columns[$column] = $keys;
                }
            }

            ksort($columns, SORT_STRING);
            $reads[] = new CascadeRead($database, $name, self::cascadeTagPrefix($database).$name.':', $columns);
        }

        return $reads;
    }

    /**
     * Prefix of the tags foreign key cascades into a table replace, followed by the table name
     * and `:any`, `:via:<column>` or `:col:<column>:<key>` (see `LuaScripts::CASCADE_TAGS`).
     */
    public static function cascadeTagPrefix(string $database): string
    {
        return self::PREFIX_DATABASE.$database.self::PREFIX_CASCADE;
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
     * at table level, so appending a table name invalidates all its cached reads. Both are
     * returned whatever `consider_rows` says, so hosts configured differently, such as old and
     * new pods during a deploy, still invalidate each other's reads.
     *
     * @return list<string>
     */
    public static function tableWideTagPrefixes(string $database): array
    {
        $databaseTag = self::PREFIX_DATABASE.$database;

        return [$databaseTag.self::PREFIX_TABLE_SPECIFIC, $databaseTag.self::PREFIX_TABLE_UNSPECIFIC];
    }

    /**
     * Tags a 5.x host's writes must invalidate for a read carrying `$tags` to miss: the tags
     * themselves and, for every table the read is pinned to rows of, the table's unspecific tag.
     * A 5.x insert invalidates only that one, so a row created by a 5.x host would otherwise
     * leave a cached lookup of the missing row in place.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function legacyDependencies(array $tags): array
    {
        $dependencies = [];

        foreach ($tags as $tag) {
            $dependencies[$tag] = true;
            $position = strrpos($tag, self::PREFIX_TABLE_SPECIFIC);

            if ($position === false || str_contains(substr($tag, $position), self::PREFIX_ROW)) {
                continue;
            }

            $dependencies[substr_replace($tag, self::PREFIX_TABLE_UNSPECIFIC, $position, strlen(self::PREFIX_TABLE_SPECIFIC))] = true;
        }

        return array_map('strval', array_keys($dependencies));
    }

    /**
     * Tags invalidating every cached read of the query's tables, a superset of what getTags()
     * invalidates for any write to them.
     *
     * @return list<string>
     */
    public function tableWideTags(): array
    {
        $tags = [];

        foreach (array_filter($this->reflector->getTables(), 'is_string') as $table) {
            [$database, $name] = $this->locate($table);

            foreach (self::tableWideTagPrefixes($database) as $prefix) {
                $tags[$prefix.$name] = true;
            }
        }

        return array_map('strval', array_keys($tags));
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
