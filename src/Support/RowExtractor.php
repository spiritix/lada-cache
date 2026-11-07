<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Spiritix\LadaCache\Database\QueryBuilder;

/**
 * Utility to extract targeted primary-key rows per table from a Query Builder.
 *
 * Returns an associative map of table => list of primary key values when
 * they can be statically determined from Basic or In wheres.
 *
 * A table the statement reads more than once, because it is joined to itself or named again in a
 * subquery, is left out: such a query reads rows its conditions do not pin, so pinning any of
 * them would leave the result stale when one of the others changes.
 */
final class RowExtractor
{
    /**
     * @param  array<string, int>  $referenceCounts  how often the statement reads each table, see
     *                                               `SqlTableScanner::tableReferenceCounts()`
     * @return array<string, array<int, scalar>>
     */
    public static function extractRows(BaseBuilder $builder, array $referenceCounts = []): array
    {
        $rows = [];
        $hasOr = false;
        $everyWherePinsRows = true;

        $aliasMap = self::resolveAliasMap($builder);

        foreach ($builder->wheres ?? [] as $where) {
            $hasOr = $hasOr || str_starts_with(self::boolean($where), 'or');

            $match = self::matchPrimaryKey($builder, $where, $aliasMap);
            if ($match === null) {
                $everyWherePinsRows = false;

                continue;
            }

            [$table, $values] = $match;
            $rows[$table] = array_merge($rows[$table] ?? [], $values);
            $everyWherePinsRows = $everyWherePinsRows && $values !== [];
        }
        if ($hasOr && (! $everyWherePinsRows || count($rows) > 1)) {
            return [];
        }

        foreach (array_keys($rows) as $table) {
            if (($referenceCounts[$table] ?? 1) > 1) {
                unset($rows[$table]);
            }
        }

        return $rows;
    }

    /**
     * Resolve a primary-key where to its table and the key values it pins.
     *
     * Returns null for wheres that are not on a primary key or that negate it (whereNot),
     * since neither can scope the query to rows. The values are empty when the operator
     * does not pin specific keys.
     *
     * @param  array<string, mixed>  $where
     * @param  array<string, string>  $aliasMap
     * @return array{0: string, 1: array<int, scalar>}|null
     */
    private static function matchPrimaryKey(BaseBuilder $builder, array $where, array $aliasMap): ?array
    {
        if (! isset($where['column']) || str_contains(self::boolean($where), 'not')) {
            return null;
        }

        $columnRef = $where['column'];
        if ($columnRef instanceof Expression) {
            $columnRef = $builder->getGrammar()->getValue($columnRef);
        }
        if (! is_string($columnRef)) {
            return null;
        }

        if (! str_contains($columnRef, '.') && is_string($builder->from)) {
            $columnRef = $builder->from.'.'.$columnRef;
        }

        [$table, $column] = self::splitTableAndColumn($columnRef);
        if ($table !== null && isset($aliasMap[$table])) {
            $table = $aliasMap[$table];
        }
        if ($table === null || $column !== self::primaryKeyOf($builder, $table)) {
            return null;
        }

        if (($where['type'] ?? null) === 'Basic'
            && (($where['operator'] ?? '=') === '=')
            && is_scalar($where['value'] ?? null)) {
            return [$table, [$where['value']]];
        }

        $values = in_array($where['type'] ?? null, ['In', 'InRaw'], true) && is_array($where['values'] ?? null) ? $where['values'] : [];
        return [$table, array_filter($values, 'is_scalar') === $values ? array_values($values) : []];
    }

    private static function primaryKeyOf(BaseBuilder $builder, string $table): string
    {
        return $builder instanceof QueryBuilder ? $builder->primaryKeyOf($table) : QueryBuilder::DEFAULT_PRIMARY_KEY_NAME;
    }

    /**
     * Laravel stores the boolean as given, so it may be 'or', 'and not' or an upper-case variant.
     *
     * @param  array<string, mixed>  $where
     */
    private static function boolean(array $where): string
    {
        return strtolower((string) ($where['boolean'] ?? 'and'));
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
