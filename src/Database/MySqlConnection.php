<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\MySqlConnection as BaseMySqlConnection;
use Spiritix\LadaCache\QueryHandler as LadaQueryHandler;

/**
 * Lada Cache-aware MySQL connection.
 *
 * Extends Laravel's MySqlConnection to return the Lada QueryBuilder when active,
 * preserving all driver-specific behaviors (schema builder, insert IDs, etc.).
 */
final class MySqlConnection extends BaseMySqlConnection
{
    /** {@inheritDoc} */
    public function query()
    {
        if (! (bool) config('lada-cache.active', true)) {
            return parent::query();
        }

        /** @var LadaQueryHandler $handler */
        $handler = app('lada.handler');

        return new QueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
