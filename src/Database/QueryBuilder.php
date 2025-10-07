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
 *
 * @package Spiritix\LadaCache\Database
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
    public function insert(array $values)
    {
        $result = parent::insert($values);

        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, $values);

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
    public function delete($id = null)
    {
        $count = parent::delete($id);

        $this->handler
            ->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_DELETE, [
                $this->getPrimaryKeyName() => $id
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
