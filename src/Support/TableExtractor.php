<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Spiritix\LadaCache\Database\HasCompiledSubQueries;
use Spiritix\LadaCache\Support\Sql\Dialects;

/**
 * Utility to extract table names (as strings) from a Laravel Query Builder.
 *
 * Handles FROM, JOINs, where subqueries, subqueries Laravel compiles to raw SQL, and UNION branches.
 */
final class TableExtractor
{
    /**
     * @return list<string>
     */
    public static function extractTables(BaseBuilder $builder): array
    {
        $tables = [];

        $from = $builder->from;
        if (is_string($from)) {
            $tables[] = SqlAliasParser::stripAlias($from);
        } elseif ($from instanceof Expression) {
            $tables = array_merge($tables, self::expressionTables($builder, $from));
        }

        foreach ($builder->joins ?? [] as $join) {
            if (! ($join instanceof JoinClause)) {
                continue;
            }
            if (is_string($join->table)) {
                $tables[] = SqlAliasParser::stripAlias($join->table);
            } elseif ($join->table instanceof Expression) {
                $tables = array_merge($tables, self::expressionTables($builder, $join->table));
            }

            $tables = array_merge($tables, self::extractSubQueryTables($join));
        }

        $tables = array_merge($tables, self::extractSubQueryTables($builder));

        foreach (($builder->unions ?? []) as $union) {
            $sub = is_array($union) ? ($union['query'] ?? null) : null;
            if ($sub instanceof EloquentBuilder) {
                $sub = $sub->toBase();
            }
            if ($sub instanceof BaseBuilder) {
                $tables = array_merge($tables, self::extractTables($sub));
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * The tables a raw FROM or JOIN expression such as `DB::raw('files FORCE INDEX (...)')` or
     * `DB::raw('(select ...) as sub')` reads, parsed as the relation it stands for.
     *
     * @param  Expression<literal-string|int|float>  $expression
     * @return list<string>
     */
    private static function expressionTables(BaseBuilder $builder, Expression $expression): array
    {
        $connection = $builder->getConnection();

        return SqlTableScanner::tables(
            'select 1 from '.$builder->getGrammar()->getValue($expression),
            $connection instanceof Connection ? $connection->getTablePrefix() : '',
            Dialects::of($connection),
        );
    }

    /**
     * @return list<string>
     */
    private static function extractSubQueryTables(BaseBuilder $builder): array
    {
        $subQueries = $builder instanceof HasCompiledSubQueries ? $builder->getCompiledSubQueries() : [];

        foreach ($builder->wheres as $where) {
            if (is_array($where) && ($where['query'] ?? null) instanceof BaseBuilder) {
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
