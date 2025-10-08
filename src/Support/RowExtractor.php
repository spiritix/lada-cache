<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Utility to extract targeted primary-key rows per table from a Query Builder.
 *
 * Returns an associative map of table => list of primary key values when
 * they can be statically determined from Basic or In wheres.
 */
final class RowExtractor
{
    /**
     * @return array<string, array<int, scalar>>
     */
    public static function extractRows(BaseBuilder $builder): array
    {
        $rows = [];

        $aliasMap = self::resolveAliasMap($builder);
        $pk = method_exists($builder, 'getPrimaryKeyName')
            ? $builder->getPrimaryKeyName()
            : 'id';

        foreach ($builder->wheres ?? [] as $where) {
            if (! isset($where['column'])) {
                // Skip non-column where types; nested queries handled by TableExtractor for tables
                continue;
            }

            $columnRef = $where['column'];
            if ($columnRef instanceof Expression) {
                $columnRef = $builder->getGrammar()->getValue($columnRef);
            }
            if (! is_string($columnRef)) {
                continue;
            }

            if (! str_contains($columnRef, '.') && is_string($builder->from)) {
                $columnRef = $builder->from.'.'.$columnRef;
            }

            [$table, $column] = self::splitTableAndColumn($columnRef);
            if ($table !== null && isset($aliasMap[$table])) {
                $table = $aliasMap[$table];
            }
            if ($table === null || $column !== $pk) {
                continue;
            }

            $rows[$table] ??= [];

            if (($where['type'] ?? null) === 'Basic'
                && (($where['operator'] ?? '=') === '=')
                && is_scalar($where['value'] ?? null)) {
                $rows[$table][] = $where['value'];
            } elseif (($where['type'] ?? null) === 'In' && isset($where['values']) && is_array($where['values'])) {
                foreach ($where['values'] as $v) {
                    if (is_scalar($v)) {
                        $rows[$table][] = $v;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private static function splitTableAndColumn(string $identifier): array
    {
        if (! str_contains($identifier, '.')) {
            return [null, $identifier];
        }

        $parts = explode('.', $identifier);
        $column = array_pop($parts);
        $table = end($parts) ?: null;

        return [$table, $column];
    }

    /**
     * @return array<string, string>
     */
    private static function resolveAliasMap(BaseBuilder $builder): array
    {
        $map = [];

        $from = $builder->from;
        if (is_string($from)) {
            $alias = SqlAliasParser::extractAlias($from);
            if ($alias !== null) {
                $map[$alias] = SqlAliasParser::stripAlias($from);
            }
        }

        foreach ($builder->joins ?? [] as $join) {
            if (! ($join instanceof JoinClause)) {
                continue;
            }
            if (is_string($join->table)) {
                $alias = SqlAliasParser::extractAlias($join->table);
                if ($alias !== null) {
                    $map[$alias] = SqlAliasParser::stripAlias($join->table);
                }
            }
        }

        return $map;
    }
}
