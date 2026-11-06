<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\App;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Support\AssignedRows;
use Spiritix\LadaCache\Support\CacheOperation;
use Spiritix\LadaCache\Support\FailureReporter;
use Throwable;

/**
 * Coordinates query caching and invalidation for Eloquent/DB queries.
 *
 * Responsibilities:
 * - Decide whether a query should be cached using `Manager`.
 * - Compute cache keys with `Hasher` and tags with `Tagger`.
 * - Look up, store and invalidate results via `Cache`.
 * - Integrate with the debug collector when available.
 *
 * Every cache failure is reported and degrades to plain database access: a query runs
 * exactly once and its own exceptions propagate unchanged.
 */
final class QueryHandler
{
    private ?QueryBuilder $builder = null;

    private ?CacheCollector $collector = null;

    /** @var array<string, array<int, string>> */
    private array $queuedInvalidations = [];

    public function __construct(
        private readonly Cache $cache,
    ) {}

    public function setBuilder(QueryBuilder $builder): self
    {
        $this->builder = $builder;

        return $this;
    }

    /**
     * @param  string  $statementType  One of Reflector::QUERY_TYPE_*
     * @param  array<string, mixed>  $values  Values used by the grammar to compile the SQL (e.g., update sets)
     */
    public function invalidateQuery(string $statementType, array $values = [], AssignedRows $assignedRows = new AssignedRows): void
    {
        $this->startCollector();

        if ($this->builder === null) {
            return;
        }

        try {
            $reflector = new Reflector($this->builder, $statementType, $values);

            if (! (new Manager($reflector))->shouldInvalidate()) {
                return;
            }

            $tags = (new Tagger($reflector, $assignedRows))->getTags();
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Invalidate, $exception, ['statement' => $statementType]);
            $this->retireGeneration();

            return;
        }

        $connection = $this->builder->getConnection();
        if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
            $this->queueInvalidationForConnection($connection, $tags);

            if (method_exists($connection, 'afterCommit')) {
                $connection->afterCommit(function () use ($connection): void {
                    $this->flushQueuedInvalidationsForConnection($connection);
                });
            }

            $this->stopCollector($reflector, $tags, [], "Invalidation queued ({$statementType})");

            return;
        }

        $this->invalidateTags($tags);
        $this->stopCollector($reflector, $tags, [], "Invalidation ({$statementType})");
    }

    /**
     * @param  Closure(): array<mixed>  $queryClosure
     * @return array<mixed>
     */
    public function cacheQuery(Closure $queryClosure): array
    {
        $this->startCollector();

        if ($this->builder === null) {
            return $queryClosure();
        }

        try {
            $reflector = new Reflector($this->builder);
            $cacheable = (new Manager($reflector))->shouldCache();
            $key = $cacheable ? (new Hasher($reflector))->getHash() : '';
            $tags = $cacheable ? (new Tagger($reflector))->getTags() : [];
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Analyze, $exception);

            return $queryClosure();
        }

        if (! $cacheable) {
            return $queryClosure();
        }

        $lookup = $this->lookup($key, $tags);

        if ($lookup instanceof CacheHit && is_array($lookup->value)) {
            $this->stopCollector($reflector, $tags, $key, 'Hit');

            return $lookup->value;
        }

        $rows = $queryClosure();

        if ($lookup instanceof CacheMiss) {
            $this->store($lookup, $rows);
        }

        $this->stopCollector($reflector, $tags, $key, $lookup instanceof CacheMiss ? 'Miss' : 'Bypass (cache unavailable)');

        return $rows;
    }

    public function queueInvalidationForConnection(ConnectionInterface $connection, array $tags): void
    {
        $name = (string) ($connection->getName() ?? 'default');
        $existing = $this->queuedInvalidations[$name] ?? [];
        $this->queuedInvalidations[$name] = array_values(array_unique([...$existing, ...$tags]));
    }

    public function flushQueuedInvalidationsForConnection(ConnectionInterface $connection): void
    {
        $name = (string) ($connection->getName() ?? 'default');
        $tags = $this->queuedInvalidations[$name] ?? [];
        unset($this->queuedInvalidations[$name]);

        if ($tags !== []) {
            $this->invalidateTags($tags);
        }
    }

    /**
     * @param  array<string>  $tags
     */
    private function lookup(string $key, array $tags): CacheHit|CacheMiss|null
    {
        try {
            return $this->cache->lookup($key, $tags);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Read, $exception, ['key' => $key]);

            return null;
        }
    }

    /**
     * @param  array<mixed>  $rows
     */
    private function store(CacheMiss $miss, array $rows): void
    {
        try {
            $this->cache->store($miss, $rows);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Store, $exception, ['key' => $miss->key]);
        }
    }

    /**
     * @param  array<string>  $tags
     */
    private function invalidateTags(array $tags): void
    {
        try {
            $this->cache->invalidate($tags);
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Invalidate, $exception, ['tags' => $tags]);
        }
    }

    private function retireGeneration(): void
    {
        try {
            $this->cache->retireCurrentGeneration();
        } catch (Throwable $exception) {
            FailureReporter::report(CacheOperation::Retire, $exception);
        }
    }

    public function clearQueuedInvalidationsForConnection(ConnectionInterface $connection): void
    {
        $name = (string) ($connection->getName() ?? 'default');
        unset($this->queuedInvalidations[$name]);
    }

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
     * @param  array<string>  $tags
     * @param  array<string>|string  $hashes
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
