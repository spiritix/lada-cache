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

    public function __construct(Reflector $reflector)
    {
        $this->reflector = $reflector;
        $this->considerRows = (bool) config('lada-cache.consider_rows', true);
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
            if (empty($rows[$table])) {
                continue;
            }

            $tablePrefix = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
            $rowPrefix = $this->prefix(self::PREFIX_ROW, $tablePrefix);

            $tags = array_merge($tags, $this->prefix($rows[$table], $rowPrefix));
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
                    // Add both specific and unspecific table tags so that broad invalidations
                    // (e.g., UPDATE/DELETE) catch row-specific cached entries.
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
                } else {
                    $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
                }
            }

            if (in_array($type, [Reflector::QUERY_TYPE_UPDATE, Reflector::QUERY_TYPE_DELETE], true)) {
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }

            if ($type === Reflector::QUERY_TYPE_INSERT) {
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }

            if ($type === Reflector::QUERY_TYPE_TRUNCATE) {
                // Truncate should invalidate all cache entries linked to the table,
                // both specific (row-aware) and unspecific (table-level) tags.
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
                if (is_scalar($item) || is_string($item)) {
                    $out[] = (string) $this->prefix((string) $item, $prefix);

                    continue;
                }
                if (is_object($item) && method_exists($item, '__toString')) {
                    $out[] = (string) $this->prefix((string) $item, $prefix);
                }
                // Otherwise skip unstringable items (e.g., Query Expressions). Reflector should already
                // have provided normalized string table names for tagging purposes.
            }

            return $out;
        }

        $normalized = preg_replace('/\s+as\s+\w+$/i', '', (string) $value);

        return $prefix.(string) $normalized;
    }
}
