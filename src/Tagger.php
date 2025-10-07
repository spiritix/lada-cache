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
    private const string PREFIX_DATABASE         = 'tags:database:';
    private const string PREFIX_TABLE_SPECIFIC   = ':table_specific:';
    private const string PREFIX_TABLE_UNSPECIFIC = ':table_unspecific:';
    private const string PREFIX_ROW              = ':row:';

    private Reflector $reflector;
    private bool $considerRows;

    public function __construct(Reflector $reflector)
    {
        $this->reflector = $reflector;
        $this->considerRows = (bool) config('lada-cache.consider_rows', true);
    }

    /**
     * Build all tags for the underlying query.
     *
     * @return array<int, string>
     */
    public function getTags(): array
    {
        $databaseTag = $this->prefix($this->reflector->getDatabase(), self::PREFIX_DATABASE);
        /** @var array<int, string> $tables */
        $tables = $this->reflector->getTables();

        if (!$this->considerRows) {
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

    /**
     * @param array<int, string> $tables
     * @param array<string, array<int, scalar>> $rows
     * @return array<int, string>
     */
    private function getTableTags(array $tables, array $rows): array
    {
        $tags = [];
        $type = $this->reflector->getType();

        foreach ($tables as $table) {
            if (!is_string($table)) {
                continue;
            }

            $hasSpecificRows = !empty($rows[$table] ?? []);

            if ($type === Reflector::QUERY_TYPE_SELECT) {
                $tags[] = $this->prefix(
                    $table,
                    $hasSpecificRows ? self::PREFIX_TABLE_SPECIFIC : self::PREFIX_TABLE_UNSPECIFIC
                );
            }

            if (in_array($type, [Reflector::QUERY_TYPE_UPDATE, Reflector::QUERY_TYPE_DELETE], true)) {
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }

            if ($type === Reflector::QUERY_TYPE_INSERT) {
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }

            if ($type === Reflector::QUERY_TYPE_TRUNCATE) {
                $tags[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
            }
        }

        return array_unique($tags);
    }

    /**
     * @param string|array<int, string> $value
     * @return string|array<int, string>
     */
    private function prefix(string|array $value, string $prefix): string|array
    {
        if (is_array($value)) {
            return array_map(fn(string $item): string => $this->prefix($item, $prefix), $value);
        }

        $normalized = preg_replace('/\s+as\s+\w+$/i', '', $value);
        return $prefix . (string) $normalized;
    }
}
