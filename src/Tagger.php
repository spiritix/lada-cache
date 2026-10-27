<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

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

    /** @var array<string, array<int, int|string>> */
    private array $extraRows;

    /**
     * @param  array<string, array<int, int|string>>  $extraRows  Row ids the query affects but that cannot be
     *                                                            derived from its WHERE clause
     */
    public function __construct(Reflector $reflector, array $extraRows = [])
    {
        $this->reflector = $reflector;
        $this->considerRows = (bool) config('lada-cache.consider_rows', true);
        $this->extraRows = $extraRows;
    }

    public function getTags(): array
    {
        $databaseTag = $this->prefix($this->reflector->getDatabase(), self::PREFIX_DATABASE);
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

        if (! $this->considerRows) {
            return $this->prefix($tables, $databaseTag);
        }

        /** @var array<string, array<int, scalar>> $rows */
        $rows = $this->reflector->getRows();
        $tags = $this->getTableTags($tables, $rows);

        foreach ($tables as $table) {
            $tableRows = array_merge($rows[$table] ?? [], $this->extraRows[$table] ?? []);

            if ($tableRows === []) {
                continue;
            }

            $tablePrefix = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
            $rowPrefix = $this->prefix(self::PREFIX_ROW, $tablePrefix);

            $tags = array_merge($tags, $this->prefix(array_unique($tableRows), $rowPrefix));
        }

        return $this->prefix($tags, $databaseTag);
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
