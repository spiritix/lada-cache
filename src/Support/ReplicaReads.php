<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class ReplicaReads
{
    public static function lagSeconds(ConnectionInterface $connection): int
    {
        if (! self::hasReplicas($connection)) {
            return 0;
        }

        return self::configuredLagSeconds();
    }

    public static function configuredLagSeconds(): int
    {
        $seconds = ConfigValue::integer('lada-cache.replica_lag', 2);

        if ($seconds < 0) {
            throw new InvalidArgumentException(sprintf('Invalid lada-cache.replica_lag %d, expected 0 or more seconds', $seconds));
        }

        return $seconds;
    }

    public static function readsFromReplica(ConnectionInterface $connection, bool $usesWritePdo): bool
    {
        if ($usesWritePdo || ! self::hasReplicas($connection)) {
            return false;
        }

        return ! ($connection instanceof Connection && $connection->hasModifiedRecords() && $connection->getConfig('sticky'));
    }

    private static function hasReplicas(ConnectionInterface $connection): bool
    {
        return $connection instanceof Connection && $connection->getRawReadPdo() !== null;
    }
}
