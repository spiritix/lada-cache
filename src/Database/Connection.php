<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Spiritix\LadaCache\Database\QueryBuilder as LadaQueryBuilder;

use function Illuminate\Support\enum_value;

/**
 * Lada Cache database connection.
 *
 * This connection decorates Laravel's `Illuminate\\Database\\Connection` to return
 * a custom `QueryBuilder` that integrates with Lada Cache, enabling transparent
 * query caching and invalidation where supported by the package.
 */
final class Connection extends \Illuminate\Database\Connection
{
    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     *
     * {@inheritDoc}
     */
    public function query()
    {
        /** @var \Spiritix\LadaCache\QueryHandler $handler */
        $handler = app('lada.handler');

        return new LadaQueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Contracts\Database\Query\Expression|\UnitEnum|string  $table
     * @param  string|null  $as
     * @return \Illuminate\Database\Query\Builder
     *
     * {@inheritDoc}
     */
    public function table($table, $as = null)
    {
        return $this->query()->from(enum_value($table), $as);
    }
}
