<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\MySqlConnection as BaseMySqlConnection;
use Spiritix\LadaCache\Services;

/**
 * Lada Cache-aware MySQL connection.
 *
 * Extends Laravel's MySqlConnection to return the Lada QueryBuilder when active,
 * preserving all driver-specific behaviors (schema builder, insert IDs, etc.).
 */
final class MySqlConnection extends BaseMySqlConnection implements RunsBuilderStatements
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
