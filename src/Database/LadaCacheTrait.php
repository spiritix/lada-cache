<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
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
    public function newPivot(Model $parent, array $attributes, $table, $exists, $using = null)
    {
        return $using
            ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
            : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /** {@inheritDoc} */
    protected function newBaseQueryBuilder()
    {
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
