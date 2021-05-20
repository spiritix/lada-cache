<?php
/**
 * This file is part of the spiritix/lada-cache package.
 *
 * @copyright Copyright (c) Matthias Isler <mi@matthias-isler.ch>
 * @license   MIT
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Reflector;

/**
 * Overrides Laravel's query builder class.
 *
 * @package Spiritix\LadaCache\Database
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class QueryBuilder extends Builder
{
    /**
     * Default primary key name.
     *
     * Is being used if a query builder is initiated without a model.
     */
    const DEFAULT_PRIMARY_KEY_NAME = 'id';

    /**
     * Handler instance.
     *
     * @var QueryHandler
     */
    private $handler;

    /**
     * Model instance.
     *
     * @var null|Model
     */
    private $model = null;

    /**
     * Create a new query builder instance.
     *
     * @param  ConnectionInterface $connection
     * @param  Grammar|null        $grammar
     * @param  Processor|null      $processor
     * @param  QueryHandler        $handler
     * @param  Model|null          $model
     */
    public function __construct(
        ConnectionInterface $connection,
        Grammar $grammar = null,
        Processor $processor = null,
        QueryHandler $handler,
        Model $model = null
    ) {
        parent::__construct($connection, $grammar, $processor);

        $this->handler = $handler;
        $this->model = $model;
    }

    /**
     * Returns the primary key name of the model, or the default one if not available.
     *
     * @return string
     */
    public function getPrimaryKeyName()
    {
        return $this->model ? $this->model->getKeyName() : self::DEFAULT_PRIMARY_KEY_NAME;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return QueryBuilder
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor, $this->handler, $this->model);
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        return $this->handler->setBuilder($this)->cacheQuery(function() {
            return parent::runSelect();
        });
    }

    /**
     * Add a subselect expression to the query.
     *
     * @param  \Closure|\Illuminate\Database\Query\Builder|string  $query
     * @param  string  $as
     * @return \Illuminate\Database\Query\Builder|static
     *
     * @throws \InvalidArgumentException
     */
    public function selectSub($query, $as)
    {
        $this->handler->setBuilder($query)
            ->collectSubQueryTags();

        return parent::selectSub($query, $as);
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array  $values
     * @return bool
     */
    public function insert(array $values)
    {
        $result = parent::insert($values);

        $this->handler->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, $values);

        return $result;
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $result = parent::insertGetId($values, $sequence);

        $this->handler->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_INSERT, $values);

        return $result;
    }

    /**
     * Update a record in the database.
     *
     * @param  array  $values
     * @return int
     */
    public function update(array $values)
    {
        $result = parent::update($values);

        $this->handler->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_UPDATE, $values);

        return $result;
    }

    /**
     * Delete a record from the database.
     *
     * @param  mixed  $id
     * @return int
     */
    public function delete($id = null)
    {
        $result = parent::delete($id);

        $this->handler->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_DELETE, [$this->getPrimaryKeyName() => $id]);

        return $result;
    }

    /**
     * Run a truncate statement on the table.
     *
     * @return void
     */
    public function truncate()
    {
        parent::truncate();

        $this->handler->setBuilder($this)
            ->invalidateQuery(Reflector::QUERY_TYPE_TRUNCATE);
    }
}
