<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use PDO;
use Spiritix\LadaCache\Database\RunsBuilderStatements;

final class Transactions
{
    /**
     * Whether the connection is inside a transaction: one Laravel opened, or one opened behind
     * its back on the PDO object or with a raw `START TRANSACTION`. The driver reports those,
     * except that some do not report the raw statement, which the connection therefore notes
     * as it passes through. A connection that has not connected yet has none, and asking never
     * connects it.
     */
    public static function isOpen(ConnectionInterface $connection): bool
    {
        if ($connection->transactionLevel() > 0) {
            return true;
        }

        $pdo = $connection instanceof Connection ? $connection->getRawPdo() : null;

        return ($pdo instanceof PDO && $pdo->inTransaction())
            || ($connection instanceof RunsBuilderStatements && $connection->inRawTransaction());
    }
}
