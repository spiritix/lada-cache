<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Reflector;

/**
 * LadaCache Query Builder for Laravel 12+
 *
 * Extends the base query builder to provide automatic caching and invalidation.
 */
class QueryBuilder extends Builder
{
    public const string DEFAULT_PRIMARY_KEY_NAME = 'id';

    private readonly QueryHandler $handler;

    private readonly ?Model $model;

    public function __construct(
        ConnectionInterface $connection,
        QueryHandler $handler,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
        ?Model $model = null
    ) {
        parent::__construct($connection, $grammar, $processor);
        $this->handler = $handler;
        $this->model = $model;
    }

    /** {@inheritDoc} */
    public function exists()
    {
        // If locked, defer to base implementation (always hit DB).
        if ($this->lock !== null) {
            return parent::exists();
        }

        // Use a cached-select pathway by limiting to 1 row and checking non-emptiness.
        // This ensures existence checks benefit from caching without changing semantics.
        return $this->clone()->limit(1)->get(['*'])->isNotEmpty();
    }

    public function getPrimaryKeyName(): string
    {
        return $this->model?->getKeyName() ?? self::DEFAULT_PRIMARY_KEY_NAME;
    }

    /** {@inheritDoc} */
    public function newQuery()
    {
        return new static(
            $this->connection,
            $this->handler,
            $this->grammar,
            $this->processor,
            $this->model
        );
    }

    /** {@inheritDoc} */
    protected function runSelect()
    {
        // Do not cache queries that use pessimistic locks (lockForUpdate/sharedLock).
        // Laravel stores lock intent in $this->lock; when present we should always hit the DB.
        if ($this->lock !== null) {
            return parent::runSelect();
        }

        return $this->handler
            ->setBuilder($this)
            ->cacheQuery(function () {
                return $this->connection->select(
                    $this->toSql(),
                    $this->getBindings(),
                    ! $this->useWritePdo
                );
            });
    }

    /** {@inheritDoc} */
    public function selectSub($query, $as)
    {
        // Only collect tags if the subquery is our own builder instance.
        if ($query instanceof self) {
            $this->handler->setBuilder($query)->collectSubQueryTags();
        }

        return parent::selectSub($query, $as);
    }

    /** {@inheritDoc} */
    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        if ($query instanceof self) {
            $this->handler->setBuilder($query)->collectSubQueryTags();
        }

        return parent::joinSub($query, $as, $first, $operator, $second, $type, $where);
    }

    /** {@inheritDoc} */
    public function insert(array $values)
    {
        $result = parent::insert($values);

        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, $values);

        return $result;
    }

    /** {@inheritDoc} */
    public function insertUsing(array $columns, $query)
    {
        $result = parent::insertUsing($columns, $query);

        // Treat as INSERT for invalidation.
        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, []);

        return $result;
    }

    /** {@inheritDoc} */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $result = parent::insertOrIgnoreUsing($columns, $query);

        // May still insert rows; invalidate conservatively as INSERT.
        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, []);

        return $result;
    }

    /** {@inheritDoc} */
    public function updateFrom(array $values)
    {
        $result = parent::updateFrom($values);

        // Update-from modifies rows; invalidate as UPDATE.
        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_UPDATE, $values);

        return $result;
    }

    /** {@inheritDoc} */
    public function insertGetId(array $values, $sequence = null)
    {
        $id = parent::insertGetId($values, $sequence);

        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, $values);

        return $id;
    }

    /** {@inheritDoc} */
    public function update(array $values)
    {
        $count = parent::update($values);

        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_UPDATE, $values);

        return $count;
    }

    /** {@inheritDoc} */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $result = parent::upsert($values, $uniqueBy, $update);

        // Treat UPSERT as an UPDATE-like invalidation to safely clear unspecific table tags.
        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_UPDATE, is_array($values) ? (array) $values : []);

        return $result;
    }

    /** {@inheritDoc} */
    public function insertOrIgnore(array $values)
    {
        $result = parent::insertOrIgnore($values);

        // Insert-or-ignore may still insert rows; invalidate as INSERT.
        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, $values);

        return $result;
    }

    /** {@inheritDoc} */
    public function updateOrInsert(array $attributes, callable|array $values = [])
    {
        $result = parent::updateOrInsert($attributes, $values);

        // May perform insert or update; invalidate conservatively as UPDATE.
        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_UPDATE, $values ?: $attributes);

        return $result;
    }

    /** {@inheritDoc} */
    public function delete($id = null)
    {
        $count = parent::delete($id);

        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_DELETE, [
                $this->getPrimaryKeyName() => $id,
            ]);

        return $count;
    }

    /** {@inheritDoc} */
    public function truncate()
    {
        parent::truncate();

        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_TRUNCATE);
    }
}
