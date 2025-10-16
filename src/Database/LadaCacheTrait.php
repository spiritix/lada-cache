<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Spiritix\LadaCache\QueryHandler;

/**
 * Integrates Lada Cache into Eloquent models.
 *
 * Include this trait on models to route all base queries through the
 * Lada Cache-aware query builder, enabling transparent caching and
 * invalidation.
 */
trait LadaCacheTrait
{
    /** {@inheritDoc} */
    protected function newBaseQueryBuilder()
    {
        // When Lada Cache is disabled, use Laravel's default query builder
        if (! (bool) config('lada-cache.active', true)) {
            return parent::newBaseQueryBuilder();
        }

        $connection = $this->getConnection();

        /** @var QueryHandler $handler */
        $handler = app('lada.handler');

        return new QueryBuilder(
            $connection,
            $handler,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
            $this
        );
    }
}
