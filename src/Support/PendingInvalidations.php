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
    /** @var WeakMap<ConnectionInterface, array{tags: array<string, true>, retire: bool}> */
    private WeakMap $pending;

    public function __construct()
    {
        $this->pending = new WeakMap;
    }

    /**
     * @param  array<string>  $tags
     */
    public function addTags(ConnectionInterface $connection, array $tags): void
    {
        $entry = $this->pending[$connection] ?? ['tags' => [], 'retire' => false];

        foreach ($tags as $tag) {
            $entry['tags'][$tag] = true;
        }

        $this->pending[$connection] = $entry;
    }

    public function requestRetire(ConnectionInterface $connection): void
    {
        $entry = $this->pending[$connection] ?? ['tags' => [], 'retire' => false];
        $entry['retire'] = true;
        $this->pending[$connection] = $entry;
    }

    /**
     * @return array{tags: list<string>, retire: bool}
     */
    public function take(ConnectionInterface $connection): array
    {
        $entry = $this->pending[$connection] ?? ['tags' => [], 'retire' => false];
        unset($this->pending[$connection]);

        return ['tags' => array_keys($entry['tags']), 'retire' => $entry['retire']];
    }
}
