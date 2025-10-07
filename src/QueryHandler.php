<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\App;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Debug\CacheCollector;
use Throwable;

/**
 * Coordinates query caching and invalidation for Eloquent/DB queries.
 *
 * Responsibilities:
 * - Decide whether a query should be cached using `Manager`.
 * - Compute cache keys with `Hasher` and tags with `Tagger`.
 * - Store/fetch cached results via `Cache` and invalidate via `Invalidator`.
 * - Integrate with the debug collector when available.
 */
final class QueryHandler
{
    private ?QueryBuilder $builder = null;
    private ?CacheCollector $collector = null;
    /** @var string[] */
    private array $subQueryTags = [];

    /**
     * Queued invalidation tags per connection name for transaction-aware flushing.
     * @var array<string, array<int, string>>
     */
    private array $queuedInvalidations = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly Invalidator $invalidator,
    ) {
    }

    public function setBuilder(QueryBuilder $builder): self
    {
        $this->builder = $builder;
        return $this;
    }

    /**
     * Collect tags from subqueries for propagation to the main query.
     */
    public function collectSubQueryTags(): void
    {
        if ($this->builder === null) {
            return;
        }

        $reflector = new Reflector($this->builder);
        $manager = new Manager($reflector);

        if (!$manager->shouldCache()) {
            return;
        }

        $tagger = new Tagger($reflector);
        $this->subQueryTags = array_values(array_unique([
            ...$this->subQueryTags,
            ...$tagger->getTags(),
        ]));
    }

    /**
     * Execute and cache a query.
     *
     * @param Closure(): array<mixed> $queryClosure
     * @return array<mixed>
     */
    public function cacheQuery(Closure $queryClosure): array
    {
        $this->startCollector();

        $subQueryTags = $this->subQueryTags;
        $this->subQueryTags = [];

        if ($this->builder === null) {
            return $queryClosure();
        }

        $reflector = new Reflector($this->builder);
        $manager = new Manager($reflector);

        if (!$manager->shouldCache()) {
            return $queryClosure();
        }

        $hasher = new Hasher($reflector);
        $tagger = new Tagger($reflector);

        $key = $hasher->getHash();
        $tags = array_values(array_unique([...$tagger->getTags(), ...$subQueryTags]));

        $cached = $this->cache->has($key) ? $this->cache->get($key) : null;
        $action = $cached === null ? 'Miss' : 'Hit';

        if ($cached === null) {
            $cached = $queryClosure();
            $this->cache->set($key, $tags, $cached);
        } else {
            // Self-heal tag membership inconsistencies by idempotently adding the key to each tag set.
            $this->cache->repairTagMembership($key, $tags);
        }

        $this->stopCollector($reflector, $tags, $key, $action);
        return $cached;
    }

    /**
     * Invalidate cache entries affected by a modifying query.
     *
     * @param string $statementType
     * @param array<string, mixed> $values
     */
    public function invalidateQuery(string $statementType, array $values = []): void
    {
        $this->startCollector();

        if ($this->builder === null) {
            return;
        }

        $reflector = new Reflector($this->builder, $statementType, $values);
        $manager = new Manager($reflector);

        if (!$manager->shouldCache()) {
            return;
        }

        $tagger = new Tagger($reflector);
        $tags = $tagger->getTags();

        // If in a transaction, queue invalidation until commit; otherwise, execute immediately.
        $connection = $this->builder->getConnection();
        if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
            $this->queueInvalidationForConnection($connection, $tags);

            // Ensure we flush after commit for this connection.
            if (method_exists($connection, 'afterCommit')) {
                $connection->afterCommit(function () use ($connection): void {
                    $this->flushQueuedInvalidationsForConnection($connection);
                });
            }

            // We can't know hashes before flushing; record action only.
            $this->stopCollector($reflector, $tags, [], "Invalidation queued ({$statementType})");
            return;
        }

        $hashes = $this->invalidator->invalidate($tags);
        $this->stopCollector($reflector, $tags, $hashes, "Invalidation ({$statementType})");
    }

    /**
     * Queue tags for invalidation for a specific connection (by name) during a transaction.
     */
    public function queueInvalidationForConnection(ConnectionInterface $connection, array $tags): void
    {
        $name = (string) ($connection->getName() ?? 'default');
        $existing = $this->queuedInvalidations[$name] ?? [];
        $this->queuedInvalidations[$name] = array_values(array_unique([...$existing, ...$tags]));
    }

    /**
     * Flush all queued invalidations for the given connection and clear the queue.
     */
    public function flushQueuedInvalidationsForConnection(ConnectionInterface $connection): void
    {
        $name = (string) ($connection->getName() ?? 'default');
        $tags = $this->queuedInvalidations[$name] ?? [];
        if ($tags !== []) {
            $this->invalidator->invalidate($tags);
        }
        unset($this->queuedInvalidations[$name]);
    }

    /**
     * Clear queued invalidations for a connection (e.g., on rollback).
     */
    public function clearQueuedInvalidationsForConnection(ConnectionInterface $connection): void
    {
        $name = (string) ($connection->getName() ?? 'default');
        unset($this->queuedInvalidations[$name]);
    }

    /**
     * Initialize Debugbar collector safely.
     */
    private function startCollector(): void
    {
        try {
            $this->collector = App::make('lada.collector');
            $this->collector->startMeasuring();
        } catch (Throwable) {
            $this->collector = null;
        }
    }

    /**
     * Finalize the Debugbar measurement if the collector is available.
     *
     * @param array<string>            $tags
     * @param array<string>|string     $hashes
     */
    private function stopCollector(Reflector $reflector, array $tags, string|array $hashes, string $action): void
    {
        if ($this->collector === null) {
            return;
        }

        $this->collector->endMeasuring(
            $action,
            (array) $hashes,
            $tags,
            $reflector->getSql(),
            $reflector->getParameters()
        );
    }
}
