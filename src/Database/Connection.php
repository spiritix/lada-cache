<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Spiritix\LadaCache\Database\QueryBuilder as LadaQueryBuilder;
use Spiritix\LadaCache\QueryHandler as LadaQueryHandler;

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
     * {@inheritDoc}
     */
    public function query()
    {
        /** @var LadaQueryHandler $handler */
        $handler = app('lada.handler');

        return new LadaQueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
