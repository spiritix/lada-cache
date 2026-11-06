<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Spiritix\LadaCache\Database\HasCompiledSubQueries;

/**
 * Utility to extract table names (as strings) from a Laravel Query Builder.
 *
 * Handles FROM, JOINs, where subqueries, subqueries Laravel compiles to raw SQL, and UNION branches.
 */
final class TableExtractor
{
    /**
     * @return array<int, string>
     */
    public static function extractTables(BaseBuilder $builder): array
    {
        $tables = [];

        $from = $builder->from;
        if (is_string($from)) {
            $tables[] = SqlAliasParser::stripAlias($from);
        } elseif ($from instanceof BaseBuilder) {
            $tables = array_merge($tables, self::extractTables($from));
        } elseif ($from instanceof Expression) {
            $alias = SqlAliasParser::extractAliasFromExpression($builder->getGrammar()->getValue($from));
            if ($alias !== null) {
                $tables[] = $alias;
            }
        }

        foreach ($builder->joins ?? [] as $join) {
            if (! ($join instanceof JoinClause)) {
                continue;
            }
            if (is_string($join->table)) {
                $tables[] = SqlAliasParser::stripAlias($join->table);
            } elseif ($join->table instanceof Expression) {
                $alias = SqlAliasParser::extractAliasFromExpression($builder->getGrammar()->getValue($join->table));
                if ($alias !== null) {
                    $tables[] = $alias;
                }
            }

            $tables = array_merge($tables, self::extractSubQueryTables($join));
        }

        $tables = array_merge($tables, self::extractSubQueryTables($builder));

        foreach (($builder->unions ?? []) as $union) {
            $sub = $union['query'] ?? null;
            if ($sub instanceof BaseBuilder) {
                $tables = array_merge($tables, self::extractTables($sub));
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return array<int, string>
     */
    private static function extractSubQueryTables(BaseBuilder $builder): array
    {
        $subQueries = $builder instanceof HasCompiledSubQueries ? $builder->getCompiledSubQueries() : [];

        foreach ($builder->wheres ?? [] as $where) {
            if (($where['query'] ?? null) instanceof BaseBuilder) {
                $subQueries[] = $where['query'];
            }
        }

        $tables = [];
        foreach ($subQueries as $subQuery) {
            $tables = array_merge($tables, self::extractTables($subQuery));
        }

        return $tables;
    }
}
