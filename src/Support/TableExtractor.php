<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Utility to extract table names (as strings) from a Laravel Query Builder.
 *
 * Handles FROM, JOINs, EXISTS subqueries, and UNION branches.
 */
final class TableExtractor
{
    /**
     * @return array<int, string>
     */
    public static function extractTables(BaseBuilder $builder): array
    {
        return array_values(array_unique(self::collectTables($builder)));
    }

    /**
     * Counts how often the query reads each table, e.g. twice for a table joined to itself.
     *
     * @return array<string, int>
     */
    public static function countTables(BaseBuilder $builder): array
    {
        return array_count_values(self::collectTables($builder));
    }

    /**
     * @return array<int, string> Table names in order of appearance, with repeats.
     */
    private static function collectTables(BaseBuilder $builder): array
    {
        $tables = [];

        // FROM
        $from = $builder->from;
        if (is_string($from)) {
            $tables[] = SqlAliasParser::stripAlias($from);
        } elseif ($from instanceof BaseBuilder) {
            $tables = array_merge($tables, self::collectTables($from));
        } elseif ($from instanceof Expression) {
            $expr = $builder->getGrammar()->getValue($from);
            $alias = SqlAliasParser::extractAliasFromExpression($expr);
            if ($alias !== null) {
                $tables[] = $alias;
            }
        }

        // JOINs
        foreach ($builder->joins ?? [] as $join) {
            if (! ($join instanceof JoinClause)) {
                continue;
            }
            if (is_string($join->table)) {
                $tables[] = SqlAliasParser::stripAlias($join->table);

                continue;
            }
            if ($join->table instanceof Expression) {
                $expr = $builder->getGrammar()->getValue($join->table);
                $alias = SqlAliasParser::extractAliasFromExpression($expr);
                if ($alias !== null) {
                    $tables[] = $alias;
                }
            }
        }

        // WHEREs: dive into subqueries (Exists/NotExists and nested where queries)
        $tables = array_merge($tables, self::collectWhereTables($builder));

        // UNION branches
        foreach (($builder->unions ?? []) as $union) {
            $sub = $union['query'] ?? null;
            if ($sub instanceof BaseBuilder) {
                $tables = array_merge($tables, self::collectTables($sub));
            }
        }

        return $tables;
    }

    /**
     * @return array<int, string>
     */
    private static function collectWhereTables(BaseBuilder $builder): array
    {
        $tables = [];

        foreach ($builder->wheres ?? [] as $where) {
            $query = $where['query'] ?? null;
            if (! $query instanceof BaseBuilder) {
                continue;
            }

            // A nested where group repeats the parent's FROM; only its own subqueries read tables.
            $tables = array_merge($tables, ($where['type'] ?? null) === 'Nested'
                ? self::collectWhereTables($query)
                : self::collectTables($query));
        }

        return $tables;
    }
}
