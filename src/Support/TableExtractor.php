<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Spiritix\LadaCache\Database\HasSubQueries;

/**
 * Utility to extract table names (as strings) from a Laravel Query Builder.
 *
 * Handles FROM, JOINs, where subqueries, compiled subqueries, and UNION branches.
 */
final class TableExtractor
{
    /**
     * @return array<int, string>
     */
    public static function extractTables(BaseBuilder $builder): array
    {
        $tables = [];

        // FROM
        $from = $builder->from;
        if (is_string($from)) {
            $tables[] = SqlAliasParser::stripAlias($from);
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

        // UNION branches
        foreach (($builder->unions ?? []) as $union) {
            $sub = $union['query'] ?? null;
            if ($sub instanceof BaseBuilder) {
                $tables = array_merge($tables, self::extractTables($sub));
            }
        }

        return array_values(array_unique([...$tables, ...self::extractSubQueryTables($builder)]));
    }

    /**
     * Tables read by subqueries, whose rows are not scoped by the outer query's wheres.
     *
     * @return array<int, string>
     */
    public static function extractSubQueryTables(BaseBuilder $builder): array
    {
        $subQueries = $builder instanceof HasSubQueries ? $builder->getSubQueries() : [];
        if ($builder->from instanceof BaseBuilder) {
            $subQueries[] = $builder->from;
        }

        $tables = [];
        foreach ($builder->wheres ?? [] as $where) {
            $query = $where['query'] ?? null;
            if (! ($query instanceof BaseBuilder)) {
                continue;
            }
            // Nested where groups read the outer tables; only their own subqueries count.
            if (($where['type'] ?? null) === 'Nested') {
                $tables = array_merge($tables, self::extractSubQueryTables($query));
            } else {
                $subQueries[] = $query;
            }
        }

        foreach ($builder->joins ?? [] as $join) {
            if ($join instanceof JoinClause) {
                $tables = array_merge($tables, self::extractSubQueryTables($join));
            }
        }

        foreach ($subQueries as $subQuery) {
            $tables = array_merge($tables, self::extractTables($subQuery));
        }

        return array_values(array_unique($tables));
    }
}
