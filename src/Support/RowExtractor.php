<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Spiritix\LadaCache\Database\QueryBuilder;
use UnexpectedValueException;

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

        foreach (self::wheres($builder) as $where) {
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
     * The values each column of each table is pinned to by `col = v` or `col IN (...)` wheres
     * the query ANDs together, so every row it reads from that table has one of them. A query with
     * an OR pins nothing; in a query with joins only qualified columns pin, since an unqualified
     * one may belong to any joined table. Tables the statement reads more than once are left out.
     *
     * @param  array<string, int>  $referenceCounts
     * @return array<string, array<string, list<scalar>>> table => lowercase column => values
     */
    public static function extractColumnPins(BaseBuilder $builder, array $referenceCounts = []): array
    {
        $wheres = self::wheres($builder);

        foreach ($wheres as $where) {
            if (str_starts_with(self::boolean($where), 'or')) {
                return [];
            }
        }

        $aliasMap = self::resolveAliasMap($builder);
        $requireQualified = ($builder->joins ?? []) !== [];
        $pins = [];

        foreach ($wheres as $where) {
            $match = self::matchColumnPin($builder, $where, $aliasMap, $requireQualified);

            if ($match === null) {
                continue;
            }

            [$table, $column, $values] = $match;
            $pins[$table][$column] = [...$pins[$table][$column] ?? [], ...$values];
        }

        return array_filter($pins, static fn (string $table): bool => ($referenceCounts[$table] ?? 1) <= 1, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  array<mixed>  $where
     * @param  array<string, string>  $aliasMap
     * @return array{0: string, 1: string, 2: non-empty-list<scalar>}|null
     */
    private static function matchColumnPin(BaseBuilder $builder, array $where, array $aliasMap, bool $requireQualified): ?array
    {
        if (! isset($where['column']) || str_contains(self::boolean($where), 'not')) {
            return null;
        }

        $columnRef = $where['column'] instanceof Expression ? $builder->getGrammar()->getValue($where['column']) : $where['column'];

        if (! is_string($columnRef) || ($requireQualified && ! str_contains($columnRef, '.'))) {
            return null;
        }

        if (! str_contains($columnRef, '.') && is_string($builder->from)) {
            $columnRef = SqlAliasParser::stripAlias($builder->from).'.'.$columnRef;
        }

        [$table, $column] = self::splitTableAndColumn($columnRef);

        if ($table === null) {
            return null;
        }

        $table = $aliasMap[$table] ?? $table;
        $values = match (true) {
            ($where['type'] ?? null) === 'Basic' && ($where['operator'] ?? '=') === '=' && is_scalar($where['value'] ?? null) => [$where['value']],
            in_array($where['type'] ?? null, ['In', 'InRaw'], true) && is_array($where['values'] ?? null) => array_values($where['values']),
            default => [],
        };

        if ($values === [] || array_filter($values, 'is_scalar') !== $values) {
            return null;
        }

        return [$table, Write::columnName($column), $values];
    }

    /**
     * Resolve a primary-key where to its table and the key values it pins.
     *
     * Returns null for wheres that are not on a primary key or that negate it (whereNot),
     * since neither can scope the query to rows. The values are empty when the operator
     * does not pin specific keys.
     *
     * @param  array<mixed>  $where
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
     * @param  array<mixed>  $where
     */
    private static function boolean(array $where): string
    {
        $boolean = $where['boolean'] ?? 'and';

        return is_string($boolean) ? strtolower($boolean) : throw new UnexpectedValueException('Where clause boolean must be a string, got '.get_debug_type($boolean));
    }

    /**
     * @return list<array<mixed>>
     */
    private static function wheres(BaseBuilder $builder): array
    {
        return array_values(array_filter($builder->wheres, 'is_array'));
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
