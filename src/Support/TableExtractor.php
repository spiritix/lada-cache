<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Utility to extract table names (as strings) from a Laravel Query Builder.
 * Handles FROM, JOINs, EXISTS subqueries, and UNION branches.
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
        } elseif ($from instanceof BaseBuilder) {
            $tables = array_merge($tables, self::extractTables($from));
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
        foreach ($builder->wheres ?? [] as $where) {
            $type = $where['type'] ?? null;
            $query = $where['query'] ?? null;
            if ($query instanceof BaseBuilder) {
                $tables = array_merge($tables, self::extractTables($query));
            } elseif ($type === 'Exists' || $type === 'NotExists') {
                if ($query instanceof BaseBuilder) {
                    $tables = array_merge($tables, self::extractTables($query));
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

        return array_values(array_unique($tables));
    }
}
