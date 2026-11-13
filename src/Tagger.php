<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Spiritix\LadaCache\Support\RowKeys;

/**
 * Generates cache tags for queries based on database, tables, and targeted rows.
 *
 * Purpose:
 * - Build a deterministic set of tags for cache storage and invalidation.
 * - Respect configuration such as `lada-cache.consider_rows` to include row-level tags.
 *
 * Architectural notes:
 * - This class is marked `readonly` as its state is fully initialized during construction.
 */
final readonly class Tagger
{
    private const string PREFIX_DATABASE = 'tags:database:';

    private const string PREFIX_TABLE_SPECIFIC = ':table_specific:';

    private const string PREFIX_TABLE_UNSPECIFIC = ':table_unspecific:';

    private const string PREFIX_ROW = ':row:';

    private Reflector $reflector;

    private bool $considerRows;

    public function __construct(Reflector $reflector)
    {
        $this->reflector = $reflector;
        $this->considerRows = (bool) config('lada-cache.consider_rows', true);
    }

    public function getTags(): array
    {
        // Normalize tables to strings only, ignoring any non-string artifacts
        $rawTables = $this->reflector->getTables();
        $tables = [];
        foreach ($rawTables as $t) {
            if (is_string($t)) {
                $tables[] = $t;
            } elseif (is_scalar($t)) {
                $tables[] = (string) $t;
            }
        }

        // Without row tracking every query is table-wide, using the same tags as with it,
        // so hosts with either setting sharing one Redis still invalidate each other.
        /** @var array<string, array<int, scalar>> $rows */
        $rows = $this->considerRows ? $this->reflector->getRows() : [];
        $tags = [];

        foreach ($tables as $table) {
            [$database, $name] = $this->locate($table);
            // Keys row tags cannot represent safely fall back to table tags.
            $tableRows = RowKeys::canonicalize($rows[$table] ?? [], $this->reflector->usesStringKeys($table)) ?? [];
            $tableTags = $this->getTableTags([$name], [$name => $tableRows]);

            if ($tableRows !== []) {
                $tablePrefix = $this->prefix($name, self::PREFIX_TABLE_SPECIFIC);
                $rowPrefix = $this->prefix(self::PREFIX_ROW, $tablePrefix);
                $tableTags = array_merge($tableTags, $this->prefix($tableRows, $rowPrefix));
            }

            $databaseTag = $this->prefix($database, self::PREFIX_DATABASE);
            $tags = array_merge($tags, $this->prefix($tableTags, $databaseTag));
        }

        return array_values(array_unique($tags));
    }

    /**
     * Splits a possibly qualified table name into the database and the table name it is tagged under.
     *
     * On MySQL and MariaDB the qualifier names a database, so `reports.sales` shares tags with
     * `sales` written on a connection to `reports`. Elsewhere it names a schema within the
     * connection's database; schemas share tags, which may only over-invalidate.
     *
     * @return array{0: string, 1: string}
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

    private function getTableTags(array $tables, array $rows): array
    {
        $tags = [];
        $type = $this->reflector->getType();

        foreach ($tables as $table) {
            if (! is_string($table)) {
                continue;
            }

            $hasSpecificRows = ! empty($rows[$table] ?? []);

            if ($type === Reflector::QUERY_TYPE_SELECT) {
                if ($hasSpecificRows) {
                    // Specific reads: only table_specific (isolation preserved)
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
                } else {
                    // Broad reads: table_unspecific
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
                }
            }

            if (in_array($type, [Reflector::QUERY_TYPE_UPDATE, Reflector::QUERY_TYPE_DELETE], true)) {
                if ($hasSpecificRows) {
                    // Specific mutations: invalidate aggregate queries but preserve row isolation.
                    // Row-level tags are added separately in getTags().
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
                } else {
                    // Broad mutations: invalidate both specific and unspecific caches for the table
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
                }
            }

            if ($type === Reflector::QUERY_TYPE_INSERT) {
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }

            if ($type === Reflector::QUERY_TYPE_TRUNCATE) {
                // Truncate: invalidate all cache entries linked to the table
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }
        }

        return array_unique($tags);
    }

    private function prefix(string|array $value, string $prefix): string|array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $out[] = $prefix.(string) $item;

                    continue;
                }
                if (is_object($item) && method_exists($item, '__toString')) {
                    $out[] = $prefix.(string) $item;

                    continue;
                }
                // Skip unstringable items (e.g., Query Expressions). TableExtractor already normalizes names.
            }

            return $out;
        }

        return $prefix.$value;
    }
}
