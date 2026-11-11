<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use PDO;

final class Transactions
{
    /**
     * Whether the connection is inside a transaction: one Laravel opened, or one opened behind
     * its back with a raw `START TRANSACTION` or on the PDO object, which only the driver
     * knows about. A connection that has not connected yet has none, and asking never connects
     * it.
     */
    public static function isOpen(ConnectionInterface $connection): bool
    {
        if ($connection->transactionLevel() > 0) {
            return true;
        }

        $pdo = $connection instanceof Connection ? $connection->getRawPdo() : null;

        return $pdo instanceof PDO && $pdo->inTransaction();
    }
}
