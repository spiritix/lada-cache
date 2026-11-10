<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\MySqlConnection as BaseMySqlConnection;
use Spiritix\LadaCache\Services;

/**
 * Lada Cache-aware MariaDB connection.
 *
 * MariaDB is compatible with Laravel's MySQL driver, so we extend
 * Illuminate's MySqlConnection while providing Lada's query builder.
 */
final class MariaDbConnection extends BaseMySqlConnection implements RunsBuilderStatements
{
    use InvalidatesRawStatements;

    /** {@inheritDoc} */
    public function query()
    {
        if (! (bool) config('lada-cache.active', true)) {
            return parent::query();
        }

        $handler = Services::handler();

        return new QueryBuilder(
            $this,
            $handler,
            $this->getQueryGrammar(),
            $this->getPostProcessor(),
        );
    }
}
