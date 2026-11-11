<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Database\ConnectionInterface;
use WeakMap;

/**
 * Invalidations of writes inside a transaction, held per connection until it ends.
 *
 * Keyed weakly by connection object, so state of a purged or reconnected connection
 * disappears with it in long-lived workers instead of leaking into later transactions.
 */
final class PendingInvalidations
{
    /** @var WeakMap<ConnectionInterface, Invalidation> */
    private WeakMap $pending;

    public function __construct()
    {
        $this->pending = new WeakMap;
    }

    public function add(ConnectionInterface $connection, Invalidation $invalidation): void
    {
        $this->pending[$connection] = ($this->pending[$connection] ?? Invalidation::none())->merge($invalidation);
    }

    public function has(ConnectionInterface $connection): bool
    {
        return isset($this->pending[$connection]);
    }

    public function take(ConnectionInterface $connection): Invalidation
    {
        $invalidation = $this->pending[$connection] ?? Invalidation::none();
        unset($this->pending[$connection]);

        return $invalidation;
    }
}
